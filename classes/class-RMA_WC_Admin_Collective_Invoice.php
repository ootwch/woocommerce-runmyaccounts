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
	 * The invoice table.
	 *
	 * @var RMA_WC_Collective_Invoice_Table
	 */
	private RMA_WC_Collective_Invoice_Table $invoice_table;

	/**
	 * Add the hooks for the menu and the form response.
	 */
	public function __construct() {

		add_action( 'admin_menu', array( $this, 'add_menu_entry' ), 12 );
		add_action( 'admin_post_collective_invoicing_form_response', array( $this, 'start_billing_run' ) );
		add_filter( 'set-screen-option', array( $this, 'set_screen_option' ), 10, 3 );
		add_action( 'wp_ajax_rma_invoice_dashboard_group_details', array( $this, 'ajax_group_details' ) );
	}

	public function set_screen_option( $status, $option, $value ) {
		if ( 'invoice_dashboard_groups_per_page' === $option ) {
			return max( 1, (int) $value );
		}
		return $status;
	}


	/**
	 * Admin menu entry
	 *
	 * @return void
	 */
	public function add_menu_entry() {

		$page = add_submenu_page(
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

		// Initialize the list table instance when the page is loaded.
		add_action( "load-$page", array( $this, 'init_list_table' ) );
	}

	public function init_list_table() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Groups per page', 'woocommerce-sailcom' ),
				'default' => 20,
				'option'  => 'invoice_dashboard_groups_per_page',
			)
		);
		$this->invoice_table = new RMA_WC_Collective_Invoice_Table();
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

		if ( isset( $_GET['invoice_action'] ) ) {
			switch ( $_GET['invoice_action'] ) {

				case 'prepare_invoices':
					$this->handle_create_invoice();
					return;
				case 'confirm_invoices': //phpcs:ignore
					$this->handle_confirm_invoice();
					return;
				default:
					break;

			}
		}

		$this->invoice_table->prepare_items();
		?>

			<div class="wrap">    
				<h2><?php _e( 'Invoice Dashboard', 'rma-wc' ); ?></h2>
					<div id="nds-wp-list-table-demo">			
						<div id="nds-post-body">		
					<form id="invoice-dashboard-form" method="post">					
				<?php
				do_action( 'admin_table_notices' );
				$this->invoice_table->display();
				?>
									
					</form>
						</div>			
					</div>
			</div>
		<?php
	}

	public function handle_create_invoice() {

		if ( ! isset( $_GET['invoice_id'] ) ) {
			wp_die( 'No invoice_id given' );
		}
		$invoice_id = esc_attr( $_GET['invoice_id'] ); //phpcs:ignore

		$params = $_GET;
		unset( $params['invoice_action'] );
		unset( $params['_wp_nonce'] );
		unset( $params['_wp_http_referer'] );
		$params['invoice_action'] = 'confirm_invoices';
		$params['_wp_nonce']      = wp_create_nonce( 'confirm_single_invoice_' . $invoice_id );
		$params['page']           = 'invoice-dashboard';
		$new_query_string         = http_build_query( $params );

		if ( isset( $_GET['_wp_nonce'] ) && wp_verify_nonce( $_GET['_wp_nonce'], 'create_single_invoice_' . $invoice_id ) ) {

			$orders = array();
			if ( isset( $_GET['selected_order_ids'] ) ) {
				$orders = array_map( 'intval', wp_parse_list( $_GET['selected_order_ids'] ) ); //phpcs:ignore
			}
			$t               = new RMA_WC_Collective_Invoicing();
			$current_invoice = $t->get_dashboard_invoice_by_id( $invoice_id );
			if ( null === $current_invoice ) {
				wp_die( 'Invoice group not found.' );
			}
			if ( empty( $orders ) ) {
				$orders = array_map( 'intval', $current_invoice['order_ids'] ?? array() );
			}

			$current_invoice['data']['part'] = array_filter(
				$current_invoice['data']['part'],
				function( $v ) use ( $orders ) {
					return in_array( $v['order_id'], $orders, true );}
			);

			$total_value = array_sum( array_column( $current_invoice['data']['part'], 'sellprice' ) );

			?>
			<h2>Verify Invoice <?php echo $invoice_id; ?></h2>
			<?php
			printf( '<a href="%s">Confirm Invoice</a>', '?' . esc_attr( $new_query_string ) );

			$i = $current_invoice['data']['invoice'];
			printf( '<div><strong>Invoice:</strong> %s </div>', esc_html( $i['invnumber'] ) );
			printf( '<div><strong>Customer:</strong> %s </div>', esc_html( $i['customernumber'] ) );
			printf( '<div><strong>Amount:</strong> %s </div>', wc_price( $total_value ) );

			foreach ( $current_invoice['data']['part'] as $part ) {
				echo( '<div>--</div>' );
				printf( '<div><strong>Order:</strong> %s </div>', esc_html( $part['order_id'] ) );
				printf( '<div><strong>Product:</strong> %s </div>', esc_html( $part['partnumber'] ) );
				printf( '<div><strong>Text:</strong> %s </div>', esc_html( $part['description'] ) );
				printf( '<div><strong>Price:</strong> %s </div>', wc_price( $part['sellprice'] ) );

			}
			// do you action

		} else {

			wp_die( esc_html__( 'Security check failure', 'rma_wc' ) );

		}
	}

	public function handle_confirm_invoice() {

		$created_invoices = array();

		if ( ! isset( $_GET['invoice_id'] ) ) {
			wp_die( 'No invoice_id given' );
		}
		$invoice_id = esc_attr( $_GET['invoice_id'] ); //phpcs:ignore

		if ( isset( $_GET['_wp_nonce'] ) && wp_verify_nonce( $_GET['_wp_nonce'], 'confirm_single_invoice_' . $invoice_id ) ) {

			$orders = array();
			if ( isset( $_GET['selected_order_ids'] ) ) {
				$orders = array_map( 'intval', wp_parse_list( $_GET['selected_order_ids'] ) ); //phpcs:ignore
			}
			$t               = new RMA_WC_Collective_Invoicing();
			$current_invoice = $t->get_dashboard_invoice_by_id( $invoice_id );
			if ( null === $current_invoice ) {
				wp_die( 'Invoice group not found.' );
			}
			if ( empty( $orders ) ) {
				$orders = array_map( 'intval', $current_invoice['order_ids'] ?? array() );
			}

			$current_invoice['data']['part'] = array_filter(
				$current_invoice['data']['part'],
				function( $v ) use ( $orders ) {
					return in_array( $v['order_id'], $orders, true );}
			);

			$total_value = array_sum( array_column( $current_invoice['data']['part'], 'sellprice' ) );

			// create xml and send invoice to Run My Accounts
			$api    = new RMA_WC_API();
			$result = $api->create_xml_content( $current_invoice['data'], $orders, true );

			if ( false !== $result ) {

				$created_invoices[] = $invoice_id;

			}

			// were invoices created, and we should send an email?
			if ( 0 < count( $created_invoices ) && SENDLOGEMAIL ) {

				$headers       = array( 'Content-Type: text/html; charset=UTF-8' );
				$email_content = sprintf( esc_html_x( 'The following collective invoices were sent: %s', 'email', 'rma-wc' ), implode( ', ', $created_invoices ) );
				wp_mail( LOGEMAIL, esc_html_x( 'Collective invoices were sent', 'email', 'rma-wc' ), $email_content, $headers );

			}

			// Show the log.
			echo wp_kses_post( RMA_WC_API::format_log_information( RMA_WC_API::$temporary_log ) );

		} else {

			wp_die( esc_html__( 'Security check failure', 'rma_wc' ) );

		}
	}



	/**
	 * AJAX: load invoice line items for one dashboard group.
	 *
	 * @return void
	 */
	public function ajax_group_details() {
		check_ajax_referer( 'rma_invoice_dashboard_details', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rma-wc' ) ), 403 );
		}

		$invoice_id     = isset( $_POST['invoice_id'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice_id'] ) ) : '';
		$payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
		$order_ids      = isset( $_POST['order_ids'] ) ? array_map( 'intval', wp_parse_list( wp_unslash( $_POST['order_ids'] ) ) ) : array();

		if ( '' === $invoice_id || empty( $order_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing invoice data.', 'rma-wc' ) ), 400 );
		}

		$collective = new RMA_WC_Collective_Invoicing();
		$expected   = $collective->get_invoice_id_from_order_id( (int) $order_ids[0] );
		if ( $expected !== $invoice_id ) {
			wp_send_json_error( array( 'message' => __( 'Invoice group mismatch.', 'rma-wc' ) ), 400 );
		}

		$item = $collective->build_dashboard_group_display( $order_ids, $payment_method );
		if ( null === $item ) {
			wp_send_json_error( array( 'message' => __( 'Could not build invoice details.', 'rma-wc' ) ), 500 );
		}

		$table = new RMA_WC_Collective_Invoice_Table();
		wp_send_json_success(
			array(
				'html'       => $table->get_order_details_html( $item ),
				'total_html' => wp_kses_post( wc_price( $table->get_invoice_parts_total( $item ) ) ),
			)
		);
	}

}


