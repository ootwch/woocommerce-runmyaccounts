<?php
/**
 * class-settings.php
 *
 * @author      Sandro Lucifora
 * @copyright   (c) 2020, Openstream Internet Solutions
 * @link        https://www.openstream.ch/
 * @package     RunmyAccountsforWooCommerce
 * @since       1.3
 */

if ( !defined('ABSPATH' ) ) exit;

if ( !class_exists('RMA_WC_Settings_Page') ) {

    class RMA_WC_Settings_Page {

        private $admin_url;
        private $option_group_general;
        private $option_group_accounting;
        private $option_group_collective_invoice;
        private $options_general;
        private $options_accounting;
        private $options_collective_invoice;
        private $option_page_general;
        private $option_page_accounting;
        private $option_page_collective_invoice;
        private $option_page_log;
        private $rma_log_count;

        public function __construct() {

            $this->admin_url                       = 'admin.php?page=rma-wc';
            $this->option_group_general            = 'wc_rma_settings';
            $this->option_group_accounting         = 'wc_rma_settings_accounting';
            $this->option_group_collective_invoice = 'wc_rma_settings_collective_invoice';
            $this->option_page_general             = 'settings-general';
            $this->option_page_accounting          = 'settings-accounting';
            $this->option_page_collective_invoice  = 'settings-collective-invoice';
            $this->option_page_log                 = 'settings-log';

            $this->options_general            = get_option( $this->option_group_general );
            $this->options_accounting         = get_option( $this->option_group_accounting );
            $this->options_collective_invoice = get_option( $this->option_group_collective_invoice );

            add_action( 'wp_ajax_rma_log_table', array( $this, 'ajax_handle_database_log_table') );

            add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );

            add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        }

        public function admin_enqueue( $hook ) {

            if( 'woocommerce_page_rma-wc' != $hook )
                return;

            // enqueue script and style for autocomplete on admin page
            wp_enqueue_script( 'wc-select2', plugins_url( '../assets/js/select2.min.js', __FILE__ ), array('jquery'), '4.0.13', 'true' );
            wp_register_style( 'wc-select2', plugins_url( '../assets/css/select2.min.css', __FILE__ ), false, '4.0.13' );
            wp_enqueue_style( 'wc-select2' );

            wp_enqueue_script( 'rma-admin-script', plugins_url( '../assets/js/admin.js', __FILE__ ), array('jquery'), get_option( 'wc_rma_version' ), 'true' );

        }

        public function add_plugin_page(){
            // This page will be under "WooCommerce"
            add_submenu_page('woocommerce', // $parent_slug
                             'Run my Accounts - Settings', // $page_title
                             __('Run my Accounts', 'rma-wc'), // $menu_title
                             'manage_options', // $capability
                             'rma-wc', // $menu_slug
                             array($this, 'create_admin_page') // $function
            );

            add_action( 'admin_init', array( $this, 'options_init') );

        }

        public function create_admin_page() {

            $active_page = sanitize_text_field( ( isset( $_GET[ 'tab' ] ) ? $_GET[ 'tab' ] : 'general' ) ); // set default tab ?>

            <div class="wrap">
                <h1><?php _e('Run my Accounts - Settings', 'rma-wc'); ?></h1>
                <?php settings_errors(); ?>
                <h2 class="nav-tab-wrapper">
                    <a href="<?php echo admin_url( $this->admin_url ); ?>" class="nav-tab<?php echo ( 'general' == $active_page ? ' nav-tab-active' : '' ); ?>"><?php esc_html_e('General', 'rma-wc'); ?></a>
                    <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'accounting' ), admin_url( $this->admin_url ) ) ); ?>" class="nav-tab<?php echo ( 'accounting' == $active_page ? ' nav-tab-active' : '' ); ?>"><?php esc_html_e('Accounting', 'rma-wc'); ?></a>
                    <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'collective' ), admin_url( $this->admin_url ) ) ); ?>" class="nav-tab<?php echo ( 'collective' == $active_page ? ' nav-tab-active' : '' ); ?>"><?php esc_html_e('Collective Invoice', 'rma-wc'); ?></a>
                    <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'log' ), admin_url( $this->admin_url ) ) ); ?>" class="nav-tab<?php echo ( 'log' == $active_page ? ' nav-tab-active' : '' ); ?>"><?php esc_html_e('Log', 'rma-wc'); ?></a>
                </h2>

                <form method="post" action="options.php"><?php //   settings_fields( $this->option_group_general );
                    switch ( $active_page ) {
                        case 'accounting':
                            settings_fields( $this->option_group_accounting );
                            do_settings_sections( $this->option_page_accounting );
                            submit_button();
                            break;
                        case 'log':
                            do_settings_sections( $this->option_page_log );
                            $this->flush_log_button();
                            break;
                        case 'collective':
                            settings_fields( $this->option_group_collective_invoice );
                            do_settings_sections( $this->option_page_collective_invoice );
                            submit_button();
                            break;

                        default:
                            settings_fields( $this->option_group_general );
                            do_settings_sections( $this->option_page_general );
                            submit_button();
                            break;
                    } ?>
                </form>
            </div> <?php

        }

        /**
         * Initialize Options on Settings Page
         */
        public function options_init() {
            register_setting(
                $this->option_group_general, // Option group
                $this->option_group_general, // Option name
                array( $this, 'sanitize' ) // Sanitize
            );

            $this->options_general_api();

            $this->options_general_customer();

            $this->options_general_billing();

            $this->options_general_payment();

            $this->options_general_product();

            $this->options_general_shipping();

            $this->options_general_log();

            $this->options_general_misc();

            register_setting(
                $this->option_group_accounting, // Option group
                $this->option_group_accounting, // Option name
                array( $this, 'sanitize' ) // Sanitize
            );

            $this->options_accounting_gateways();

            $this->options_payment_gateways();

            register_setting(
                $this->option_group_collective_invoice, // Option group
                $this->option_group_collective_invoice, // Option name
                array( $this, 'sanitize' ) // Sanitize
            );

            $this->options_collective_invoice();

            $this->log();

        }

        /**
         * Page General, Section API
         */
        public function options_general_api() {
            $section = 'general_settings_api';

            add_settings_section(
                $section, // ID
                esc_html__('API', 'rma-wc'),
                '', // Callback
                $this->option_page_general // Page
            );

            $id = 'rma-active';
            add_settings_field(
                $id,
                esc_html__('Activate Function', 'rma-wc'),
                array( $this, 'option_input_checkbox_cb'), // general callback for checkbox
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('Do not activate the plugin before you have set up all data.', 'rma-wc' )
                )
            );

            $id = 'rma-mode';
            add_settings_field(
                $id,
                esc_html__('Operation Mode', 'rma-wc'),
                array( $this, 'rma_mode_cb'), // individual callback
                $this->option_page_general,
                $section,
                array( 'option_group' => $this->option_group_general,
                       'id'           => $id
                )
            );

            $id = 'rma-live-client';
            if ( defined( 'RMA_MANDANT_LIVE' ) ) {
                add_settings_field(
                    $id,
                        esc_html__( 'Production Client', 'rma-wc' ),
                        array( $this, 'option_secret_cb' ), // general call back for input text
                    $this->option_page_general,
                    $section,
                    array(
                        'option_group' => $this->option_group_general,
                        'id'           => $id,
                        'value'        => RMA_MANDANT_LIVE,
                        'description'  => esc_attr__( 'Configured in WP-CONFIG', 'rma-wc' ),
                    )
                );
            } else {
                add_settings_field(
                    $id,
                    esc_html__( 'Production Client', 'rma-wc' ),
                    array( $this, 'option_input_text_cb' ), // general call back for input text
                    $this->option_page_general,
                    $section,
                    array(
                        'option_group' => $this->option_group_general,
                        'id'           => $id,
                        'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    )
                );
            }

            $id = 'rma-live-apikey';
            if ( defined( 'RMA_APIKEY_LIVE' ) ) {
                add_settings_field(
                    $id,
                        esc_html__( 'Production API key', 'rma-wc' ),
                        array( $this, 'option_secret_cb' ),
                    $this->option_page_general,
                    $section,
                    array(
                        'option_group' => $this->option_group_general,
                        'id'           => $id,
                        'value'        => RMA_APIKEY_LIVE,
                        'description'  => esc_attr__( 'Configured in WP-CONFIG', 'rma-wc' ),
                )
            );
            } else {
                add_settings_field(
                    $id,
                    esc_html__( 'Production API key', 'rma-wc' ),
                    array( $this, 'option_input_text_cb' ),
                    $this->option_page_general,
                    $section,
                    array(
                        'option_group' => $this->option_group_general,
                        'id'           => $id,
                        'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    )
                );
            }

            $id = 'rma-test-client';
            // Defined in WP-CONFIG?
            if ( defined( 'RMA_MANDANT_TEST' ) ) {
                add_settings_field(
                    $id,
                        esc_html__( 'Sandbox Client', 'rma-wc' ),
                        array( $this, 'option_secret_cb' ), // general call back for input text
                    $this->option_page_general,
                    $section,
                    array(
                        'option_group' => $this->option_group_general,
                        'id'           => $id,
                        'value'        => RMA_MANDANT_TEST,
                        'description'  => esc_attr__( 'Configured in WP-CONFIG', 'rma-wc' ),
                )
            );
            } else {
                add_settings_field(
                    $id,
                    esc_html__( 'Sandbox Client', 'rma-wc' ),
                    array( $this, 'option_input_text_cb' ), // general call back for input text
                    $this->option_page_general,
                    $section,
                    array(
                        'option_group' => $this->option_group_general,
                        'id'           => $id,
                        'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    )
                );
            }

            $id = 'rma-test-apikey';
            if ( defined( 'RMA_APIKEY_TEST' ) ) {
                add_settings_field(
                    $id,
                        esc_html__( 'Sandbox API key', 'rma-wc' ),
                        array( $this, 'option_secret_cb' ),
                    $this->option_page_general,
                    $section,
                    array(
                        'option_group' => $this->option_group_general,
                        'id'           => $id,
                        'value'        => RMA_APIKEY_TEST,
                        'description'  => esc_attr__( 'Configured in WP-CONFIG', 'rma-wc' ),
                        )
                    );
            } else {
                add_settings_field(
                    $id,
                    esc_html__( 'Sandbox API key', 'rma-wc' ),
                    array( $this, 'option_input_text_cb' ),
                    $this->option_page_general,
                    $section,
                    array(
                        'option_group' => $this->option_group_general,
                        'id'           => $id,
                        'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    )
                );
            }
        do_action( 'rma-settings-' . $section, $this );
        }


        /**
         * Page General, Section Billing
         */
        public function options_general_billing() {

            $section = 'general_settings_billing';

            add_settings_section(
                $section, // ID
                esc_html__('Billing', 'rma-wc'),
                '', // Callback
                $this->option_page_general // Page
            );

            $id = 'rma-invoice-trigger';
            add_settings_field(
                $id,
                esc_html__('Trigger', 'rma-wc'),
                array( $this, 'option_select_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group'    => $this->option_group_general,
                    'id'              => $id,
                    'options'         => $this->options_general,
                    'select_options'  => array(
                        'immediately' => esc_html__('Immediately after ordering','rma-wc'),
                        'completed'   => esc_html__('On order status completed','rma-wc'),
                        'collective'  => esc_html__('Collective invoice','rma-wc'),
                    ),
                    'class'        => 'invoice-trigger',
                    'description'  => esc_html__('When should customers and invoices be created in Run My Accounts?', 'rma-wc' ),

                )
            );

            $id = 'rma-payment-period';
            add_settings_field(
                $id,
                esc_html__('Payment Period in days', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('Please set the general payment period. You can set a individual value, for a customer, in the user profile.', 'rma-wc' )
                )
            );

            $id = 'rma-invoice-prefix';
            add_settings_field(
                $id,
                esc_html__('Invoice Prefix', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => 'rma-invoice-prefix',
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('Prefix followed by order number will be the invoice number in Run my Accounts.', 'rma-wc' )
                )
            );

            $id = 'rma-digits';
            add_settings_field(
                $id,
                esc_html__('Number of digits', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('Set the maximum number of digits for the invoice number (including prefix).', 'rma-wc' )
                )
            );

            $id = 'rma-invoice-description';
            add_settings_field(
                $id,
                esc_html__('Invoice Description', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => $this->options_general[$id] ?? '',
                    'description'  => esc_html__('Description of the invoice in Run My Accounts. Possible variable: [orderdate]', 'rma-wc' )
                )
            );

            $id = 'rma-collective-invoice-description';
            add_settings_field(
                $id,
                esc_html__('Collective Invoice Description', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => $this->options_general[$id] ?? '',
                    'description'  => esc_html__('Description of the collective invoice in Run My Accounts. Possible variable: [period]', 'rma-wc' )
                )
            );
            do_action( 'rma-settings-' . $section, $this );
        }

        /**
         * Page General, Section Payment
         */
        public function options_general_payment() {

            $section = 'general_settings_payment';

            add_settings_section(
                $section, // ID
                esc_html__('Payment', 'rma-wc'),
                '', // Callback
                $this->option_page_general // Page
            );

            $id = 'rma-payment-trigger';
            add_settings_field(
                $id,
                esc_html__('Trigger', 'rma-wc'),
                array( $this, 'option_select_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group'    => $this->option_group_general,
                    'id'              => $id,
                    'options'         => $this->options_general,
                    'select_options'  => array(
                        ''            => esc_html__('Booking manually','rma-wc'),
                        'immediately' => esc_html__('Immediately after ordering','rma-wc'),
                        'completed'   => esc_html__('On order status completed','rma-wc'),
                    ),
                    'class'        => 'payment-trigger',
                    'description'  => esc_html__('When should the payment be booked in Run My Accounts', 'rma-wc' ),

                )
            );

            $id = 'rma-payment-trigger-exclude';
            add_settings_field(
                $id,
                esc_html__('Excluded Payment Options', 'rma-wc'),
                array( $this, 'option_select_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'options'      => $this->options_general,
                    'select_options' => array(
                        'no'         => esc_html__('no','rma-wc'),
                        'yes'        => esc_html__('yes','rma-wc'),
                    ),
                    'class'        => 'payment-trigger-exclude',
                    'description'  => esc_html__('Would you like to exclude payment options from the payment booking?', 'rma-wc' ),
                )
            );

            $id = 'rma-payment-trigger-exclude-values';
            add_settings_field(
                $id,
                '',
                array( $this, 'option_input_multiple_payment_gateway_checkbox_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => $this->options_general[$id] ?? '',
                    'description'  => esc_html__('Please select the payment options you want to exclude?', 'rma-wc' ),
                )
            );
            do_action( 'rma-settings-' . $section, $this );
        }

        /**
         * Page General, Section Customer
         */
        public function options_general_customer() {

            $section = 'general_settings_customer';

            add_settings_section(
                $section, // ID
                esc_html__('Customer', 'rma-wc'),
                '', // Callback
                $this->option_page_general // Page
            );

            $id = 'rma-create-customer';
            add_settings_field(
                $id,
                esc_html__('Create New Customer', 'rma-wc'),
                array( $this, 'option_input_checkbox_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('Tick this if you want to create a customer as soon as a new user is created in WooCommerce (recommended if customer can register by itself).', 'rma-wc' )
                )
            );

            $id = 'rma-customer-prefix';
            add_settings_field(
                $id,
                esc_html__('Customer Number Prefix', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('Prefix followed by user id will be  the customer number in Run my Accounts.', 'rma-wc' )
                )
            );

            $id = 'rma-create-guest-customer';
            add_settings_field(
                $id,
                esc_html__('Create Account for Guests', 'rma-wc'),
                array( $this, 'option_input_checkbox_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('Tick this if you want to create unique customer account in Run my Accounts for guest orders. Otherwise the guest orders will be booked on a pre-defined catch-all customer account.', 'rma-wc' )
                )
            );

            $id = 'rma-guest-customer-prefix';
            add_settings_field(
                $id,
                esc_html__('Guest Customer Number Prefix', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('Prefix followed by order id will be the customer number in Run my Accounts.', 'rma-wc' )
                )
            );

            $id = 'rma-guest-catch-all';
            add_settings_field(
                $id,
                esc_html__('Catch-All Account', 'rma-wc'),
                array( $this, 'option_input_text_cb'), // individual callback
                $this->option_page_general,
                $section,
                array( 'option_group' => $this->option_group_general,
                       'id'           => $id
                )
            );
            do_action( 'rma-settings-' . $section, $this );
        }

        /**
         * Page General, Section Product
         */
        public function options_general_product() {

            $section = 'general_settings_product';

            add_settings_section(
                $section, // ID
                esc_html__('Product', 'rma-wc'),
                '', // Callback
                $this->option_page_general // Page
            );

            $id = 'rma-product-fallback_id';
            add_settings_field(
                $id,
                esc_html__('Fallback product sku', 'rma-wc'),
                array( $this, 'rma_parts_cb'), // individual callback
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('This is a fallback sku in Run My Accounts which will be used to create an invoice if the WooCommerce sku of a product is not available in Run My Accounts. Leave it empty if you do not want to use it. In this case the invoice cannot be created if the sku is not available in Run My Accounts.', 'rma-wc' )
                )
            );


            $id = 'rma-product-rnb-rental-article';
            add_settings_field(
                $id,
                esc_html__('RNB rental Booking article', 'rma-wc'),
                array( $this, 'rma_parts_cb'), // individual callback
                'settings-general',
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('If you use Redq Rental and Booking, this is the article that is used for the rental booking.', 'rma-wc' )
                )
            );


            $id = 'rma-product-rnb-cancelation-article';
            add_settings_field(
                $id,
                esc_html__('RNB rental cancelation article', 'rma-wc'),
                array( $this, 'rma_parts_cb'), // individual callback
                'settings-general',
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('If you use Redq Rental and Booking, this is the article that is used for the rental cancelation booking.', 'rma-wc' )
                )
            );
            do_action( 'rma-settings-' . $section, $this );
        }

        /**
         * Page General, Section Shipping
         */
        public function options_general_shipping() {

            $section = 'general_settings_shipping';

            add_settings_section(
                $section, // ID
                esc_html__('Shipping', 'rma-wc'),
                '', // Callback
                $this->option_page_general // Page
            );

            $id = 'rma-shipping-id';
            add_settings_field(
                $id,
                esc_html__('SKU', 'rma-wc'),
                array( $this, 'rma_parts_cb'), // individual callback
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('To book the shipping costs, you have to select a dedicated product in Run my Accounts. The shipping costs will be booked on this product id.', 'rma-wc' )
                )
            );

            $id = 'rma-shipping-text';
            add_settings_field(
                $id,
                esc_html__('Description', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('Optionally, the text on the invoice for shipping. Usually it is the text from the shipping method chosen by the customer.', 'rma-wc' )
                )
            );

            do_action( 'rma-settings-' . $section, $this );
        }

        /**
         * Page General, Section Misc
         */
        public function options_general_log() {

            $section = 'general_settings_log';

            add_settings_section(
                $section, // ID
                esc_html__('Error Log', 'rma-wc'),
                '', // Callback
                $this->option_page_general // Page
            );

            $id = 'rma-loglevel';
            add_settings_field(
                $id,
                esc_html__('Log Level', 'rma-wc'),
                array( $this, 'option_select_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group'   => $this->option_group_general,
                    'id'             => $id,
                    'options'        => $this->options_general,
                    'select_options' => array(
                        'error'      => esc_html__('error','rma-wc'),
                        'complete'   => esc_html__('complete','rma-wc'),
                    )
                )
            );

            $id = 'rma-log-send-email';
            add_settings_field(
                $id,
                esc_html__('Send email', 'rma-wc'),
                array( $this, 'option_select_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group'   => $this->option_group_general,
                    'id'             => $id,
                    'options'        => $this->options_general,
                    'select_options' => array(
                        'no'         => esc_html__('no','rma-wc'),
                        'yes'        => esc_html__('yes','rma-wc'),
                    ),
                    'description'  => esc_html__('Receive emails on errors and general notifications.', 'rma-wc' )

                )
            );

            $id = 'rma-log-email';
            add_settings_field(
                $id,
                esc_html__('email', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : get_option( 'admin_email' ),
                    'description'  => esc_html__( 'Get an email to this recipient. Administrators email address is used by default.', 'rma-wc' )
                )
            );

            do_action( 'rma-settings-' . $section, $this );
        }

        /**
         * Page General, Section Misc
         */
        public function options_general_misc() {

            $section = 'general_settings_misc';

            add_settings_section(
                $section, // ID
                esc_html__('Misc', 'rma-wc'),
                '', // Callback
                $this->option_page_general // Page
            );

            $id = 'rma-delete-settings';
            add_settings_field(
                $id,
                esc_html__('Delete Settings', 'rma-wc'),
                array( $this, 'option_select_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group'   => $this->option_group_general,
                    'id'             => $id,
                    'options'        => $this->options_general,
                    'select_options' => array(
                        'no'         => esc_html__('no','rma-wc'),
                        'yes'        => esc_html__('yes','rma-wc'),
                    ),
                    'description'  => esc_html__('Remove all plugin data when using the "Delete" link on the plugins screen', 'rma-wc' ),

                )
            );
            do_action( 'rma-settings-' . $section, $this );
        }

        public function options_accounting_gateways() {

            $section = 'accounting_settings_payment';

            add_settings_section(
                $section, // ID
                esc_html__('Receivables Account', 'rma-wc'), // Title
                array( $this, 'section_info_accounting' ), // Callback
                $this->option_page_accounting // Page
            );
            
            // add  settings fields for all payment gateways
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();

            foreach ( $available_gateways as $gateway_key => $values ) {

                add_settings_field(
                    $gateway_key,
                    $values->title,
                    array( $this, 'option_input_text_cb'),
                    $this->option_page_accounting,
                    $section,
                    array(
                        'option_group' => $this->option_group_accounting,
                        'id'           => $gateway_key,
                        'value'        => isset( $this->options_accounting[ $gateway_key ] ) ? $this->options_accounting[ $gateway_key ] : ''
                    )
                );

            }
            do_action( 'rma-settings-' . $section, $this );
        }

        public function options_payment_gateways() {

            $section = 'accounting_settings_payment_account';

            add_settings_section(
                $section, // ID
                esc_html__('Payment Account', 'rma-wc'), // Title
                array( $this, 'section_info_payment' ), // Callback
                $this->option_page_accounting // Page
            );

            // add  settings fields for all payment gateways
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();

            foreach ( $available_gateways as $gateway_key => $values ) {

                $field_id = $gateway_key . '_payment_account';

                add_settings_field(
                    $field_id,
                    $values->title,
                    array( $this, 'option_input_text_cb'),
                    $this->option_page_accounting,
                    $section,
                    array(
                        'option_group' => $this->option_group_accounting,
                        'id'           => $field_id,
                        'value'        => isset( $this->options_accounting[ $field_id ] ) ? $this->options_accounting[ $field_id ] : '',
                        'placeholder'  => '1020'
                    )
                );

            }
            do_action( 'rma-settings-' . $section, $this );
        }

        public function options_collective_invoice() {

            $section = 'collective_invoice_settings';
            add_settings_section(
                $section, // ID
                esc_html__( 'Collective Invoice', 'rma-wc'), // Title
                array( $this, 'section_info_collective_invoice' ), // Callback
                $this->option_page_collective_invoice // Page
            );

            if( empty( $this->options_collective_invoice[ 'collective_invoice_next_date_ts' ] ) ) {
                $text = __('The next invoice date cannot be calculated. Please set all options first.');
            }
            else {
                $text = date_i18n( get_option('date_format') , $this->options_collective_invoice[ 'collective_invoice_next_date_ts' ] );
            }
            $id = 'collective_invoice_next_text';
            add_settings_field(
                $id,
                esc_html__('Next Invoice Date', 'rma-wc'),
                array( $this, 'plain_text_cb'),
                $this->option_page_collective_invoice,
                $section,
                array(
                    'text'  => $text,
                    'class' => 'next-invoice-date'
                )
            );

            $id = 'collective_invoice_period';
            add_settings_field(
                $id,
                esc_html__('Invoice Period', 'rma-wc'),
                array( $this, 'option_select_cb'),
                $this->option_page_collective_invoice,
                $section,
                array(
                    'option_group' => $this->option_group_collective_invoice,
                    'id'           => $id,
                    'options'      => $this->options_collective_invoice,
                    'select_options'  => array(
                        'week'        => esc_html__( 'Once a week','rma-wc'),
                        'second_week' => esc_html__( 'Every second week','rma-wc'),
                        'month'       => esc_html__( 'Every month (first weekday of the month)','rma-wc'),
                        'manually'    => esc_html__( 'Trigger invoicing manually', 'rma-wc' ),
                    ),
                    'description'  => esc_html__('For what period of time should collective invoices be created?', 'rma-wc' ),
                    'class'        => 'collective-invoice__period'
                )
            );

            $id = 'collective_invoice_weekday';
            add_settings_field(
                $id,
                esc_html__('Weekday', 'rma-wc'),
                array( $this, 'option_input_multiple_radio_cb'),
                $this->option_page_collective_invoice,
                $section,
                array(
                    'option_group' => $this->option_group_collective_invoice,
                    'id'           => $id,
                    'value'        => $this->options_collective_invoice[$id] ?? '',
                    'description'  => esc_html__('Please select the days of the week on which a collective invoice should be created.', 'rma-wc' ),
                    'values'       => array(
                            'monday'    => esc_html__( 'Monday', 'rma-wc' ),
                            'tuesday'   => esc_html__( 'Tuesday', 'rma-wc' ),
                            'wednesday' => esc_html__( 'Wednesday', 'rma-wc' ),
                            'thursday'  => esc_html__( 'Thursday', 'rma-wc' ),
                            'friday'    => esc_html__( 'Friday', 'rma-wc' ),
                            'saturday'  => esc_html__( 'Saturday', 'rma-wc' ),
                            'sunday'    => esc_html__( 'Sunday', 'rma-wc' ),
                    ),
                    'line_break'  => false,
                    'class'       => 'collective-invoice__weekday'
                )
            );

            $id = 'collective_invoice_span';
            add_settings_field(
                $id,
                esc_html__('Invoice Span', 'rma-wc'),
                array( $this, 'option_select_cb'),
                $this->option_page_collective_invoice,
                $section,
                array(
                    'option_group' => $this->option_group_collective_invoice,
                    'id'           => $id,
                    'options'      => $this->options_collective_invoice,
                    'select_options' => array(
                        'all'        => esc_html__( 'All unbilled invoices','rma-wc'),
                        'per_week'   => esc_html__( 'Day of creation and a week before','rma-wc'),
                        'per_month'  => esc_html__( 'Day of creation and a month before','rma-wc'),
                    ),
                    'description'  => esc_html__('For what period of time should collective invoices be created?', 'rma-wc' ),

                )
            );
            do_action( 'rma-settings-' . $section, $this );
        }

        public function section_info_accounting() {
            esc_html_e('You can specify a dedicated receivable account for each active payment gateway.', 'rma-wc');
        }

        public function section_info_collective_invoice() {
            esc_html_e('Set up the handling of the collective invoices', 'rma-wc');
        }

        public function section_info_payment() {
            esc_html_e('You can specify a dedicated payment account for each active payment gateway.', 'rma-wc');
        }

        /**
         * Page Error Log, Section Log
         */
        public function log() {

            $section = 'log';

            add_settings_section(
                $section, // ID
                esc_html__('Error Log', 'rma-wc'),
                array( $this, 'section_info_log' ), // Callback
                $this->option_page_log // Page
            );

            $id = 'rma-error-log';
            add_settings_field(
                $id,
                '',
                array( $this, 'output_log'), // general callback for checkbox
                $this->option_page_log,
                $section
            );

        }

        public function section_info_log() {
            esc_html_e('This page shows you the error logs.', 'rma-wc');
        }

        /**
         * General Input Field Checkbox
         *
         * @param array $args
         */
        public function option_input_checkbox_cb( $args ){

            $option_group = ( isset( $args['option_group'] ) ) ? $args['option_group'] : '';
            $id           = ( isset( $args['id'] ) ) ? $args['id'] : '';
            $checked      = ( isset( $args['value'] ) && !empty( $args['value'] ) ) ? 'checked' : '';
            $description  = ( isset( $args['description'] ) ) ? $args['description'] : '';

            printf(
                '<input type="checkbox" id="%1$s" name="%3$s[%1$s]" value="1" %2$s />',
                $id, $checked, $option_group
            );

            if ( !empty( $description) )
                echo '<p class="description">' . $description . '</p>';

        }

        /**
         * General Input with Multiple Checkboxes
         *
         * @param array $args
         *
         * @since 1.7.0
         */
        public function option_input_multiple_checkbox_cb( $args ){

            $legend       = ( isset( $args['legend'] ) ) ? $args['legend'] : '';
            $option_group = ( isset( $args['option_group'] ) ) ? $args['option_group'] : '';
            $id           = ( isset( $args['id'] ) ) ? $args['id'] : '';
            $options      = ( isset( $args['options'] ) ) ? $args['options'] : '';
            $description  = ( isset( $args['description'] ) ) ? $args['description'] : '';
            $values       = ( isset( $args['values'] ) ) ? $args['values'] : '';
            $line_break   = ( isset( $args['line_break'] ) ) ? $args['line_break'] : true;

            if( 0 == count( $values ) )
                return;

            echo '<fieldset id="' . $id . '"> ';
            if( $legend ) {
                printf( '<legend>%1$s</legend>', $legend );
            }

            foreach ( $values as $key => $title ) {

                $checked = isset( $options[ $id . '-'. $key ] ) && 1 == $options[ $id . '-'. $key ] ? 'checked' : '';
                $br      = isset( $line_break ) && true === $line_break ? '<br>' : '&nbsp;';

                printf(
                    '<input type="checkbox" id="%5$s" name="%3$s[%1$s-%5$s]" value="1" %2$s /><label for="%5$s">%4$s</label>%6$s',
                    $id, $checked, $option_group, $title, $key, $br
                );

            }

            echo '</fieldset>';

            if ( !empty( $description) )
                echo '<p class="description">' . $description . '</p>';

        }

        /**
         * General Input with Multiple Checkboxes
         *
         * @param array $args
         *
         * @since 1.7.0
         */
        public function option_input_multiple_radio_cb( $args ){

            $legend       = ( isset( $args['legend'] ) ) ? $args['legend'] : '';
            $option_group = ( isset( $args['option_group'] ) ) ? $args['option_group'] : '';
            $id           = ( isset( $args['id'] ) ) ? $args['id'] : '';
            $value        = ( isset( $args['value'] ) ) ? $args['value'] : '';
            $description  = ( isset( $args['description'] ) ) ? $args['description'] : '';
            $values       = ( isset( $args['values'] ) ) ? $args['values'] : '';
            $line_break   = ( isset( $args['line_break'] ) ) ? $args['line_break'] : true;

            if( 0 == count( $values ) )
                return;

            if( $legend ) {
                printf( '<legend>%1$s</legend>', $legend );
            }

            foreach ( $values as $key => $title ) {

                $checked = $value == $key ? 'checked' : '';
                $br      = isset( $line_break ) && true === $line_break ? '<br>' : '&nbsp;';

                printf(
                    '<input type="radio" id="%5$s" name="%3$s[%1$s]" value="%5$s" %2$s /><label for="%5$s">%4$s</label>%6$s',
                    $id, $checked, $option_group, $title, $key, $br
                );

            }

            if ( !empty( $description) )
                echo '<p class="description">' . $description . '</p>';

        }

        /**
         * Input Field with Multiple Checkboxes for Payment Gateways
         *
         * @param array $args
         */
        public function option_input_multiple_payment_gateway_checkbox_cb( $args ){

            // add  settings fields for all payment gateways
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();

            if( 0 == count( $available_gateways ) )
                return;

            $legend       = ( isset( $args['legend'] ) ) ? $args['legend'] : '';
            $option_group = ( isset( $args['option_group'] ) ) ? $args['option_group'] : '';
            $id           = ( isset( $args['id'] ) ) ? $args['id'] : '';
            $description  = ( isset( $args['description'] ) ) ? $args['description'] : '';

            $s = get_option('wc_rma_settings');

            echo '<fieldset id="' . $id . '"> ';
            if( $legend ) {
                printf( '<legend>%1$s</legend>', $legend );
            }

            foreach ( $available_gateways as $gateway_key => $values ) {

                $checked = ( isset( $this->options_general[ $id . '-'. $gateway_key ] ) && 1 == $this->options_general[ $id . '-'. $gateway_key ] ) ? 'checked' : '';

                printf(
                    '<input type="checkbox" id="%5$s" name="%3$s[%1$s-%5$s]" value="1" %2$s />&nbsp;%4$s<br />',
                    $id, $checked, $option_group, $values->title, $gateway_key
                );

            }

            echo '</fieldset>';

            if ( !empty( $description) )
                echo '<p class="description">' . $description . '</p>';

        }

        /**
         * General Input Field Text
         *
         * @param array $args
         */
        public function option_input_text_cb( $args ) {

            $option_group = ( isset( $args['option_group'] ) ) ? $args['option_group'] : '';
            $id           = ( isset( $args['id'] ) ) ? $args['id'] : '';
            $value        = ( isset( $args['value'] ) ) ? $args['value'] : '';
            $placeholder  = ( isset( $args['placeholder'] ) ) ? $args['placeholder'] : '';
            $description  = ( isset( $args['description'] ) ) ? $args['description'] : '';

            printf(
                '<input type="text" id="%1$s" name="%3$s[%1$s]" value="%2$s" placeholder="%4$s" />',
                $id, $value, $option_group, $placeholder
            );

            if ( !empty( $description) )
                echo '<p class="description">' . $description . '</p>';
        }

        /**
         * General Select
         *
         * @param array $args
         */
        public function option_select_cb( $args ) {
            $option_group   = (isset($args['option_group'])) ? $args['option_group'] : '';
            $id             = (isset($args['id'])) ? $args['id'] : '';
            $options        = (isset($args['options'])) ? $args['options'] : '';
            $select_options = (isset($args['select_options'])) ? $args['select_options'] : array();
            $description    = (isset($args['description'])) ? $args['description'] : '';
            $class          = (isset($args['class'])) ? $args['class'] : '';

            echo '<select name="' . $option_group . '[' . $id . ']"' . ( !empty( $class) ? 'id="'. $id .'" class="' . $class . '"' : '' ) . '>';

            foreach ( $select_options as $value => $text ) {
                printf(
                    '<option value="%1$s" %2$s />%3$s</option>',
                    $value,
                    ( isset( $options[ $id ] ) && $value == $options[ $id ] ) ? 'selected="selected"' : '',
                    $text
                );
            }

            echo '</select>';

            if ( !empty( $description) )
                echo '<p class="description">' . $description . '</p>';

        }

        /**
         * @param array
         */
        public function plain_text_cb( $args ) {
            $text  = ( isset( $args[ 'text' ] ) ) ? $args[ 'text' ] : '';
            $class = ( isset( $args[ 'class' ] ) ) ? $args[ 'class' ] : '';

            echo '<p class="' . $class . '">' . $text . '</p>';
        }

        /**
		 * Secret Value Field Text
		 *
		 * @param array $args
		 */
		public function option_secret_cb( $args ) {

			$id           = ( isset( $args['id'] ) ) ? $args['id'] : '';
			$value        = ( isset( $args['value'] ) ) ? $args['value'] : '';
			$description  = ( isset( $args['description'] ) ) ? $args['description'] : '';

			$secret_value = substr( $value, 0, 2 ) . '..' . substr( $value, -1 );

			printf(
				'<p " id="%1$s" " > %2$s </p>',
				$id,
				$secret_value,
            );

			if ( ! empty( $description ) ) {
                echo '<p class="description">' . $description . '</p>';
        }
		}

        /**
         * Individual pulldown
         *
         * @param array $args
         */
        public function rma_mode_cb( $args ) {
            $option_group = ( isset( $args['option_group'] ) ) ? $args['option_group'] : '';
            $id           = ( isset( $args['id'] ) ) ? $args['id'] : '';

            $select_args  = array(
                'option_group' => $option_group,
                'id'           => $id,
                'options'      => $this->options_general,
                'select_options' => array(
                    'test' => esc_html__('Sandbox Test','rma-wc'),
                    'live' => esc_html__('Production','rma-wc'),
                )
            );

            // create select
            self::option_select_cb( $select_args );

            // output connection status
            $RMA_WC_API = new RMA_WC_API();
            // Retrieve customers to check connection
            $options = $RMA_WC_API->get_parts();
            unset( $RMA_WC_API );

            if ( ! $options )
                echo '&nbsp;<span style="color: red; font-weight: bold">' . __('No connection. Please check your settings.', 'rma-wc') . '</span>';
            else
                echo '&nbsp;<span style="color: green">' . __('Connection successful.', 'rma-wc') . '</span>';

        }

        /**
         * Pull down with RMA customer list
         *
         * @param $args
         */
        public function rma_customer_accounts_cb( $args ) {
            $option_group = ( isset( $args['option_group'] ) ) ? $args['option_group'] : '';
            $id           = ( isset( $args['id'] ) ) ? $args['id'] : '';

            $RMA_WC_API = new RMA_WC_API();
            $options = $RMA_WC_API->get_customers();

            if ( !empty( $RMA_WC_API ) ) unset( $RMA_WC_API );

            if( !isset( $options ) || !$options ) {

                $options = array('' => __( 'Error while connecting to RMA. Please check your settings.', 'rma-wc' ) );

            }
            else {

                $options = array('' => __( 'Select...', 'rma-wc' ) ) + $options;

            }

            $select_args  = array (
                'option_group'   => $option_group,
                'id'             => $id,
                'options'        => $this->options_general,
                'select_options' => $options,
                'class'          => 'select2'
            );

            // create select
            self::option_select_cb( $select_args );

        }

        /**
         * Pull down with RMA parts list
         *
         * @param $args
         *
         * @since 1.5.0
         */
        public function rma_parts_cb( $args ) {
            $option_group = ( isset( $args['option_group'] ) ) ? $args['option_group'] : '';
            $id           = ( isset( $args['id'] ) ) ? $args['id'] : '';
            $description  = ( isset( $args['description'] ) ) ? $args['description'] : '';

            if ( !isset( $parts ) || empty( $parts ) ) {

                $rma_api = new RMA_WC_API();
                $parts   = $rma_api->get_parts();

                if ( !empty( $RMA_WC_API ) ) unset( $RMA_WC_API );

            }

            if( !isset( $parts ) || !$parts ) {

                $options = array('' => __( 'Error while connecting to RMA. Please check your settings.', 'rma-wc' ) );

            }
            else {

                $options = array('' => __( 'Select...', 'rma-wc' ) ) + $parts;

            }

            $select_args  = array (
                'option_group'   => $option_group,
                'id'             => $id,
                'options'        => $this->options_general,
                'select_options' => $options,
                'description'    => $description,
                'class'          => 'select2'
            );

            // create select
            self::option_select_cb( $select_args );

        }


        /**
         * Output the error log from database
         */
        public function output_log() {

            $output = $this->get_log_from_database();

            echo '<style>[scope=row]{ display:none; }.form-table th { padding: 8px 10px; width: unset; font-weight: unset; }#textarea td {border: 1px solid #ddd; border-width: 0 1px 1px 0; }</style>';

            echo '<div id="textarea" contenteditable>';
            echo $output;
            echo '</div>';

        }

        /**
         * Sanitizes a string from user input
         * Checks for invalid UTF-8, Converts single < characters to entities, Strips all tags, Removes line breaks, tabs, and extra whitespace, Strips octets
         *
         * @param array $input
         *
         * @return array
         */
        public function sanitize( $input )  {

            $new_input = array();

            foreach ( $input as $key => $value ) {

                $new_input[ $key ] = sanitize_text_field( $input[ $key ] );

            }

            return $new_input;
        }

        /**
         * Read log information from database
         * 
         * TODO: That should perhaps be refactored into a dedicated "RMA_Logging" Class.
         *
         * @return string
         */
        private function get_log_from_database() {

            global $wpdb;

            $table_name = $wpdb->prefix . RMA_WC_LOG_TABLE;

            $results = $wpdb->get_results( "SELECT SQL_CALC_FOUND_ROWS * FROM $table_name ORDER BY id DESC;", ARRAY_A );

            if ( 0 == count( $results ) ) {

                $this->rma_log_count = 0;

                return esc_html__('No error data was found.', 'rma-wc');

            }

            return RMA_WC_API::format_log_information( $results );

        }

        /**
         * Add flush table button below table
         */
        private function flush_log_button() {

            if ( 0 < $this->rma_log_count || !empty( $this->rma_log_count ) || !isset( $this->rma_log_count )) {
                echo sprintf(
                    '<a href="#" id="flush-table" class="button-primary">%s</a>&nbsp;<span class="spinner">',
                    esc_html__('Flush Table', 'rma-wc')
                );
            }

        }

        /**
         * WP ajax request to flush error table
         */
        public function ajax_handle_database_log_table() {
            global $wpdb; // this is how you get access to the database

            $db_action = $_POST['db_action'];

            switch ( $db_action ) {
                case 'flush':

                    $table_name = $wpdb->prefix . RMA_WC_LOG_TABLE;

                    $wpdb->query("TRUNCATE TABLE $table_name");

                    if($wpdb->last_error !== '') {

                        esc_html_e( 'An error occurred while flushing the error log.', 'rma-wc');

                        $wpdb->print_error();

                        break;

                    }

                    $this->rma_log_count = 0;

                    break;
                default:
                    break;
            }

            wp_die(); // this is required to terminate immediately and return a proper response
        }

    }

}
