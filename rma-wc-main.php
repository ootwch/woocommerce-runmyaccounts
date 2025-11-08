<?php

/**
 * Load class for plugin in the right order
 */
include_once RMA_WC_PFAD . 'classes/class-RMA_WC_Backend_Abstract.php';
include_once RMA_WC_PFAD . 'classes/class-RMA_WC_Backend.php';
include_once RMA_WC_PFAD . 'classes/class-RMA_WC_Settings_Page.php';
include_once RMA_WC_PFAD . 'classes/class-RMA_WC_API.php';
include_once RMA_WC_PFAD . 'classes/class-RMA_WC_Frontend.php';
include_once RMA_WC_PFAD . 'classes/class-RMA_WC_Collective_Invoicing.php';
include_once RMA_WC_PFAD . 'classes/class-RMA_WC_Payment.php';
include_once RMA_WC_PFAD . 'classes/class-RMA_WC_Rental_And_Booking.php';

// LOAD BACKEND ////////////////////////////////////////////////////////////////

if ( is_admin() ) {

	// Instantiate backend class
	$RMA_WC_BACKEND = new RMA_WC_Backend();

	register_activation_hook(__FILE__, array('RMA_WC_Backend', 'activate') );
	register_deactivation_hook(__FILE__, array('RMA_WC_Backend', 'deactivate') );

	$my_settings_page = new RMA_WC_Settings_Page();

	// delete table from deprecated log, which is moved to WC_LOG
	if( get_option( 'wc_rma_db_version' ) ) {

		global $wpdb;

		// drop table
		$table_name = $wpdb->prefix . 'rma_wc_log';
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange

		delete_option('wc_rma_db_version');

	}

}

/*
 * Instantiate Frontend Class
 */
$RMA_WC_FRONTEND = new RMA_WC_Frontend();

$t = new RMA_WC_Collective_Invoicing();

/*
 * Integration of WooCommerce Rental & Booking System if activated
 * https://codecanyon.net/item/rnb-woocommerce-rental-booking-system/14835145
 */
if ( class_exists( 'RedQ_Rental_And_Bookings' ) ) {

	$RMA_RnB = new RMA_WC_Rental_And_Booking();

}
