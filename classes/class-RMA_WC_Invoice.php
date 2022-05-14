<?php

if ( ! defined('ABSPATH')) exit;

/**
 * Class for reporting on the invoice status
 *
 * @since 1.7.0
 */
class RMA_WC_Invoice {

    private $settings;

    public function __construct() {
        $this->init_hooks();

    }

    public function init_hooks() {

                // allow order query by invoice number.
                add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'handle_invoice_number_query_var' ), 10, 2 );

                // update order status once per hour and display it on the admin and user order overview
                add_filter( 'init', array( $this, 'maybe_create_schedule_update_invoice_status_event' ) );
                add_action( 'update_invoice_status', array( $this, 'hourly_update_invoice_status' ) );

                // add status column to order page
                add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_status_column_to_order_table' ) , 20);
                add_action( 'manage_shop_order_posts_custom_column', array( $this, 'add_status_value_to_order_table_row' ) );

                // User profile invoice info

                // Add endpoints
                add_action( 'init', array( $this, 'my_invoices_endpoint' ) );
                add_filter( 'query_vars', array( $this, 'my_invoices_query_vars', 0 ) );
                // Menu entry
                add_filter( 'woocommerce_account_menu_items', array( $this, 'my_invoices_my_account_menu_items' ) );


    }


    /**
         * Allow querying orders by invoice number.
         *
         * https://github.com/woocommerce/woocommerce/wiki/wc_get_orders-and-WC_Order_Query#adding-custom-parameter-support
         * Handle a custom 'customvar' query var to get orders with the 'customvar' meta.
         * 
         * @param array $query - Args for WP_Query.
         * @param array $query_vars - Query vars from WC_Order_Query.
         * @return array modified $query
         */
        function handle_invoice_number_query_var( $query, $query_vars ) {
            if ( ! empty( $query_vars['invoice_number'] ) ) {
                $query['meta_query'][] = array(
                    'key' => '_rma_invoice',
                    'value' => esc_attr( $query_vars['invoice_number'] ),
                );
            }

            return $query;
        }

        /**
         * Create scheduler for hourly order status update.
         * Schedules the event if it's NOT already scheduled.
         */
        public function maybe_create_schedule_update_invoice_status_event( ) {
            if ( ! wp_next_scheduled ( 'update_invoice_status' ) ) {
                wp_schedule_event( time(), 'hourly', 'update_invoice_status' );
            }
        }

        /**
         * Update order status
         * 
         */
        public function hourly_update_invoice_status( ) {
            $RMA_WC_API = new RMA_WC_API();
            $status_array = $RMA_WC_API->get_invoice_status();
            foreach( $status_array as $invoice_number=>$status) {
                $orders = wc_get_orders( array( 'invoice_number' =>  $invoice_number ) );
                foreach( $orders as $order ) {
                    $debug_var = (json_encode(array(
                        'id' => $order->get_id(),
                        'inv'=> $invoice_number,
                        'stat'=>$status,
                        'array'=>$status_array
                    )));

                    $order->update_meta_data( '_rma_invoice_status', sanitize_text_field( $status ) );
                    $order->update_meta_data('_rma_invoice_status_timestamp', current_datetime()->format('c') );
                    $order->save_meta_data();
                }
            }

            unset( $RMA_WC_API );
        }


        /**
         * Add status column to order table
         *
         * @param $columns
         *
         * @return array
         */
        public function add_status_column_to_order_table( $columns ) {

            // $this->update_invoice_status();
            $columns = RMA_WC_Frontend::array_insert( $columns, 'rma_invoice', 'rma_invoice_status', __( 'Invoice Status', 'rma-wc' ) );
            return $columns;
        }

        public function add_status_value_to_order_table_row( $column ) {

            global $post;

            switch ( $column ) {

                case 'rma_invoice_status' :
                    echo '<mark class="order-status" title="';
                    echo __( 'Last updated: ', 'rma-wc' );
                    echo wp_date( get_option( 'date_format' ),strtotime( get_post_meta( $post->ID, '_rma_invoice_status_timestamp', true ) ) );
                    echo ' ';
                    echo wp_date( get_option( 'time_format' ),strtotime( get_post_meta( $post->ID, '_rma_invoice_status_timestamp', true ) ) );
                    echo '"><span>';
                    echo get_post_meta( $post->ID, '_rma_invoice_status', true );
                    echo "</span></mark>";
                    
                default:
            }


        }


        /**
         * User Profile Info
         * Show invoice information to users on their profile page.
         */

         /**
         * Register new endpoint to use inside My Account page.
         *
         * @see https://developer.wordpress.org/reference/functions/add_rewrite_endpoint/
         */
        function my_invoices_endpoint() {
            add_rewrite_endpoint( 'my-invoices', EP_ROOT | EP_PAGES );
        }

        /**
         * Add new query var.
         *
         * @param array $vars
         * @return array
         */
        function my_invoices_query_vars( $vars ) {
            $vars[] = 'my-invoices-endpoint';

            return $vars;
        }

        /**
         * Insert the new endpoint into the My Account menu.
         *
         * @param array $items
         * @return array
         */
        function my_invoices_my_account_menu_items( $items ) {

            $items = RMA_WC_Frontend::array_insert( $items, 'orders', 'invoices', __( 'Invoices', 'rma-wc' ) );

            return $items;
        }



        }