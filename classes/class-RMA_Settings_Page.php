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

if ( !class_exists('RMA_Settings_Page') ) {

    class RMA_Settings_Page {

        private $admin_url;
        private $option_group_general;
        private $option_group_accounting;
        private $options_general;
        private $options_accounting;
        private $option_page_general;
        private $option_page_accounting;
        private $option_page_log;
        private $rma_log_count;

        public function __construct() {

            $this->admin_url               = 'admin.php?page=rma-wc';
            $this->option_group_general    = 'wc_rma_settings';
            $this->option_group_accounting = 'wc_rma_settings_accounting';
            $this->option_page_general     = 'settings-general';
            $this->option_page_accounting  = 'settings-accounting';
            $this->option_page_accounting  = 'settings-log';

            $this->options_general    = get_option( $this->option_group_general );
            $this->options_accounting = get_option( $this->option_group_accounting );

            add_action( 'wp_ajax_rma_log_table', array( $this, 'ajax_handle_database_log_table') );

            add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );

            add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        }

        public function admin_enqueue( $hook ) {

            if( 'woocommerce_page_rma-wc' != $hook )
                return;

            // enqueue script and style for autocomplete on admin page
            wp_enqueue_script( 'select2', plugins_url( '../assets/js/select2.min.js', __FILE__ ), array('jquery'), '4.0.13', 'true' );
            wp_register_style( 'select2', plugins_url( '../assets/css/select2.min.css', __FILE__ ), false, '4.0.13' );
            wp_enqueue_style( 'select2' );

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
            add_settings_field(
                $id,
                esc_html__('Production Client', 'rma-wc'),
                array( $this, 'option_input_text_cb'), // general call back for input text
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : ''
                )
            );

            $id = 'rma-live-apikey';
            add_settings_field(
                $id,
                esc_html__('Production API key', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : ''
                )
            );

            $id = 'rma-test-client';
            add_settings_field(
                $id,
                esc_html__('Sandbox Client', 'rma-wc'),
                array( $this, 'option_input_text_cb'), // general call back for input text
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : ''
                )
            );

            $id = 'rma-test-apikey';
            add_settings_field(
                $id,
                esc_html__('Sandbox API key', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : ''
                )
            );
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
                esc_html__('Invoice Description in RMA', 'rma-wc'),
                array( $this, 'option_input_text_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'description'  => esc_html__('Possible variable: [orderdate]', 'rma-wc' )
                )
            );

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

            $id = 'rma-book-payment-trigger';
            add_settings_field(
                $id,
                esc_html__('Trigger', 'rma-wc'),
                array( $this, 'option_select_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'options'      => array(
                        ''            => esc_html__('Never. Booking is done manually.','rma-wc'),
                        'immediately' => esc_html__('Immediately when the invoice is created','rma-wc'),
                        'completed'   => esc_html__('On order status change Completed','rma-wc'),
                    ),
                    'description'  => esc_html__('When should the payment be booked in Run My Accounts', 'rma-wc' ),

                )
            );
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

            $id = 'rma-create-trigger';
            add_settings_field(
                $id,
                esc_html__('Trigger', 'rma-wc'),
                array( $this, 'option_select_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'options'      => array(
                        'immediately' => esc_html__('Immediately when the order is created','rma-wc'),
                        'completed'   => esc_html__('On order status change Completed','rma-wc'),
                    ),
                    'description'  => esc_html__('When should customers and invoices be created in Run My Accounts', 'rma-wc' ),

                )
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
                array( $this, 'rma_customer_accounts_cb'), // individual callback
                $this->option_page_general,
                $section,
                array( 'option_group' => $this->option_group_general,
                       'id'           => $id
                )
            );

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
                esc_html__('Shipping', 'rma-wc'),
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
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'options'      => array(
                        'error'    => esc_html__('error','rma-wc'),
                        'complete' => esc_html__('complete','rma-wc'),
                    )
                )
            );

            $id = 'rma-log-send-email';
            add_settings_field(
                $id,
                esc_html__('Send email on error', 'rma-wc'),
                array( $this, 'option_select_cb'),
                $this->option_page_general,
                $section,
                array(
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'options'      => array(
                        'no'       => esc_html__('no','rma-wc'),
                        'yes'      => esc_html__('yes','rma-wc'),
                    ),
                    'description'  => esc_html__('Send email on error with Run my Accounts API.', 'rma-wc' )

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
                    'description'  => esc_html__('Send email to this recipient. By default administrators email.', 'rma-wc' )
                )
            );


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
                    'option_group' => $this->option_group_general,
                    'id'           => $id,
                    'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                    'options'      => array(
                        'no'       => esc_html__('no','rma-wc'),
                        'yes'      => esc_html__('yes','rma-wc'),
                    ),
                    'description'  => esc_html__('Remove all plugin data when using the "Delete" link on the plugins screen', 'rma-wc' ),

                )
            );

        }

        public function options_accounting_gateways() {

            $section = 'accounting_settings_payment';

            add_settings_section(
                $section, // ID
                'Receivables Account', // Title
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

        }

        public function options_payment_gateways() {

            $section = 'accounting_settings_payment_account';

            add_settings_section(
                $section, // ID
                'Payment Account', // Title
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

        }

        public function section_info_accounting() {
            esc_html_e('You can specify a dedicated receivable account for each active payment gateway.', 'rma-wc');
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
            $option_group = (isset($args['option_group'])) ? $args['option_group'] : '';
            $id           = (isset($args['id'])) ? $args['id'] : '';
            $options      = (isset($args['options'])) ? $args['options'] : array();
            $description  = (isset($args['description'])) ? $args['description'] : '';
            $class        = (isset($args['class'])) ? $args['class'] : '';

            echo '<select name="' . $option_group . '[' . $id . ']"' . ( !empty( $class) ? 'class="' . $class . '"' : '' ) . '>';

            foreach ($options as $value => $text) {
                printf(
                    '<option value="%1$s" %2$s />%3$s</option>',
                    $value,
                    ( isset( $this->options_general[ $id ] ) && $value == $this->options_general[ $id ] ) ? 'selected="selected"' : '',
                    $text
                );
            }

            echo '</select>';

            if ( !empty( $description) )
                echo '<p class="description">' . $description . '</p>';

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
                'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                'options'      => array(
                    'test' => esc_html__('Sandbox Test','rma-wc'),
                    'live' => esc_html__('Production','rma-wc'),
                )
            );

            // create select
            self::option_select_cb( $select_args );

            // output connection status
            $RMA_WC_API = new RMA_WC_API();
            // Retrieve customers to check connection
            $options = $RMA_WC_API->get_customers();
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
                'option_group' => $option_group,
                'id'           => $id,
                'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                'options'      => $options,
                'class'        => 'select2'
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
                'option_group' => $option_group,
                'id'           => $id,
                'value'        => isset( $this->options_general[ $id ] ) ? $this->options_general[ $id ] : '',
                'options'      => $options,
                'description'  => $description,
                'class'        => 'select2'
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

            $output = '<table class="widefat">';

            $table_header = true;

            foreach ( $results as $result ) {

                if ( $table_header ) {
                    $output .= '<thead><tr>';
                    foreach ( array_keys( ( $result ) ) as $key ) {
                        $output .= '<th>' . $key . '</th>';
                    }
                    $output .= '</tr></thead>';

                    $table_header = false;
                }

                $output .= '<tr>';
                foreach ( $result as $key => $value ) {

                    $value = str_replace('error','<span style="color: red;">error</span>',$value);
                    $value = str_replace('failed','<span style="color: red;">failed</span>',$value);
                    $value = str_replace('success','<span style="color: green;">success</span>',$value);
                    $value = str_replace('paid','<span style="color: green;">paid</span>',$value);
                    $value = str_replace('created','<span style="color: green;">created</span>',$value);
                    $value = str_replace('invoiced','<span style="color: green;">invoiced</span>',$value);

                    $output .= '<td>' . $value . '</td>';
                }
                $output .= '</tr>';
            }

            $output .= '</table>';

            return $output;

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
