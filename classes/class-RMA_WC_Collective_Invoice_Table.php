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

        // Bulk set checkboxes on orders
        jQuery('input[name=\"invoice_id[]\"').change( function(event, data) {
            if (event.target !== this) return;
            current_invoice_id = event.currentTarget.dataset.invoice_id;
            if (typeof data?.state !== 'undefined') {
                current_state = data.state ;
            } else {
                current_state = event.currentTarget.checked;
            }
            // current_order_cbs = jQuery('input[data-invoice_id=\"' + current_invoice_id + '\"]');
            current_order_cbs = jQuery(this).closest('tr').next().find('input[name=\"order_id[]\"');
            current_order_cbs.prop(\"checked\", current_state).change();

        });

        // Update selected amount on invoices
        jQuery('input[name=\"order_id[]\"').change(
            debounce(function(event, data) {
                console.log('y');
                jQuery('tr.row_invoice').each(function() {
                    var sum = 0;
                    jQuery(this).next().find('td.column-sellprice bdi').each(function() {
                        if(jQuery(this).closest('tr').find('input:checkbox').is(':checked') ) {
                            sum += Number(jQuery(this).contents().filter(function() { return this.nodeType == Node.TEXT_NODE; }).first().text());
                        }
                    })
                    jQuery(this).find('td.col_price .invoice_price_selected bdi').contents().filter(function() { return this.nodeType == Node.TEXT_NODE; }).first().replaceWith(sum.toFixed(2));

                });
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
        
        ";

		wp_register_script( 'collective_invoice_table_js', false, array(), false, true );
		wp_add_inline_script( 'collective_invoice_table_js', $script );
		wp_enqueue_script( 'collective_invoice_table_js', '', array( 'common-js' ), false, true );
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
		if ( $which == 'top' ) {
			// The code that goes before the table is here.
			echo '<div><strong>Tabs for the languages</strong></div>';
			echo '<div><strong>Numbers of errors:</strong> ' . count( $this->errors ) . '</div>';
			echo '<div><strong>Select/Filter by user, date, language</strong></div>';
			echo "<div><strong>Hello, I'm before the table</strong></div>";
			echo '<div><strong>Total Invoice Amount</strong></div>';
			echo '<div><strong>Total Number of Invoices</strong></div>';
			echo '<div><strong>Selected Invoice Amount</strong></div>';
			echo '<div><strong>Selected Number of Invoices</strong></div>';
			// $this->show_action_form();

			echo '<input type="hidden" name="page" value="' . esc_attr( $_REQUEST['page'] ) . '" />';
			$this->search_box( 'Search Customer', 'customer-search' );
			$this->views();

		}
		if ( $which == 'bottom' ) {
			// The code that goes after the table is there.
			echo "Hi, I'm after the table";
			// $this->show_action_form();
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
		$perpage = 5;

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
	}

	public function column_col_invoice_id( $item ) {

		$invoice_id = $item['data']['invoice']['invnumber'];
		$nonce      = wp_create_nonce( 'create_single_invoice_' . $invoice_id );
		echo esc_html( stripslashes( $invoice_id ) );

		$actions = array(

			'create_invoice' => sprintf( '<a href="#" data-nonce="%s" data-invoice_id="%s">%s</a>', $nonce, $invoice_id, __( 'Create Invoice', 'wc_rma' ) ),
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
			echo ' < mark class = "error invoice-error" > Customer ' . esc_attr( $item['user_id'] ?? ' < item missing > ' ) . ' does not seem to exist . < / mark > ';
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

		echo '<div class="invoice_price_total"><span>' . __( 'Total:', 'wc-rma' ) . '</span>' . wc_price( $total_amount ) . '</div>' . PHP_EOL;
		echo '<div class="invoice_price_selected"><span>' . __( 'Selected:', 'wc-rma' ) . '</span>' . wc_price( $total_amount ) . '</div>' . PHP_EOL;

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
		return sprintf(
			'<input type="checkbox" data-invoice_id="%1$s" name="invoice_id[]" value="%1$s" checked />',
			$invoice_id
		);
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

			$order_rows .= sprintf( PHP_EOL . '<tr %s>', $alternate ? 'class="alternate"' : '' );
			// $order_rows .= sprintf( PHP_EOL . '<tr %s %s>', $row_info, $alternate ? 'class="alternate"' : '' );

			// Checkbox for orders
			$order_rows .= '<td>';
			$order_rows .= sprintf(
				'<label class="screen-reader-text" for="invoice_' . $invoice_id . '">' . sprintf( __( 'Select %s' ), $invoice_id ) . '</label>'
				 . '<input type="checkbox" data-invoice_id=' . $invoice_id
				 . ' name="order_id[]" data-order_id=' . $part['order_id'] . ' checked />'
			);
			echo '</td>' . PHP_EOL;

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



