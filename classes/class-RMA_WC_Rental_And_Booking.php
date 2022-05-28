<?php

if ( ! defined('ABSPATH') ) exit;

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

        add_filter( 'rma_invoice_part', array( $this, 'modify_rma_invoice_part' ), 10, 2 );

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

        $tabs[ 'inventory' ][ 'class' ][] = 'show_if_redq_rental';

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

        // bail if item_id is not an int (like shipping costs can be)
        if( null === $item_id ) {

            return $part;

        }
        
        $order = wc_get_order( wc_get_order_id_by_order_item_id($item_id) );
        $item = $order->get_item( $item_id);
        $item_product = $item->get_product();

        // Bail if this is not a rental product.
        if( ! $item_product->is_type( 'redq_rental' ) ) {
            return $part;
        }

        // Set the article to the rental article for all rental bookings.
        $settings     = get_option( 'wc_rma_settings' );
        $rental_booking_article = $settings[ 'rma-product-rnb-rental-article' ];
        $part[ 'partnumber' ] = $rental_booking_article;

        // Set the projectnumber to the sku for the rental articles
        $sku = $item_product->get_sku();
        if ( ! empty( $sku ) ) {
            $part[ 'projectnumber' ] = $sku;
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

        $rnb_order_meta = wc_get_order_item_meta( $item_id, 'rnb_price_breakdown' );
        $duration_breakdown_sailcom = $rnb_order_meta['duration_breakdown_sailcom'];
        $discount_breakdown_sailcom = $rnb_order_meta['discount_breakdown_sailcom'];

        $datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $pickup_time = strtotime($rnb_order_meta[ 'pickup_time'] . ' ' . $rnb_order_meta[ 'pickup_time']);
        $pickup_datetime_formatted = wp_date( $datetime_format, $pickup_time );

        $dropoff_time = strtotime($rnb_order_meta[ 'dropoff_time'] . ' ' . $rnb_order_meta[ 'dropoff_time']);
        $dropoff_datetime_formatted = wp_date($datetime_format, $dropoff_time );

        $confimed_datetime = $order->get_date_paid();
        $confimed_datetime_formatted = wp_date( $datetime_format, $dropoff_time );

        // build multiline description
        $part[ 'description' ] = $part[ 'description' ] . "\n" . $part_title . "\n";
        $part[ 'description' ] .= ' ' .$pickup_datetime_formatted . ' - ' . $dropoff_datetime_formatted;
        $part[ 'description' ] .= "\n" . esc_html__( 'Booked at', 'woocommerce-sailcom') . ': ' . $confimed_datetime_formatted;
        $part[ 'description' ] .= ' (' . $order->get_customer_ip_address() . ')';

        // set line total price
        $total = $rnb_order_meta[ 'discounted_duration_total' ];
        // $total = $item->get_total(); Gives undiscounted...
        if( wc_tax_enabled() ) {

            $part[ 'sellprice' ] = round( $total + $tax, 2 );

        }
        else {

            $part[ 'sellprice' ] = $total;

        }

        return $part;

    }

}