<?php
/**
 * Invoice Table Class
 */

 // Our class extends the WP_List_Table class, so we need to make sure that it's there.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}


/**
 * Table to display, select and run invoicing.
 *
 * Link: https://www.smashingmagazine.com/2011/11/native-admin-tables-wordpress/
 */
class RMA_WC_Collective_Invoice_Table extends WP_List_Table {

	/**
	 * $errors array of rows with errors.
	 *
	 * @var array
	 */
	public array $errors = array();

	/**
	 * The execution mode of the table. 'plan' or 'confirm'
	 *
	 * @var string
	 */
	public string $execution_mode = 'plan';

	/**
	 * The complete unfiltered unpaginated list of invoices.
	 *
	 * @var array
	 */
	public array $all_items = array();

	/**
	 * Constructor, we override the parent to pass our own arguments
	 * We usually focus on three parameters: singular and plural labels, as well as whether the class supports AJAX.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wp_list_text_link', // Singular label
				'plural'   => 'wp_list_test_links', // plural label, also this well be one of the table css class
				'ajax'     => false, // We won't support Ajax for this table
			)
		);

		// Register the javascript.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_js' ), 999 );

	}

		/**
		 * Function get_bulk_actions
		 *
		 * @return array bulk actions
		 */
	public function get_bulk_actions() {

		if ( 'plan' === $this->execution_mode ) {

			return array(
				'create-invoice' => __( 'Create invoices for selected orders', 'woocommerce-sailcom' ),
			);
		}

		if ( 'confirm' === $this->execution_mode ) {

			return array(
				'confirm-invoice' => __( 'CONFIRM: Create invoices for selected orders', 'woocommerce-sailcom' ),
			);
		}
	}

	/**
	 * Function process_bulk_action
	 *
	 * @return void
	 */
	public function process_bulk_action() {

		// security check!

		if ( isset( $_POST['_wpnonce'] ) && ! empty( $_POST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );

			$action = 'bulk-' . $this->_args['plural'];

			if ( ! wp_verify_nonce( $nonce, $action ) ) {
				wp_die( 'Nope! Security check failed!' );
			}
		}

		$action = $this->current_action();

		switch ( $action ) {

			case 'create-invoice':
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( 'Current user does not have the permission to create invoices.' );
				}

				$this->total_invoice_amount = 0;  // Updated by jQuery script.

				/*
				* -- Set current page to 1 --
				*/

				unset( $_GET['paged'] );

				$this->execution_mode = 'confirm';
				return;

			case 'confirm-invoice':
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( 'Current user does not have the permission to create invoices.' );
				}

				$invoice_title_en       = isset( $_POST['invoice-title-en'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice-title-en'] ) ) : 'SailCom Abrechnung';
				$invoice_description_en = isset( $_POST['invoice-description-en'] ) ? sanitize_textarea_field( wp_unslash( $_POST['invoice-description-en'] ) ) : '';
				$invoice_footer_en      = isset( $_POST['invoice-footer-en'] ) ? sanitize_textarea_field( wp_unslash( $_POST['invoice-footer-en'] ) ) : '';

				$created_invoices = array();

				// create xml and send invoice to Run My Accounts.
				$api = new RMA_WC_API();

				foreach ( $this->items as $invoice_id => $current_invoice ) {

					$current_invoice['data']['invoice']['description']  = esc_xml( $invoice_title_en );
					$current_invoice['data']['invoice']['text_field_1'] = esc_xml( $invoice_description_en );
					$current_invoice['data']['invoice']['notes']        = esc_xml( $invoice_footer_en );

					$order_ids = array_column( $current_invoice['data']['part'], 'order_id' );

					// $result = false;
					$result = $api->create_xml_content( $current_invoice['data'], $order_ids, true );

					if ( false !== $result ) {
						$created_invoices[]                           = $invoice_id;
						$this->items[ $invoice_id ]['created_status'] = 'OK';
					} else {
						$this->items[ $invoice_id ]['created_status'] = 'FAIL';
					}
				}

				// were invoices created, and we should send an email?
				if ( 0 < count( $created_invoices ) && SENDLOGEMAIL ) {

					$headers       = array( 'Content-Type: text/html; charset=UTF-8' );
					$email_content = sprintf( esc_html_x( 'The following collective invoices were sent: %s', 'email', 'rma-wc' ), implode( ', ', $created_invoices ) );
					wp_mail( LOGEMAIL, esc_html_x( 'Collective invoices were sent', 'email', 'rma-wc' ), $email_content, $headers );

				}

				// Show the log.
				echo '<div>';
				echo wp_kses_post( RMA_WC_API::format_log_information( RMA_WC_API::$temporary_log ) );
				echo '</div>';

				$this->execution_mode = 'done';

				break;

			default:
				// do nothing or something else.
				return;
		}
	}

	public function enqueue_admin_js() {

		// This does not trigger as the prop change does not trigger an event and wp is not handling <it class=""></it>
		// common.js 1183
		$script = "

        function debounce(func, timeout = 300) {
            let timer;
            return (...args) => {
              clearTimeout(timer);
              timer = setTimeout(() => { func.apply(this, args); }, timeout);
            };
        }

        // Bulk set checkboxes on invoices
        jQuery('.check-column #cb-select-all-1:checkbox,#cb-select-all-2:checkbox').click( function( event ) { 
            if (event.target !== this) return;
            jQuery('input[name=\"invoice_id[]\"').trigger( \"change\", {'state': event.currentTarget.checked }); 
        });

        // Update selected amount on invoices
        jQuery('input[name=\"order_id[]\"').change(
            debounce(function(event, data) {
                console.log('y');
				var total_sum = 0;
				var number_selected = 0;
                jQuery('tr.row_invoice').each(function() {
                    var sum = 0;
                    jQuery(this).next().find('td.column-sellprice bdi').each(function() {
                        if(jQuery(this).closest('tr').find('input:checkbox').is(':checked') ) {
                            sum += Number(jQuery(this).contents().filter(function() { return this.nodeType == Node.TEXT_NODE; }).first().text());
							number_selected += 1;
                        }
                    })
                    jQuery(this).find('td.col_price .invoice_price_selected bdi').contents().filter(function() { return this.nodeType == Node.TEXT_NODE; }).first().replaceWith(sum.toFixed(2));
					total_sum += sum;
                });

				jQuery('#selected-invoice-amount').contents().filter(function() { return this.nodeType == Node.TEXT_NODE; }).first().replaceWith(total_sum.toFixed(2));
				jQuery('#selected-invoice-number').contents().filter(function() { return this.nodeType == Node.TEXT_NODE; }).first().replaceWith(number_selected.toFixed(0));
				

            })
        );
        

        // Create invoice action
        jQuery('span.create_invoice a').click( function(event) { 
            event.preventDefault(); 

            current_invoice_id = event.currentTarget.dataset.invoice_id;
            current_orders = jQuery('input:checked[data-invoice_id=\"' + current_invoice_id + '\"]').map(function() {return jQuery(this).data('order_id') }).get();
            console.log(current_orders);

            arguments = new URLSearchParams({
                _wp_nonce: event.currentTarget.dataset.nonce,
                invoice_action: 'prepare_invoices',
                invoice_id: event.currentTarget.dataset.invoice_id,
                selected_order_ids: current_orders,
            });
        
            window.location.href = window.location.href + '&' + arguments.toString();
        
        
        });

		// hide/unhinde invoice details
		jQuery('a.expand-order-details-toggle').click( function(event) {
			event.preventDefault();
			jQuery( 'tr.order-details-' + event.target.getAttribute('invoice-id')).toggle();
		})

		// Invoice title / text tabs
		jQuery('a.nav-tab').click( function(event) {
			event.preventDefault();
			console.log('toggle');
			jQuery('.tab-content div').hide();
			jQuery('a.nav-tab').removeClass('nav-tab-active');
			jQuery('.tab-content .nav-tab-' + event.target.dataset.language).show();
			jQuery('a.nav-tab-' + event.target.dataset.language).addClass('nav-tab-active');
		});

		jQuery('input[name=\"order_id[]\"').first().change();
        
        ";

		wp_register_script( 'collective_invoice_table_js', false, array(), 124, true );
		wp_add_inline_script( 'collective_invoice_table_js', $script );
		wp_enqueue_script( 'collective_invoice_table_js', '', array( 'common-js' ), 124, true );
	}

	/**
	 * Shows the form to set invoice data and start the invoice run.
	 *
	 * @return void
	 */
	public function show_action_form() {
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" id="collective_invoicing_form">';
		echo '<input type="hidden" name="action" value="collective_invoicing_form_response">';

		echo '<label for="collective-invoice-title-field"> Collective Invoice Title </label><br>';
		echo '<input required id="collective-invoice-title-field" type="text" name="collective-invoice-title-field" value="" placeholder="" /><br>';
		wp_nonce_field( 'collective_invoicing_form' );
		echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Start Billing Run now!"/></p>';
		echo '</form>';
	}

	/**
	 * Add extra markup in the toolbars before or after the list
	 *
	 * @param string $which helps you decide if you add the markup after (bottom) or before (top) the list.
	 */
	public function extra_tablenav( $which ) {

		if ( 'top' === $which ) {
			if ( 'confirm' === $this->execution_mode ) {
				echo '<div id="selected-invoice-amount"><strong>Selected Invoice Amount</strong>' . esc_attr( count( $this->items ) ) . '</div>';
				echo '<div id="selected-invoice-number"><strong>Selected Number of Invoices</strong></div>';
			} else {
				// The code that goes before the table is here.
				echo '<div><strong>Tabs for the languages</strong></div>';
				echo '<div><strong>Numbers of errors:</strong> ' . count( $this->errors ) . '</div>';
				echo '<div><strong>Select/Filter by user, date, language</strong></div>';
				echo "<div><strong>Hello, I'm before the table</strong></div>";
				echo '<div><strong>Total Invoice Amount</strong></div>';
				echo '<div><strong>Total Number of Invoices</strong></div>';
				echo '<div id="selected-invoice-amount"><strong>Selected Invoice Amount</strong>d</div>';
				echo '<div id="selected-invoice-number"><strong>Selected Number of Invoices</strong>d</div>';
				// $this->show_action_form();
			}
			// Get the active tab from the $_GET param
			$default_tab = null;
			$tab         = isset( $_GET['tab'] ) ? $_GET['tab'] : $default_tab;

			$readonly = 'plan' === $this->execution_mode ? '' : 'readonly';

			$invoice_title_en       = isset( $_POST['invoice-title-en'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice-title-en'] ) ) : 'SailCom Abrechnung';
			$invoice_description_en = isset( $_POST['invoice-description-en'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice-description-en'] ) ) : '';
			$invoice_footer_en      = isset( $_POST['invoice-footer-en'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice-footer-en'] ) ) : '';

			$textarea_value = empty( $_POST['user-list-filter'] ) ? '' : sanitize_text_field( wp_unslash( $_POST['user-list-filter'] ) ); // phpcs:ignore
			?>

			<div id="user-list-text-area">
				<label for="user-list-filter" style="display:block" >Filter user list by GBID: Enter list of GBID and click [Apply Filter]</label>
				<textarea id="user-list-filter" name="user-list-filter" rows="2" style="display:block; width:100%" placeholder="Paste a list of customer id's ('N12345,SCK789,T231')."><?php echo esc_attr( $textarea_value ); ?></textarea>
				<?php submit_button( 'Apply Filter', '', '', false, array( 'id' => 'search-submit' ) ); ?>
			</div>






			<nav class="nav-tab-wrapper">
				<a href="#" data-language="en" class="nav-tab nav-tab-en nav-tab-active">English</a>
				<a href="#" data-language="de" class="nav-tab nav-tab-de">Deutsch</a>
				<a href="#" data-language="fr" class="nav-tab nav-tab-fr">Français</a>
			</nav>

			<div class="tab-content">
			<h2>ToDo: Only english works!</h2>
			<div class="nav-tab-en">

			<label>EN Title</label><br>
			<input <?php echo esc_attr( $readonly ); ?> type="text" name = "invoice-title-en" value="<?php echo esc_attr( $invoice_title_en ); ?>"><br>

			<label>EN Header Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice-description-en" cols="80" rows="3"><?php echo esc_attr( $invoice_description_en ); ?></textarea><br>

			<label>EN Footer Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice-footer-en" cols="80" rows="10"><?php echo esc_attr( $invoice_footer_en ); ?></textarea><br>
			</div>

			<div hidden class="nav-tab-de">

			<label>DE Title</label><br>
			<input <?php echo esc_attr( $readonly ); ?> type="text" name = "invoice-title-de"><br>

			<label>DE Header Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice-description-de" cols="80" rows="3"></textarea><br>

			<label>DE Footer Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice-footer-de" cols="80" rows="10"></textarea><br>

			</div>

			<div hidden class="nav-tab-fr">

			<label>FR Title</label><br>
			<input <?php echo esc_attr( $readonly ); ?> type="text" name = "invoice-title-fr"><br>

			<label>FR Header Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice-description-fr" cols="80" rows="3"></textarea><br>

			<label>FR Footer Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice-footer-fr" cols="80" rows="10"></textarea><br>

			</div>


			</div>


			<?php

			echo '<input type="hidden" name="page" value="' . esc_attr( $_REQUEST['page'] ) . '" />';
			$this->search_box( 'Search Customer', 'customer-search' );
			$this->views();

		}
		if ( 'botttom' === $which ) {
			// The code that goes after the table is there.
			echo "Hi, I'm after the table";

			if ( 'done' === $this->execution_mode ) {
				// Show the log.
				echo '<div>';
				echo wp_kses_post( RMA_WC_API::format_log_information( RMA_WC_API::$temporary_log ) );
				echo '</div>';
			}
			// $this->show_action_form();<form
		}
	}

	protected function get_views() {

		$view = ! empty( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$status_links = array(
			'all'    => '<a class="' . ( 'all' === $view ? 'current' : '' ) . '" href="' . add_query_arg( 'view', 'all' ) . '">' . esc_html__( 'All', 'wc-rma' ) . '(' . count( $this->all_items ) . ')</a>',
			'errors' => '<a class="' . ( 'errors' === $view ? 'current' : '' ) . '" href="' . add_query_arg( 'view', 'errors' ) . '">' . esc_html__( 'Errors', 'wc-rma' ) . '(' . count( $this->errors ) . ')</a>',
		);
		return $status_links;
	}

	/**
	 * Define the columns that are going to be used in the table
	 *
	 * @return array $columns, the array of columns to use with the table
	 */
	public function get_columns() {
		$columns = array(
			'cb'                  => '<input type="checkbox" checked />', // to display the checkbox.
			'col_invoice_id'      => __( 'Invoice ID' ),
			'col_customer_number' => __( 'Customer Number (Customer Name)' ),
			'col_due_date'        => __( 'Due Date' ),
			'col_price'           => __( 'Price' ),
		);

		if ( 'confirm-invoice' === $this->current_action() ) {
			$columns['col_creation_status'] = __( 'Invoice Created' );
		}

		return $columns;
	}

	public function column_default( $item, $column_name ) {

			  return $item[ $column_name ];
	}

	/**
	 * Decide which columns to activate the sorting functionality on
	 *
	 * @return array $sortable, the array of columns that can be sorted by the user
	 */
	public function get_sortable_columns() {
		return array(
			'col_invoice_id'      => 'invoice_id',
			'col_customer_number' => 'customer_umber',
			'col_due_date'        => 'due_date',
			'col_price'           => 'price',
		);
	}

	/**
	 * Prepare the table with different parameters, pagination, columns and table elements
	 */
	public function prepare_items() {

		$t               = new RMA_WC_Collective_Invoicing();
		$display_data    = $t->create_collective_invoice( true, true );
		$this->all_items = $display_data;
		// $display_data = $t->get_not_invoiced_orders();

		// Filter by key list.
		$user_list_filter_string = trim( sanitize_text_field( wp_unslash( $_POST['user-list-filter'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$user_list_filter_string = str_replace( ' ', ',', $user_list_filter_string );
		$user_list_filter_string = str_replace( ',,', ',', $user_list_filter_string );

		$user_list_filter = empty( $user_list_filter_string ) ? array() : explode( ',', $user_list_filter_string );

		$filtered_items = array();
		if ( ! empty( $user_list_filter ) ) {
			foreach ( $display_data as $key => $item ) {
				$customernumber = $item['data']['invoice']['customernumber'];
				if ( in_array( $customernumber, $user_list_filter, true ) ) {
					$filtered_items[ $key ] = $item;
				}
			}
			$display_data = $filtered_items;
		}

		// Only show selected invoiced
		$invoice_ids = ! empty( $_POST['invoice_id'] ) ? array_map( 'esc_attr', array_map( 'sanitize_text_field', wp_unslash( $_POST['invoice_id'] ) ) ) : array();
		if ( ! empty( $invoice_ids ) ) {
			$display_data = array_filter( $display_data, fn( $i ) => in_array( $i, $invoice_ids, true ), ARRAY_FILTER_USE_KEY );
			unset( $_GET['paged'] );
			unset( $_REQUEST['paged'] );
		}

		// Filter data
		$filter = ! empty( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		if ( ! empty( $filter ) ) {
			$filtered_items = array();
			foreach ( $display_data as $item ) {
				$customernumber = $item['data']['invoice']['customernumber'];
				$user_id        = esc_attr( $item['user_id'] );

				if ( 0 === absint( $user_id ) ) {
					$search_string = 'guest';
				} else {
					$user_data  = get_userdata( $item['user_id'] );
					$user_meta  = get_user_meta( $item['user_id'] );
					$user_name  = $user_data->display_name;
					$user_email = $user_meta['user_email'] ?? '';

					$search_string = $customernumber . $user_name . $user_id . $user_email;
				}

				if ( str_contains( strtoupper( $search_string ), strtoupper( $filter ) ) ) {
					$filtered_items[] = $item;
				}
			}
			$display_data = $filtered_items;
		}

		// Find errors.
		foreach ( $display_data as $item ) {
			$customernumber = $item['data']['invoice']['customernumber'];
			if ( empty( $customernumber ) ) {
				$this->errors[] = $item;
			}
		}

		// If view=errors then only show those.
		$view = ! empty( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';
		switch ( $view ) {
			case 'errors':
				$display_data = $this->errors;
				break;
			default:
				break;
		}

		/*
		* -- Ordering parameters --
		*/
		// Parameters that are going to be used to order the result.
		$orderby = ! empty( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = ! empty( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'ASC';
		if ( ! empty( $orderby ) & ! empty( $order ) ) {
			$display_data = wp_list_sort( $display_data, $orderby, $order, false );

		}

		/*
		-- Pagination parameters --
		*/

		// Number of elements in your table?
		$totalitems = count( $display_data );

		// How many to display per page?
		$perpage = 500;

		// Which page is this?
		$paged = ! empty( $_GET['paged'] ) ? sanitize_text_field( wp_unslash( $_GET['paged'] ) ) : '';

		// Page Number.
		if ( empty( $paged ) || ! is_numeric( $paged ) || $paged <= 0 ) {
			$paged = 1;
		}
		// How many pages do we have in total?
		$totalpages = ceil( $totalitems / $perpage );

		// adjust the query to take pagination into account.
		if ( ! empty( $paged ) && ! empty( $perpage ) ) {
			$offset = ( $paged - 1 ) * $perpage;

			$display_data = array_slice( $display_data, $offset, $perpage, false );

			/*
			* -- Register the pagination --
			*/
			$this->set_pagination_args(
				array(
					'total_items' => $totalitems,
					'total_pages' => $totalpages,
					'per_page'    => $perpage,
				)
			);

			// The pagination links are automatically built according to those parameters.

			// $this->items = array();
			$this->items = $display_data;

		}

		$this->process_bulk_action();
	}

	public function column_col_creation_status( $item ) {
		echo esc_attr( $item['created_status'] ?? '--X--' );
	}


	public function column_col_invoice_id( $item ) {

		$payment_method = $item['data']['invoice']['paymentmethod'];
		if ( empty( $payment_method ) ) {
			$payment_method = '<no payment method>';
		}
		echo esc_html( stripslashes( $payment_method ) );
		echo '<br>';

		$invoice_id = $item['data']['invoice']['invnumber'];
		$nonce      = wp_create_nonce( 'create_single_invoice_' . $invoice_id );
		echo esc_html( stripslashes( $invoice_id ) );

		$actions = array(

			'create_invoice' => sprintf( '<a href="#" data-nonce="%s" data-invoice_id="%s">%s</a>', $nonce, $invoice_id, __( 'Create Invoice', 'rma-wc' ) ),
		);
		echo $this->row_actions( $actions );
	}

	public function column_col_customer_number( $item ) {
		$user_data = get_userdata( $item['user_id'] );

		if ( false !== $user_data ) {
			$customernumber = $item['data']['invoice']['customernumber'];
			if ( empty( $customernumber ) ) {
				printf(
					'<mark class="error invoice-error"> %s </mark> (  <a href="%s" > %s </a>  )',
					esc_attr__( 'Customer missing in RMA! ', 'wc-rma' ),
					esc_url( get_edit_user_link( $item['user_id'] ) ),
					esc_html( $user_data->display_name )
				);
			} else {
				printf(
					' %s (  <a href="%s" > %s </a>  )',
					wp_kses_post( $customernumber ),
					esc_url( get_edit_user_link( $item['user_id'] ) ),
					esc_html( $user_data->display_name )
				);
			}
		} else {
			printf(
				'<mark class="error invoice-error"> %s </mark> ',
				// translators: %s = Woocommerce customer_id.
				sprintf( esc_attr__( 'Customer %s does not seem to exist! ', 'wc-rma' ), esc_attr( $item['user_id'] ?? '<item missing>' ) )
			);
		}

	}

	public function column_col_due_date( $item ) {
		$dateformat = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        echo ( new \DateTime( $item['data']['invoice']['duedate'] ) )->format( $dateformat ); //phpcs:ignore
	}

	public function column_col_price( $item ) {
		$total_amount = 0;
		foreach ( $item['data']['part'] as $part ) {
			$total_amount += $part['sellprice'];
		}

		echo '<div class="invoice_price_total">' . wp_kses_post( wc_price( $total_amount ) ) . '</div>' . PHP_EOL;

	}

	/**
	 * Render the bulk edit checkbox
	 *
	 * @param array $item
	 *
	 * @return string
	 */
	public function column_cb( $item ) {
		$invoice_id = $item['data']['invoice']['invnumber'];

		if ( 'plan' === $this->execution_mode ) {
			printf(
				'<input type="checkbox" data-invoice_id="%1$s" name="invoice_id[]" value="%1$s" checked />',
				esc_attr( $invoice_id )
			);
		} else {
			printf(
				'<input disabled type="checkbox" data-invoice_id="%1$s" name="invoice_id[]" value="%1$s" checked />',
				esc_attr( $invoice_id )
			);
			printf(
				'<input type="hidden" data-invoice_id="%1$s" name="invoice_id[]" value="%1$s"/>',
				esc_attr( $invoice_id )
			);

		}

		printf( '<div><a href="#" class="expand-order-details-toggle" invoice-id="%s">Expand</a></div>', esc_attr( $invoice_id ) );

	}

	/**
	 * Generates content for a single row of the table.
	 *
	 * @since 3.1.0
	 *
	 * @param object|array $item The current item
	 */
	public function single_row( $item ) {
		echo '<tr class="row_invoice">';
		$this->single_row_columns( $item );
		$this->display_order_rows( $item );
		echo '</tr>';
	}

	/**
	 * Display the rows of records in the table
	 *
	 * @return string echo the markup of the rows
	 */
	public function display_order_rows( $item ) {

		$invoice_id = $item['data']['invoice']['invnumber'];

		// Get the order data.
		$order_rows   = '';
		$total_amount = 0;

		$alternate = false;

		foreach ( $item['data']['part'] as $part ) {

			$description = preg_replace( '[&#xA;|&#xD;]', '<br/>', $part['description'] );

			// add info to facilitate automated testing.
			// $row_info = sprintf( 'data-invoice_id=%s', $invoice_id );
			// $row_info = sprintf( 'data-customer_id=%s', $user_data->ID );
			// $row_info = sprintf( 'data-customernumber=%s', $invoice_data['invoice']['customernumber'] );

			$order_rows .= sprintf( PHP_EOL . '<tr style="display:none" class="%s %s">', $alternate ? 'alternate' : '', 'order-details-' . esc_attr( $invoice_id ) );

			$alternate = ! $alternate;
			// $order_rows .= sprintf( PHP_EOL . '<tr %s %s>', $row_info, $alternate ? 'class="alternate"' : '' );

			// Checkbox for orders
			/*
			$order_rows .= '<td>';
			$order_rows .= sprintf(
				'<label class="screen-reader-text" for="invoice_' . $invoice_id . '">' . sprintf( __( 'Select %s' ), $invoice_id ) . '</label>'
				 . '<input type="checkbox" data-invoice_id=' . $invoice_id
				 . ( ( 'plan' === $this->execution_mode ) ? '' : ' disabled ' )
				 . ' name="order_id[]" data-order_id=' . $part['order_id'] . ' value="' . $part['order_id'] . '" checked />'
			);
			$order_rows .= '</td>' . PHP_EOL;
			*/

			$order_rows .= "<td class='column-partnumber'>" . esc_html( $part['partnumber'] ) . '</td>' . PHP_EOL;
			$order_rows .= "<td class='column-description'>" . wp_kses_post( $description ) . '</td>' . PHP_EOL;
			$order_rows .= "<td class='column-projectnumber'>" . esc_html( $part['projectnumber'] ?? '' ) . '</td>' . PHP_EOL;

			// Price and selected price.
			$order_rows .= '<td class="column-sellprice">';
			$order_rows .= '<div>' . wc_price( $part['sellprice'] ) . '</div>';
			$order_rows .= '</td>' . PHP_EOL;
			$order_rows .= '</tr>' . PHP_EOL;

			$total_amount += $part['sellprice'];
		}

		echo PHP_EOL . '<tr class="no-items">' . PHP_EOL;

		echo '<td class="colspanchange" colspan="' . ( $this->get_column_count() ) . '">' . PHP_EOL;

		echo '<table class="widefat fixed" cellspacing="0">' . PHP_EOL;
		echo '<thead>' . PHP_EOL;
		echo '</thead' . PHP_EOL;
		echo '<tbody>' . PHP_EOL;
		echo $order_rows . PHP_EOL;
		echo '</tbody>' . PHP_EOL;
		echo '</table>' . PHP_EOL;

		echo '</td>' . PHP_EOL;
		echo '</tr>' . PHP_EOL;

	}
}



