<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for extending features for plugin WooCommerce Rental & Booking System
 * https://codecanyon.net/item/rnb-woocommerce-rental-booking-system/14835145
 *
 * @since 1.7.0
 */
class RMA_WC_Rental_And_Booking {

	const XML_NL = '&#xA;';

	public function __construct() {

		self::init();

	}

	/**
	 * Initialize stuff like variables, filter, hooks
	 *
	 * @return void
	 *
	 * @since 1.7.0
	 *
	 * @author Sandro Lucifora
	 */
	public function init() {

		add_filter( 'woocommerce_product_data_tabs', array( $this, 'woocommerce_product_data_tabs' ) );

		add_filter( 'rma_invoice_part', array( $this, 'modify_rma_invoice_part' ), 15, 2 );

	}

	/**
	 * Shows additional product tab for product type
	 *
	 * @param $tabs
	 *
	 * @return array
	 *
	 * @since 1.7.0
	 *
	 * @author Sandro Lucifora
	 */
	public function woocommerce_product_data_tabs( $tabs ): array {

		$tabs['inventory']['class'][] = 'show_if_redq_rental';

		return $tabs;

	}

	/**
	 * Modifies invoice part with rental details
	 *
	 * @param array    $part    The original part array
	 * @param int|null $item_id The item id of this order part
	 *
	 * @return array         Modified part
	 * @throws Exception
	 *
	 * @since 1.7.0
	 *
	 * @author Sandro Lucifora
	 */
	public function modify_rma_invoice_part( array $part, ?int $item_id ): array {

		$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		// bail if item_id is not an int (like shipping costs can be)
		if ( null === $item_id ) {

			return $part;

		}

		$order_id     = wc_get_order_id_by_order_item_id( $item_id );
		$order        = wc_get_order( $order_id );
		$item         = $order->get_item( $item_id );
		$item_product = $item->get_product();

		// Bail if this is not a rental product.
		if ( ! $item_product->is_type( 'redq_rental' ) ) {
			return $part;
		}

		// Set the article to the rental article for all rental bookings.
		$settings = get_option( 'wc_rma_settings' );

		$item_rental_days_and_cost = $item->get_meta( 'rnb_hidden_order_meta' )['rental_days_and_costs'] ?? false;

		if ( false === $item_rental_days_and_cost ) {
			// bail.
			return $part;
		}

		$is_cancelation_order = ( ! empty( $item_rental_days_and_cost['price_breakdown']['order_modification_type'] ) ) && 'cancelation' === $item_rental_days_and_cost['price_breakdown']['order_modification_type'];

		$canceled_order_id = null;
		if ( $is_cancelation_order ) {

			if ( ! isset( $settings['rma-product-rnb-cancelation-article'] ) ) {
				wp_die( 'RMA Rental Booking Cancelation Article is not configured' );
			}
			$rental_booking_article = $settings['rma-product-rnb-cancelation-article'];

			$canceled_order_id = $item_rental_days_and_cost['price_breakdown']['order_modification_original_order'];
			$canceled_order    = wc_get_order( $canceled_order_id );

			$canceled_order_booking_time = wp_date( $datetime_format, $canceled_order->get_date_created() );
		} else {
			$rental_booking_article = $settings['rma-product-rnb-rental-article'];
			if ( ! isset( $rental_booking_article ) ) {
				wp_die( 'RMA Rental Booking Article is not configured' );
			}
		}

		$part['partnumber'] = $rental_booking_article;

		// Set the projectnumber to the sku for the rental articles.
		$sku = $item_product->get_sku();
		if ( ! empty( $sku ) ) {
			$part['projectnumber'] = $sku;
		} else {
			$log_values = array(
				'status'     => 'error',
				'section_id' => $item_product->get_id(),
				'section'    => $item_product->get_name(),
				'mode'       => RMA_WC_API::rma_mode(),
				'message'    => 'Product ' . $item_product->get_name() . ' does not have a valid SKU.',
			);

			( new RMA_WC_API() )->write_log( $log_values );
		}

		// get values.
		$total = wc_get_order_item_meta( $item_id, '_line_total' );
		$tax   = wc_get_order_item_meta( $item_id, '_line_tax' );

		$part_title = wc_get_order_item_meta( $item_id, 'Choose Inventory' );

		$rnb_order_meta = wc_get_order_item_meta( $item_id, 'rnb_hidden_order_meta' );

		$confirmed_datetime_formatted = $order->get_date_created()->format( $datetime_format );

		// build multiline description.
		$part['description'] = '';

		if ( $is_cancelation_order ) {
			$part['description']  = '#' . $order_id . ': ' . esc_html__( 'Cancelation of original order', 'woocommerce-sailcom' ) . ' #' . $canceled_order_id . self::XML_NL;
			$part['description'] .= $part_title;

		} else {

			$pickup_time                = new \DateTime( $rnb_order_meta['pickup_date'] . ' ' . $rnb_order_meta['pickup_time'], wp_timezone() );
			$pickup_datetime_formatted  = wp_date( $datetime_format, $pickup_time->format( 'U' ) );
			$dropoff_time               = new \DateTime( $rnb_order_meta['dropoff_date'] . ' ' . $rnb_order_meta['dropoff_time'], wp_timezone() );
			$dropoff_datetime_formatted = wp_date( $datetime_format, $dropoff_time->format( 'U' ) );

			$part['description']  = '#' . $order_id . ': ' . esc_html__( 'Reservation', 'woocommerce-sailcom' ) . self::XML_NL;
			$part['description'] .= $part_title . self::XML_NL . $pickup_datetime_formatted . ' - ' . $dropoff_datetime_formatted;
		}

		$part['description'] .= self::XML_NL . '(' . $confirmed_datetime_formatted . '/' . $order->get_customer_ip_address() . ')';

		// set line total price.
		$total = $item->get_total(); // Gives total of line item, which is the sustainable variant.

		if ( wc_tax_enabled() ) {

			$part['sellprice'] = round( $total + $tax, 2 );

		} else {

			$part['sellprice'] = $total;

		}

		// Attaching order notes.
		$notes = $order->get_customer_note();

		if ( ! empty( $notes ) ) {

			$notes = str_replace( PHP_EOL, self::XML_NL, $notes );

			$part['itemnote'] = wp_kses_post( $notes );
		}

		return $part;

	}

}
