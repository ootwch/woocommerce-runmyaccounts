<?php
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined('ABSPATH')) exit;

/**
 * Class for reporting on the invoice status
 *
 * @since 1.7.0
 */
class RMA_WC_Invoice {

	private $settings;

	public function __construct() {
        add_action( 'admin_init', array($this, 'admin_init_hooks'));
        add_action( 'init', array($this, 'init_hooks'));

		add_action( 'wp_head', array( $this, 'check_invoices' ) );

		// update order status once per hour and display it on the admin and user order overview.
		add_action( 'init', array( $this, 'maybe_create_schedule_update_invoice_status_event' ) );
		add_action( 'update_invoice_status', array( $this, 'hourly_update_invoice_status' ) );
		add_action( 'update_invoice_status_continue', array( $this, 'hourly_update_invoice_status' ) );
	}



	public function admin_init_hooks() {

		add_action( 'admin_notices', array( $this, 'render_status_sync_notice' ) );

				// Add invoice column to order list.
		$is_hpos_enabled = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
		if ( ! $is_hpos_enabled ) {

			// add status column to order page
                    add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_status_column_to_order_table' ) , 20);
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'add_status_value_to_order_table_row' ), 10, 2 );

			// allow filtering for order invoice status
			add_action( 'restrict_manage_posts', array( $this, 'filter_status_column' ), 25, 2 );
			add_action( 'pre_get_posts', array( $this, 'filter_orders_legacy' ), 25, 2 ); // Legacy.

		} else {
			// HPOS Hooks.

			// add status column to order page
                    add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_status_column_to_order_table' ) , 20);
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'add_status_value_to_order_table_row' ), 10, 2 );

			// allow filtering for order invoice status
			add_action( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_orders_hpos' ), 25, 2 ); // HPOS.
			add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'filter_status_column' ), 25, 2 );

		}

              


	}

	public function init_hooks() {

			// Allow order query by invoice number (init so cron can use wc_get_orders).
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'handle_invoice_number_query_var' ), 10, 2 );
			add_filter( 'woocommerce_order_query_args', array( $this, 'handle_invoice_number_query_var_hpos' ), 10, 1 );

			// User profile invoice info

				// Add endpoints
				add_rewrite_endpoint( 'invoices', EP_ROOT | EP_PAGES );
				add_filter( 'query_vars', array( $this, 'my_invoices_query_vars' ) );
				// Menu entry
				add_filter( 'woocommerce_account_menu_items', array( $this, 'my_invoices_my_account_menu_items' ) );
				add_action( 'woocommerce_account_invoices_endpoint', array( $this, 'my_invoices_endpoint_content' ) );
				// Template for invoice list
				add_filter( 'theme_page_templates', array( $this, 'add_account_invoices_template' ) );
				// PDF Download
				add_action( 'template_redirect', array( $this, 'invoice_pdf_download' ) );
	}


	/**
	 * Checks the invoices for the currently logged-in user and adds notices for overdue or unpaid invoices.
	 *
	 * This function retrieves the invoices for the logged-in user from the transient cache or fetches them via the RMA_WC_API if not cached.
	 * It then filters the invoices to identify those that are overdue or unpaid and adds appropriate notices to the WooCommerce session.
	 *
	 * @return string|null Returns 'overdue' if there are overdue invoices, 'unpaid' if there are unpaid invoices, or null if all invoices are paid.
	 */
	public function check_invoices() {

		if ( did_action( 'woocommerce_init' ) === 1 && is_user_logged_in() && isset( WC()->session ) && WC()->session->has_session() ) {

			$warning_icon = '<span class="dashicons dashicons-warning"></span>';
			$invoice_url  = wc_get_endpoint_url( 'invoices', '', get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) );
			// translators: %s = url.
			$overdue_message = '<span color="red">' . $warning_icon . sprintf( __( 'You have overdue <a href="%s">invoices</a>', 'rma-wc' ), $invoice_url ) . '</span>';

			// translators: %s = url.
			$unpaid_message = sprintf( __( 'You have unpaid <a href="%s">invoices</a>', 'rma-wc' ), $invoice_url );

			// Only set the message once.
			if ( wc_has_notice( $overdue_message, 'notice' ) || wc_has_notice( $unpaid_message, 'notice' ) ) {
				return;
			}

			$user_id         = get_current_user_id();
			$rma_customer_id = get_user_meta( $user_id, 'rma_customer', true );

			if ( ! empty( $rma_customer_id ) ) {

				$invoices = get_transient( RMA_WC_API::customer_invoices_transient_key( $rma_customer_id ) );
				if ( false === $invoices ) {
					$RMA_WC_API = new RMA_WC_API();
					$invoices   = $RMA_WC_API->get_customer_invoices( $rma_customer_id );
					unset( $RMA_WC_API );
					if ( false === $invoices ) {
						return;
					}
					set_transient( RMA_WC_API::customer_invoices_transient_key( $rma_customer_id ), $invoices, HOUR_IN_SECONDS );
				}

				$overdue_invoices = array_filter(
					$invoices['invoice'],
					function( $i ) {
						return 'OVERDUE' === $i['status'] && 0 < $i['amount'];
					}
				);

				$unpaid_invoices = array_filter(
					$invoices['invoice'],
					function( $i ) {
						return 'PAID' !== $i['status'] && 0 < $i['amount'];
					}
				);

				if ( ! empty( $overdue_invoices ) ) {
					wc_add_notice( $overdue_message, 'notice' );
					return 'overdue';

				} elseif ( ! empty( $unpaid_invoices ) ) {
					// translators: %s = url.
					wc_add_notice( $unpaid_message, 'notice' );
					return 'unpaid';

				}
			}
		}
	}


	/**
	 * Allow querying orders by invoice number.
	 *
	 * https://github.com/woocommerce/woocommerce/wiki/wc_get_orders-and-WC_Order_Query#adding-custom-parameter-support
	 * Handle a custom 'customvar' query var to get orders with the 'customvar' meta.
	 *
	 * @param array $query - Args for WP_Query.
	 * @param array $query_vars - Query vars from WC_Order_Query.
	 * @return array modified $query
	 */
	function handle_invoice_number_query_var( $query, $query_vars ) {
		if ( ! empty( $query_vars['invoice_number'] ) ) {
			if ( ! isset( $query['meta_query'] ) ) {
				$query['meta_query'] = array();
			}
			$query['meta_query'][] = array(
				'key'   => '_rma_invoice',
				'value' => $query_vars['invoice_number'],
			);
		}

		return $query;
	}

	/**
	 * Allow querying orders by invoice number (HPOS).
	 *
	 * @param array $query_args Query args from WC_Order_Query.
	 * @return array Modified query args.
	 */
	function handle_invoice_number_query_var_hpos( $query_args ) {
		if ( ! empty( $query_args['invoice_number'] ) ) {
			if ( ! isset( $query_args['meta_query'] ) ) {
				$query_args['meta_query'] = array();
			}
			$query_args['meta_query'][] = array(
				'key'   => '_rma_invoice',
				'value' => $query_args['invoice_number'],
			);
			unset( $query_args['invoice_number'] );
		}

		return $query_args;
	}

		/**
		 * Create scheduler for hourly order status update.
		 * Schedules the event if it's NOT already scheduled.
		 */
        public function maybe_create_schedule_update_invoice_status_event( ) {
            if ( ! wp_next_scheduled ( 'update_invoice_status' ) ) {
			wp_schedule_event( time(), 'hourly', 'update_invoice_status' );
		}
	}

		/**
		 * Option holding an in-progress status download and the next order page.
		 */
		const STATUS_SYNC_STATE = 'rma_wc_invoice_status_sync_state';

		/**
		 * Option used as a lock so a second cron tick does not apply the same page.
		 */
		const STATUS_SYNC_LOCK = 'rma_wc_invoice_status_sync_lock';

		/**
		 * Option holding the last sync outcome: completed_at, failed_at, error.
		 */
		const STATUS_SYNC_RESULT = 'rma_wc_invoice_status_sync_result';

		/**
		 * Seconds after the last successful sync before the orders screen warns. The job runs hourly.
		 */
		const STATUS_SYNC_STALE_AFTER = 2 * HOUR_IN_SECONDS;

		/**
		 * Seconds between runs that download every invoice instead of only the unpaid window.
		 */
		const STATUS_SYNC_FULL_EVERY = DAY_IN_SECONDS;

		/**
		 * Seconds before retrying after Run my Accounts failed to answer.
		 */
		const STATUS_SYNC_RETRY_AFTER = 15 * MINUTE_IN_SECONDS;

		/**
		 * Orders written per page. Small enough that one page finishes if the process is stopped.
		 */
		const STATUS_SYNC_PAGE_SIZE = 40;

		/**
		 * Seconds spent writing statuses in one cron request, after the API download.
		 *
		 * WordPress treats the cron lock as expired after 60 seconds. Staying under that
		 * for the write loop, then continuing on the next tick, avoids a mix of fresh and
		 * old statuses when the request is stopped.
		 */
		const STATUS_SYNC_APPLY_BUDGET = 40;

		/**
		 * Fetch invoice statuses and write them onto matching orders.
		 *
		 * A failed download does not change stored statuses. Writing is resumable: each
		 * request updates one or more pages, saves the next page, and schedules
		 * `update_invoice_status_continue` when work remains.
		 *
		 * @return void
		 */
		public function hourly_update_invoice_status() {
			if ( ! $this->acquire_status_sync_lock() ) {
				// The holder may be killed before it can schedule the next tick. Keep one queued.
				if ( 'update_invoice_status_continue' === current_action() ) {
					$this->schedule_status_sync_continuation();
				}
				return;
			}

			try {
				$this->run_invoice_status_sync();
			} finally {
				$this->release_status_sync_lock();
			}
		}

		/**
		 * Download statuses when needed, then apply them in short pages.
		 *
		 * @return void
		 */
		private function run_invoice_status_sync() {
			// The HTTP client that hits wp-cron.php may disconnect while Run my Accounts is still responding.
			ignore_user_abort( true );
			$started_at = microtime( true );

			$state = $this->load_status_sync_state();
			if ( null === $state ) {
				$full = ( time() - self::get_status_sync_result()['full_at'] ) > self::STATUS_SYNC_FULL_EVERY;
				$from = $full ? '1900-01-01' : $this->oldest_unpaid_invoice_date();
				if ( null === $from ) {
					// Every invoiced order is already PAID; the daily full run still catches reversals.
					$this->record_status_sync(
						array(
							'completed_at' => time(),
							'error'        => '',
						)
					);
					return;
				}

				$this->maybe_extend_cron_lock( $started_at );
				$statuses = $this->read_invoice_status_map( $from );
				if ( null === $statuses ) {
					$error = RMA_WC_API::$last_invoice_status_error;
					$this->record_status_sync(
						array(
							'failed_at' => time(),
							'error'     => '' !== $error ? wp_html_excerpt( $error, 300, '…' ) : __( 'Invoice Status could not be read.', 'rma-wc' ),
						)
					);
					$this->schedule_status_sync_continuation( self::STATUS_SYNC_RETRY_AFTER );
					return;
				}
				$state = array(
					'statuses' => $statuses,
					'page'     => 1,
					'started'  => time(),
					'full'     => $full,
				);
				$this->save_status_sync_state( $state );
			}

			// Queued before any writes so a stopped request resumes instead of waiting for the next hour.
			$this->schedule_status_sync_continuation();

			if ( ! function_exists( 'wc_get_orders' ) ) {
				return;
			}

			$apply_deadline = time() + self::STATUS_SYNC_APPLY_BUDGET;
			$hard_deadline  = (int) $started_at + 240;

			while ( time() < $apply_deadline && time() < $hard_deadline ) {
				$this->touch_status_sync_lock();
				$this->maybe_extend_cron_lock( $started_at );
				$found = $this->apply_invoice_status_page( $state['statuses'], (int) $state['page'] );
				if ( $found < 0 ) {
					$this->schedule_status_sync_continuation();
					return;
				}
				if ( $found < self::STATUS_SYNC_PAGE_SIZE ) {
					delete_option( self::STATUS_SYNC_STATE );
					$this->clear_status_sync_continuation();
					$result = array(
						'completed_at' => time(),
						'error'        => '',
					);
					if ( ! empty( $state['full'] ) ) {
						$result['full_at'] = (int) $state['started'];
					}
					$this->record_status_sync( $result );
					return;
				}
				$state['page'] = (int) $state['page'] + 1;
				$this->save_status_sync_state( $state );
			}

			$this->schedule_status_sync_continuation();
		}

		/**
		 * Earliest date an unpaid invoice can have, or null when no invoiced order is unpaid.
		 *
		 * An invoice is created after its order, so the order creation date bounds the
		 * invoice date from below. One day of margin covers timezone differences.
		 *
		 * @return string|null yyyy-MM-dd.
		 */
		private function oldest_unpaid_invoice_date() {
			if ( ! function_exists( 'wc_get_orders' ) ) {
				return '1900-01-01';
			}

			$ids = wc_get_orders(
				array(
					'limit'      => 1,
					'orderby'    => 'date',
					'order'      => 'ASC',
					'status'     => 'any',
					'return'     => 'ids',
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'key'     => '_rma_invoice',
							'compare' => 'EXISTS',
						),
						array(
							'relation' => 'OR',
							array(
								'key'     => '_rma_invoice_status',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => '_rma_invoice_status',
								'value'   => 'PAID',
								'compare' => '!=',
							),
						),
					),
				)
			);

			if ( ! is_array( $ids ) ) {
				return '1900-01-01';
			}
			if ( empty( $ids ) ) {
				return null;
			}

			$order   = wc_get_order( reset( $ids ) );
			$created = $order ? $order->get_date_created() : null;
			if ( ! $created ) {
				return '1900-01-01';
			}

			return gmdate( 'Y-m-d', $created->getTimestamp() - DAY_IN_SECONDS );
		}

		/**
		 * Read invoice statuses from Run my Accounts. Returns null and logs when the call fails.
		 *
		 * @param string $from yyyy-MM-dd lower bound for the invoice date.
		 * @return array<string,string>|null
		 */
		private function read_invoice_status_map( $from ) {
			$api      = new RMA_WC_API();
			$statuses = $api->get_invoice_status( $from );
			unset( $api );

			if ( ! is_array( $statuses ) ) {
				$log_values = array(
					'status'     => 'error',
					'section_id' => '',
					'section'    => esc_html_x( 'Get Invoice Status', 'Log Section', 'rma-wc' ),
					'mode'       => RMA_WC_API::rma_mode(),
					'message'    => esc_html_x( 'Invoice Status could not be read.', 'Log Section', 'rma-wc' ),
				);

				RMA_WC_API::write_log( $log_values );
				return null;
			}

			return $statuses;
		}

		/**
		 * Write one page of order statuses.
		 *
		 * The order query asks only for orders that have `_rma_invoice`. Each order is
		 * still checked: the stored invoice number must be a key in the fetched map.
		 * Orders that do not match are left unchanged.
		 *
		 * @param array<string,string> $statuses Invoice number => status.
		 * @param int                  $page     1-based page.
		 * @return int Number of orders read, or -1 when the query failed.
		 */
		private function apply_invoice_status_page( array $statuses, $page ) {
			$orders = wc_get_orders(
				array(
					'limit'      => self::STATUS_SYNC_PAGE_SIZE,
					'page'       => max( 1, (int) $page ),
					'orderby'    => 'id',
					'order'      => 'ASC',
					'status'     => 'any',
					'return'     => 'objects',
					'meta_query' => array(
						array(
							'key'     => '_rma_invoice',
							'compare' => 'EXISTS',
						),
					),
				)
			);

			if ( ! is_array( $orders ) ) {
				return -1;
			}

			foreach ( $orders as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'update_meta_data' ) ) {
					continue;
				}

				$status = RMA_WC_API::matching_invoice_status( $order->get_meta( '_rma_invoice', true ), $statuses );
				if ( null === $status ) {
					continue;
				}

				$status = sanitize_text_field( $status );
				if ( (string) $order->get_meta( '_rma_invoice_status', true ) === $status ) {
					continue;
				}

				$order->update_meta_data( '_rma_invoice_status', $status );
				$order->update_meta_data( '_rma_invoice_status_timestamp', current_datetime()->format( 'c' ) );
				$order->save_meta_data();
			}

			return count( $orders );
		}

		/**
		 * In-progress sync, or null when a new download is required.
		 *
		 * @return array<string,mixed>|null
		 */
		private function load_status_sync_state() {
			$state = get_option( self::STATUS_SYNC_STATE, array() );
			if ( ! is_array( $state ) || empty( $state['statuses'] ) || ! is_array( $state['statuses'] ) ) {
				return null;
			}

			$started = isset( $state['started'] ) ? (int) $state['started'] : 0;
			if ( $started <= 0 || ( time() - $started ) > 6 * HOUR_IN_SECONDS ) {
				delete_option( self::STATUS_SYNC_STATE );
				return null;
			}

			$state['page'] = max( 1, (int) ( isset( $state['page'] ) ? $state['page'] : 1 ) );
			return $state;
		}

		/**
		 * Persist the status map and the next page. Not autoloaded.
		 *
		 * @param array<string,mixed> $state Sync state.
		 * @return void
		 */
		private function save_status_sync_state( array $state ) {
			update_option( self::STATUS_SYNC_STATE, $state, false );
		}

		/**
		 * @return bool True when this request holds the lock.
		 */
		private function acquire_status_sync_lock() {
			$now = time();
			if ( add_option( self::STATUS_SYNC_LOCK, $now, '', false ) ) {
				return true;
			}

			$started = (int) get_option( self::STATUS_SYNC_LOCK, 0 );
			if ( $started > 0 && ( $now - $started ) < 5 * MINUTE_IN_SECONDS ) {
				return false;
			}

			update_option( self::STATUS_SYNC_LOCK, $now, false );
			return true;
		}

		/**
		 * Keep the lock from looking abandoned while a page is still being written.
		 *
		 * @return void
		 */
		private function touch_status_sync_lock() {
			update_option( self::STATUS_SYNC_LOCK, time(), false );
		}

		/**
		 * @return void
		 */
		private function release_status_sync_lock() {
			delete_option( self::STATUS_SYNC_LOCK );
		}

		/**
		 * Slide the WordPress cron lock forward once this request has run for a while.
		 *
		 * The lock value is the start time. Another wp-cron.php request starts when that
		 * time is more than WP_CRON_LOCK_TIMEOUT seconds ago. Updating the value keeps
		 * the next tick from applying statuses at the same time. Calls that finish
		 * quickly leave the original lock alone so later events in this request still run.
		 *
		 * @param float $started_at microtime(true) from the start of this sync.
		 * @return void
		 */
		private function maybe_extend_cron_lock( $started_at ) {
			if ( ( microtime( true ) - (float) $started_at ) < 50 ) {
				return;
			}
			if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON || ! defined( 'WP_CRON_LOCK_TIMEOUT' ) ) {
				return;
			}

			set_transient( 'doing_cron', sprintf( '%.22F', microtime( true ) ) );
		}

		/**
		 * Run the rest of the sync on the next cron tick.
		 *
		 * @return void
		 */
		private function schedule_status_sync_continuation( $delay = MINUTE_IN_SECONDS ) {
			if ( ! wp_next_scheduled( 'update_invoice_status_continue' ) ) {
				wp_schedule_single_event( time() + (int) $delay, 'update_invoice_status_continue' );
			}
		}

		/**
		 * @return void
		 */
		private function clear_status_sync_continuation() {
			wp_clear_scheduled_hook( 'update_invoice_status_continue' );
		}

		/**
		 * Merge into the stored sync outcome shown on the orders screen.
		 *
		 * @param array<string,int|string> $changes Keys: completed_at, full_at, failed_at, error.
		 * @return void
		 */
		private function record_status_sync( array $changes ) {
			update_option( self::STATUS_SYNC_RESULT, array_merge( self::get_status_sync_result(), $changes ), false );
		}

		/**
		 * Last sync outcome with defaults.
		 *
		 * @return array{completed_at:int,full_at:int,failed_at:int,error:string}
		 */
		public static function get_status_sync_result() {
			$result = get_option( self::STATUS_SYNC_RESULT, array() );
			if ( ! is_array( $result ) ) {
				$result = array();
			}

			return array(
				'completed_at' => isset( $result['completed_at'] ) ? (int) $result['completed_at'] : 0,
				'full_at'      => isset( $result['full_at'] ) ? (int) $result['full_at'] : 0,
				'failed_at'    => isset( $result['failed_at'] ) ? (int) $result['failed_at'] : 0,
				'error'        => isset( $result['error'] ) && is_string( $result['error'] ) ? $result['error'] : '',
			);
		}

		/**
		 * Whether the orders list is the current admin screen (not the single-order editor).
		 *
		 * @return bool
		 */
		private function is_order_list_screen() {
			if ( ! function_exists( 'get_current_screen' ) ) {
				return false;
			}

			$screen = get_current_screen();
			if ( ! $screen ) {
				return false;
			}

			if ( 'edit-shop_order' === $screen->id ) {
				return true;
			}

			if ( 'woocommerce_page_wc-orders' === $screen->id ) {
				$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
				return ! in_array( $action, array( 'edit', 'new' ), true );
			}

			return false;
		}

		/**
		 * Above the orders list: when statuses were last synced, or a warning when they may be stale.
		 *
		 * @return void
		 */
		public function render_status_sync_notice() {
			if ( ! $this->is_order_list_screen() || ! current_user_can( 'edit_shop_orders' ) ) {
				return;
			}

			$result      = self::get_status_sync_result();
			$now         = time();
			$completed   = $result['completed_at'];
			$failed_at   = $result['failed_at'];
			$in_progress = false !== get_option( self::STATUS_SYNC_STATE, false );
			$failed      = '' !== $result['error'] && $failed_at > $completed;
			$stale       = 0 === $completed || ( $now - $completed ) > self::STATUS_SYNC_STALE_AFTER;
			$format      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

			if ( 0 === $completed ) {
				$parts = array( esc_html__( 'Invoice statuses have not been synced from Run my Accounts yet.', 'rma-wc' ) );
			} elseif ( $stale || $failed ) {
				$parts = array(
					sprintf(
						/* translators: %s: date and time of the last successful sync */
						esc_html__( 'Invoice statuses may be out of date. Last successful sync: %s.', 'rma-wc' ),
						esc_html( wp_date( $format, $completed ) )
					),
				);
			} else {
				$parts = array(
					sprintf(
						/* translators: 1: relative time such as "25 mins", 2: date and time */
						esc_html__( 'Invoice statuses synced from Run my Accounts %1$s ago (%2$s).', 'rma-wc' ),
						esc_html( human_time_diff( $completed, $now ) ),
						esc_html( wp_date( $format, $completed ) )
					),
				);
			}

			if ( $failed ) {
				$parts[] = sprintf(
					/* translators: 1: date and time of the failed attempt, 2: error message */
					esc_html__( 'Last attempt failed at %1$s (%2$s).', 'rma-wc' ),
					esc_html( wp_date( $format, $failed_at ) ),
					esc_html( $result['error'] )
				);
			}

			if ( $in_progress ) {
				$parts[] = esc_html__( 'An update is in progress.', 'rma-wc' );
			}

			$class = ( $stale || $failed ) ? 'notice-warning' : 'notice-info';
			printf(
				'<div class="notice %1$s rma-invoice-status-sync"><p>%2$s</p></div>',
				esc_attr( $class ),
				implode( ' ', $parts ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Parts are escaped above.
			);
		}


		/**
		 * Add status column to order table
		 *
		 * @param $columns
		 *
		 * @return array
		 */
	public function add_status_column_to_order_table( $columns ) {

		// $this->update_invoice_status();
		$columns = RMA_WC_Frontend::array_insert( $columns, 'rma_invoice', 'rma_invoice_status', __( 'Invoice Status', 'rma-wc' ) );
		return $columns;
	}

	public function add_status_value_to_order_table_row( $column, $order ) {

		if ( 'rma_invoice_status' !== $column ) {
			return;
		}

		// The legacy posts list passes a post ID; the HPOS list passes the order.
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order || ! $order->get_meta( '_rma_invoice', true ) ) {
			echo '&mdash;';
			return;
		}

		$status = (string) $order->get_meta( '_rma_invoice_status', true );
		if ( '' === $status ) {
			echo '<small class="rma-invoice-status-since">' . esc_html__( 'Not synced yet', 'rma-wc' ) . '</small>';
			return;
		}

		echo '<mark class="order-status"><span>' . esc_html( $status ) . '</span></mark>';

		$changed_at = strtotime( (string) $order->get_meta( '_rma_invoice_status_timestamp', true ) );
		if ( false === $changed_at || $changed_at <= 0 ) {
			return;
		}

		$absolute = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $changed_at );
		printf(
			'<br><small class="rma-invoice-status-since" title="%1$s">%2$s</small>',
			/* translators: %s: date and time the invoice status changed */
			esc_attr( sprintf( __( 'Status changed: %s', 'rma-wc' ), $absolute ) ),
			/* translators: %s: relative time such as "3 days" */
			esc_html( sprintf( __( 'Changed %s ago', 'rma-wc' ), human_time_diff( $changed_at, time() ) ) )
		);
	}

	public function filter_status_column( $post_type, $which ) {

            if( 'shop_order' !== $post_type ) {
			return;
		}

            $status_field = isset( $_GET['rma_status_field'] ) ? sanitize_text_field( wp_unslash( $_GET['rma_status_field'] ) ) : '';

		?>
				<select name="rma_status_field">
					<option selected="selected" value="any" <?php selected( $status_field, 'any' ); ?>>Any Invoice Status</option>
					<option value="invoiced" <?php selected( $status_field, 'invoiced' ); ?>>Invoiced</option>
					<option value="empty" <?php selected( $status_field, 'empty' ); ?>>No Invoice</option>
					<option value="OPEN" <?php selected( $status_field, 'OPEN' ); ?>>OPEN</option>
					<option value="OVERDUE" <?php selected( $status_field, 'OVERDUE' ); ?>>OVERDUE</option>
					<option value="PAID" <?php selected( $status_field, 'PAID' ); ?>>PAID</option>
				</select>
			<?php
	}

	public function filter_orders_hpos( $query_args ) {

            $status_field = isset( $_GET['rma_status_field'] ) ? sanitize_text_field( wp_unslash( $_GET['rma_status_field'] ) ) : '';

		if ( 'any' === $status_field ) {
			return $query_args;
		}
		$meta_query = $query_args['meta_query'] ?? array();
		if ( 'empty' === $status_field ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_rma_invoice_status',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_rma_invoice_status',
					'value' => '',
				),
			);
		}

		if ( 'invoiced' === $status_field ) {
			$meta_query[] = array(
                        'key'   => '_rma_invoice_status',
                        'value' => '',
				'compare' => '!=',
			);
		}

		if ( in_array( $status_field, array( 'OPEN', 'OVERDUE', 'PAID' ), true ) ) {
			$meta_query[] = array(
				'key'     => '_rma_invoice_status',
				'value'   => $status_field,
				'compare' => '=',
			);
		}

		$query_args['meta_query'] = $meta_query;


		return $query_args;
	}

	public function filter_orders_legacy( $query ) {

            if( ! is_admin() ) {
			return;
		}

		global $pagenow;
		// just being super-accurate here
            if( 'edit.php' !== $pagenow || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

            $status_field = isset( $_GET['rma_status_field'] ) ? sanitize_text_field( wp_unslash( $_GET['rma_status_field'] ) ) : '';

		if ( 'any' === $status_field ) {
			return $query;
		}
            $meta_query = (array) $query->get( 'meta_query' );
		if ( 'empty' === $status_field ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
                        'key' => '_rma_invoice_status',
					'compare' => 'NOT EXISTS',
				),
				array(
                        'key' => '_rma_invoice_status',
					'value' => '',
                    )
			);
		}

		if ( 'invoiced' === $status_field ) {
			$meta_query[] = array(
				array(
                        'key' => '_rma_invoice_status',
                        'value' => '',
					'compare' => '!=',
                    )
			);
		}

		if ( in_array( $status_field, array( 'OPEN', 'OVERDUE', 'PAID' ), true ) ) {
			$meta_query[] = array(
				'key'     => '_rma_invoice_status',
				'value'   => $status_field,
				'compare' => '=',
			);
		}

		// Set the meta query to the complete, altered query
            $query->set('meta_query',$meta_query);

	}



		/**
		 * User Profile Info
		 * Show invoice information to users on their profile page.
		 */

		/**
		 * Register new endpoint to use inside My Account page.
		 *
		 * @see https://developer.wordpress.org/reference/functions/add_rewrite_endpoint/
		 */
	function my_invoices_endpoint() {
		add_rewrite_endpoint( 'invoices', EP_ROOT | EP_PAGES );
	}

		/**
		 * Add new query var.
		 *
		 * @param array $vars
		 * @return array
		 */
	function my_invoices_query_vars( $vars ) {
		$vars[] = 'invoices';

		return $vars;
	}

		/**
		 * Insert the new endpoint into the My Account menu.
		 *
		 * @param array $items
		 * @return array
		 */
	function my_invoices_my_account_menu_items( $items ) {

		$items = RMA_WC_Frontend::array_insert( $items, 'orders', 'invoices', __( 'Invoices', 'rma-wc' ) );

		return $items;
	}


		/**
		 * Add page templates.
		 *
         * @param  array  $templates  The list of page templates
		 *
		 * @return array  $templates  The modified list of page templates
		 */
        function add_account_invoices_template ( $templates ) {
            $templates[plugin_dir_path( __FILE__ ) . 'templates/invoices.php'] = __( 'Page Template From Plugin', 'text-domain' );

		return $templates;
	}


		/**
		 * Endpoint HTML content.
		 */
	function my_invoices_endpoint_content() {

		$invoice_data = array();

            $user_id = get_current_user_id();
		$rma_customer_id = get_user_meta( $user_id, 'rma_customer', true );

            if( ! empty( $rma_customer_id ) ) {

			$RMA_WC_API = new RMA_WC_API();

			$customer_info = $RMA_WC_API->get_customer( $rma_customer_id );
                $invoices = $RMA_WC_API->get_customer_invoices( $rma_customer_id );
			if ( false !== $invoices ) {
				// Keeps the overdue banner in step with this table and lets PDF downloads skip the ownership call.
				set_transient( RMA_WC_API::customer_invoices_transient_key( $rma_customer_id ), $invoices, HOUR_IN_SECONDS );
			}

                $invoice_data[ 'customer_info'] = $customer_info;
                $invoice_data['invoices'] = $invoices;

			unset( $RMA_WC_API );
                load_template(plugin_dir_path(__FILE__) . '../templates/invoices.php', null, $invoice_data); // TODO: Do real templating
            }
            else {
                printf ('<div class="warning">%1s</div>', esc_html_e( 'This user does not have an account in the accounting system.', 'rma-wc' ));

		}
	}

		/**
		 * Endpoint PDF download.
		 *
		 * Requires the logged-in user and a `_wpnonce` for this invoice (12–24 hours, bound to the login session).
		 */
	public function invoice_pdf_download() {

		if ( empty( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return;
		}

		$endpoint_path = wp_parse_url( wc_get_endpoint_url( 'invoices/pdf', '', wc_get_page_permalink( 'myaccount' ) ), PHP_URL_PATH );
		if ( ! is_string( $endpoint_path ) || untrailingslashit( $endpoint_path ) !== dirname( $path ) ) {
			return;
		}

		$invoice_number = RMA_WC_API::normalize_invoice_number( basename( $path ) );
		if ( '' === $invoice_number ) {
			$this->reject_invoice_pdf( 404, __( 'The invoice PDF could not be downloaded. Please try again.', 'rma-wc' ) );
		}

		if ( ! is_user_logged_in() ) {
			$this->reject_invoice_pdf( 401, __( 'You must be logged in to download this invoice.', 'rma-wc' ) );
		}

		$raw_nonce = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';
		$nonce     = is_string( $raw_nonce ) ? sanitize_text_field( $raw_nonce ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, RMA_WC_API::invoice_pdf_nonce_action( $invoice_number ) ) ) {
			// Expired or foreign link: back to the invoice list, which renders fresh links.
			wc_add_notice( __( 'This download link has expired. Please download the invoice again.', 'rma-wc' ), 'notice' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'invoices' ) );
			exit;
		}

		$RMA_WC_API = new RMA_WC_API();
		$pdf_data   = $RMA_WC_API->get_invoice_pdf( $invoice_number );
		unset( $RMA_WC_API );

		if ( ! is_string( $pdf_data ) || ! RMA_WC_API::body_is_pdf( $pdf_data ) ) {
			$this->reject_invoice_pdf( 502, __( 'The invoice PDF could not be downloaded. Please try again.', 'rma-wc' ) );
		}

		$filename = str_replace( array( '"', "\r", "\n" ), '', $invoice_number ) . '.pdf';
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) strlen( $pdf_data ) );
		echo $pdf_data;
		exit;
	}

	/**
	 * Send a plain-text failure. Never a 200 response with a PDF content type.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Already translated message.
	 * @return void
	 */
	private function reject_invoice_pdf( $status, $message ) {
		status_header( (int) $status );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo esc_html( $message );
		exit;
	}

}
