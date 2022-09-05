<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for reporting on the invoice status
 *
 * @since 1.7.0
 */
class RMA_WC_Admin_Collective_Invoice {


	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_entry' ), 12 );

	}

	public function add_menu_entry() {

		add_submenu_page(
			'woocommerce',
			'Invoice Generation Dashboard',
			'Invoice Generation Dashboard',
			'manage_woocommerce',
			'invoice-dashboard',
			array(
				$this,
				'show_dashboard',
			)
		);

	}

	public function show_dashboard() {

		$t = new RMA_WC_Collective_Invoicing();

		$invoices     = $t->get_not_invoiced_orders();
		$display_data = $t->create_collective_invoice( true, true );

		echo '<h3>Dashboard</h3>';

		echo '<table class="widefat fixed" cellspacing="0">';
		echo '<thead>';
		echo '<tr>';

		$keys = array( 'Invoice ID', 'RMA Product', 'Description', 'RMA Project ID', 'Price' );

		foreach ( $keys as $key ) {
			echo "<th id='$key' class='manage-column column-columnname' scope='col'>$key</th>";
		}
		echo '</tr>';
		echo '</thead>';

		echo '<tbody>';

		$alternate = false;

		foreach ( $display_data as $invoice_id => $invoice_info ) {
			printf( '<tr class="%s" style="font-weight:bold">', $alternate ? 'alternate' : '' );
			echo '<td>';
			echo $invoice_id . '</td>';
			$invoice_data = $invoice_info['data'];
			$user_data = get_userdata( $invoice_info['user_id'] );
			echo '<td>' . $invoice_data['invoice']['customernumber'] . ' ( ' . $user_data->display_name . ' )</td>';
			echo '<td>';

			$links = array();
			foreach ( $invoice_info['order_ids'] as $order_id ) {
				$links[] = sprintf( '<a target="_blank" href="%s">%s</a>', get_edit_post_link( $order_id ), $order_id );
			}
			echo implode( ',', $links );
			echo '</td>';

			echo '</td><td></td><td></td>';
			echo '</tr>';

			foreach ( $invoice_info['data']['part'] as $part ) {
				printf( '<tr %s>', $alternate ? 'class="alternate"' : '' );
				echo '<td></td>';
				echo "<td class='column-columnname'>" . $part['partnumber'] . "</td>";
				echo "<td class='column-columnname'>" . $part['description'] . "</td>";
				echo "<td class='column-columnname'>" . ( $part['projectnumber'] ?? '' ) . "</td>";
				echo "<td class='column-columnname'>" . wc_price( $part['sellprice'] ) . "</td>";
				echo '</tr>';
			}
			echo '</tr>';
			$alternate = ! $alternate;
		}

		echo '</tbody>';
		echo '</table>';
	}
}
