<?php

/**
 * uninstall.php
 *
 * Removes the plugin's settings, scheduled events and caches. Order meta
 * (`_rma_invoice`, `_rma_invoice_status`, `_rma_invoice_route`, ...) and the
 * users' `rma_customer` stay, because they document what was invoiced.
 *
 * WordPress checks the user's capability before it runs this file, also for WP-CLI.
 *
 * @author      Sandro Lucifora
 * @copyright   (c) 2020, Openstream Internet Solutions
 * @link        https://www.openstream.ch/
 * @package     RunmyAccountsforWooCommerce
 * @since       1.0
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'run_my_accounts_collective_invoice', 'update_invoice_status', 'update_invoice_status_continue' ) as $rma_wc_hook ) {
	wp_clear_scheduled_hook( $rma_wc_hook );
}

foreach (
	array(
		'wc_rma_settings',
		'wc_rma_settings_accounting',
		'wc_rma_settings_collective_invoice',
		'wc_rma_version',
		'wc_rma_db_version',
		'rma-invoice-user-list-filter',
	) as $rma_wc_option
) {
	delete_option( $rma_wc_option );
}
delete_transient( 'rma-wc-page-activated' );

global $wpdb;

// Prefixed options: status sync state, dashboard texts per language, and transients
// (customer invoice lists, error email throttle, project bookings).
$rma_wc_prefixes = array(
	'rma_wc_',
	'rma_invoice_title_',
	'rma_invoice_description_',
	'rma_invoice_footer_',
	'_transient_rma_wc_',
	'_transient_timeout_rma_wc_',
	'_transient_rma_project_booking_transient_',
	'_transient_timeout_rma_project_booking_transient_',
);
foreach ( $rma_wc_prefixes as $rma_wc_prefix ) {
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $rma_wc_prefix ) . '%' )
	);
}
wp_cache_delete( 'alloptions', 'options' );
