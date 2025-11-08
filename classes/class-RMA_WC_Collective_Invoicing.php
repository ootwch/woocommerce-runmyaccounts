<?php

if ( ! defined('ABSPATH')) exit;

/**
 * Class for collective invoicing
 *
 * @since 1.7.0
 */
class RMA_WC_Collective_Invoicing {

    private $settings;

    public function __construct() {
        $this->maybe_create_scheduled_event();

        // read rma settings
        $this->settings = get_option( 'wc_rma_settings_collective_invoice' );

        add_action( 'wp_ajax_next_invoice_date', [ $this, 'ajax_next_invoice_date' ] );

        add_action( 'run_my_accounts_collective_invoice', array( $this, 'create_collective_invoice' ) );

    }

    /**
     * Handle next invoice date by ajax
     *
     * @return void
     *
     * @throws Exception
     * @since 1.7.0
     */
    public function ajax_next_invoice_date(){

        $period  = $_POST[ 'period' ];
        $weekday = $_POST[ 'weekday' ];

        $dates   = self::get_next_invoice_date( $period, $weekday );

        if( !empty( $dates ) ) {

            $this->settings[ 'collective_invoice_next_date_ts' ] = strtotime(gmdate('Y-m-d', $dates[ 'next_date_ts' ] ) );
            update_option( 'wc_rma_settings_collective_invoice', $this->settings );

        }

        echo $dates[ 'date' ] ?? '';
        exit();
    }

    /**
     * Calculate next invoice date
     *
     * @param $period
     * @param $weekday
     *
     * @return array
     *
     * @throws Exception
     * @since 1.7.0
     */
    public function get_next_invoice_date( $period, $weekday ): array {

        // get next time from cron hook
        $next_time = self::get_cron_next_time();
        // separate hour, minutes and seconds from next cron hook run
        $time     = explode( ':', $next_time[ 'time' ] );
        $time_utc = explode( ':', $next_time[ 'time_utc' ] );

        switch ( $period ) {
            case 'week' :
                // get number day of the week from weekday
                $weekday_number = array_search( $weekday, self::get_weekdays() );
                // get timestamp and add hours and minutes if next cron run
                $dt = new DateTime();
                // set time by hours and minutes from next cron hook run
                $dt->setTime( $time_utc[ 0 ], $time_utc[ 1 ], $time_utc[ 2 ]);
                // create today's cron time stamp
                $possible_today_cron_ts_utc = $dt->getTimestamp();

                // if weekday is today and the next cron run is today
                if( gmdate('N', strtotime( 'now' ) ) == $weekday_number &&
                    $next_time[ 'next_time_ts_utc' ] == $possible_today_cron_ts_utc ) {

                    $next_date_ts_utc = strtotime('now');

                }
                // otherwise, set next date to next week
                else {
                    $next_date_ts_utc = strtotime("next $weekday");
                }
                break;
            case 'second_week' :
                $next_date_ts_utc = strtotime("+2 weeks next $weekday");
                break;
            case 'month' :
                $next_date_ts_utc = strtotime("first $weekday of next month");
                break;
        }

        if( !empty( $next_date_ts_utc ) ) {

            // add hours and minutes to next date
            $dt = new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) );
            $dt->setTimestamp( $next_date_ts_utc );
            $dt->setTime( $time[ 0 ], $time[ 1 ], '00' );

            return array(
                'next_date_ts' => $dt->getTimestamp() + $dt->getOffset() ,
                'date'         => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dt->getTimestamp() + $dt->getOffset() )
            );

        }

        return array();

    }

    /**
     * Returns an array with a mapping of weekday number and weekdays
     *
     * @return array Array with weekdays
     *
     * @since 1.7.0
     */
    private function get_weekdays(): array {

        return array(
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
            6 => 'saturday',
            7 => 'sunday',
        );

    }

    /**
     * Fetches the list of cron events from WordPress core.
     *
     * @return array
     *
     * @since 1.7.0
     */
    private function get_cron_next_time(): array {
        $crons = _get_cron_array();

        if ( empty( $crons ) ) {
            $crons = array();
        }

        foreach ( $crons as $next_ts_utc => $cron ) {

            foreach ( $cron as $hook => $values ) {

                if( 'run_my_accounts_collective_invoice' == $hook ) {

                    return array(
                        'next_time_ts_utc' => $next_ts_utc,
                        'time_utc'         => gmdate( get_option( 'time_format' ) . ':s', $next_ts_utc ),
                        'time'             => get_date_from_gmt( gmdate( get_option( 'time_format' ), $next_ts_utc ), get_option( 'time_format' ) )
                    );

                }

            }

        }

        return array();

    }

    /**
     * Collecting completed invoices, sorted by customer,
     * which were still not invoiced
     *
     * @return array
     *
     * @since 1.7.0
     */
    public function get_not_invoiced_orders(): array {

        switch ( $this->settings[ 'collective_invoice_span' ] ) {
            case 'per_week':
                $invoice_from_date = strtotime( "-1 week" );
                break;
            case 'per_month':
                $invoice_from_date = strtotime( "-1 month" );
                break;
            default:
                $invoice_from_date = 0;
                break;
        }

	    $orders_no_invoice = wc_get_orders(
		    array(
			    'type' => 'shop_order',
			    'limit' => -1,
			    'status' => array(
				    'wc-completed'
			    ),
			    array(
				    'meta_query'      => array(
					    'relation'    => 'AND',
					    array(
						    'key'     => '_rma_invoice',
						    'compare' => 'NOT EXISTS',
					    ),
				    )
			    )
		    )
	    );

        $cumulated_orders_by_customer_id = array();

        // loop through orders and create an associative array with orders for customer
        foreach ( $orders_no_invoice as $key => $order ) {

            // get values
            $order          = wc_get_order( $order->ID );
            $order_id       = $order->get_id();
            $user_id        = $order->get_user_id( $order_id );
            $paid_date      = $order->get_date_completed( $order_id );
            $tax_included   = $order->get_prices_include_tax( $order_id );
            $payment_method = $order->get_payment_method( $order_id );

            // is paid date in the range for invoicing?
            if( strtotime( $paid_date ) > $invoice_from_date ) {

                // prepare arrays to merge
                $a = isset( $cumulated_orders_by_customer_id[ $user_id ][ $tax_included ? 'tax' : 'no_tax' ][ $payment_method ] ) && is_array( $cumulated_orders_by_customer_id[ $user_id ][ $tax_included ? 'tax' : 'no_tax' ][ $payment_method ] ) ? $cumulated_orders_by_customer_id[ $user_id ][ $tax_included ? 'tax' : 'no_tax' ][ $payment_method ] : array();
                $b = array( 1 => $order_id );

                // merge previous order ids of a customer with additional order id
                $cumulated_orders_by_customer_id[ $user_id ][ $tax_included ? 'tax' : 'no_tax' ][ $payment_method ] = array_merge( $a, $b );

            }

        }

        return $cumulated_orders_by_customer_id;

    }

    /**
     * Create collective invoice triggered by cron job
     *
     * @return array array of created invoices
     *
     * @throws DOMException
     * @throws Exception
     * @since 1.7.0
     */
    public function create_collective_invoice(): array {
        // reset array
        $order_date_created = array();
        $created_invoices   = array();

        // get the timestamp with the current date, but without time
        $current_date = strtotime(gmdate('Y-m-d', time() ) );

        // if we do not have to create collective invoices today
        if( $current_date != $this->settings[ 'collective_invoice_next_date_ts' ] ) {
            // return the empty array
            return $created_invoices;
        }

        // get settings
        $settings         = get_option( 'wc_rma_settings' );

        // get all orders with no invoice
        $not_invoiced_orders = self::get_not_invoiced_orders();

        $invoice = new RMA_WC_API();

        foreach ( $not_invoiced_orders as $tax_statuses ) {

            foreach ( $tax_statuses as $payment_methods ) {

                foreach ( $payment_methods as $order_ids ) {

                    // sort the order ids in ascending order to output the items chronologically.
                    asort( $order_ids, SORT_NUMERIC );

                    // set first payment method
                    $first_order = true;
                    // reset variables
                    $order_details_products = array();
                    $order_details          = array();
                    $invoice_id             = '';

                    foreach ( $order_ids as $order_id ) {

                        $order                = wc_get_order( $order_id );
                        $order_date_created[] = $order->get_date_created()->date( 'U' ); // get order date created as unix timestamp
                        unset( $order );
                        
                        if( $first_order ) {

                            // remove flag for first payment method
                            $first_order = false;

                            // get the invoice header with $tax_status and $payment_method
                            $order_details = $invoice->get_wc_order_details( $order_id );

                            // create the invoice id based on the first order id
                            $invoice_id = RMA_INVOICE_PREFIX . str_pad( $order_id, max(intval(RMA_INVOICE_DIGITS) - strlen(RMA_INVOICE_PREFIX ), 0 ), '0', STR_PAD_LEFT );

                        }

                        // add products to order
                        $order_details_products = $invoice->get_order_details_products( $order_id, $order_details_products );

                        // add shipping costs to order
                        $order_details_products = $invoice->get_order_details_shipping_costs( $order_id, $order_details_products );
                        
                    }

                    // make sure we have an invoice header with values
                    if( 0 < count( $order_details ) ) {

                        // create period between oldest and latest order
                        $period = date_i18n( get_option( 'date_format' ), min( $order_date_created ) ) . ' - ' . date_i18n( get_option( 'date_format' ), max( $order_date_created ) );
                        // create description
                        $description = str_replace('[period]', $period, $settings[ 'rma-collective-invoice-description' ] ?? '' );
                        // collect invoice data
                        $data = $invoice->get_invoice_data( $order_details, $order_details_products, $invoice_id, '', $description );
                        // create xml and send invoice to Run My Accounts
                        $result = $invoice->create_xml_content( $data, $order_ids, true );

                        if( false != $result ) {

                            $created_invoices[] = $invoice_id;

                        }

                    }

                }

            }

        }

        unset( $invoice );

        // were invoices created, and we should send an email?
        if( 0 < count( $created_invoices ) && SENDLOGEMAIL ) {

            $headers = array('Content-Type: text/html; charset=UTF-8');
            $email_content = sprintf( esc_html_x('The following collective invoices were sent: %s', 'email', 'run-my-accounts-for-woocommerce'), implode(', ', $created_invoices ) );
            wp_mail( LOGEMAIL, esc_html_x( 'Collective invoices were sent', 'email', 'run-my-accounts-for-woocommerce' ), $email_content, $headers);

        }

        // get parameters for next invoice date
        $period  = $this->settings[ 'collective_invoice_period' ];
        $weekday = $this->settings[ 'collective_invoice_weekday' ];
        $dates   = self::get_next_invoice_date( $period, $weekday );

        // set the next invoice date
        if( !empty( $dates ) ) {

            $this->settings[ 'collective_invoice_next_date_ts' ] = strtotime(gmdate('Y-m-d', $dates[ 'next_date_ts' ] ) );
            update_option( 'wc_rma_settings_collective_invoice', $this->settings );

        }

        return $created_invoices;

    }

    /**
     * Create a daily cron event, if one does not already exist.
     *
     * @since 1.7.0
     */
    public function maybe_create_scheduled_event() {
        if ( ! wp_next_scheduled( 'run_my_accounts_collective_invoice' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS , 'daily', 'run_my_accounts_collective_invoice' );
        }
    }

}
