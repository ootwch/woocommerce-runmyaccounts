<?php
/**
 * class-frontend.php
 * 
 * @author      Sandro Lucifora
 * @copyright   (c) 2020, Openstream Internet Solutions
 * @link        https://www.openstream.ch/
 * @package     RunmyAccountsforWooCommerce
 * @since       1.0
 */

if ( !defined('ABSPATH' ) ) exit;

if ( ! class_exists('RMA_WC_Frontend' ) ) {

    class RMA_WC_Frontend {

        private $locale = '';

        /**
         *  Construct
         */
        public function __construct() {

            if ( !defined('PHP_VERSION_ID') ) {

                $version = explode('.', PHP_VERSION);

                define( 'PHP_VERSION_ID', ( $version[0] * 10000 + $version[1] * 100 + $version[2] ) );

                unset( $version );
            }

            // get plugin locale
            $this->locale = get_locale();

            /**
             * add_action() WP Since: 1.2.0
             * https://developer.wordpress.org/reference/functions/add_action/
             */
            add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );

            if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

                $settings = get_option( 'wc_rma_settings' );
                $trigger  = $settings[ 'rma-invoice-trigger' ] ?? '';

                switch ( $trigger ) :
                    // trigger invoice and customer creation on order status change
                    case 'completed':
                        add_action('woocommerce_order_status_changed', array( $this,
                            'create_invoice_on_status_change'
                        ), 90, 3);
                        break;

                    // trigger invoice creation when trigger is set 'immediately' or no selection was done on settings page
                    case 'immediately':
                    default:
                        // fire when new customer was saved on checkout page
                        add_action( 'woocommerce_checkout_update_user_meta', array( $this, 'create_rma_customer'), 10, 1 );
                        // fire when a new order comes in and create new invoice in RMA
                        add_action( 'woocommerce_checkout_order_processed', array( $this, 'create_rma_invoice'), 10, 1 );
                        break;
                endswitch;

                $trigger  = ( $settings['rma-payment-trigger'] ?? '' );

                switch ( $trigger ) :
                    // trigger payment booking on order status change
                    case 'completed':

                        add_action('woocommerce_order_status_completed', array( $this, 'rma_payment_booking' ), 95, 3);

                        break;
                    // trigger payment booking when trigger is set Never or no selection was done on settings page
                    default:
                        // Do nothing
                endswitch;


                // fire when a customer was updated
                add_action( 'woocommerce_update_customer', array( $this, 'update_customer' ) );

                // add title field to woocommerce billing address only, if WooCommerce Germanized is not active
                if ( ! defined( 'WC_GERMANIZED_VERSION' ) ) {
                    add_filter('woocommerce_billing_fields', array( $this, 'woocommerce_billing_fields'), 10, 1);
                    add_filter('woocommerce_checkout_fields', array( $this, 'woocommerce_billing_fields'), 10, 1);
                    add_filter('woocommerce_checkout_update_order_meta', array( $this, 'woocommerce_save_billing_fields'), 10, 1);
                }

            }

            add_filter( 'manage_users_columns', array( $this, 'add_column_to_user_table' ) );
            add_filter( 'manage_users_custom_column', array( $this, 'add_value_to_user_table_row' ), 10, 3 );

        }

        /**
         * Add custom user meta for WooCommerce
         *
         * @param $fields
         *
         * @return mixed
         */
        public function woocommerce_billing_fields( $fields ) {

            $fields['billing_title'] = array(
                'label'       => esc_html__('Title', 'run-my-accounts-for-woocommerce'),
                'type'        => 'select',
                'class'       => array ( 'form-row-wide', 'address-field' ),
                'options'     => apply_filters(
                    'woocommerce_rma_title_options',
                    array(
                        1 => __('Mr.', 'run-my-accounts-for-woocommerce'),
                        2 => __('Ms.', 'run-my-accounts-for-woocommerce')
                    )
                ),
                'required' => true,
                'priority' => 5
            );

            return $fields;

        }

        /**
         * Save custom billing fields to order
         *
         * @param int $order_id
         *
         */
        public function woocommerce_save_billing_fields( $order_id  ) {

            if ( ! empty( $_POST['billing_title'] ) ) {

	            $order = wc_get_order( $order_id );
	            $order->update_meta_data( '_billing_title', sanitize_text_field( $_POST['billing_title'] ) );
	            $order->save();

            }

        }

        /**
         * Add column to user table
         *
         * @param $column
         *
         * @return array
         */
        public function add_column_to_user_table( $column ): array {

            $column = self::array_insert( $column, 'email', 'rma_customer_id', __( 'RMA Customer # ', 'run-my-accounts-for-woocommerce') );

            return $column;
        }

        /**
         * Add value to new column on user table
         *
         * @param $val
         * @param $column_name
         * @param $user_id
         *
         * @return string
         */
        public function add_value_to_user_table_row( $val, $column_name, $user_id ): string {
            switch ( $column_name ) {
                case 'rma_customer_id' :
                    return get_the_author_meta( 'rma_customer', $user_id );
                default:
            }
            return $val;
        }

        /**
         * Insert key/value in array after a given key
         *
         * @param $array
         * @param $after_key
         * @param $key
         * @param $value
         *
         * @return array
         */
        static function array_insert( $array, $after_key, $key, $value ): array {

            $pos   = array_search( $after_key, array_keys( $array ) ) ;
            $pos ++;

            return array_merge(
                array_slice( $array, 0, $pos, $preserve_keys = true),
                array( $key=>$value ),
                array_slice( $array, $pos, $preserve_keys = true )
            );
        }

        /**
         * Plugins Loaded
         */
        public function plugins_loaded() {

            // Display the admin notification
	        add_action( 'admin_notices', array( $this, 'admin_notices' ) ) ;
        }

	    /**
	     * Show admin notices
	     */
	    public function admin_notices() {

	        $rma_settings = get_option('wc_rma_settings');
	        $rma_client = ( isset ($rma_settings['rma-live-client']) ? $rma_settings['rma-live-client'] : '');
	        $rma_apikey = ( isset ($rma_settings['rma-live-apikey']) ? $rma_settings['rma-live-apikey'] : '');

	        if ( 70200 > PHP_VERSION_ID ) {
                $html = '<div class="notice notice-error">';
                $html .= '<p>';
                $html .= '<b>'.esc_html__( 'Run my Accounts for WooCommerce', 'run-my-accounts-for-woocommerce' ).'&nbsp;</b>';
                $html .= esc_html__( 'You are using a wrong PHP version. You need to install PHP 7.2 or higher.', 'run-my-accounts-for-woocommerce' );
                $html .= '&nbsp;';
                $html .= sprintf( esc_html__( 'The current PHP version is %s.', 'run-my-accounts-for-woocommerce' ), PHP_VERSION);
                $html .= '</p>';
                $html .= '</div>';

                echo $html;

            }

	        if( ( !$rma_client || !$rma_apikey ) ) {

		        $html = '<div class="notice notice-warning">';
		        $html .= '<p>';
		        $html .= '<b>'.esc_html__( 'Warning', 'run-my-accounts-for-woocommerce' ).'&nbsp;</b>';
		        $html .= esc_html__('Please enter your Production Client and Production API Key before using Run my Accounts for WooCommerce in production mode.', 'run-my-accounts-for-woocommerce' );
		        $html .= '</p>';
		        $html .= '</div>';

		        echo $html;

	        }

	        if( ( isset($rma_settings['rma-active']) && $rma_settings['rma-active']=='') || !isset($rma_settings['rma-active'] ))  {

		        $html = '<div class="update-nag">';
		        $html .= esc_html__( 'WooCommerce Run my Accounts is not activated. No invoice will be created.', 'run-my-accounts-for-woocommerce' );
		        $html .= '</div>';

		        echo $html;

	        }

        }

        /**
         * Create new invoice in Run my Accounts when new order came in
         *
         * @param $order_id
         *
         * @return bool|string
         * @throws Exception
         */
	    public function create_rma_invoice( $order_id ) {

	    	$RMA_WC_API = new RMA_WC_API();

            $result = $RMA_WC_API->create_invoice( $order_id );

            unset( $RMA_WC_API );

            /*
             * If invoice creation was successful, check for payment booking
             */
            if( true === $result ) {

                $settings = get_option( 'wc_rma_settings' );
                $trigger  = ( $settings['rma-payment-trigger'] ?? '' );

                // trigger payment booking when trigger is set 'immediately' (means, immediately after invoice creation)
                if( 'immediately' == $trigger ) {

                    self::rma_payment_booking( $order_id );

                }

            }


		    return ( !empty( $result) ? $result : '' );

	    }

        /**
         * Create new invoice in Run my Accounts on status change
         *
         * @param $order_id
         * @param $old_status
         * @param $new_status
         *
         * @return bool|string
         * @throws Exception
         *
         * @since 1.6.0
         *
         * @author Sandro Lucifora
         */
        public function create_invoice_on_status_change( $order_id, $old_status, $new_status ) {

	        $order = wc_get_order( $order_id );
	        $invoice_number = $order->get_meta( '_rma_invoice', true );

            if( !empty( $invoice_number ) || 'completed' != $new_status || !class_exists('RMA_WC_API') )
                return false;

            $RMA_WC_API = new RMA_WC_API();

            // get user_id from order_id to create customer
            $order           = wc_get_order( $order_id );
            $customer_id     = $order->get_customer_id();
            unset( $order );
            $rma_customer_id = get_user_meta( $customer_id, 'rma_customer', true );

            // If the customer_id has not already a rma_customer_id assigned, create the customer
            $customer_result = ( !empty( $rma_customer_id ) ? true : self::create_rma_customer( $customer_id ) );

            // create invoice if customer creation was successfully
            $result = ( true === $customer_result ? $RMA_WC_API->create_invoice( $order_id ) : '' );

            unset( $RMA_WC_API );

            return ( $result );

        }

        /**
         * Book payment in Run my Accounts
         *
         * @param $order_id
         * @param $old_status
         * @param $new_status
         *
         * @throws Exception
         *
         * @since 1.6.0
         *
         * @author Sandro Lucifora
         */
        public function rma_payment_booking( $order_id, $old_status = null , $new_status = null ) {

            $payment = new RMA_WC_Payment();
            $payment->order_id = $order_id;
            $payment->send_payment();

            unset( $payment );

        }

        /**
         * Create customer in Run my Accounts when user is registered in WooCommerce
         *
         * @param $user_id
         * @param $data
         *
         * @return bool|string
         * @throws Exception
         */
        public function create_rma_customer( $user_id ) {

            // create new customer in RMA if user was created in WordPress before
            if ( 0 <> $user_id ) {
                $RMA_WC_API = new RMA_WC_API();

                $result = $RMA_WC_API->create_rma_customer( 'user', $user_id );

                unset( $RMA_WC_API );
            }

            return ( isset ( $result) && !empty( $result) ? $result : true );

        }

        /**
         * Update customer in Run my Accounts when data are updated at the front end
         *
         * @param $user_id
         */
        public function update_customer( $user_id ) {

            if ( !empty( $user_id ) && 0 != $user_id) {

                $RMA_WC_API = new RMA_WC_API();
                $RMA_WC_API->create_rma_customer( 'user', $user_id, 'update' );

                unset( $RMA_WC_API );

            }

        }

    }

}
