<?php
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

		// The orders-list column and filter are in RMA_WC_Invoicing_Column.

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

				$transient_key   = RMA_WC_API::customer_invoices_transient_key( $rma_customer_id );
				$unavailable_key = $transient_key . '_unavailable';

				$invoices = get_transient( $transient_key );
				if ( false === $invoices ) {
					// This runs while the page renders: a slow or failing Run my Accounts must not hold up every page view.
					if ( false !== get_transient( $unavailable_key ) ) {
						return;
					}
					$RMA_WC_API = new RMA_WC_API();
					$invoices   = $RMA_WC_API->get_customer_invoices( $rma_customer_id, '1900-01-01', null, 5 );
					unset( $RMA_WC_API );
					if ( false === $invoices ) {
						set_transient( $unavailable_key, 1, 10 * MINUTE_IN_SECONDS );
						return;
					}
					set_transient( $transient_key, $invoices, HOUR_IN_SECONDS );
				}

				if ( empty( $invoices['invoice'] ) || ! is_array( $invoices['invoice'] ) ) {
					return;
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
		 * Seconds between walks back to 1 January of the previous year, so a reopened invoice from that span is seen.
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
		 * Seconds of empty months fetched in one request before the next tick continues the gap.
		 *
		 * An empty month is a small response, so the walk requests the previous month immediately.
		 * The cap leaves time to write a month that does contain invoices.
		 */
		const STATUS_SYNC_EMPTY_BUDGET = 15;

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
		 * Download one month of statuses, write them onto matching orders, then step backward.
		 *
		 * The first walk starts at today and continues until the oldest invoiced order.
		 * Once a day the walk goes back to 1 January of the previous year, and further when an unpaid invoice is older.
		 * Empty months are requested again in the same tick until one has invoices or the empty-month budget runs out. A failed request retries that month.
		 *
		 * @return void
		 */
		private function run_invoice_status_sync() {
			// The HTTP client that hits wp-cron.php may disconnect while Run my Accounts is still responding.
			ignore_user_abort( true );
			$started_at = microtime( true );

			$state = $this->load_status_sync_state();
			if ( null === $state ) {
				$state = $this->begin_status_sync_state();
				if ( null === $state ) {
					$this->record_status_sync(
						array(
							'completed_at' => time(),
							'error'        => '',
						)
					);
					return;
				}
				$this->save_status_sync_state( $state );
				$this->log_status_sync(
					'info',
					sprintf(
						/* translators: 1: start date, 2: earliest date the walk will request */
						__( 'Invoice status walk started from %1$s back to %2$s.', 'rma-wc' ),
						$state['cursor'],
						$state['floor']
					)
				);
			}

			if ( ! array_key_exists( 'statuses', $state ) ) {
				$empty_deadline = (int) $started_at + self::STATUS_SYNC_EMPTY_BUDGET;
				while ( true ) {
					$this->touch_status_sync_lock();
					$this->maybe_extend_cron_lock( $started_at );
					$window = RMA_WC_API::invoice_status_month_window( $state['cursor'], $state['floor'], $state['until'] );
					if ( null === $window ) {
						$this->finish_status_sync_walk( $state );
						return;
					}

					$statuses = $this->read_invoice_status_map( $window['from'], $window['to'] );
					if ( null === $statuses ) {
						$error = RMA_WC_API::$last_invoice_status_error;
						$this->log_status_sync(
							'failed',
							sprintf(
								/* translators: 1: period start, 2: period end */
								__( 'Invoice statuses for %1$s to %2$s could not be read.', 'rma-wc' ),
								$window['from'],
								$window['to']
							)
						);
						$this->record_status_sync(
							array(
								'failed_at' => time(),
								'error'     => '' !== $error ? wp_html_excerpt( $error, 300, '…' ) : __( 'Invoice Status could not be read.', 'rma-wc' ),
							)
						);
						$this->schedule_status_sync_continuation( self::STATUS_SYNC_RETRY_AFTER );
						return;
					}

					$this->log_status_sync(
						'info',
						sprintf(
							/* translators: 1: period start, 2: period end, 3: number of invoices */
							__( 'Invoice statuses for %1$s to %2$s: %3$d.', 'rma-wc' ),
							$window['from'],
							$window['to'],
							count( $statuses )
						)
					);

					$state['next']     = $window['next'];
					$state['progress'] = time();
					if ( ! empty( $statuses ) || time() >= $empty_deadline ) {
						$state['statuses'] = $statuses;
						$state['page']     = 1;
						$this->save_status_sync_state( $state );
						break;
					}

					$next = $window['next'];
					if ( null === RMA_WC_API::invoice_status_month_window( $next, $state['floor'], $state['until'] ) ) {
						$this->finish_status_sync_walk( $state );
						return;
					}
					$state['cursor'] = $next;
					$this->save_status_sync_state( $state );
				}
			}

			if ( empty( $state['statuses'] ) ) {
				$this->advance_status_sync_month( $state );
				return;
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
					$this->advance_status_sync_month( $state );
					return;
				}
				$state['page'] = (int) $state['page'] + 1;
				$this->save_status_sync_state( $state );
			}

			$this->schedule_status_sync_continuation();
		}

		/**
		 * Where a new walk starts, or null when nothing is due.
		 *
		 * Before the first completed walk, the floor is the oldest invoiced order.
		 * Once a day after that, the floor is 1 January of the previous year, extended back to the oldest unpaid invoice.
		 * Other hourly runs stop at the oldest unpaid invoice.
		 *
		 * @return array<string,mixed>|null
		 */
		private function begin_status_sync_state() {
			$until  = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
			$result = self::get_status_sync_result();
			$cursor = gmdate( 'Y-m-d' );

			if ( $result['full_at'] <= 0 ) {
				$floor = $this->oldest_invoiced_order_date();
				if ( null === $floor ) {
					return null;
				}

				return array(
					'cursor'    => $cursor,
					'floor'     => $floor,
					'until'     => $until,
					'mark_full' => true,
					'started'   => time(),
					'progress'  => time(),
				);
			}

			$daily  = ( time() - $result['full_at'] ) > self::STATUS_SYNC_FULL_EVERY;
			$unpaid = $this->oldest_unpaid_invoice_date();
			$floor  = $daily ? ( (int) gmdate( 'Y' ) - 1 ) . '-01-01' : null;
			if ( null !== $unpaid && ( null === $floor || $unpaid < $floor ) ) {
				$floor = $unpaid;
			}
			if ( null === $floor ) {
				return null;
			}

			return array(
				'cursor'    => $cursor,
				'floor'     => $floor,
				'until'     => $until,
				'mark_full' => $daily,
				'started'   => time(),
				'progress'  => time(),
			);
		}

		/**
		 * Step to the previous month, or record the walk as finished.
		 *
		 * @param array<string,mixed> $state Sync state for the month just applied.
		 * @return void
		 */
		private function advance_status_sync_month( array $state ) {
			$next = isset( $state['next'] ) ? (string) $state['next'] : '';
			if ( '' === $next || null === RMA_WC_API::invoice_status_month_window( $next, $state['floor'], $state['until'] ) ) {
				$this->finish_status_sync_walk( $state );
				return;
			}

			unset( $state['statuses'], $state['page'] );
			$state['cursor']   = $next;
			$state['progress'] = time();
			$this->save_status_sync_state( $state );
			$this->schedule_status_sync_continuation();
		}

		/**
		 * Drop the in-progress walk and store its outcome.
		 *
		 * @param array<string,mixed> $state Sync state.
		 * @return void
		 */
		private function finish_status_sync_walk( array $state ) {
			$this->log_status_sync(
				'info',
				sprintf(
					/* translators: %s: earliest invoice date included in this walk */
					__( 'Invoice status walk finished. Covered invoices back to %s.', 'rma-wc' ),
					isset( $state['floor'] ) ? (string) $state['floor'] : ''
				)
			);
			delete_option( self::STATUS_SYNC_STATE );
			$this->clear_status_sync_continuation();
			$result = array(
				'completed_at' => time(),
				'error'        => '',
			);
			if ( ! empty( $state['mark_full'] ) ) {
				$result['full_at'] = isset( $state['started'] ) ? (int) $state['started'] : time();
			}
			$this->record_status_sync( $result );
		}

		/**
		 * Earliest invoiced order, or null when no order has an invoice.
		 *
		 * @return string|null yyyy-MM-dd.
		 */
		private function oldest_invoiced_order_date() {
			return $this->oldest_invoice_order_date( false );
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
			return $this->oldest_invoice_order_date( true );
		}

		/**
		 * Creation date of the oldest order that has an invoice.
		 *
		 * @param bool $unpaid_only Limit the query to orders that are not marked PAID.
		 * @return string|null yyyy-MM-dd.
		 */
		private function oldest_invoice_order_date( $unpaid_only ) {
			if ( ! function_exists( 'wc_get_orders' ) ) {
				return null;
			}

			$meta_query = array(
				array(
					'key'     => '_rma_invoice',
					'compare' => 'EXISTS',
				),
			);
			if ( $unpaid_only ) {
				$meta_query = array(
					'relation' => 'AND',
					$meta_query[0],
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
				);
			}

			$ids = wc_get_orders(
				array(
					'limit'      => 1,
					'orderby'    => 'date',
					'order'      => 'ASC',
					'status'     => 'any',
					'return'     => 'ids',
					'meta_query' => $meta_query,
				)
			);

			if ( ! is_array( $ids ) || empty( $ids ) ) {
				return null;
			}

			$order   = wc_get_order( reset( $ids ) );
			$created = $order ? $order->get_date_created() : null;
			if ( ! $created ) {
				return null;
			}

			return gmdate( 'Y-m-d', $created->getTimestamp() - DAY_IN_SECONDS );
		}

		/**
		 * Write one invoice-status sync line to the Run my Accounts log.
		 *
		 * `failed` is a warning. `error` would also send the outage email, and the API call already does that.
		 *
		 * @param string $status  `info` or `failed`.
		 * @param string $message Translated log message.
		 * @return void
		 */
		private function log_status_sync( $status, $message ) {
			$log_values = array(
				'status'     => $status,
				'section_id' => '',
				'section'    => esc_html_x( 'Get Invoice Status', 'Log Section', 'rma-wc' ),
				'mode'       => RMA_WC_API::rma_mode(),
				'message'    => $message,
			);

			RMA_WC_API::write_log( $log_values );
		}

		/**
		 * Read invoice statuses from Run my Accounts. Returns null when the call fails.
		 *
		 * An empty array means the month has no invoices.
		 *
		 * @param string $from yyyy-MM-dd lower bound for the invoice date.
		 * @param string $to   yyyy-MM-dd upper bound for the invoice date.
		 * @return array<string,string>|null
		 */
		private function read_invoice_status_map( $from, $to ) {
			$api      = new RMA_WC_API();
			$statuses = $api->get_invoice_status( $from, $to );
			unset( $api );

			if ( ! is_array( $statuses ) ) {
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
			$invoice_numbers = array();
			foreach ( array_keys( $statuses ) as $invoice_number ) {
				if ( is_scalar( $invoice_number ) && '' !== trim( (string) $invoice_number ) ) {
					$invoice_numbers[] = (string) $invoice_number;
				}
			}
			if ( empty( $invoice_numbers ) ) {
				return 0;
			}

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
							'value'   => $invoice_numbers,
							'compare' => 'IN',
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
			if ( ! is_array( $state ) || empty( $state['cursor'] ) || empty( $state['floor'] ) || empty( $state['until'] ) ) {
				if ( is_array( $state ) && ! empty( $state ) ) {
					delete_option( self::STATUS_SYNC_STATE );
				}
				return null;
			}

			$progress = isset( $state['progress'] ) ? (int) $state['progress'] : 0;
			if ( $progress <= 0 || ( time() - $progress ) > 6 * HOUR_IN_SECONDS ) {
				delete_option( self::STATUS_SYNC_STATE );
				return null;
			}

			if ( array_key_exists( 'statuses', $state ) && ! is_array( $state['statuses'] ) ) {
				unset( $state['statuses'] );
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
