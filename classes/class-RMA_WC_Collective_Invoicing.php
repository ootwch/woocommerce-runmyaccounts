<?php

if ( ! defined('ABSPATH')) exit;

/**
 * Class for collective invoicing
 *
 * @since 1.7.0
 */
class RMA_WC_Collective_Invoicing {

    private const MAX_ORDERS_PER_GROUP = 100;
    private const MAX_ORDERS_PER_PAGE = 500;

    private $settings;

    public function __construct() {
        $this->maybe_create_scheduled_event();

        // read rma settings
        $this->settings = get_option( 'wc_rma_settings_collective_invoice' );

        add_action( 'wp_ajax_next_invoice_date', [ $this, 'ajax_next_invoice_date' ] );

        add_action( 'run_my_accounts_collective_invoice', array( $this, 'create_collective_invoice' ) );

        // Collective invoices should have the order_id in the name.
        add_filter( 'rma_invoice_part', array( $this, 'add_order_id_to_description' ), 10, 2 );
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
            case 'manually':
                // Wait forever (until 2130) to trigger automatically.
                $next_date_ts_utc = strtotime( '01/01/2130' );
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
     * @param array $customers List of customer id's for which orders are listed.
     * @return array
     *
     * @since 1.7.0
     */
    public function get_not_invoiced_orders( array $customers = array() ): array {
        $rows = $this->get_not_invoiced_order_rows( $customers );

        $cumulated_orders_by_customer_id = array();
        foreach ( $rows as $row ) {
            $user_id        = $row['user_id'];
            $tax_bucket     = $row['tax_included'] ? 'tax' : 'no_tax';
            $payment_method = $row['payment_method'];

            if ( ! isset( $cumulated_orders_by_customer_id[ $user_id ][ $tax_bucket ][ $payment_method ] ) ) {
                $cumulated_orders_by_customer_id[ $user_id ][ $tax_bucket ][ $payment_method ] = array();
            }
            $cumulated_orders_by_customer_id[ $user_id ][ $tax_bucket ][ $payment_method ][] = $row['order_id'];
        }

        return $cumulated_orders_by_customer_id;
    }

    /**
     * Build pass-1 dashboard grouping and pagination metadata.
     *
     * @param array  $customers Customer IDs filter.
     * @param int    $groups_per_page Group cap per page.
     * @param string $payment_method_filter Payment filter.
     * @param string $search_filter Search term for customer/group matching.
     * @return array
     */
    public function get_dashboard_group_plan( array $customers = array(), int $groups_per_page = 20, string $payment_method_filter = '', string $search_filter = '' ): array {
        $groups = $this->build_order_groups( $this->get_not_invoiced_order_rows( $customers ) );

        if ( '' !== $payment_method_filter ) {
            $groups = array_filter(
                $groups,
                function( array $group ) use ( $payment_method_filter ): bool {
                    $method = $group['payment_method'];
                    return ( $method === $payment_method_filter ) || ( empty( $method ) && 'no-payment-method' === $payment_method_filter );
                }
            );
        }

        if ( '' !== $search_filter ) {
            $groups = array_filter(
                $groups,
                static function( array $group ) use ( $search_filter ): bool {
                    $user_id = (int) ( $group['user_id'] ?? 0 );

                    if ( 0 === $user_id ) {
                        $search_string = 'guest';
                    } else {
                        $user_data      = get_userdata( $user_id );
                        $customernumber = (string) get_user_meta( $user_id, 'rma_customer', true );
                        $user_name      = false !== $user_data ? (string) $user_data->display_name : '';
                        $user_email     = false !== $user_data ? (string) $user_data->user_email : '';

                        $search_string = $customernumber . $user_name . (string) $user_id . $user_email;
                    }

                    return str_contains( strtoupper( $search_string ), strtoupper( $search_filter ) );
                }
            );
        }

        $groups = $this->assign_group_pages( array_values( $groups ), max( 1, $groups_per_page ), self::MAX_ORDERS_PER_PAGE );

        $affected_users = array();
        $total_orders   = 0;
        $total_pages    = 1;
        $group_lookup   = array();

        foreach ( $groups as $group ) {
            $affected_users[ $group['user_id'] ] = true;
            $total_orders += $group['order_count'];
            $total_pages   = max( $total_pages, $group['page_number'] );
            $group_lookup[ $group['invoice_id'] ] = $group;
        }

        return array(
            'groups'       => $groups,
            'group_lookup' => $group_lookup,
            'totals'       => array(
                'affected_users' => count( $affected_users ),
                'orders'         => $total_orders,
                'groups'         => count( $groups ),
                'pages'          => $total_pages,
            ),
        );
    }

    /**
     * Build full invoice details for selected groups only.
     *
     * @param array       $groups Group metadata from pass-1.
     * @param string|bool $description Optional description.
     * @return array
     */
    public function build_display_invoices_for_groups( array $groups, $description = false ): array {
        $display_invoices = array();
        $invoice          = new RMA_WC_API();
        $settings         = get_option( 'wc_rma_settings' );

        foreach ( $groups as $group ) {
            $order_ids = $group['order_ids'] ?? array();
            if ( empty( $order_ids ) ) {
                continue;
            }

            asort( $order_ids, SORT_NUMERIC );

            $order_details_products = array();
            $order_details          = array();
            $order_date_created     = array();
            $group_description      = $description;

            foreach ( $order_ids as $order_id ) {
                if ( false === $group_description ) {
                    $order = wc_get_order( $order_id );
                    if ( false !== $order && null !== $order->get_date_created() ) {
                        $order_date_created[] = $order->get_date_created()->date( 'U' );
                        RMA_WC_API::delete_order_cache( $order );
                    }
                }

                if ( empty( $order_details ) ) {
                    $order_details = $invoice->get_wc_order_details( $order_id );
                }
                $order_details_products = $invoice->get_order_details_products( $order_id, $order_details_products );
                $order_details_products = $invoice->get_order_details_shipping_costs( $order_id, $order_details_products );
            }

            if ( empty( $order_details ) ) {
                continue;
            }

            if ( false === $group_description && ! empty( $order_date_created ) ) {
                $period            = date_i18n( get_option( 'date_format' ), min( $order_date_created ) ) . ' - ' . date_i18n( get_option( 'date_format' ), max( $order_date_created ) );
                $group_description = str_replace( '[period]', $period, $settings['rma-collective-invoice-description'] ?? '' );
            }

            $first_order_id = (int) reset( $order_ids );
            $invoice_id     = $this->get_invoice_id_from_order_id( $first_order_id );
            $data           = $invoice->get_invoice_data( $order_details, $order_details_products, $invoice_id, '', (string) $group_description );
            if ( isset( $data['error'] ) ) {
                wp_die( esc_html( (string) ( $data['message'] ?? $data['error'] ) ) );
            }
            $data['invoice']['paymentmethod'] = (string) ( $group['payment_method'] ?? '' );
            $first_order    = wc_get_order( $first_order_id );

            $display_invoices[ $invoice_id ] = array(
                'data'        => $data,
                'user_id'     => false !== $first_order ? $first_order->get_customer_id() : 0,
                'order_ids'   => array_values( $order_ids ),
                'order_count' => count( $order_ids ),
                'page_number' => $group['page_number'] ?? 1,
            );

            if ( false !== $first_order ) {
                RMA_WC_API::delete_order_cache( $first_order );
            }
        }

        return $display_invoices;
    }

    /**
     * Get one invoice payload by invoice ID.
     *
     * @param string $invoice_id Invoice ID.
     * @param array  $customers Optional customer filter.
     * @return array|null
     */
    public function get_dashboard_invoice_by_id( string $invoice_id, array $customers = array() ): ?array {
        $plan = $this->get_dashboard_group_plan( $customers, 1 );
        if ( ! isset( $plan['group_lookup'][ $invoice_id ] ) ) {
            return null;
        }
        $display = $this->build_display_invoices_for_groups( array( $plan['group_lookup'][ $invoice_id ] ) );
        return $display[ $invoice_id ] ?? null;
    }

    /**
     * Show admin notice.
     *
     * @return void
     */
    public function admin_notice_too_many_invoices() {
        ?>
        <div class="notice is-dismissible notice-info">
            <p>More than 500 invoices ready. Only showing the first 500; use filters to show more invoices.</p>
        </div>
        <?php
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
    public function create_collective_invoice( bool $force = false, bool $display = false, $description = false, $customers = array() ): array {
        // reset array
        $order_date_created = array();
        $created_invoices   = array();
        $display_invoices   = array();

        // get the timestamp with the current date, but without time
        $current_date = strtotime(gmdate('Y-m-d', time() ) );

        // if we do not have to create collective invoices today
        if ( false === $force && $current_date !== $this->settings[ 'collective_invoice_next_date_ts' ] ) {
            // return the empty array
            return $created_invoices;
        }

        // get settings
        $settings         = get_option( 'wc_rma_settings' );

        // get all orders with no invoice
        $not_invoiced_orders = self::get_not_invoiced_orders( $customers );

        $invoice = new RMA_WC_API();

        $invoice_prof = 0;

        $invoice_counter = 0;

        if ( empty( $customers ) ) {
            $max_invoices = 500;
        } else {
            $max_invoices = 500;
        }

        // Only select the first n customers.
        $not_invoiced_orders = array_slice( $not_invoiced_orders, 0, $max_invoices, true );

        foreach ( $not_invoiced_orders as $tax_statuses ) {
            if ( $invoice_counter > $max_invoices ) {
                break;
            }
            foreach ( $tax_statuses as $payment_methods ) {
                if ( $invoice_counter > $max_invoices ) {
                    break;
                }

                foreach ( $payment_methods as $order_ids ) {

                    ++$invoice_counter;
                    if ( $invoice_counter > $max_invoices ) {
                        add_action( 'admin_table_notices', array( $this, 'admin_notice_too_many_invoices' ) );
                        break;
                    }

                    // sort the order ids in ascending order to output the items chronologically.
                    asort( $order_ids, SORT_NUMERIC );

                    // set first payment method
                    $first_order = true;
                    // reset variables
                    $order_details_products = array();
                    $order_details          = array();
                    $invoice_id             = '';

                    foreach ( $order_ids as $order_id ) {

                        // Only needed to build the description string.
                        if ( false === $description ) {
                            $order                = wc_get_order( $order_id );
                            $order_date_created[] = $order->get_date_created()->date( 'U' ); // get order date created as unix timestamp
                            RMA_WC_API::delete_order_cache( $order );
                            unset( $order );
                        }

                        if ( $first_order ) {

                            // remove flag for first payment method
                            $first_order = false;

                            // get the invoice header with $tax_status and $payment_method
                            $order_details = $invoice->get_wc_order_details( $order_id );

                            // create the invoice id based on the first order id
                            $invoice_id = RMA_INVOICE_PREFIX . str_pad( $order_id, max( intval( RMA_INVOICE_DIGITS ) - strlen( RMA_INVOICE_PREFIX ), 0 ), '0', STR_PAD_LEFT );

                        }

                        // add products to order
                        $order_details_products = $invoice->get_order_details_products( $order_id, $order_details_products );

                        // add shipping costs to order
                        $order_details_products = $invoice->get_order_details_shipping_costs( $order_id, $order_details_products );
                    }

                    // make sure we have an invoice header with values
                    if ( 0 < count( $order_details ) ) {

                        // create period between oldest and latest order
                        $period = date_i18n( get_option( 'date_format' ), min( $order_date_created ) ) . ' - ' . date_i18n( get_option( 'date_format' ), max( $order_date_created ) );
                        // create description
                        if ( false === $description ) {
                            // create period between oldest and latest order
                            $description = str_replace( '[period]', $period, $settings[ 'rma-collective-invoice-description' ] ?? '' );
                        }
                        // collect invoice data
                        $data = $invoice->get_invoice_data( $order_details, $order_details_products, $invoice_id, '', $description );
                        if ( isset( $data['error'] ) ) {
                            wp_die( esc_html( (string) ( $data['message'] ?? $data['error'] ) ) );
                        }

                        if ( ! $display ) {
                            // create xml and send invoice to Run My Accounts
                            $result = $invoice->create_xml_content( $data, $order_ids, true );

                            if ( false != $result ) {
                                $created_invoices[] = $invoice_id;
                            }
                        } else {
                            $first_order                     = wc_get_order( $order_ids[0] );
                            $display_invoices[ $invoice_id ] = array(
                                'data'      => $data,
                                'user_id'   => $first_order->get_customer_id(),
                                'order_ids' => $order_ids,
                            );
                        }
                    }

                    // Delete the order cache once the order is processed.
                    RMA_WC_API::delete_order_cache( $first_order );
                }
            }
        }

        unset( $invoice );

        if ( $display ) {
            return $display_invoices;
        }

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

    /**
     * Add order ID to description.
     */
    public function add_order_id_to_description( array $part, ?int $item_id ): array {

        $order_id            = wc_get_order_id_by_order_item_id( $item_id );
        $order               = wc_get_order( $order_id );
        $order_item          = $order->get_item( $item_id );
        $part['order_id']    = $order_id;
        $part['description'] = '#' . $order_id . ' ' . $order_item->get_name();
        return $part;
    }

    /**
     * Fetch completed orders without invoice as lightweight rows.
     *
     * @param array $customers Optional customer IDs.
     * @return array
     */
    private function get_not_invoiced_order_rows( array $customers = array() ): array {
        if ( $this->is_hpos_enabled() ) {
            return $this->get_not_invoiced_order_rows_hpos( $customers );
        }

        global $wpdb;

        $invoice_from_date = $this->get_invoice_from_date();
        $params            = array();

        $sql = "
            SELECT
                p.ID AS order_id,
                CAST(COALESCE(customer_user.meta_value, '0') AS UNSIGNED) AS user_id,
                COALESCE(payment_method.meta_value, '') AS payment_method,
                COALESCE(prices_include_tax.meta_value, 'no') AS prices_include_tax
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} customer_user
                ON customer_user.post_id = p.ID AND customer_user.meta_key = '_customer_user'
            LEFT JOIN {$wpdb->postmeta} payment_method
                ON payment_method.post_id = p.ID AND payment_method.meta_key = '_payment_method'
            LEFT JOIN {$wpdb->postmeta} prices_include_tax
                ON prices_include_tax.post_id = p.ID AND prices_include_tax.meta_key = '_prices_include_tax'
            LEFT JOIN {$wpdb->postmeta} rma_invoice
                ON rma_invoice.post_id = p.ID AND rma_invoice.meta_key = '_rma_invoice'
            WHERE p.post_type = 'shop_order'
              AND p.post_status = 'wc-completed'
              AND rma_invoice.post_id IS NULL
        ";

        if ( 0 < $invoice_from_date ) {
            // Approximate completion date for CPT orders via modified date.
            $sql      .= ' AND p.post_modified_gmt >= %s';
            $params[] = gmdate( 'Y-m-d H:i:s', $invoice_from_date );
        }

        if ( ! empty( $customers ) ) {
            $customer_ids = array_map( 'intval', $customers );
            $placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
            $sql         .= " AND CAST(COALESCE(customer_user.meta_value, '0') AS UNSIGNED) IN ({$placeholders})";
            $params       = array_merge( $params, $customer_ids );
        }

        $sql .= ' ORDER BY p.ID ASC';

        if ( ! empty( $params ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare( $sql, $params );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_map(
            static function( array $row ): array {
                $tax_value = strtolower( (string) $row['prices_include_tax'] );
                return array(
                    'order_id'       => (int) $row['order_id'],
                    'user_id'        => (int) $row['user_id'],
                    'payment_method' => (string) $row['payment_method'],
                    'tax_included'   => in_array( $tax_value, array( 'yes', '1', 'true' ), true ),
                );
            },
            $rows
        );
    }

    /**
     * Fetch completed orders without invoice for HPOS stores.
     *
     * @param array $customers Optional customer IDs.
     * @return array
     */
    private function get_not_invoiced_order_rows_hpos( array $customers = array() ): array {
        global $wpdb;

        $orders_table      = $wpdb->prefix . 'wc_orders';
        $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
        $invoice_from_date = $this->get_invoice_from_date();
        $params            = array();

        $sql = "
            SELECT
                o.id AS order_id,
                CAST(COALESCE(o.customer_id, 0) AS UNSIGNED) AS user_id,
                COALESCE(o.payment_method, '') AS payment_method,
                COALESCE(prices_include_tax.meta_value, '0') AS prices_include_tax
            FROM {$orders_table} o
            LEFT JOIN {$orders_meta_table} rma_invoice
                ON rma_invoice.order_id = o.id AND rma_invoice.meta_key = '_rma_invoice'
            LEFT JOIN {$orders_meta_table} prices_include_tax
                ON prices_include_tax.order_id = o.id AND prices_include_tax.meta_key = '_prices_include_tax'
            WHERE o.type = 'shop_order'
              AND o.status = 'wc-completed'
              AND rma_invoice.order_id IS NULL
        ";

        if ( 0 < $invoice_from_date ) {
            $sql      .= ' AND o.date_completed_gmt >= %s';
            $params[] = gmdate( 'Y-m-d H:i:s', $invoice_from_date );
        }

        if ( ! empty( $customers ) ) {
            $customer_ids = array_map( 'intval', $customers );
            $placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
            $sql         .= " AND o.customer_id IN ({$placeholders})";
            $params       = array_merge( $params, $customer_ids );
        }

        $sql .= ' ORDER BY o.id ASC';

        if ( ! empty( $params ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare( $sql, $params );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_map(
            static function( array $row ): array {
                $tax_value = strtolower( (string) $row['prices_include_tax'] );
                return array(
                    'order_id'       => (int) $row['order_id'],
                    'user_id'        => (int) $row['user_id'],
                    'payment_method' => (string) $row['payment_method'],
                    'tax_included'   => in_array( $tax_value, array( 'yes', '1', 'true' ), true ),
                );
            },
            $rows
        );
    }

    /**
     * Determine whether WooCommerce HPOS is active.
     *
     * @return bool
     */
    private function is_hpos_enabled(): bool {
        if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            return false;
        }
        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Build logical groups and split by max group size.
     *
     * @param array $order_rows Lightweight rows.
     * @return array
     */
    private function build_order_groups( array $order_rows ): array {
        $logical_groups = array();
        foreach ( $order_rows as $row ) {
            $tax_bucket = $row['tax_included'] ? 'tax' : 'no_tax';
            $key        = $row['user_id'] . '|' . $tax_bucket . '|' . $row['payment_method'];
            if ( ! isset( $logical_groups[ $key ] ) ) {
                $logical_groups[ $key ] = array(
                    'user_id'        => (int) $row['user_id'],
                    'tax_status'     => $tax_bucket,
                    'payment_method' => (string) $row['payment_method'],
                    'order_ids'      => array(),
                );
            }
            $logical_groups[ $key ]['order_ids'][] = (int) $row['order_id'];
        }

        $groups = array();
        foreach ( $logical_groups as $logical ) {
            $chunks = array_chunk( $logical['order_ids'], self::MAX_ORDERS_PER_GROUP );
            foreach ( $chunks as $chunk ) {
                $first_order_id = (int) $chunk[0];
                $groups[] = array(
                    'invoice_id'     => $this->get_invoice_id_from_order_id( $first_order_id ),
                    'user_id'        => $logical['user_id'],
                    'tax_status'     => $logical['tax_status'],
                    'payment_method' => $logical['payment_method'],
                    'order_ids'      => $chunk,
                    'order_count'    => count( $chunk ),
                    'page_number'    => 1,
                );
            }
        }

        return $groups;
    }

    /**
     * Assign group page numbers based on both limits.
     *
     * @param array $groups Group list.
     * @param int   $max_groups_per_page Max groups per page.
     * @param int   $max_orders_per_page Max orders per page.
     * @return array
     */
    private function assign_group_pages( array $groups, int $max_groups_per_page, int $max_orders_per_page ): array {
        $page_number    = 1;
        $groups_on_page = 0;
        $orders_on_page = 0;

        foreach ( $groups as $idx => $group ) {
            $group_orders = (int) $group['order_count'];
            $break_group  = $groups_on_page >= $max_groups_per_page;
            $break_orders = ( $orders_on_page + $group_orders ) > $max_orders_per_page;

            if ( ( $break_group || $break_orders ) && $groups_on_page > 0 ) {
                ++$page_number;
                $groups_on_page = 0;
                $orders_on_page = 0;
            }

            $groups[ $idx ]['page_number'] = $page_number;
            ++$groups_on_page;
            $orders_on_page += $group_orders;
        }

        return $groups;
    }

    /**
     * Get lower bound for eligible order dates.
     *
     * @return int
     */
    private function get_invoice_from_date(): int {
        switch ( $this->settings['collective_invoice_span'] ?? '' ) {
            case 'per_week':
                return strtotime( '-1 week' );
            case 'per_month':
                return strtotime( '-1 month' );
            default:
                return 0;
        }
    }

    /**
     * Build invoice id from first order id.
     *
     * @param int $order_id Order ID.
     * @return string
     */
    private function get_invoice_id_from_order_id( int $order_id ): string {
        $settings = get_option( 'wc_rma_settings' );
        $prefix   = defined( 'RMA_INVOICE_PREFIX' ) ? (string) RMA_INVOICE_PREFIX : (string) ( $settings['rma-invoice-prefix'] ?? '' );
        $digits   = defined( 'RMA_INVOICE_DIGITS' ) ? (int) RMA_INVOICE_DIGITS : (int) ( $settings['rma-digits'] ?? 0 );

        return $prefix . str_pad( (string) $order_id, max( $digits - strlen( $prefix ), 0 ), '0', STR_PAD_LEFT );
    }
}
