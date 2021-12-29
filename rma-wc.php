<?php

/**
 * rma-wc.php
 *
 * Run my Accounts for WooCommerce
 *
 * @package              RunmyAccountsforWooCommerce
 * @author               Sandro Lucifora
 * @copyright            2021 Openstream Internet Solutions
 * @license              GPL-3.0-or-later
 *
 * Plugin Name:          Run my Accounts for WooCommerce
 * Version:              1.6.4
 * Description:          This plug-in connects WooCommerce to <a href="https://www.runmyaccounts.ch/">Run my Accounts</a>. Create customers and invoices as soon as you get an order in your WooCommerce shop.
 * Requires at least:    4.7
 * Requires PHP:         7.2
 * Author:               Openstream Internet Solutions
 * Author URI:           https://www.openstream.ch
 * Text Domain:          rma-wc
 * Domain Path:          /languages/
 * WC requires at least: 3.2
 * WC tested up to:      6.1
 * License:              GPL v2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( !defined('ABSPATH' ) ) exit;

// Set full path
if (!defined('RMA_WC_PFAD')) { define('RMA_WC_PFAD', plugin_dir_path(__FILE__)); }

if (!defined('RMA_WC_LOG_TABLE')) { define('RMA_WC_LOG_TABLE', 'rma_wc_log'); }

/**
 * Class autoload for plugin classes which contains RMA in the class name
 *
 * @param $class_name
 */
function rma_wc_autoloader( $class_name )
{
    if ( false !== strpos( $class_name, 'RMA' ) ) {
        require_once plugin_dir_path(__FILE__) . 'classes/class-' . $class_name . '.php';
    }
}

spl_autoload_register('rma_wc_autoloader');

// LOAD BACKEND ////////////////////////////////////////////////////////////////

if ( is_admin() ) {

    // Instantiate backend class
    $RMA_WC_BACKEND = new RMA_WC_Backend();

    register_activation_hook(__FILE__, array('RMA_WC_Backend', 'activate') );
    register_deactivation_hook(__FILE__, array('RMA_WC_Backend', 'deactivate') );

    $my_settings_page = new RMA_Settings_Page();

}

// LOAD FRONTEND ///////////////////////////////////////////////////////////////

// Instantiate backend class
$RMA_WC_FRONTEND = new RMA_WC_Frontend();

