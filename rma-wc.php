<?php
/**
 * rma-wc.php
 *
 * Run my Accounts for WooCommerce
 *
 * @package              RunmyAccountsforWooCommerce
 * @author               Sandro Lucifora
 * @copyright            2025 Openstream Internet Solutions
 * @license              GPL-3.0-or-later
 *
 * Plugin Name:          Run my Accounts for WooCommerce - SailCom Edition
 * Version:              1.9.1-SailCom {BUILD}
 * Description:          This plug-in connects WooCommerce to <a href="https://www.runmyaccounts.ch/">Run my Accounts</a>. Create customers and invoices as soon as you get an order in your WooCommerce shop.
 * Requires at least:    6.2
 * Requires PHP:         7.2
 * Author:               Openstream Internet Solutions
 * Author URI:           https://www.openstream.ch
 * Text Domain:          run-my-accounts-for-woocommerce
 * WC requires at least: 8.2
 * WC tested up to:      9.8
 * License:              GPL v2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined('ABSPATH' ) ) exit;

// Set full path
if ( ! defined('RMA_WC_PFAD') ) {
	define('RMA_WC_PFAD', trailingslashit( plugin_dir_path( __FILE__) ) );
}

/**
 * Declare WooCommerce HPOS compatibility
 *
 * @since 1.8.0
 */
function rma_declare_hpos() : void{
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'rma_declare_hpos');

/**
 * Scheduled events of this plugin; the classes reschedule them on `init` while active.
 *
 * @return string[]
 */
function rma_wc_cron_hooks(): array {
	return array( 'run_my_accounts_collective_invoice', 'update_invoice_status', 'update_invoice_status_continue' );
}

/**
 * Stop the billing and status sync jobs, which would otherwise keep firing without the plugin loaded.
 *
 * Registered here, not in rma-wc-main.php: WordPress only matches the main plugin file,
 * and that file is not loaded during the deactivation request.
 *
 * @return void
 */
function rma_wc_deactivate(): void {
	foreach ( rma_wc_cron_hooks() as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}
register_deactivation_hook( __FILE__, 'rma_wc_deactivate' );

/**
 * Load the main file only if WooCommerce is full loaded
 *
 * @return void
 */
function rma_woocommerce_loaded_action(){

	include_once RMA_WC_PFAD . '/rma-wc-main.php';

}
add_action( 'woocommerce_loaded', 'rma_woocommerce_loaded_action' );
