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
	public int $all_items_count = 0;

	/**
	 * Summary counts from first pass.
	 *
	 * @var array
	 */
	public array $summary_counts = array(
		'affected_users' => 0,
		'orders'         => 0,
		'groups'         => 0,
	);

	/**
	 * Action result counters for the latest creation run.
	 *
	 * @var array
	 */
	public array $action_result_counts = array(
		'ok'      => 0,
		'fail'    => 0,
		'skipped' => 0,
	);

	/**
	 * Current page group metadata from pass-1 (for bulk confirm hydration).
	 *
	 * @var array
	 */
	private array $current_page_groups = array();

	/**
	 * Cached payment-method filter choices.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $payment_method_filter_options = null;

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

		$action = $this->current_action();

		if ( ! in_array( $action, array( 'create-invoice', 'confirm-invoice' ), true ) ) {
			return;
		}

		if ( ! $this->is_valid_dashboard_post() ) {
			wp_die( esc_html__( 'Security check failed', 'rma-wc' ), '', array( 'response' => 403 ) );
		}

		if ( 'confirm-invoice' === $action && empty( $_POST['invoice_id'] ) ) {
			add_action( 'admin_table_notices', array( $this, 'admin_notice_no_invoice_selected' ) );
			return;
		}

		switch ( $action ) {

			case 'create-invoice':
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( esc_html__( 'Current user does not have the permission to create invoices.', 'rma-wc' ), '', array( 'response' => 403 ) );
				}

				unset( $_GET['paged'] );

				$selected_invoice_ids = ! empty( $_POST['invoice_id'] )
					? array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) $_POST['invoice_id'] ) )
					: array();

				if ( ! empty( $selected_invoice_ids ) && ! empty( $this->current_page_groups ) ) {
					$groups_to_hydrate = array_values(
						array_filter(
							$this->current_page_groups,
							static function( array $group ) use ( $selected_invoice_ids ): bool {
								return in_array( $group['invoice_id'] ?? '', $selected_invoice_ids, true );
							}
						)
					);
					if ( ! empty( $groups_to_hydrate ) ) {
						$collective    = new RMA_WC_Collective_Invoicing();
						$this->items   = $collective->build_display_invoices_for_groups( $groups_to_hydrate );
					}
				}

				$this->execution_mode = 'confirm';
				add_action( 'admin_table_notices', array( $this, 'admin_notice_confirm_step' ) );
				return;

			case 'confirm-invoice':
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( esc_html__( 'Current user does not have the permission to create invoices.', 'rma-wc' ), '', array( 'response' => 403 ) );
				}

				$invoice_title_en       = get_option( 'rma_invoice_title_en', self::default_invoice_title() );
				$invoice_description_en = get_option( 'rma_invoice_description_en', '' );
				$invoice_footer_en      = get_option( 'rma_invoice_footer_en', '' );

				$created_invoices = array();
				$this->action_result_counts = array(
					'ok'      => 0,
					'fail'    => 0,
					'skipped' => 0,
				);

				// create xml and send invoice to Run My Accounts.
				$api = new RMA_WC_API();

				foreach ( $this->items as $invoice_id => $current_invoice ) {

					$order_ids = array_column( $current_invoice['data']['part'], 'order_id' );

					// Skip groups that contain an order not invoiced through Run my Accounts.
					$off_route = array_filter(
						array_unique( array_map( 'intval', $order_ids ) ),
						static function ( int $order_id ): bool {
							$order = wc_get_order( $order_id );
							return ! ( $order instanceof WC_Order ) || ! RMA_WC_API::is_rma_invoice_route( $order );
						}
					);
					if ( empty( $order_ids ) || ! empty( $off_route ) ) {
						$this->items[ $invoice_id ]['created_status'] = 'SKIPPED';
						++$this->action_result_counts['skipped'];
						continue;
					}

					$lang      = 'en';
					if ( ! empty( $order_ids ) ) {
						$invoice_order = wc_get_order( (int) $order_ids[0] );
						if ( $invoice_order instanceof WC_Order ) {
							$lang = RMA_WC_Order_Language::get_order_language( $invoice_order );
						}
					}

					$invoice_title = RMA_WC_Order_Language::collective_invoice_option( 'title', $lang );
					if ( '' === $invoice_title ) {
						$invoice_title = $invoice_title_en;
					}

					$invoice_description = RMA_WC_Order_Language::collective_invoice_option( 'description', $lang );
					if ( '' === $invoice_description ) {
						$invoice_description = $invoice_description_en;
					}

					$invoice_footer = RMA_WC_Order_Language::collective_invoice_option( 'footer', $lang );
					if ( '' === $invoice_footer ) {
						$invoice_footer = $invoice_footer_en;
					}

					$current_invoice['data']['invoice']['description']  = $invoice_title;
					$current_invoice['data']['invoice']['text_field_1'] = $invoice_description;
					$current_invoice['data']['invoice']['notes']        = $invoice_footer;

					// $result = false;
					RMA_WC_API::clear_last_invoice_error_message();
					$result = $api->create_xml_content( $current_invoice['data'], $order_ids, true );

					if ( false !== $result ) {
						$created_invoices[]                           = $invoice_id;
						$this->items[ $invoice_id ]['created_status'] = 'OK';
						++$this->action_result_counts['ok'];
					} else {
						$this->items[ $invoice_id ]['created_status'] = 'FAIL';
						$api_error_message = trim( RMA_WC_API::get_last_invoice_error_message() );
						if ( '' !== $api_error_message ) {
							/* translators: %s is the API error returned by Run my Accounts. */
							$this->items[ $invoice_id ]['created_message'] = sprintf( __( 'Invoice creation failed: %s', 'wc-rma' ), $api_error_message );
						}
						++$this->action_result_counts['fail'];
					}
				}

				// were invoices created, and we should send an email?
				if ( 0 < count( $created_invoices ) && SENDLOGEMAIL ) {

					$headers       = array( 'Content-Type: text/html; charset=UTF-8' );
					/* translators: %s is a comma-separated list of collective invoice IDs. */
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

	/**
	 * Whether this request is a POST of the dashboard form with a valid bulk nonce.
	 *
	 * @return bool
	 */
	private function is_valid_dashboard_post(): bool {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['_wpnonce'] ) ) {
			return false;
		}

		return false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bulk-' . $this->_args['plural'] );
	}

	/**
	 * Notice when CONFIRM was submitted without any invoice selected.
	 *
	 * @return void
	 */
	public function admin_notice_no_invoice_selected() {
		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html__( 'No invoice was selected, so no invoice was created.', 'rma-wc' )
		);
	}

	/**
	 * Confirm step: number of selected invoices, what to do next, and the way back to change the selection.
	 *
	 * @return void
	 */
	public function admin_notice_confirm_step() {
		$back_url = add_query_arg(
			array_filter(
				array(
					'page'                  => 'invoice-dashboard',
					'payment-method-filter' => $this->current_payment_method_filter(),
					'language-filter'       => $this->current_language_filter(),
					'boat-rental-filter'    => isset( $_REQUEST['boat-rental-filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['boat-rental-filter'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					's'                     => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				)
			),
			admin_url( 'admin.php' )
		);
		printf(
			'<div class="notice notice-info inline" id="invoice-confirm-step"><p id="selected-invoice-amount"><strong>%1$s</strong> %2$d</p><p>%3$s<br><a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'Selected invoices:', 'rma-wc' ),
			(int) count( $this->items ),
			esc_html__( 'Check the selected invoices, then choose "CONFIRM: Create invoices for selected orders" and click Apply. The selection cannot be changed on this step.', 'rma-wc' ),
			esc_url( $back_url ),
			esc_html__( 'Back to the dashboard to change the selection', 'rma-wc' )
		);
		// WordPress' select-all would untick the disabled (read-only) checkboxes of this step.
		echo '<style>#cb-select-all-1,#cb-select-all-2{display:none}</style>';
	}

	public function enqueue_admin_js( $hook ) {

		if ( 'woocommerce_page_invoice-dashboard' !== $hook ) {
			return;
		}

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
        

		// hide/unhide invoice details (lazy-loaded via AJAX when collapsed)
		jQuery('a.expand-order-details-toggle').click( function(event) {
			event.preventDefault();
			var toggle = jQuery( this );
			var invoiceId = toggle.attr( 'invoice-id' );
			var container = jQuery( 'tr.order-details-container-' + invoiceId );
			var expanded = 'true' === toggle.attr( 'aria-expanded' );

			if ( expanded ) {
				container.hide();
				toggle.attr( 'aria-expanded', 'false' );
				toggle.find( '.order-details-toggle-icon' ).text( '▸' );
				toggle.find( '.order-details-toggle-label' ).text( toggle.attr( 'data-expand-label' ) );
				return;
			}

			var finishExpand = function() {
				container.show();
				toggle.attr( 'aria-expanded', 'true' );
				toggle.find( '.order-details-toggle-icon' ).text( '▾' );
				toggle.find( '.order-details-toggle-label' ).text( toggle.attr( 'data-collapse-label' ) );
				jQuery('input[name=\"order_id[]\"').first().change();
			};

			if ( 'true' === toggle.attr( 'data-details-loaded' ) ) {
				finishExpand();
				return;
			}

			var loadingCell = container.find( '.order-details-loading' );
			loadingCell.show();
			container.show();

			jQuery.post( rmaInvoiceDashboard.ajaxUrl, {
				action: 'rma_invoice_dashboard_group_details',
				nonce: rmaInvoiceDashboard.nonce,
				invoice_id: invoiceId,
				payment_method: toggle.attr( 'data-payment-method' ) || '',
				order_ids: toggle.attr( 'data-order-ids' ) || ''
			}).done( function( response ) {
				if ( ! response || ! response.success ) {
					loadingCell.text( rmaInvoiceDashboard.errorLabel );
					return;
				}
				container.find( '.order-details-parts tbody' ).html( response.data.html );
				if ( response.data.total_html ) {
					toggle.closest( 'tr.row_invoice' ).find( 'td.col_price .invoice_price_total' ).html( response.data.total_html );
				}
				toggle.attr( 'data-details-loaded', 'true' );
				loadingCell.hide();
				finishExpand();
			}).fail( function() {
				loadingCell.text( rmaInvoiceDashboard.errorLabel );
			});
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

		wp_register_script( 'collective_invoice_table_js', false, array( 'jquery' ), 125, true );
		wp_localize_script(
			'collective_invoice_table_js',
			'rmaInvoiceDashboard',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'rma_invoice_dashboard_details' ),
				'errorLabel'   => __( 'Could not load invoice details.', 'rma-wc' ),
			)
		);
		wp_add_inline_script( 'collective_invoice_table_js', $script );
		wp_enqueue_script( 'collective_invoice_table_js' );
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
			if ( 'done' === $this->execution_mode ) {
				printf(
					'<div class="notice notice-info inline"><p><strong>%s</strong> %d | <strong>%s</strong> %d | <strong>%s</strong> %d</p></div>',
					esc_html__( 'Created:', 'wc-rma' ),
					(int) ( $this->action_result_counts['ok'] ?? 0 ),
					esc_html__( 'Failed:', 'wc-rma' ),
					(int) ( $this->action_result_counts['fail'] ?? 0 ),
					esc_html__( 'Skipped:', 'wc-rma' ),
					(int) ( $this->action_result_counts['skipped'] ?? 0 )
				);
			}

			printf(
				'<div class="alignleft actions"><strong>%s</strong> %d | <strong>%s</strong> %d | <strong>%s</strong> %d</div>',
				esc_html__( 'Affected users:', 'wc-rma' ),
				(int) ( $this->summary_counts['affected_users'] ?? 0 ),
				esc_html__( 'Orders:', 'wc-rma' ),
				(int) ( $this->summary_counts['orders'] ?? 0 ),
				esc_html__( 'Groups:', 'wc-rma' ),
				(int) ( $this->summary_counts['groups'] ?? 0 )
			);

			$language_counts = $this->invoice_language_counts();
			if ( ! empty( $language_counts ) ) {
				$parts = array();
				foreach ( $language_counts as $lang => $count ) {
					$parts[] = strtoupper( $lang ) . ' ' . (int) $count;
				}
				printf(
					'<div id="invoice-language-counts"><strong>%s</strong> %s</div>',
					esc_html( 'confirm' === $this->execution_mode ? __( 'Invoice languages (selected):', 'rma-wc' ) : __( 'Invoice languages on this page:', 'rma-wc' ) ),
					esc_html( implode( ' | ', $parts ) )
				);
			}
			$readonly = 'plan' === $this->execution_mode ? '' : 'readonly';

			$invoice_title_en       = get_option( 'rma_invoice_title_en', self::default_invoice_title() );
			$invoice_description_en = get_option( 'rma_invoice_description_en', '' );
			$invoice_footer_en      = get_option( 'rma_invoice_footer_en', '' );

			/*
			 * Only the English texts are saved (see prepare_items()); the DE and FR fields are not stored.
			 * RMA_WC_Order_Language::collective_invoice_option() would already pick per-language texts.
			 * Enable DE/FR only together with handling text changes across languages: an edited English
			 * text must not leave stale German/French versions unnoticed (e.g. stored text templates with
			 * one name and a snippet per language, and a visible "older than English" state).
			 */
			?>
			<nav class="nav-tab-wrapper">
				<a href="#" data-language="en" class="nav-tab nav-tab-en nav-tab-active">English</a>
				<a href="#" data-language="de" class="nav-tab nav-tab-de">Deutsch</a>
				<a href="#" data-language="fr" class="nav-tab nav-tab-fr">Français</a>
			</nav>

			<div class="tab-content">
			<h2>ToDo: Only english works!</h2>
			<div class="nav-tab-en">

			<label>EN Title</label><br>
			<input <?php echo esc_attr( $readonly ); ?> type="text" name = "invoice_title_en" value="<?php echo esc_attr( $invoice_title_en ); ?>"><br>

			<label>EN Header Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice_description_en" cols="80" rows="3"><?php echo esc_textarea( $invoice_description_en ); ?></textarea><br>

			<label>EN Footer Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice_footer_en" cols="80" rows="10"><?php echo esc_textarea( $invoice_footer_en ); ?></textarea><br>
			</div>

			<div hidden class="nav-tab-de">

			<label>DE Title</label><br>
			<input <?php echo esc_attr( $readonly ); ?> type="text" name = "invoice_title_de"><br>

			<label>DE Header Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice_description_de" cols="80" rows="3"></textarea><br>

			<label>DE Footer Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice_footer_de" cols="80" rows="10"></textarea><br>

			</div>

			<div hidden class="nav-tab-fr">

			<label>FR Title</label><br>
			<input <?php echo esc_attr( $readonly ); ?> type="text" name = "invoice_title_fr"><br>

			<label>FR Header Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice_description_fr" cols="80" rows="3"></textarea><br>

			<label>FR Footer Text</label><br>
			<textarea <?php echo esc_attr( $readonly ); ?> id="" name="invoice_footer_fr" cols="80" rows="10"></textarea><br>

			</div>


			</div>


			<?php

			$textarea_value = get_option( 'rma-invoice-user-list-filter', '' );
			?>

			<div id="user-list-text-area">
				<label for="user-list-filter" style="display:block"><?php esc_html_e( 'Filter by customer number (separate several numbers with commas or spaces):', 'rma-wc' ); ?></label>
				<textarea id="user-list-filter" name="user-list-filter" rows="2" style="display:block; width:100%" placeholder="N12345, SCK789, T231"><?php echo esc_textarea( $textarea_value ); ?></textarea>
				<?php submit_button( 'Apply Filter', '', '', false, array( 'id' => 'search-submit' ) ); ?>
			</div>
			<?php

			$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : 'invoice-dashboard';
			echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '" />';

			/** Payment Method Filter: only methods whose orders are invoiced through Run my Accounts. */
			$payment_method_filter = $this->current_payment_method_filter();
			echo '<select style="width:50%;" class="select short payment-method-filter" name="payment-method-filter" data-allow_clear="true">';
			echo '<option class="select short" value="" ' . esc_attr( wc_selected( $payment_method_filter, '' ) ) . '>' . esc_html__( 'All payment methods', 'rma-wc' ) . '</option>';
			foreach ( $this->payment_method_filter_options() as $value => $label ) {
				echo '<option class="select short" value="' . esc_attr( $value ) . '" ' . esc_attr( wc_selected( $payment_method_filter, (string) $value ) ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';

			/** Language Filter: invoice language is the language of the group's first order. */
			$language_filter = $this->current_language_filter();
			$language_counts = (array) ( $this->summary_counts['languages'] ?? array() );
			if ( '' !== $language_filter && ! isset( $language_counts[ $language_filter ] ) ) {
				$language_counts[ $language_filter ] = 0;
			}
			echo '<select style="width:50%;" class="select short language-filter" name="language-filter" id="language-filter">';
			echo '<option class="select short" value="" ' . esc_attr( wc_selected( $language_filter, '' ) ) . '>' . esc_html__( 'All languages', 'rma-wc' ) . '</option>';
			foreach ( $language_counts as $lang => $count ) {
				$lang = (string) $lang;
				echo '<option class="select short" value="' . esc_attr( $lang ) . '" ' . esc_attr( wc_selected( $language_filter, $lang ) ) . '>' . esc_html( sprintf( '%1$s (%2$d)', strtoupper( $lang ), (int) $count ) ) . '</option>';
			}
			echo '</select>';

			/** Boat Rental Filter */
			$boat_rental_filter = isset( $_REQUEST['boat-rental-filter'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['boat-rental-filter'] ) ) : '';
			echo '<select style="width:50%;" class="select short boat-rental-filter" name="boat-rental-filter" id="boat-rental-filter" data-allow_clear="true">';
			echo '<option class="select short" value="" ' . esc_attr( wc_selected( $boat_rental_filter, '' ) ) . '>' . esc_html__( 'All products', 'rma-wc' ) . '</option>';
			echo '<option class="select short" value="rental-only" ' . esc_attr( wc_selected( $boat_rental_filter, 'rental-only' ) ) . '>' . esc_html__( 'Only rental products', 'rma-wc' ) . '</option>';
			echo '<option class="select short" value="no-rental" ' . esc_attr( wc_selected( $boat_rental_filter, 'no-rental' ) ) . '>' . esc_html__( 'Exclude rental products', 'rma-wc' ) . '</option>';
			echo '</select>';

			$this->search_box( 'Search', 'customer-search' );
			$this->views();

		}
		if ( 'bottom' === $which ) {
			if ( 'done' === $this->execution_mode ) {
				// Show the log.
				echo '<div>';
				echo wp_kses_post( RMA_WC_API::format_log_information( RMA_WC_API::$temporary_log ) );
				echo '</div>';
			}
			// $this->show_action_form();<form
		}
	}

	/**
	 * Payment-method filter choices: methods on the Run my Accounts route, plus orders without one
	 * when those are on that route too.
	 *
	 * @return array<string,string> Filter value => label.
	 */
	private function payment_method_filter_options(): array {
		if ( null !== $this->payment_method_filter_options ) {
			return $this->payment_method_filter_options;
		}

		$options = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			$id = (string) $id;
			if ( RMA_WC_API::INVOICE_ROUTE_RMA !== RMA_WC_API::payment_method_invoice_route( $id ) ) {
				continue;
			}
			$title          = $gateway instanceof WC_Payment_Gateway ? wp_strip_all_tags( (string) $gateway->get_method_title() ) : '';
			$options[ $id ] = '' === $title ? $id : sprintf( '%1$s (%2$s)', $title, $id );
		}
		if ( RMA_WC_API::INVOICE_ROUTE_RMA === RMA_WC_API::payment_method_invoice_route( '' ) ) {
			$options['no-payment-method'] = __( 'No payment method', 'rma-wc' );
		}

		$this->payment_method_filter_options = $options;

		return $options;
	}

	/**
	 * Requested payment-method filter, or '' (all) when it is not one of the offered choices.
	 *
	 * @return string
	 */
	private function current_payment_method_filter(): string {
		$value = isset( $_REQUEST['payment-method-filter'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['payment-method-filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array_key_exists( $value, $this->payment_method_filter_options() ) ? $value : '';
	}

	/**
	 * Requested invoice language (e.g. en, de, fr, pt-br), or '' (all).
	 *
	 * @return string
	 */
	private function current_language_filter(): string {
		$value = isset( $_REQUEST['language-filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['language-filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 1 === preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $value ) ? $value : '';
	}

	/**
	 * Default title for collective invoices when none was saved on the dashboard.
	 *
	 * @return string
	 */
	private static function default_invoice_title(): string {
		return (string) apply_filters( 'rma_wc_default_invoice_title', __( 'Invoice', 'rma-wc' ) );
	}

	/**
	 * Invoices per language: the language of each group's first order, as used when the invoice is created.
	 *
	 * In confirm mode only the selected invoices count; otherwise all groups on the current page.
	 *
	 * @return array<string,int> Language code => number of invoices, sorted by code.
	 */
	private function invoice_language_counts(): array {
		$counts = array();
		foreach ( $this->current_page_groups as $group ) {
			if ( 'confirm' === $this->execution_mode && ! isset( $this->items[ $group['invoice_id'] ?? '' ] ) ) {
				continue;
			}
			$lang            = (string) ( $group['language'] ?? '' );
			$counts[ $lang ] = ( $counts[ $lang ] ?? 0 ) + 1;
		}
		ksort( $counts );

		return $counts;
	}

	protected function get_views() {

		$view = ! empty( $_REQUEST['view'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$status_links = array(
			'all'    => '<a class="' . ( 'all' === $view ? 'current' : '' ) . '" href="' . esc_url( add_query_arg( 'view', 'all' ) ) . '">' . esc_html__( 'All', 'wc-rma' ) . '(' . (int) $this->all_items_count . ')</a>',
			'errors' => '<a class="' . ( 'errors' === $view ? 'current' : '' ) . '" href="' . esc_url( add_query_arg( 'view', 'errors' ) ) . '">' . esc_html__( 'Errors', 'wc-rma' ) . '(' . count( $this->errors ) . ')</a>',
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
			'col_customer_number' => 'customer_number',
			'col_due_date'        => 'due_date',
			'col_price'           => 'price',
		);
	}

	/**
	 * Prepare the table with different parameters, pagination, columns and table elements
	 */
	public function prepare_items() {

		// Saved texts and the user filter only change through a valid dashboard POST.
		$can_save = $this->is_valid_dashboard_post() && current_user_can( 'manage_woocommerce' );

		// English only; see the note above the text tabs in extra_tablenav() before adding DE/FR.
		foreach ( array( 'invoice_title_en', 'invoice_description_en', 'invoice_footer_en' ) as $o ) {
			if ( $can_save && isset( $_POST[ $o ] ) ) {
				update_option( 'rma_' . $o, sanitize_text_field( wp_unslash( $_POST[ $o ] ) ) );
			}
		}

		// Filter by key list.
		$user_list_filter_string = (string) get_option( 'rma-invoice-user-list-filter', '' );
		if ( $can_save && isset( $_POST['user-list-filter'] ) ) {
			$user_list_filter_string = trim( sanitize_text_field( wp_unslash( $_POST['user-list-filter'] ) ) );
			$user_list_filter_string = str_replace( ' ', ',', $user_list_filter_string );
			$user_list_filter_string = str_replace( ',,', ',', $user_list_filter_string );
			update_option( 'rma-invoice-user-list-filter', $user_list_filter_string );
		}
		$user_list_filter = empty( $user_list_filter_string ) ? array() : explode( ',', $user_list_filter_string );

		if ( empty( $user_list_filter ) ) {
			$user_id_filter = array();
		} else {
			$customer_query = new \WP_User_Query(
				array(
					'fields'     => 'ID',
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'key'     => 'rma_customer',
							'compare' => 'IN',
							'value'   => $user_list_filter,
						),
					),
				)
			);
			$user_id_filter = $customer_query->get_results();
		}

		$payment_method_filter = $this->current_payment_method_filter();
		$groups_per_page       = max( 1, (int) $this->get_items_per_page( 'invoice_dashboard_groups_per_page', 20 ) );
		$current_page          = max( 1, $this->get_pagenum() );
		$filter                = ! empty( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$t         = new RMA_WC_Collective_Invoicing();
		$plan_data = $t->get_dashboard_group_plan( $user_id_filter, $groups_per_page, $payment_method_filter, $filter, $this->current_language_filter() );
		$this->summary_counts  = $plan_data['totals'];
		$this->all_items_count = (int) $plan_data['totals']['groups'];

		$this->set_pagination_args(
			array(
				'total_items' => (int) $plan_data['totals']['groups'],
				'per_page'    => $groups_per_page,
				'total_pages' => max( 1, (int) $plan_data['totals']['pages'] ),
			)
		);

		$current_page_groups = array_values(
			array_filter(
				$plan_data['groups'],
				static function( array $group ) use ( $current_page ): bool {
					return (int) $group['page_number'] === $current_page;
				}
			)
		);
		$this->current_page_groups = $current_page_groups;

		$boat_rental_filter = isset( $_REQUEST['boat-rental-filter'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['boat-rental-filter'] ) ) : '';
		$bulk_action        = $this->current_action();
		$use_lazy_details   = ( '' === $boat_rental_filter && 'plan' === $this->execution_mode && 'confirm-invoice' !== $bulk_action );

		// Build only the selected invoice groups.
		$invoice_ids = ! empty( $_POST['invoice_id'] ) ? array_map( 'esc_attr', array_map( 'sanitize_text_field', wp_unslash( $_POST['invoice_id'] ) ) ) : array();
		$groups_to_build = $current_page_groups;
		if ( ! empty( $invoice_ids ) ) {
			$groups_to_build = array_values(
				array_filter(
					$current_page_groups,
					static function( array $group ) use ( $invoice_ids ): bool {
						return in_array( $group['invoice_id'] ?? '', $invoice_ids, true );
					}
				)
			);
			unset( $_GET['paged'] );
			unset( $_REQUEST['paged'] );
		}

		if ( $use_lazy_details ) {
			$display_data = $t->build_display_invoice_summaries_for_groups( $groups_to_build );
		} else {
			$display_data = $t->build_display_invoices_for_groups( $groups_to_build );
		}

		// Payment method filter is already applied in first pass.

		// filter product class (all/non-rental/rental).
		if ( '' !== $boat_rental_filter ) {
			$display_data = array_filter(
				array_map(
					function( $item ) use ( $boat_rental_filter ) {
						$settings = get_option( 'wc_rma_settings' );
						$rental_booking_article     = $settings['rma-product-rnb-rental-article'];
						$rental_cancelation_article = $settings['rma-product-rnb-cancelation-article'];
						$rental_sku                  = array( $rental_booking_article, $rental_cancelation_article );
						switch ( $boat_rental_filter ) {
							case 'rental-only':
								$data                 = array_filter( $item['data']['part'], fn( $part ) => in_array( $part['partnumber'], $rental_sku, true ) );
								$item['data']['part'] = $data;
								break;
							case 'no-rental':
								$data                 = array_filter( $item['data']['part'], fn( $part ) => ! in_array( $part['partnumber'], $rental_sku, true ) );
								$item['data']['part'] = $data;
								break;
							default:
								wp_die( 'Invalid boat rental filter: ' . esc_attr( $boat_rental_filter ) . ' !' );
								break;
						}
						$order_ids         = array_column( $item['data']['part'], 'order_id' );
						$item['order_ids'] = $order_ids;

						return empty( $order_ids ) ? false : $item;
					},
					$display_data,
				),
			);
		}

		// Find errors.
		foreach ( $display_data as $item ) {
			$customernumber = $item['data']['invoice']['customernumber'];
			if ( empty( $customernumber ) ) {
				$this->errors[] = $item;
			}
		}

		// If view=errors then only show those.
		$view = ! empty( $_REQUEST['view'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['view'] ) ) : '';
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
		$orderby = ! empty( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : '';
		$order   = ! empty( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'ASC';
		if ( ! empty( $orderby ) & ! empty( $order ) ) {
			$display_data = wp_list_sort( $display_data, $orderby, $order, false );

		}

		$this->items = $display_data;

		$this->process_bulk_action();
	}

	public function column_col_creation_status( $item ) {
		echo esc_html( (string) ( $item['created_status'] ?? '--X--' ) );
		if ( ! empty( $item['created_message'] ) ) {
			echo '<br><small>' . esc_html( (string) $item['created_message'] ) . '</small>';
		}
	}


	public function column_col_invoice_id( $item ) {
		$payment_method = $item['data']['invoice']['paymentmethod'] ?? '';
		if ( empty( $payment_method ) ) {
			$payment_method = '<no payment method>';
		}
		echo esc_html( stripslashes( $payment_method ) );
		echo '<br>';
		
		$invoice_id = $item['data']['invoice']['invnumber'];
		echo esc_html( stripslashes( $invoice_id ) );

		$order_count = count( $item['order_ids'] ?? array() );
		if ( 0 === $order_count ) {
			$order_count = count( $item['data']['part'] ?? array() );
		}
		/* translators: %d is the number of orders contained in an invoice group. */
		$expand_label = sprintf( __( 'Details (%d)', 'wc-rma' ), $order_count );
		/* translators: %d is the number of orders contained in an invoice group. */
		$collapse_label = sprintf( __( 'Hide details (%d)', 'wc-rma' ), $order_count );
		printf(
			'<div class="order-details-toggle-wrap" style="margin-top:2px;"><a href="#" class="expand-order-details-toggle" style="display:inline-block;white-space:nowrap;" invoice-id="%1$s" data-order-ids="%2$s" data-payment-method="%3$s" data-details-loaded="%4$s" aria-expanded="false" data-expand-label="%5$s" data-collapse-label="%6$s"><span class="order-details-toggle-icon" aria-hidden="true">▸</span> <span class="order-details-toggle-label">%7$s</span></a></div>',
			esc_attr( $invoice_id ),
			esc_attr( implode( ',', array_map( 'intval', $item['order_ids'] ?? array() ) ) ),
			esc_attr( (string) ( $item['data']['invoice']['paymentmethod'] ?? '' ) ),
			! empty( $item['details_loaded'] ) ? 'true' : 'false',
			esc_attr( $expand_label ),
			esc_attr( $collapse_label ),
			esc_html( $expand_label )
		);
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
					// Get the user link directly without costly permission checks.
					esc_url( apply_filters( 'get_edit_user_link', add_query_arg( 'user_id', $item['user_id'], self_admin_url( 'user-edit.php' ) ), $item['user_id'] ) ),
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
		if ( isset( $item['summary_total'] ) && empty( $item['details_loaded'] ) ) {
			echo '<div class="invoice_price_total">' . wp_kses_post( wc_price( (float) $item['summary_total'] ) ) . '</div>' . PHP_EOL;
			return;
		}

		echo '<div class="invoice_price_total">' . wp_kses_post( wc_price( $this->get_invoice_parts_total( $item ) ) ) . '</div>' . PHP_EOL;
	}

	/**
	 * Sum sell prices from invoice line items.
	 *
	 * @param array $item Invoice row.
	 * @return float
	 */
	public function get_invoice_parts_total( array $item ): float {
		$total_amount = 0.0;
		foreach ( $item['data']['part'] ?? array() as $part ) {
			$total_amount += (float) ( $part['sellprice'] ?? 0 );
		}
		return $total_amount;
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
				'<input type="checkbox" data-invoice_id="%1$s" name="invoice_id[]" value="%1$s" />',
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

		if ( ! empty( $item['details_loaded'] ) ) {
			echo PHP_EOL . '<tr class="no-items order-details-container-' . esc_attr( $invoice_id ) . '">' . PHP_EOL;
			echo '<td class="colspanchange" colspan="' . ( $this->get_column_count() ) . '">' . PHP_EOL;
			echo '<table class="widefat fixed order-details-parts" cellspacing="0">' . PHP_EOL;
			echo '<tbody>' . PHP_EOL;
			echo $this->get_order_details_html( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tbody>' . PHP_EOL;
			echo '</table>' . PHP_EOL;
			echo '</td>' . PHP_EOL;
			echo '</tr>' . PHP_EOL;
			return;
		}

		echo PHP_EOL . '<tr class="no-items order-details-container-' . esc_attr( $invoice_id ) . '" style="display:none">' . PHP_EOL;
		echo '<td class="colspanchange" colspan="' . ( $this->get_column_count() ) . '">' . PHP_EOL;
		echo '<p class="order-details-loading" style="display:none;margin:0.5em 0;">' . esc_html__( 'Loading…', 'rma-wc' ) . '</p>' . PHP_EOL;
		echo '<table class="widefat fixed order-details-parts" cellspacing="0">' . PHP_EOL;
		echo '<tbody></tbody>' . PHP_EOL;
		echo '</table>' . PHP_EOL;
		echo '</td>' . PHP_EOL;
		echo '</tr>' . PHP_EOL;
	}

	/**
	 * Render invoice line-item rows HTML for one group.
	 *
	 * @param array $item Invoice row.
	 * @return string
	 */
	public function get_order_details_html( array $item ): string {
		ob_start();

		$invoice_id = $item['data']['invoice']['invnumber'];
		$alternate  = false;

		foreach ( $item['data']['part'] as $part ) {

			$description = preg_replace( '[&#xA;|&#xD;]', '<br/>', $part['description'] );
			$order_id    = isset( $part['order_id'] ) ? (int) $part['order_id'] : 0;
			if ( $order_id > 0 ) {
				$order_edit_link = get_edit_post_link( $order_id, '' );
				if ( ! empty( $order_edit_link ) ) {
					$order_number_label = '#' . $order_id;
					$order_number_link  = sprintf(
						'<a href="%s" target="_blank">%s</a>',
						esc_url( $order_edit_link ),
						esc_html( $order_number_label )
					);
					$description = preg_replace(
						'/\#' . preg_quote( (string) $order_id, '/' ) . '\b/',
						$order_number_link,
						$description,
						1
					);
				}
			}

			printf(
				'<tr class="%s order-details-%s">',
				$alternate ? 'alternate' : '',
				esc_attr( $invoice_id )
			);

			$alternate = ! $alternate;

			echo "<td class='column-partnumber'>" . esc_html( $part['partnumber'] ) . '</td>' . PHP_EOL;
			echo "<td class='column-description'>" . wp_kses_post( $description ) . '</td>' . PHP_EOL;
			echo "<td class='column-projectnumber'>" . esc_html( $part['projectnumber'] ?? '' ) . '</td>' . PHP_EOL;
			echo '<td class="column-sellprice">';
			echo '<div>' . wp_kses_post( wc_price( $part['sellprice'] ) ) . '</div>';
			echo '</td>' . PHP_EOL;
			echo '</tr>' . PHP_EOL;
		}

		return (string) ob_get_clean();
	}
}
