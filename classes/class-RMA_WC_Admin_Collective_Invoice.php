<?php
/**
 * Show the dashboard to manually manage collective invoices.
 *
 * @package RMA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for reporting on the invoice status
 *
 * @since 1.7.0
 */
class RMA_WC_Admin_Collective_Invoice {

	/**
	 * Add the hooks for the menu and the form response.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_entry' ), 12 );
		add_action( 'admin_post_collective_invoicing_form_response', array( $this, 'start_billing_run' ) );
	}

	/**
	 * Admin menu entry
	 *
	 * @return void
	 */
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

	/**
	 * Create the invoices in RMA
	 */
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

	/**
	 * Output the dashboard
	 *
	 * @return void
	 */
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
		echo '<tr style="vertical-align: text-top;">';

		$keys = array(
			'<p style="font-weight:bold">Invoice ID</p>',
			'<p style="font-weight:bold">Customer Number (Customer Name)</p><p>RMA Product</p>',
			'<p style="font-weight:bold">Links to Related Orders</p><p>Description</p>',
			'<p style="font-weight:bold">Due Date</p><p>RMA Project ID</p>',
			'<p>&nbsp;</p><p>Price</p>',
		);

		foreach ( $keys as $key ) {
			$key_id = sanitize_title( $key );
			echo "<th id='$key_id' class='manage-column column-columnname' scope='col'>$key</th>"; //phpcs:ignore
		}
		echo '</tr>';
		echo '</thead>';

		echo '<tbody>';

		$alternate = false;

		if ( empty( $display_data ) ) {
			echo '<tr><td colspan="4" style="text-align: center;">No invoices to generate.</td></tr>';
		}

		foreach ( $display_data as $invoice_id => $invoice_info ) {
			printf( '<tr class="%s" style="font-weight:bold">', $alternate ? 'alternate' : '' );
			echo '<td>';
			echo esc_html( $invoice_id ) . '</td>';
			$invoice_data = $invoice_info['data'];
			$user_data    = get_userdata( $invoice_info['user_id'] );
			echo '<td>' . esc_html( $invoice_data['invoice']['customernumber'] ) . ' ( ' . esc_html( $user_data->display_name ) . ' )</td>';
			echo '<td>';

			$links = array();
			foreach ( $invoice_info['order_ids'] as $order_id ) {
				$links[] = sprintf( '<a target="_blank" href="%s">%s</a>', get_edit_post_link( $order_id ), esc_html( $order_id ) );
			}
			echo implode( ',', $links ); //phpcs:ignore
			echo '</td>';

			$dateformat = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			echo '</td><td>' . ( new \DateTime( $invoice_data['invoice']['duedate'] ) )->format( $dateformat ) . '</td><td></td>'; //phpcs:ignore
			echo '</tr>';

			foreach ( $invoice_info['data']['part'] as $part ) {

				$description = preg_replace( '[&#xA;|&#xD;]', '<br/>', $part['description'] );

				// add info to facilitate automated testing.
				$row_info = sprintf( 'data-invoice_id=%s', $invoice_id );
				$row_info = sprintf( 'data-customer_id=%s', $user_data->ID );
				$row_info = sprintf( 'data-customernumber=%s', $invoice_data['invoice']['customernumber'] );

				printf( '<tr %s %s>', $row_info, $alternate ? 'class="alternate"' : '' );
				echo '<td></td>';
				echo "<td class='column-columnname'>" . esc_html( $part['partnumber'] ) . '</td>';
				echo "<td class='column-columnname'>" . wp_kses_post( $description ) . '</td>';
				echo "<td class='column-columnname'>" . esc_html( $part['projectnumber'] ?? '' ) . '</td>';
				echo "<td class='column-columnname'>" . wc_price( $part['sellprice'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tr>';
			$alternate = ! $alternate;
		}

		echo '</tbody>';
		echo '</table>';
	}
}
