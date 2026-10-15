<?php
/**
 * "Invoicing" column and filter on the WooCommerce orders list.
 *
 * @package RunmyAccountsforWooCommerce
 */

use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One column for how an order is invoiced: its Run my Accounts invoice state, or the route it is billed
 * through instead (labels for other routes come from the `rma_wc_invoice_route_label` filter).
 */
class RMA_WC_Invoicing_Column {

	public const COLUMN = 'rma_invoicing';

	public const FILTER = 'rma_invoicing';

	/**
	 * Order status that means the order should be invoiced.
	 */
	private const BILLABLE_STATUS = 'completed';

	/**
	 * Badge classes from WooCommerce's order status styles.
	 */
	private const BADGE_CLASSES = array(
		'PAID'     => 'status-completed',
		'OPEN'     => 'status-processing',
		'OVERDUE'  => 'status-failed',
		'failed'   => 'status-failed',
		'not-sent' => 'status-on-hold',
	);

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'admin_head', array( $this, 'print_styles' ) );

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ), 20 );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );
			add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_filter' ), 25, 2 );
			add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'add_filter_query_arg' ), 25 );
			add_filter( 'woocommerce_orders_table_query_clauses', array( $this, 'filter_query_clauses' ), 10, 3 );
		} else {
			// Legacy order storage: column only; the filter needs the HPOS query clauses.
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * WooCommerce's order-status badge cuts its text to one line; let the invoicing badge wrap instead.
	 *
	 * @return void
	 */
	public function print_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			return;
		}

		echo '<style>'
			. '.column-' . esc_attr( self::COLUMN ) . '{min-width:9em}'
			. 'mark.order-status.rma-invoicing{max-width:100%;height:auto;line-height:1.3;padding:.3em 0;margin:0;white-space:normal}'
			. 'mark.order-status.rma-invoicing>span{white-space:normal;overflow:visible;text-overflow:clip;margin:0 .7em}'
			. '</style>';
	}

	/**
	 * @param array $columns Order list columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		return RMA_WC_Frontend::array_insert( (array) $columns, 'order_total', self::COLUMN, __( 'Invoicing', 'rma-wc' ) );
	}

	/**
	 * @param string       $column Column ID.
	 * @param WC_Order|int $order  Order (HPOS) or post ID (legacy).
	 * @return void
	 */
	public function render_column( $column, $order ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			echo '&mdash;';
			return;
		}

		$state = self::order_state( $order );
		if ( '' === $state['label'] ) {
			echo '&mdash;';
			return;
		}

		printf(
			'<mark class="order-status rma-invoicing %1$s"%2$s><span>%3$s</span></mark>',
			esc_attr( self::BADGE_CLASSES[ $state['key'] ] ?? '' ),
			'' !== $state['title'] ? ' title="' . esc_attr( $state['title'] ) . '"' : '',
			esc_html( $state['label'] )
		);
		if ( '' !== $state['detail'] ) {
			echo '<br><small class="rma-invoicing-detail">' . esc_html( $state['detail'] ) . '</small>';
		}
	}

	/**
	 * Invoicing state of an order. An existing Run my Accounts invoice wins over the route, so orders
	 * invoiced before their payment method was routed elsewhere still show their invoice.
	 *
	 * @param WC_Order $order Order.
	 * @return array{key: string, label: string, detail: string, title: string}
	 */
	public static function order_state( WC_Order $order ): array {
		$invoice = (string) $order->get_meta( '_rma_invoice', true );
		if ( '' !== $invoice ) {
			$status = (string) $order->get_meta( '_rma_invoice_status', true );
			$state  = array(
				'key'    => '' === $status ? 'not-synced' : $status,
				/* translators: %s: invoice state such as "Open" or "Not synced yet" */
				'label'  => sprintf( __( 'RMA: %s', 'rma-wc' ), '' === $status ? __( 'Not synced yet', 'rma-wc' ) : RMA_WC_API::invoice_status_label( $status ) ),
				'detail' => $invoice,
				'title'  => '',
			);

			$changed_at = '' === $status ? false : strtotime( (string) $order->get_meta( '_rma_invoice_status_timestamp', true ) );
			if ( false !== $changed_at && $changed_at > 0 ) {
				/* translators: %s: relative time such as "3 days" */
				$state['detail'] .= ' · ' . sprintf( __( 'Changed %s ago', 'rma-wc' ), human_time_diff( $changed_at, time() ) );
				/* translators: %s: date and time the invoice status changed */
				$state['title'] = sprintf( __( 'Status changed: %s', 'rma-wc' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $changed_at ) );
			}

			return $state;
		}

		$route = RMA_WC_API::order_invoice_route( $order );
		if ( RMA_WC_API::INVOICE_ROUTE_RMA !== $route ) {
			return array(
				'key'    => 'route-' . $route,
				'label'  => RMA_WC_API::invoice_route_label( $route, $order ),
				'detail' => '',
				'title'  => '',
			);
		}

		$error = (string) $order->get_meta( RMA_WC_API::INVOICE_ERROR_META, true );
		if ( '' !== $error ) {
			$failed_at = strtotime( (string) $order->get_meta( RMA_WC_API::INVOICE_ERROR_AT_META, true ) );

			return array(
				'key'    => 'failed',
				'label'  => __( 'RMA: invoice failed', 'rma-wc' ),
				'detail' => false !== $failed_at && $failed_at > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $failed_at ) : '',
				'title'  => $error,
			);
		}

		if ( $order->has_status( self::BILLABLE_STATUS ) ) {
			return array(
				'key'    => 'not-sent',
				'label'  => __( 'RMA: not sent', 'rma-wc' ),
				'detail' => '',
				'title'  => '',
			);
		}

		return array(
			'key'    => 'none',
			'label'  => '',
			'detail' => '',
			'title'  => '',
		);
	}

	/**
	 * Filter choices: value => label.
	 *
	 * @return array<string,string>
	 */
	public static function filter_options(): array {
		$options = array(
			''             => __( 'All invoicing states', 'rma-wc' ),
			'needs-action' => __( 'Needs action: no Run my Accounts invoice yet', 'rma-wc' ),
			'failed'       => __( 'RMA: invoice failed', 'rma-wc' ),
		);
		foreach ( array( 'OPEN', 'OVERDUE', 'PAID' ) as $status ) {
			/* translators: %s: invoice state such as "Open" */
			$options[ $status ] = sprintf( __( 'RMA: %s', 'rma-wc' ), RMA_WC_API::invoice_status_label( $status ) );
		}
		/* translators: %s: invoice state */
		$options['not-synced'] = sprintf( __( 'RMA: %s', 'rma-wc' ), __( 'Not synced yet', 'rma-wc' ) );

		foreach ( RMA_WC_API::invoice_routes() as $route ) {
			if ( RMA_WC_API::INVOICE_ROUTE_RMA !== $route ) {
				$options[ 'route:' . $route ] = RMA_WC_API::invoice_route_label( $route );
			}
		}

		return $options;
	}

	/**
	 * Requested filter value, or '' when it is not one of the choices.
	 *
	 * @return string
	 */
	public static function current_filter(): string {
		$value = isset( $_GET[ self::FILTER ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array_key_exists( $value, self::filter_options() ) ? $value : '';
	}

	/**
	 * @param string $order_type Order type.
	 * @param string $which      'top' or 'bottom'.
	 * @return void
	 */
	public function render_filter( $order_type, $which = 'top' ) {
		if ( 'shop_order' !== $order_type || 'top' !== $which ) {
			return;
		}

		$current = self::current_filter();
		echo '<select name="' . esc_attr( self::FILTER ) . '" id="filter-by-rma-invoicing">';
		foreach ( self::filter_options() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Carry the filter into the order query, where filter_query_clauses() turns it into SQL.
	 *
	 * @param array $query_args wc_get_orders() args of the orders list.
	 * @return array
	 */
	public function add_filter_query_arg( $query_args ) {
		$filter = self::current_filter();
		if ( '' !== $filter ) {
			$query_args[ self::FILTER ] = $filter;
		}

		return $query_args;
	}

	/**
	 * @param array                                                         $clauses Query clauses.
	 * @param \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableQuery $query   Query.
	 * @param array                                                         $args    Query args.
	 * @return array
	 */
	public function filter_query_clauses( $clauses, $query, $args ) {
		$filter = (string) ( $args[ self::FILTER ] ?? '' );
		if ( '' === $filter || ! array_key_exists( $filter, self::filter_options() ) ) {
			return $clauses;
		}

		$condition = self::filter_condition( $filter, $query->get_table_name( 'orders' ), $query->get_table_name( 'meta' ) );
		if ( '' !== $condition ) {
			$where            = trim( (string) ( $clauses['where'] ?? '' ) );
			$clauses['where'] = ( '' === $where ? '' : $where . ' AND ' ) . '( ' . $condition . ' )';
		}

		return $clauses;
	}

	/**
	 * Prepared SQL condition for a filter value.
	 *
	 * @param string $filter       Filter value.
	 * @param string $orders_table HPOS orders table.
	 * @param string $meta_table   HPOS order meta table.
	 * @return string
	 */
	public static function filter_condition( string $filter, string $orders_table, string $meta_table ): string {
		global $wpdb;

		$meta = static function ( string $key ) use ( $orders_table, $meta_table ): string {
			return "(SELECT rma_m.meta_value FROM {$meta_table} rma_m WHERE rma_m.order_id = {$orders_table}.id AND rma_m.meta_key = '" . esc_sql( $key ) . "' LIMIT 1)";
		};

		$params       = array();
		$invoice_meta = $meta( '_rma_invoice' );
		$status_meta  = $meta( '_rma_invoice_status' );
		$error_meta   = $meta( RMA_WC_API::INVOICE_ERROR_META );
		$no_invoice   = "COALESCE({$invoice_meta}, '') = ''";
		$route        = RMA_WC_API::invoice_route_sql_expression( "{$orders_table}.payment_method", $meta( RMA_WC_API::INVOICE_ROUTE_META ), $params );

		switch ( true ) {
			case 'needs-action' === $filter:
				$params[] = RMA_WC_API::INVOICE_ROUTE_RMA;
				$params[] = 'wc-' . self::BILLABLE_STATUS;
				$sql      = "{$no_invoice} AND {$route} = %s AND {$orders_table}.status = %s";
				break;
			case 'failed' === $filter:
				$params[] = RMA_WC_API::INVOICE_ROUTE_RMA;
				$sql      = "{$no_invoice} AND {$route} = %s AND COALESCE({$error_meta}, '') <> ''";
				break;
			case in_array( $filter, array( 'OPEN', 'OVERDUE', 'PAID' ), true ):
				$params = array( $filter );
				$sql    = "{$status_meta} = %s";
				break;
			case 'not-synced' === $filter:
				$params = array();
				$sql    = "COALESCE({$invoice_meta}, '') <> '' AND COALESCE({$status_meta}, '') = ''";
				break;
			case str_starts_with( $filter, 'route:' ):
				$params[] = substr( $filter, strlen( 'route:' ) );
				$sql      = "{$no_invoice} AND {$route} = %s";
				break;
			default:
				return '';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return empty( $params ) ? $sql : $wpdb->prepare( $sql, $params );
	}
}
