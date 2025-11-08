<?php
/**
 * class-backend.php
 *  
 * @author      Sandro Lucifora
 * @copyright   (c) 2018, Openstream Internet Solutions
 * @link        https://www.openstream.ch/
 * @package     RunmyAccountsforWooCommerce
 * @since       1.0  
 */

if ( !defined('ABSPATH' ) ) exit;

if (!class_exists('RMA_WC_Backend')) {

    /**
     * Create class and extends it
     */
    class RMA_WC_Backend extends RMA_WC_Backend_Abstract {

        /**
         * Construct  
         */
        public function __construct() {

            add_action( 'admin_init', array($this, 'admin_init'));
            add_action( 'plugins_loaded', array($this, 'plugins_loaded'));
            add_action( 'plugins_loaded', array($this, 'plugins_loaded_settings'), 1);

        }

        /**
         * Activate - is triggered when calling register_activation_hook(), but we do this in rma-wc.php
         */
        static function activate() {
            /**
             * set_transient() WP Since: 2.8  
             * https://codex.wordpress.org/Function_Reference/set_transient  
             */
            set_transient('rma-wc-page-activated', 1, 30);
        }

        /**
         * Deactivate - is triggered when register_deactivation_hook() is called, but we do this in the rma-wc.php
         */
        static function deactivate() {
            wp_clear_scheduled_hook( 'run_my_accounts_collective_invoice' );
        }

	    /**
	     * Uninstall - is triggered when register_uninstall_hook() is called, but we do it already in rma-wc.php
	     */
	    public function uninstall() {

		    $this->delete(); // Delete

	    }

        /**
         * Admin Init - we initiate everything we need
         */
        public function admin_init() {

            $this->init_options();
            $this->init_hooks();
        }

        /**
         * Plugins Loaded
         */
        public function plugins_loaded() {

            $this->update();
        }

        /**
         * Plugins Loaded Once on Activate -- This function is only called once when the plugin is activated.
         * this function is called in the constructor, with add_action()
         */
        public function plugins_loaded_settings() {

            /**
             * We check whether there is transient. If not, we will do it here
             * get_transient() WP Since: 2.8 
             * https://codex.wordpress.org/Function_Reference/get_transient 
             */
            if (!get_transient('rma-wc-page-activated')) {
                return;
            }

            /**
             * We delete the transient because we do not want the welcome page to be called again and again
             * delete_transient() WP Since: 2.8 
             * https://codex.wordpress.org/Function_Reference/delete_transient 
             */
            delete_transient('rma-wc-page-activated');

            /**
             * here we redirect to the settings page
             * wp_redirect() WP Since: 1.5.1 
             * https://codex.wordpress.org/Function_Reference/wp_redirect 
             */
            wp_redirect(
                    /**
                     * admin_url() WP Since:2.6.0 
                     * https://codex.wordpress.org/Function_Reference/admin_url 
                     */
                    admin_url('admin.php?page=rma-wc')
            );

            exit;
        }

        /**
         * Returns current plugin version
         *
         * @return int|string
         *
         * @since 1.6.1
         *
         */
        public static function get_plugin_version() {

            $plugin_data = get_file_data(__FILE__, [
                'Version' => 'Version'
            ], 'plugin');

            return $plugin_data[ 'Version' ];

        }

    }

} 