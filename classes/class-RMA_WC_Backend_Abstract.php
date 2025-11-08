<?php
/**
 * class-backend-abstract.php
 * 
 * @author      Sandro Lucifora
 * @copyright   (c) 2020, Openstream Internet Solutions
 * @link        https://www.openstream.ch/
 * @package     RunmyAccountsforWooCommerce
 * @since       1.0  
 */

if ( !defined('ABSPATH' ) ) exit;

if ( !class_exists('RMA_WC_Backend_Abstract') ) {

    abstract class RMA_WC_Backend_Abstract {

        const VERSION = '1.7.1';

        /**
         * Update Custom Tables
         * is called in class-backend.php with plugins_loaded()
         */
        public function update() {

            if ( self::VERSION > get_option( 'wc_rma_version' ) ) { // update option if value is different

                update_option("wc_rma_version", self::VERSION);

            }
        }

	    public function delete() {

		    $settings = get_option( 'wc_rma_settings' ); // get settings

		    if ( 'yes' == $settings[ 'rma-delete-settings' ] ) {
			    global $wpdb;

			    // drop table
			    $table_name = $wpdb->prefix . 'rma_wc_log';
			    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange

			    // delete all options
			    delete_option('wc_rma_db_version');
			    delete_option('wc_rma_version');
			    delete_option('wc_rma_settings');
                delete_option('wc_rma_settings_accounting');
		    }
	    }

        public function init_options() {

            /**
             * add_option() WP Since: 1.0.0
             * https://codex.wordpress.org/Function_Reference/add_option
             */
            add_option('wc_rma_version', self::VERSION);

        }

        public function init_hooks() {

            // fire if woocommerce is enabled
            if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

		        // Add RMA fields to user profile
		        add_filter('woocommerce_customer_meta_fields', array( $this, 'usermeta_profile_fields' ), 10, 1 );

		        // update user data in Run My Accounts
                add_action( 'profile_update', array( $this, 'update_profile' ), 99 );

                // add order action
                add_action( 'woocommerce_order_actions', array( $this, 'order_meta_box_action' ) );
                add_action( 'woocommerce_order_action_create_rma_invoice', array( $this, 'process_order_meta_box_action' ) );

                // add invoice column to order page
                add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column_to_order_table' ) ); // compatibility without HPOS
                add_action( 'manage_shop_order_posts_custom_column', array( $this, 'add_value_to_order_table_row' ) );  // compatibility without HPOS

	            add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column_to_order_table' ) ); // with HPOS
	            add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'add_value_to_order_table_row_hpos' ), 10, 2 ); // with HPOS

	            // add bulk action to order page
                add_filter( 'bulk_actions-edit-shop_order', array( $this, 'create_invoice_bulk_actions_edit_product'), 20, 1 );
                add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'create_invoice_handle_bulk_action_edit_shop_order'), 10, 3 );
                add_action( 'admin_notices', array( $this, 'create_invoice_bulk_action_admin_notice' ) );

            }

            // if WooCommerce Germanized is not active add our own custom meta field title
            if ( ! defined( 'WC_GERMANIZED_VERSION' ) ) {

                add_action( 'personal_options_update', array( $this, 'usermeta_form_field_update' ) ); // add the save action to user's own profile editing screen update
                add_action( 'edit_user_profile_update', array( $this, 'usermeta_form_field_update' ) ); // add the save action to user profile editing screen update

            }

        }

        /**
         * Adding to admin order list bulk create invoice a custom action 'rma_create_invoice'
         *
         * @param $actions
         *
         * @return mixed
         *
         * @since 1.6.0
         *
         * @author Sandro Lucifora
         */
        public function create_invoice_bulk_actions_edit_product( $actions ) {
            $actions['rma_create_invoice'] = __( 'Create Invoice', 'run-my-accounts-for-woocommerce' );
            return $actions;
        }

        /**
         * Make the bulk action create invoice from selected orders
         *
         * @param $redirect_to
         * @param $action
         * @param $post_ids
         *
         * @return string
         * @throws DOMException
         * @author Sandro Lucifora
         * @since 1.6.0
         *
         */
        public function create_invoice_handle_bulk_action_edit_shop_order( $redirect_to, $action, $post_ids ) {

            // Exit if...
            // ...wrong action
            // ...no posts selected
            // ...class RMA_WC_API does not exist
            if( 'rma_create_invoice' !== $action || 0 == count( $post_ids ) || !class_exists('RMA_WC_API') )
                return $redirect_to;

            $processed_ids       = array();
            $success_invoice_ids = array();
            $no_invoice_ids      = array();

            $RMA_WC_API = new RMA_WC_API();

            foreach ( $post_ids as $post_id ) {

	            $order = wc_get_order( $post_id );
	            $invoice_number = $order->get_meta( '_rma_invoice', true );

                // order has already an invoice
                if( !empty( $invoice_number ) ) {

                    $processed_ids[]  = $no_invoice_ids[] = $post_id;

                }
                else {

                    $result = $RMA_WC_API->create_invoice( $post_id );

                    $processed_ids[] = $post_id;

                    if( 200 == $result || 204 == $result ) {
                        $success_invoice_ids[] = $post_id;
                    }
                    else {
                        $no_invoice_ids[] = $post_id;
                    }

                }

            }

            unset( $RMA_WC_API );

            return $redirect_to = add_query_arg( array(
                                                     'rma_create_invoice' => '1',
                                                     'success_count'      => count( $success_invoice_ids ),
                                                     'failed_count'       => count( $no_invoice_ids ),
                                                     'processed_count'    => count( $processed_ids ),
                                                     'processed_ids'      => implode( ',', $processed_ids ),
                                                 ), $redirect_to );
        }

        /**
         * The results notice from bulk action create invoice on orders
         *
         * @since 1.6.0
         *
         * @author Sandro Lucifora
         */
        public function create_invoice_bulk_action_admin_notice() {
            if ( empty( $_REQUEST['rma_create_invoice'] ) ) return; // Exit

            $success_count   = intval( $_REQUEST['success_count'] );
            $processed_count = intval( $_REQUEST['processed_count'] );

            printf( '<div id="message" class="updated fade"><p>' .
                    _n( 'Created %s invoice',
                        'Created %s invoices',
                        $success_count,
                        'run-my-accounts-for-woocommerce'
                    ) . '</p></div>', number_format_i18n( $success_count ) );

        }

        /**
         * Add a custom action to order actions select box on edit order page
         * Only added for not paid orders and when invoice is not created yet
         *
         * @param array $actions order actions array to display
         *
         * @return array - updated actions
         */
        public function order_meta_box_action( $actions ) {

            global $theorder;

            // bail if the order has been paid for or invoice was already created
            if ( $theorder->is_paid() || !empty( $theorder->get_meta( '_rma_invoice', true ) ) ){
                return $actions;
            }

            $actions['create_rma_invoice'] = __( 'Create invoice in Run my Accounts', 'run-my-accounts-for-woocommerce' );
            return $actions;

        }

        /**
         * Create invoice when custom action is clicked
         *
         * @param $order
         */
        public function process_order_meta_box_action( $order ) {

            $RMA_WC_API = new RMA_WC_API();
            $result = $RMA_WC_API->create_invoice(  $order->get_id() );

            unset( $RMA_WC_API );

        }

        /**
         * Add column to order table
         *
         * @param $columns
         *
         * @return array
         */
        public function add_column_to_order_table( $columns ): array {

			return RMA_WC_Frontend::array_insert($columns, 'order_total', 'rma_invoice', __('Invoice #', 'run-my-accounts-for-woocommerce'));

        }
        public function add_value_to_order_table_row( $column ) {

            global $post;

            switch ( $column ) {
                case 'rma_invoice' :

	                $order = wc_get_order( $post->ID );
	                echo $order->get_meta( '_rma_invoice', true );

                default:
            }


        }

	    /**
	     * Add value to admin order table for HPOS
	     *
	     * @param $column
	     * @param $wc_order_obj
	     *
	     * @return void
	     */
	    public function add_value_to_order_table_row_hpos( $column, $wc_order_obj ) {

		    switch ( $column ) {
			    case 'rma_invoice' :

				    echo $wc_order_obj->get_meta( '_rma_invoice', true );

			    default:
		    }


	    }

        /**
         * Update user profile in Run my Accounts
         *
         * @param $user_id
         */
        public function update_profile( $user_id ) {

            $RMA_WC_API = new RMA_WC_API();
            $options = $RMA_WC_API->create_rma_customer( 'user', $user_id, 'update' );

            unset( $RMA_WC_API );

        }

        /**
         * Save settings options
         *
         * @param $input
         * @return boolean
         */
        public function save_option( $input ) {

            $return = $input;

            if ( !empty( $_POST ) &&
                 check_admin_referer('rma-wc-nonce-action', 'rma-wc-nonce' ) ) {

                /**
                 * https://codex.wordpress.org/Function_Reference/current_user_can
                 * https://codex.wordpress.org/Roles_and_Capabilities
                 * since 2.0.0
                 */
                if ( !current_user_can('edit_posts') &&
                     !current_user_can('edit_pages'))
                    $return = false;

                return $return;
            }

            return false;
        }

	    /**
	     * Add custom fields to user profile
	     *
	     * @param $fields
	     * @return mixed
	     */
	    public function usermeta_profile_fields( $fields ) {

            // if WooCommerce Germanized is not active add our own billing title
            if ( ! defined( 'WC_GERMANIZED_VERSION' ) ) {
                $fields['billing']['fields']['billing_title'] = array(
                    'label'       => __('Title', 'run-my-accounts-for-woocommerce'),
                    'type'        => 'select',
                    'options'     => apply_filters( 'woocommerce_rma_title_options',
                        array(
                            1 => __('Mr.', 'run-my-accounts-for-woocommerce'),
                            2 => __('Ms.', 'run-my-accounts-for-woocommerce')
                        )
                    ),
                    'description' => ''
                );
            }

		    $fields[ 'rma' ][ 'title' ] = __( 'Settings Run my Accounts', 'run-my-accounts-for-woocommerce' );

            $RMA_WC_API = new RMA_WC_API();
            $options = $RMA_WC_API->get_customers();

    	    if( !$options ) {

			    $fields[ 'rma' ][ 'fields' ][ 'rma_customer' ] = array(
				    'label'       => __( 'Customer', 'run-my-accounts-for-woocommerce' ),
				    'type'		  => 'select',
				    'options'	  => array('' => __( 'Error while connecting to RMA. Please check your settings.', 'run-my-accounts-for-woocommerce' )),
				    'description' => __( 'Select the corresponding RMA customer for this account.', 'run-my-accounts-for-woocommerce' )
			    );

			    return $fields;
		    }

		    $options = array('' => __( 'Select customer...', 'run-my-accounts-for-woocommerce' )) + $options;

		    $fields[ 'rma' ][ 'fields' ][ 'rma_customer' ] = array(
			    'label'       => __( 'Customer', 'run-my-accounts-for-woocommerce' ),
			    'type'		  => 'select',
			    'options'	  => $options,
			    'description' => __( 'Select the corresponding RMA customer for this account.', 'run-my-accounts-for-woocommerce' )
		    );

		    if ( !empty( $RMA_WC_API )) unset( $RMA_WC_API );

		    $fields[ 'rma' ][ 'fields' ][ 'rma_billing_account' ] = array(
			    'label'       => __( 'Receivables Account', 'run-my-accounts-for-woocommerce' ),
			    'type'		  => 'input',
			    'description' => __( 'The receivables account has to be available in RMA. Leave it blank to use default value 1100.', 'run-my-accounts-for-woocommerce' )
		    );

		    $fields[ 'rma' ][ 'fields' ][ 'rma_payment_period' ] = array(
			    'label'       => __( 'Payment Period', 'run-my-accounts-for-woocommerce' ),
			    'type'		  => 'input',
			    'description' => __( 'How many days has this customer to pay your invoice?', 'run-my-accounts-for-woocommerce' )
		    );

		    return $fields;
	    }

        /**
         * Save custom user meta field.
         *
         * @param $user_id int the ID of the current user.
         *
         * @return bool Meta ID if the key didn't exist, true on successful update, false on failure.
         */
        public function usermeta_form_field_update( int $user_id ): bool {
            // check that the current user have the capability to edit the $user_id
            if (!current_user_can('edit_user', $user_id))
                return false;

            // create/update user meta for the $user_id
            if ( isset( $_POST['billing_title'] ) ) {
                return update_user_meta( $user_id, 'billing_title', absint( $_POST['billing_title'] ) );
            }

            return false;

        }

    }

}
