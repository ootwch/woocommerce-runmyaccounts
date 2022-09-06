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

		add_action( 'admin_post_collective_invoicing_form_response', array( $this, 'start_billing_run' ) );

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

	public function start_billing_run() {

		check_admin_referer( 'collective_invoicing_form' );

		if ( isset( $_POST['collective-invoice-title-field'] ) ) {
			$invoice_title = sanitize_text_field( wp_unslash( $_POST['collective-invoice-title-field'] ) );

			$t = new RMA_WC_Collective_Invoicing();

			$rval = $t->create_collective_invoice( true, false, $invoice_title );

			echo '<h3> Created Invoices:</h3>';
			echo esc_html( implode( ', ', $rval ) );

			echo '<h3> Error Log</h3>';
			( new RMA_WC_Settings_Page() )->output_log();
		} else {
			wp_die( 'form error' );
		}
	}

	public function show_dashboard() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			echo '<h3> You do not have permission to use this dashboard. </h3>';
			return;
		}

		$t = new RMA_WC_Collective_Invoicing();

		$invoices     = $t->get_not_invoiced_orders();
		$display_data = $t->create_collective_invoice( true, true );

		echo '<h3>Dashboard</h3>';

		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" id="collective_invoicing_form">';
		echo '<input type="hidden" name="action" value="collective_invoicing_form_response">';

		echo '<label for="collective-invoice-title-field"> Collective Invoice Title </label><br>';
		echo '<input required id="collective-invoice-title-field" type="text" name="collective-invoice-title-field" value="" placeholder="" /><br>';
		wp_nonce_field( 'collective_invoicing_form' );
		echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Start Billing Run now!"/></p>';
		echo '</form>';

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

				$description = preg_replace( '[&#xA;|&#xD;]', '<br/>', $part['description'] );

				printf( '<tr %s>', $alternate ? 'class="alternate"' : '' );
				echo '<td></td>';
				echo "<td class='column-columnname'>" . $part['partnumber'] . "</td>";
				echo "<td class='column-columnname'>" . $description . "</td>";
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
