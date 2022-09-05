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
		$settings               = get_option( 'wc_rma_settings' );

		$item_rental_days_and_cost = $item->get_meta( 'rnb_hidden_order_meta' )['rental_days_and_costs'] ?? false;

		if ( false === $item_rental_days_and_cost ) {
			// bail.
			return $part;
		}

		$order_meta_cancelation = $order->get_meta( 'cancellation_fee_order' );
		$is_cancelation_order   = ( ! empty( $item_rental_days_and_cost['price_breakdown']['order_modification_type'] ) ) && 'cancelation' === $item_rental_days_and_cost['price_breakdown']['order_modification_type'];

		// Just for debugging. TODO: Remove!
		// $part['itemnote'] = htmlspecialchars( print_r( $order_meta, true ), ENT_XML1, 'UTF-8' );

		$canceled_order_id = null;
		if ( $is_cancelation_order ) {
            
            if ( ! isset( $settings['rma-product-rnb-cancelation-article'] ) ) {
                wp_die( 'RMA Rental Booking Cancelation Article is not configured' );
			}
            $rental_booking_article = $settings['rma-product-rnb-cancelation-article'];

			$canceled_order_id           = $item_rental_days_and_cost['price_breakdown']['order_modification_original_order'];
			$canceled_order = wc_get_order( $canceled_order_id );
			
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
		}

		// get values
		$days            = wc_get_order_item_meta( $item_id, '_return_hidden_days' );
		$total           = wc_get_order_item_meta( $item_id, '_line_total' );
		$tax             = wc_get_order_item_meta( $item_id, '_line_tax' );
		$pickup_location = wc_get_order_item_meta( $item_id, 'Pickup Location' );
		$pickup_date     = wc_get_order_item_meta( $item_id, 'Pickup Date & Time' );
		$return_date     = wc_get_order_item_meta( $item_id, 'Return Date & Time' );
		$total_days      = wc_get_order_item_meta( $item_id, 'Total Days' );

		$part_title = wc_get_order_item_meta( $item_id, 'Choose Inventory' );

		$rnb_order_meta             = wc_get_order_item_meta( $item_id, 'rnb_hidden_order_meta' );
		$rnb_price_breakdown        = wc_get_order_item_meta( $item_id, 'rnb_price_breakdown' );
		$duration_breakdown_sailcom = $rnb_price_breakdown['duration_breakdown_sailcom'];
		$discount_breakdown_sailcom = $rnb_price_breakdown['discount_breakdown_sailcom'];
		$cancelation_breakdown      = $rnb_price_breakdown['cancelation_breakdown'] ?? '';



		$confirmed_datetime_formatted = $order->get_date_created()->format( $datetime_format );

		// build multiline description.
		$part['description'] = '';

        if ( $is_cancelation_order ) {
            $part['description']  = '#' . $order_id . ': ' . esc_html__( 'Cancelation of original order', 'woocommerce-sailcom' ) . ' #' . $canceled_order_id . " \n";
			$part['description'] .= $part_title;

        } else {

			$pickup_time               = strtotime( $rnb_order_meta['pickup_date'] . ' ' . $rnb_order_meta['pickup_time'] );
			$pickup_datetime_formatted = wp_date( $datetime_format, $pickup_time );

			$dropoff_time               = strtotime( $rnb_order_meta['dropoff_date'] . ' ' . $rnb_order_meta['dropoff_time'] );
			$dropoff_datetime_formatted = wp_date( $datetime_format, $dropoff_time );

            $part['description']  = '#' . $order_id . ': ' . esc_html__( 'Reservation', 'woocommerce-sailcom' ) . " \n";
			$part['description'] .= $part_title . ' ' . $pickup_datetime_formatted . ' - ' . $dropoff_datetime_formatted;
        }

		$part['description'] .= ' (' . $confirmed_datetime_formatted . '/' . $order->get_customer_ip_address() . ')';

		// set line total price.
		$total = $item->get_total(); // Gives total of line item, which is the sustainable variant.

		if ( wc_tax_enabled() ) {

			$part['sellprice'] = round( $total + $tax, 2 );

		} else {

			$part['sellprice'] = $total;

		}

		return $part;

	}

}
