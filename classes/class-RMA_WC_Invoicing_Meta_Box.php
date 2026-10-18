<?php
/**
 * "Invoicing" meta box on the order screen: shows how the order is invoiced and lets staff change it
 * (another route, "not billable", or an invoice created by hand in Run my Accounts), always with a reason.
 *
 * @package RunmyAccountsforWooCommerce
 */

use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order screen meta box for the invoicing route.
 */
class RMA_WC_Invoicing_Meta_Box {

	public const BOX_ID = 'rma-invoicing';

	public const CHANGE_FIELD = 'rma_invoicing_change';

	public const REASON_FIELD = 'rma_invoicing_reason';

	public const INVOICE_FIELD = 'rma_invoicing_invoice_number';

	private const NONCE_ACTION = 'rma_invoicing_change';

	private const NONCE_FIELD = 'rma_invoicing_nonce';

	/**
	 * Change value that removes the override, so the payment method decides again.
	 */
	public const CHANGE_AUTOMATIC = 'automatic';

	/**
	 * Change value for an invoice that was created by hand in Run my Accounts.
	 */
	public const CHANGE_MANUAL_INVOICE = 'manual-invoice';

	/**
	 * Prefix of change values that set a route override.
	 */
	public const CHANGE_ROUTE_PREFIX = 'route:';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save' ), 60, 2 );
	}

	/**
	 * @return void
	 */
	public function add_meta_box() {
		$screen = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box( self::BOX_ID, __( 'Invoicing', 'rma-wc' ), array( $this, 'render' ), $screen, 'side', 'default' );
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order Post (legacy storage) or order (HPOS).
	 * @return void
	 */
	public function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$state = RMA_WC_Invoicing_Column::order_state( $order );
		echo '<p id="rma-invoicing-state"><strong>' . esc_html( '' === $state['label'] ? __( 'Not invoiced yet', 'rma-wc' ) : $state['label'] ) . '</strong>';
		if ( '' !== $state['detail'] ) {
			echo '<br>' . esc_html( $state['detail'] );
		}
		if ( '' !== $state['title'] ) {
			echo '<br><small>' . esc_html( $state['title'] ) . '</small>';
		}
		echo '</p>';

		$reason = (string) $order->get_meta( RMA_WC_API::INVOICE_ROUTE_REASON_META, true );
		if ( '' !== $reason ) {
			$user    = get_userdata( (int) $order->get_meta( RMA_WC_API::INVOICE_ROUTE_BY_META, true ) );
			$changed = strtotime( (string) $order->get_meta( RMA_WC_API::INVOICE_ROUTE_AT_META, true ) );
			echo '<p id="rma-invoicing-change-reason">';
			printf(
				/* translators: 1: user name, 2: date and time */
				esc_html__( 'Changed by %1$s on %2$s.', 'rma-wc' ),
				esc_html( false !== $user ? $user->display_name : __( 'unknown user', 'rma-wc' ) ),
				esc_html( false !== $changed && $changed > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $changed ) : '' )
			);
			/* translators: %s: reason entered by the staff member */
			echo '<br>' . esc_html( sprintf( __( 'Reason: %s', 'rma-wc' ), $reason ) ) . '</p>';
		}

		if ( '' !== (string) $order->get_meta( '_rma_invoice', true ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<p><label for="' . esc_attr( self::CHANGE_FIELD ) . '">' . esc_html__( 'Change how this order is invoiced:', 'rma-wc' ) . '</label>';
		echo '<select name="' . esc_attr( self::CHANGE_FIELD ) . '" id="' . esc_attr( self::CHANGE_FIELD ) . '" style="width:100%">';
		foreach ( self::change_options( $order ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';

		echo '<p><label for="' . esc_attr( self::INVOICE_FIELD ) . '">' . esc_html__( 'Run my Accounts invoice number (only for an invoice created by hand):', 'rma-wc' ) . '</label>';
		echo '<input type="text" name="' . esc_attr( self::INVOICE_FIELD ) . '" id="' . esc_attr( self::INVOICE_FIELD ) . '" style="width:100%" autocomplete="off"></p>';

		echo '<p><label for="' . esc_attr( self::REASON_FIELD ) . '">' . esc_html__( 'Reason (required):', 'rma-wc' ) . '</label>';
		echo '<textarea name="' . esc_attr( self::REASON_FIELD ) . '" id="' . esc_attr( self::REASON_FIELD ) . '" rows="2" style="width:100%"></textarea></p>';
		echo '<p class="description">' . esc_html__( 'Applied when the order is updated.', 'rma-wc' ) . '</p>';
	}

	/**
	 * Choices for changing an order's invoicing: value => label. The current route is left out.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,string>
	 */
	public static function change_options( WC_Order $order ): array {
		$current  = RMA_WC_API::order_invoice_route( $order );
		$override = (string) $order->get_meta( RMA_WC_API::INVOICE_ROUTE_META, true );

		$options = array( '' => __( 'No change', 'rma-wc' ) );
		if ( '' !== $override ) {
			$from_payment_method = RMA_WC_API::payment_method_invoice_route( (string) $order->get_payment_method() );
			/* translators: %s: invoicing route label, e.g. "Run my Accounts" */
			$options[ self::CHANGE_AUTOMATIC ] = sprintf( __( 'From the payment method (%s)', 'rma-wc' ), RMA_WC_API::invoice_route_label( $from_payment_method, $order ) );
		}
		foreach ( RMA_WC_API::invoice_routes() as $route ) {
			if ( $route !== $current ) {
				$options[ self::CHANGE_ROUTE_PREFIX . $route ] = RMA_WC_API::invoice_route_label( $route, $order );
			}
		}
		$options[ self::CHANGE_MANUAL_INVOICE ] = __( 'Invoiced by hand in Run my Accounts', 'rma-wc' );

		return $options;
	}

	/**
	 * Apply the change submitted with the order form.
	 *
	 * @param int                   $order_id Order ID.
	 * @param WC_Order|WP_Post|null $order    Order being saved (HPOS) or its post (legacy storage).
	 * @return void
	 */
	public function save( $order_id, $order = null ) {
		$change = isset( $_POST[ self::CHANGE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CHANGE_FIELD ] ) ) : '';
		if ( '' === $change ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$result = self::apply_change(
			$order,
			$change,
			isset( $_POST[ self::REASON_FIELD ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::REASON_FIELD ] ) ) : '',
			isset( $_POST[ self::INVOICE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::INVOICE_FIELD ] ) ) : '',
			get_current_user_id()
		);
		if ( is_wp_error( $result ) && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			WC_Admin_Meta_Boxes::add_error( $result->get_error_message() );
		}
	}

	/**
	 * Change how an order is invoiced, record who did it and why, and add an order note.
	 *
	 * @param WC_Order $order          Order.
	 * @param string   $change         A key of change_options().
	 * @param string   $reason         Reason (required).
	 * @param string   $invoice_number Run my Accounts invoice number, for CHANGE_MANUAL_INVOICE.
	 * @param int      $user_id        User making the change.
	 * @return true|WP_Error
	 */
	public static function apply_change( WC_Order $order, string $change, string $reason, string $invoice_number, int $user_id ) {
		$reason = trim( $reason );
		if ( '' !== (string) $order->get_meta( '_rma_invoice', true ) ) {
			return new WP_Error( 'rma_invoicing_has_invoice', __( 'This order already has a Run my Accounts invoice, so how it is invoiced cannot be changed.', 'rma-wc' ) );
		}
		if ( ! array_key_exists( $change, self::change_options( $order ) ) || '' === $change ) {
			return new WP_Error( 'rma_invoicing_invalid', __( 'This invoicing change is not available for the order.', 'rma-wc' ) );
		}
		if ( '' === $reason ) {
			return new WP_Error( 'rma_invoicing_reason', __( 'Enter a reason to change how the order is invoiced.', 'rma-wc' ) );
		}

		$user      = get_userdata( $user_id );
		$user_name = false !== $user ? $user->display_name : __( 'unknown user', 'rma-wc' );
		$from      = RMA_WC_API::invoice_route_label( RMA_WC_API::order_invoice_route( $order ), $order );

		if ( self::CHANGE_MANUAL_INVOICE === $change ) {
			$invoice_number = RMA_WC_API::normalize_invoice_number( $invoice_number );
			if ( '' === $invoice_number ) {
				return new WP_Error( 'rma_invoicing_invoice_number', __( 'Enter the Run my Accounts invoice number.', 'rma-wc' ) );
			}
			$customer_number = RMA_WC_API::normalize_customer_number( get_user_meta( $order->get_customer_id(), 'rma_customer', true ) );
			if ( '' === $customer_number ) {
				return new WP_Error( 'rma_invoicing_no_customer', __( 'The customer of this order has no Run my Accounts customer number, so the invoice cannot be checked.', 'rma-wc' ) );
			}
			/**
			 * Decide whether the invoice belongs to the customer without asking Run my Accounts (null asks it).
			 *
			 * @param bool|null $verified        Verification result, or null.
			 * @param string    $customer_number Customer number of the order's customer.
			 * @param string    $invoice_number  Invoice number entered.
			 * @param WC_Order  $order           Order.
			 */
			$verified = apply_filters( 'rma_wc_verify_manual_invoice', null, $customer_number, $invoice_number, $order );
			if ( null === $verified ) {
				$verified = ( new RMA_WC_API() )->customer_has_invoice( $customer_number, $invoice_number, esc_html_x( 'Check Manual Invoice', 'Log Section', 'rma-wc' ) );
			}
			if ( ! $verified ) {
				return new WP_Error(
					'rma_invoicing_invoice_not_found',
					/* translators: 1: invoice number, 2: customer number */
					sprintf( __( 'Invoice %1$s was not found in Run my Accounts for customer %2$s.', 'rma-wc' ), $invoice_number, $customer_number )
				);
			}

			$order->update_meta_data( '_rma_invoice', $invoice_number );
			$order->update_meta_data( RMA_WC_API::INVOICE_MANUAL_META, 'yes' );
			$order->update_meta_data( RMA_WC_API::INVOICE_ROUTE_META, RMA_WC_API::INVOICE_ROUTE_RMA );
			$order->delete_meta_data( RMA_WC_API::INVOICE_ERROR_META );
			$order->delete_meta_data( RMA_WC_API::INVOICE_ERROR_AT_META );
			/* translators: 1: invoice number, 2: user name, 3: reason */
			$note = sprintf( __( 'Marked as invoiced by hand in Run my Accounts (invoice %1$s) by %2$s. Reason: %3$s', 'rma-wc' ), $invoice_number, $user_name, $reason );
		} else {
			if ( self::CHANGE_AUTOMATIC === $change ) {
				$order->delete_meta_data( RMA_WC_API::INVOICE_ROUTE_META );
				$to = RMA_WC_API::payment_method_invoice_route( (string) $order->get_payment_method() );
			} else {
				$to = substr( $change, strlen( self::CHANGE_ROUTE_PREFIX ) );
				$order->update_meta_data( RMA_WC_API::INVOICE_ROUTE_META, $to );
			}
			/* translators: 1: previous invoicing route, 2: new invoicing route, 3: user name, 4: reason */
			$note = sprintf( __( 'Invoicing changed from "%1$s" to "%2$s" by %3$s. Reason: %4$s', 'rma-wc' ), $from, RMA_WC_API::invoice_route_label( $to, $order ), $user_name, $reason );
		}

		$order->update_meta_data( RMA_WC_API::INVOICE_ROUTE_REASON_META, $reason );
		$order->update_meta_data( RMA_WC_API::INVOICE_ROUTE_BY_META, (string) $user_id );
		$order->update_meta_data( RMA_WC_API::INVOICE_ROUTE_AT_META, current_datetime()->format( 'c' ) );
		$order->save();
		$order->add_order_note( $note, 0, true );

		return true;
	}
}
