<?php
if ( !defined('ABSPATH') ) exit;

if ( !class_exists('RMA_WC_API') ) {

	class RMA_WC_API {
		/**
		 * Backward-compatible in-memory log sink for admin screens.
		 *
		 * @var array<int,array<string,mixed>>
		 */
		public static $temporary_log = array();

		/**
		 * Last invoice API error message from create_xml_content().
		 *
		 * @var string
		 */
		public static $last_invoice_error_message = '';

		/**
		 * Why the last get_invoice_status() call returned false.
		 *
		 * @var string
		 */
		public static $last_invoice_status_error = '';

		/**
		 * Return last invoice API error message.
		 *
		 * @return string
		 */
		public static function get_last_invoice_error_message(): string {
			return (string) self::$last_invoice_error_message;
		}

		/**
		 * Clear last invoice API error message.
		 *
		 * @return void
		 */
		public static function clear_last_invoice_error_message(): void {
			self::$last_invoice_error_message = '';
		}

		/**
		 *  Construct
		 */
		public function __construct() {

			// define constants only if they are not defined yet
			// for this we check for two common constants which definitely needs to be defined
			if ( !defined( 'RMA_MANDANT' ) || !defined( 'RMA_INVOICE_PREFIX' ) )
				$this->define_constants();

		}

		public static function format_log_information( $log_information ) {
			if ( empty( $log_information ) || ! is_array( $log_information ) ) {
				return '';
			}

			$output = '<table class="widefat">';

			$table_header = true;

			foreach ( $log_information as $result ) {

				if ( $table_header ) {
					$output .= '<thead><tr>';
					foreach ( array_keys( ( $result ) ) as $key ) {
						$output .= '<th>' . $key . '</th>';
					}
					$output .= '</tr></thead>';

					$table_header = false;
				}

				$output .= '<tr>';
				foreach ( $result as $key => $value ) {
					$value = esc_xml( $value );
					$value = str_replace('error','<span style="color: red;">error</span>',$value);
					$value = str_replace('failed','<span style="color: red;">failed</span>',$value);
					$value = str_replace('success','<span style="color: green;">success</span>',$value);
					$value = str_replace('paid','<span style="color: green;">paid</span>',$value);
					$value = str_replace('created','<span style="color: green;">created</span>',$value);
					$value = str_replace('invoiced','<span style="color: green;">invoiced</span>',$value);

					$output .= '<td>' . $value . '</td>';
				}
				$output .= '</tr>';
			}

			$output .= '</table>';

			return $output;
		}

		/**
		 * define default constants
		 */
		private function define_constants() {

			// read rma settings
			$settings = get_option('wc_rma_settings');

			// check if operation mode is set and is live
			if( isset( $settings['rma-mode'] ) && 'live' == $settings['rma-mode'] ) {

				// define constants with live values
				if ( ! defined( 'RMA_MANDANT' ) ) {
					if( defined( 'RMA_MANDANT_LIVE' ) ) {
						DEFINE( 'RMA_MANDANT', RMA_MANDANT_LIVE );
					} else {
						DEFINE( 'RMA_MANDANT', ( $settings['rma-live-client'] ?? '' ) );
					}
				}

				if ( ! defined( 'RMA_APIKEY' ) ) {
					if( defined( 'RMA_APIKEY_LIVE' ) ) {
						DEFINE( 'RMA_APIKEY', RMA_APIKEY_LIVE );
					} else {
						DEFINE( 'RMA_APIKEY', ( $settings['rma-live-apikey'] ?? '' ) );
					}
				}
				if ( ! defined( 'RMA_CALLERSANDBOX' ) ) {
					DEFINE( 'RMA_CALLERSANDBOX', false );
				}

			}
			else {

				// set default operation mode to test
                if ( ! defined( 'RMA_MANDANT' ) ) {
                    if( defined( 'RMA_MANDANT_TEST' ) ) {
                        DEFINE( 'RMA_MANDANT', RMA_MANDANT_TEST );
                    } else {
                        DEFINE( 'RMA_MANDANT', ( $settings['rma-test-client'] ?? '' ) );
                    }
                }
                if ( ! defined( 'RMA_APIKEY' ) ) {
                    if( defined( 'RMA_APIKEY_TEST' ) ) {
                        DEFINE( 'RMA_APIKEY', RMA_APIKEY_TEST );
                    } else {
                        DEFINE( 'RMA_APIKEY', ( $settings['rma-test-apikey'] ?? '' ) );
                    }
                }
                if ( ! defined( 'RMA_CALLERSANDBOX' ) ) {
                    DEFINE( 'RMA_CALLERSANDBOX', true );
                }

			}

			DEFINE( 'RMA_GLOBAL_PAYMENT_PERIOD', ( $settings[ 'rma-payment-period' ] ?? '0') ); // default value 0 days
			DEFINE( 'RMA_INVOICE_PREFIX', ( $settings[ 'rma-invoice-prefix' ] ?? '') );
			DEFINE( 'RMA_INVOICE_DIGITS', ( isset( $settings['rma-digits'] ) ? $settings['rma-digits'] : '' ) );

			// if rma-loglevel ist not set, LOGLEVEL is set to error by default
			if( isset( $settings['rma-loglevel'] ) ) {
				if( 'error' == $settings['rma-loglevel']  || empty( $settings['rma-loglevel'] ) ) {
					if( !defined( 'LOGLEVEL' ) ) DEFINE( 'LOGLEVEL' , 'error' );
				}
				elseif ( $settings['rma-loglevel'] == 'complete' ) {
					if( !defined( 'LOGLEVEL' ) ) DEFINE( 'LOGLEVEL' , 'complete' );
				}
			} else {
				if( !defined( 'LOGLEVEL' ) ) DEFINE( 'LOGLEVEL' , 'error' );
			}

			// if rma-log-send-email ist not set, SENDLOGEMAIL is set to false by default
			if( isset ( $settings['rma-log-send-email'] ) &&
			    'yes' == $settings['rma-log-send-email'] ) {
				if( !defined( 'SENDLOGEMAIL' ) ) DEFINE( 'SENDLOGEMAIL' , true );

				// who will get email on error
				if( !defined( 'LOGEMAIL' ) ) DEFINE( 'LOGEMAIL', ( !empty( $settings['rma-log-email'] ) ? $settings['rma-log-email'] : get_option( 'admin_email' ) ) );

			} else {
				if( !defined( 'SENDLOGEMAIL' ) ) DEFINE( 'SENDLOGEMAIL' , false );
			}

		}

		/**
		 * Set Caller URL live oder sandbox
		 *
		 * @return string
		 */
		public static function get_caller_url(): string
		{
			// Set caller URL
			if( RMA_CALLERSANDBOX ) { // Caller URL for sandbox
				$url = 'https://service.int.runmyaccounts.com/api/latest/clients/'; // End with / !
				//$url = ' https://service-swint.runmyaccounts.com/api/latest/clients/'; // End with / !
			}
			else { // Caller URL set for Live page
				$url = 'https://service.runmyaccounts.com/api/latest/clients/'; // End with / !
			}

			return $url;
		}

		/**
		 * Build authenticated request args for RMA API requests.
		 *
		 * @param array $args Optional request args.
		 * @return array
		 */
		private static function get_authenticated_http_args( array $args = array() ): array {
			$http_args = $args;

			$headers = array();
			if ( isset( $http_args['headers'] ) && is_array( $http_args['headers'] ) ) {
				$headers = $http_args['headers'];
			}

			$http_args['headers'] = array_merge(
				$headers,
				array(
					'Authorization' => 'Bearer ' . RMA_APIKEY,
				)
			);

			return $http_args;
		}

		/**
		 * Build request args for RMA HTTP calls.
		 *
		 * @param array $args Optional request args.
		 * @return array
		 */
		private static function get_rma_http_args( array $args = array() ): array {
			return wp_parse_args(
				$args,
				array(
					'timeout' => 120,
				)
			);
		}

		/**
		 * Append request URL from WP HTTP response object to an error message.
		 *
		 * @param string $message  Current message.
		 * @param mixed  $response Response array from wp_remote_*.
		 * @return string
		 */
		private static function append_response_url_to_message( string $message, $response ): string {
			$http_response = is_array( $response ) ? ( $response['http_response'] ?? null ) : null;
			if ( ! is_object( $http_response ) || ! method_exists( $http_response, 'get_response_object' ) ) {
				return $message;
			}

			$response_object = $http_response->get_response_object();
			if ( is_object( $response_object ) && ! empty( $response_object->url ) ) {
				$message .= ' ' . $response_object->url;
			}

			return $message;
		}

		/**
		 * Invoice number safe to put in a URL path and an API path segment.
		 *
		 * Keeps characters that sanitize_key() would remove (for example "."), and
		 * rejects path separators so the value cannot change which request is made.
		 *
		 * @param mixed $invoice_number Raw invoice number.
		 * @return string Uppercase number, or an empty string when the value is unusable.
		 */
		public static function normalize_invoice_number( $invoice_number ) {
			$value = trim( rawurldecode( (string) $invoice_number ) );
			if ( '' === $value || strlen( $value ) > 128 ) {
				return '';
			}
			if ( false !== strpos( $value, '..' ) ) {
				return '';
			}
			if ( preg_match( '/[\x00-\x1F\x7F\/\\\\?#"]/', $value ) ) {
				return '';
			}

			return strtoupper( $value );
		}

		/**
		 * Customer number safe to put in an API path segment or query value.
		 *
		 * Unlike sanitize_key() it keeps the case and characters such as ".",
		 * because Run my Accounts matches the number as stored.
		 *
		 * @param mixed $customer_number Raw customer number.
		 * @return string Trimmed number, or an empty string when the value is unusable.
		 */
		public static function normalize_customer_number( $customer_number ) {
			if ( ! is_scalar( $customer_number ) ) {
				return '';
			}
			$value = trim( (string) $customer_number );
			if ( '' === $value || strlen( $value ) > 64 ) {
				return '';
			}
			if ( false !== strpos( $value, '..' ) ) {
				return '';
			}
			if ( preg_match( '/[\x00-\x1F\x7F\/\\\\]/', $value ) ) {
				return '';
			}

			return $value;
		}

		/**
		 * Calendar date in the YYYY-MM-DD form the Run my Accounts API expects.
		 *
		 * @param mixed $date Raw date.
		 * @return string The date, or an empty string when it is not a valid date.
		 */
		public static function normalize_api_date( $date ) {
			if ( ! is_scalar( $date ) ) {
				return '';
			}
			$value = trim( (string) $date );
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
				return '';
			}

			return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $value : '';
		}

		/**
		 * `from` and optional `to` query arguments for the list endpoints.
		 *
		 * @param mixed $from YYYY-MM-DD.
		 * @param mixed $to   YYYY-MM-DD, or empty for no upper bound.
		 * @return array<string,string>|false False when a given date is not valid.
		 */
		public static function date_range_query_args( $from, $to = null ) {
			$query_args = array( 'from' => self::normalize_api_date( $from ) );
			if ( '' === $query_args['from'] ) {
				return false;
			}
			if ( null !== $to && '' !== $to ) {
				$query_args['to'] = self::normalize_api_date( $to );
				if ( '' === $query_args['to'] ) {
					return false;
				}
			}

			return $query_args;
		}

		/**
		 * Status to store for an order, or null when this order must not be updated.
		 *
		 * An empty stored invoice, or a stored invoice that is not the one just
		 * fetched, must not be overwritten. Otherwise a broad order query would
		 * stamp every invoiced order with the current invoice's status.
		 *
		 * @param mixed $stored_invoice_number Value of `_rma_invoice`.
		 * @param array $statuses              Map of invoice number => status.
		 * @return string|null
		 */
		public static function matching_invoice_status( $stored_invoice_number, array $statuses ) {
			if ( ! is_scalar( $stored_invoice_number ) ) {
				return null;
			}

			$stored_invoice_number = trim( (string) $stored_invoice_number );
			if ( '' === $stored_invoice_number || ! array_key_exists( $stored_invoice_number, $statuses ) ) {
				return null;
			}

			$status = $statuses[ $stored_invoice_number ];
			if ( ! is_scalar( $status ) ) {
				return null;
			}

			$status = trim( (string) $status );
			if ( '' === $status ) {
				return null;
			}

			return $status;
		}

		/**
		 * Whether the response media type is a PDF, ignoring parameters such as charset.
		 *
		 * @param mixed $content_type Header value from wp_remote_retrieve_header().
		 * @return bool
		 */
		public static function is_pdf_content_type( $content_type ) {
			if ( is_array( $content_type ) ) {
				$content_type = end( $content_type );
			}
			if ( ! is_string( $content_type ) ) {
				return false;
			}

			$media_type = strtolower( trim( explode( ';', $content_type, 2 )[0] ) );

			return 'application/pdf' === $media_type || 'application/octet-stream' === $media_type;
		}

		/**
		 * Whether the body looks like a PDF. Error text and empty bodies do not.
		 *
		 * @param mixed $body Response body.
		 * @return bool
		 */
		public static function body_is_pdf( $body ) {
			if ( ! is_string( $body ) || strlen( $body ) < 5 ) {
				return false;
			}

			return false !== strpos( substr( $body, 0, 1024 ), '%PDF-' );
		}

		/**
		 * Route value for orders that are invoiced through Run my Accounts.
		 */
		public const INVOICE_ROUTE_RMA = 'rma';

		/**
		 * Order meta that overrides the route derived from the payment method.
		 */
		public const INVOICE_ROUTE_META = '_rma_invoice_route';

		/**
		 * Invoicing route per payment method, for example `array( 'cod' => 'cash' )`.
		 *
		 * @return array<string,string>
		 */
		public static function payment_method_invoice_routes(): array {
			$routes = apply_filters( 'rma_wc_payment_method_invoice_routes', array() );

			return is_array( $routes ) ? array_map( 'strval', $routes ) : array();
		}

		/**
		 * Route for payment methods not listed in payment_method_invoice_routes().
		 *
		 * @return string
		 */
		public static function default_invoice_route(): string {
			$route = (string) apply_filters( 'rma_wc_default_invoice_route', self::INVOICE_ROUTE_RMA );

			return '' === $route ? self::INVOICE_ROUTE_RMA : $route;
		}

		/**
		 * Route derived from a payment method.
		 *
		 * @param string $payment_method Payment method ID.
		 * @return string
		 */
		public static function payment_method_invoice_route( string $payment_method ): string {
			$routes = self::payment_method_invoice_routes();

			return $routes[ $payment_method ] ?? self::default_invoice_route();
		}

		/**
		 * Route of an order: the override meta if set, otherwise derived from the payment method.
		 *
		 * @param WC_Order $order Order.
		 * @return string
		 */
		public static function order_invoice_route( WC_Order $order ): string {
			$override = (string) $order->get_meta( self::INVOICE_ROUTE_META, true );
			if ( '' !== $override ) {
				return $override;
			}

			return self::payment_method_invoice_route( (string) $order->get_payment_method() );
		}

		/**
		 * Whether an order is invoiced through Run my Accounts.
		 *
		 * @param WC_Order $order Order.
		 * @return bool
		 */
		public static function is_rma_invoice_route( WC_Order $order ): bool {
			return self::INVOICE_ROUTE_RMA === self::order_invoice_route( $order );
		}

		/**
		 * SQL condition (with placeholders) that matches orders on the Run my Accounts route.
		 *
		 * Mirrors order_invoice_route() so queries and single-order checks agree.
		 *
		 * @param string $payment_method_column Column holding the payment method.
		 * @param string $override_column       Column holding the route override meta value (NULL when absent).
		 * @param array  $params                Prepared statement params (by reference).
		 * @return string
		 */
		public static function rma_invoice_route_sql( string $payment_method_column, string $override_column, array &$params ): string {
			$routes      = self::payment_method_invoice_routes();
			$default_rma = self::INVOICE_ROUTE_RMA === self::default_invoice_route();
			$listed      = array_keys(
				array_filter(
					$routes,
					static function ( string $route ) use ( $default_rma ): bool {
						return $default_rma ? self::INVOICE_ROUTE_RMA !== $route : self::INVOICE_ROUTE_RMA === $route;
					}
				)
			);

			$params[] = self::INVOICE_ROUTE_RMA;

			$payment_method = "COALESCE({$payment_method_column}, '')";
			if ( empty( $listed ) ) {
				$by_payment_method = $default_rma ? '1=1' : '1=0';
			} else {
				$placeholders      = implode( ', ', array_fill( 0, count( $listed ), '%s' ) );
				$by_payment_method = $payment_method . ( $default_rma ? ' NOT IN ' : ' IN ' ) . "({$placeholders})";
				$params            = array_merge( $params, array_map( 'strval', $listed ) );
			}

			return "( {$override_column} = %s OR ( COALESCE({$override_column}, '') = '' AND {$by_payment_method} ) )";
		}

		/**
		 * Nonce action for an invoice PDF download.
		 *
		 * @param string $invoice_number Normalized invoice number.
		 * @return string
		 */
		public static function invoice_pdf_nonce_action( $invoice_number ) {
			return 'download-pdf-nonce-' . $invoice_number;
		}

		/**
		 * Download URL for an invoice PDF, signed for the current user and session.
		 *
		 * The nonce expires after 12–24 hours and when the user logs out.
		 *
		 * @param string $invoice_number Normalized invoice number.
		 * @return string Unescaped URL.
		 */
		public static function invoice_pdf_download_url( $invoice_number ) {
			return add_query_arg(
				'_wpnonce',
				wp_create_nonce( self::invoice_pdf_nonce_action( $invoice_number ) ),
				wc_get_endpoint_url( 'invoices/pdf', rawurlencode( $invoice_number ), wc_get_page_permalink( 'myaccount' ) )
			);
		}

		/**
		 * Read customer list from RMA
		 *
		 * @return mixed
		 */
		public function get_customers() {

			if( !RMA_MANDANT || !RMA_APIKEY ) {

				$log_values = array(
					'status' => 'error',
					'section_id' => '',
					'section' => esc_html_x('Get Customer', 'Log Section', 'run-my-accounts-for-woocommerce'),
					'mode' => self::rma_mode(),
					'message' => esc_html__('Missing API data', 'run-my-accounts-for-woocommerce') );

				self::write_log($log_values);

				return false;

			}

			$url       = self::get_caller_url() . RMA_MANDANT . '/customers';
            $response  = wp_remote_get(
                $url,
                self::get_rma_http_args( self::get_authenticated_http_args() )
            );

			// Check response code
			if ( 200 <> wp_remote_retrieve_response_code( $response ) ){

				$message = esc_html__( 'Response Code', 'run-my-accounts-for-woocommerce') . ' '. wp_remote_retrieve_response_code( $response );
				$message .= ' '. wp_remote_retrieve_response_message( $response );


				if ( is_wp_error( $response ) ) {

					$error_string = sanitize_text_field( $response->get_error_message() );
					echo '<div id="message" class="error"><p>' . esc_html( $error_string ) . '</p></div>';
                } else {
                    $message = self::append_response_url_to_message( $message, $response );
                }

                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Customers', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => $message,
                );

                self::write_log( $log_values );

					return false;

            } else {

                libxml_use_internal_errors( true );

                $body = wp_remote_retrieve_body( $response );
                $xml  = simplexml_load_string( $body );

                if ( ! $xml ) {
                    // ToDO: Add this information to error log
                    foreach ( libxml_get_errors() as $error ) {
                        echo "\t", $error->message;
                    }

                    return false;

                } else {
                    // Parse response
                    $array = json_decode( json_encode( (array) $xml ), true );

                    // Transform into array
                    foreach ( $array as $value ) {

                        foreach ( $value as $key => $customer ) {
                            $number = $customer['customernumber'];

                            if ( is_array( $customer['name'] ) ) {

                                if ( is_array( $customer['firstname'] ) ||
                                     is_array( $customer['lastname'] ) ) {
                                    $name = '';
                                } else {
                                    $name = $customer['firstname'] . ' ' . $customer['lastname'];
                                }
                            } else {
                                $name = $customer['name'];
                            }

                            $customers[ $number ] = $name . ' ( ' . $number . ' )';
                        }
                    }

                    return ( ! empty( $customers ) ? $customers : false );
                }
            }
        }

        /**
         * Read customer data from RMA
         *
         * @return mixed
         */
        public static function get_customers_data() {

            if ( ! RMA_MANDANT || ! RMA_APIKEY ) {

                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Customer', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => esc_html__( 'Missing API data', 'run-my-accounts-for-woocommerce' ),
                );

                self::write_log( $log_values );

					return false;

				}

            $url      = self::get_caller_url() . RMA_MANDANT . '/customers';
            $response = wp_remote_get( $url, self::get_rma_http_args( self::get_authenticated_http_args() ) );

            $customers = array();

            // Check response code
            if ( 200 <> wp_remote_retrieve_response_code( $response ) ) {

                $message  = esc_html__( 'Response Code', 'run-my-accounts-for-woocommerce' ) . ' ' . wp_remote_retrieve_response_code( $response );
                $message .= ' ' . wp_remote_retrieve_response_message( $response );

                if ( is_wp_error( $response ) ) {
                    $message .= ' ' . $response->get_error_message();
                } else {
				$message = self::append_response_url_to_message( $message, $response );
                }

				$log_values = array(
					'status' => 'error',
					'section_id' => '',
					'section' => esc_html_x('Get Customer', 'Log Section', 'run-my-accounts-for-woocommerce'),
					'mode' => self::rma_mode(),
					'message' => $message );

				self::write_log($log_values);

				return false;

			}
			else {

				libxml_use_internal_errors( true );

				$body = wp_remote_retrieve_body( $response );
				$xml  = simplexml_load_string( $body );

				if ( !$xml ) {
					// ToDO: Add this information to error log
					foreach( libxml_get_errors() as $error ) {
						echo "\t", $error->message;
					}

					return false;

				}
				else {
					// Parse response
					$array = json_decode( json_encode( (array)$xml ), TRUE);

					// Transform into array
					foreach ( $array as $value ) {

						foreach ($value as $key => $customer ) {
							$number = $customer['customernumber'];

							if ( is_array( $customer['name'] ) ) {

								if ( is_array( $customer['firstname'] ) ||
								     is_array( $customer['lastname'] )) {
									$name = '';
								}
								else {
									$name = $customer['firstname'] . ' ' . $customer['lastname'];
								}

							}
							else {
								$name   = $customer['name'];
                                $customers[ $number ]['name'] = $customer['name'];
							}
                            $customers[ $number ]['rmaid']          = $customer['id'] ?? '';
                            $customers[ $number ]['customernumber'] = $customer['customernumber'] ?? '';
                            $customers[ $number ]['display_name']   = $name . ' ( ' . $number . ' )';
                            $customers[ $number ]['email']          = empty( $customer['email'] ?? '' ) ? '' : $customer['email'];
                            $customers[ $number ]['phone']          = empty( $customer['phone'] ?? '' ) ? '' : $customer['phone'];
                            $customers[ $number ]['mobile']         = empty( $customer['mobile'] ?? '' ) ? '' : $customer['mobile'];
                            $customers[ $number ]['firstname']      = empty( $customer['firstname'] ?? '' ) ? '' : $customer['firstname'];
                            $customers[ $number ]['lastname']       = empty( $customer['lastname'] ?? '' ) ? '' : $customer['lastname'];

                            $customers[ $number ]['typeofcontact'] = $customer['typeofcontact'] ?? '';
                            $customers[ $number ]['gender']        = $customer['gender'] ?? '';
						}
					}

					return ( !empty( $customers ) ? $customers : false );
				}
			}
		}

        /**
         * Read info on one customer
         */
        public function get_customer( $rma_customer_id ) {
            if ( ! RMA_MANDANT || ! RMA_APIKEY ) {

                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Customer', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => esc_html__( 'Missing API data', 'run-my-accounts-for-woocommerce' ),
                );

                self::write_log( $log_values );

                return false;

            }

            $customer_number = self::normalize_customer_number( $rma_customer_id );
            if ( '' === $customer_number ) {
                return false;
            }

            $url = self::get_caller_url() . RMA_MANDANT . '/customers/' . rawurlencode( $customer_number );

            $response = wp_remote_get( $url, self::get_rma_http_args( self::get_authenticated_http_args() ) );

            // Check response code
            if ( 200 <> wp_remote_retrieve_response_code( $response ) ) {

                $message  = esc_html__( 'Response Code', 'run-my-accounts-for-woocommerce' ) . ' ' . wp_remote_retrieve_response_code( $response );
                $message .= ' ' . wp_remote_retrieve_response_message( $response );

                if ( is_wp_error( $response ) ) {
                    $message .= ' ' . $response->get_error_message();
                } else {
                    $message = self::append_response_url_to_message( $message, $response );
                }

                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Customer', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => $message,
                );

                self::write_log( $log_values );

                return false;

            } else {

                libxml_use_internal_errors( true );

                $body = wp_remote_retrieve_body( $response );
                $xml  = simplexml_load_string( $body );

                if ( ! $xml ) {
                    // ToDO: Add this information to error log
                    foreach ( libxml_get_errors() as $error ) {
                        echo "\t", $error->message;
                    }

                    return false;

                } else {
                    // Parse response
                    $customer = json_decode( json_encode( (array) $xml ), true );
                    return ( ! empty( $customer ) ? $customer : false );
                }
            }
        }


        /**
         * Read invoices
         *
         * @param mixed  $rma_customer_id customernumber.
         * @param string $from YYYY-MM-DD.
         * @param mixed  $to YYYY-MM-DD.
         * @return mixed .
         */
        public function get_customer_invoices( $rma_customer_id = null, $from = '1900-01-01', $to = null ) {
            if ( ! RMA_MANDANT || ! RMA_APIKEY ) {

                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Customer', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => esc_html__( 'Missing API data', 'run-my-accounts-for-woocommerce' ),
                );

                self::write_log( $log_values );

                return false;

            }

            $query_args = self::date_range_query_args( $from, $to );
            if ( false === $query_args ) {
                return false;
            }
            // An unusable customer number must not fall back to the unfiltered list of all customers' invoices.
            if ( null !== $rma_customer_id ) {
                $customer_number = self::normalize_customer_number( $rma_customer_id );
                if ( '' === $customer_number ) {
                    return false;
                }
                $query_args['customer_number'] = rawurlencode( $customer_number );
            }
            $url      = add_query_arg( $query_args, self::get_caller_url() . RMA_MANDANT . '/invoices' );
            $response = wp_remote_get( $url, self::get_rma_http_args( self::get_authenticated_http_args() ) );

            // Check response code
            if ( 200 <> wp_remote_retrieve_response_code( $response ) ) {

                $message  = esc_html__( 'Response Code', 'run-my-accounts-for-woocommerce' ) . ' ' . wp_remote_retrieve_response_code( $response );
                $message .= ' ' . wp_remote_retrieve_response_message( $response );

                if ( is_wp_error( $response ) ) {
                    $message .= ' ' . $response->get_error_message();
                } else {
                    $message = self::append_response_url_to_message( $message, $response );
                }

                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Customer Invoice', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => $message,
                );

                self::write_log( $log_values );

                return false;

            } else {

                libxml_use_internal_errors( true );

                $body = wp_remote_retrieve_body( $response );
                $xml  = simplexml_load_string( $body );

                if ( false === $xml ) {
                    // ToDO: Add this information to error log
                    foreach ( libxml_get_errors() as $error ) {
                        echo "\t", $error->message;
                    }

                    return false;

                } else {
                    // Parse response.
                    $invoices = array();
                    foreach ( $xml->invoice as $invoice ) {
                        $invoices[] = json_decode( json_encode( (array) $invoice ), true );
                    }

                    return ( ! empty( $invoices ) ? array( 'invoice' => $invoices ) : array( 'invoice' => array() ) );
                }
            }
        }

        /**
         * Read vendor invoices
         *
         * @return mixed .
         */
        public function get_vendor_invoices( $from = '1900-01-01', $to = null ) {
            if ( ! RMA_MANDANT || ! RMA_APIKEY ) {

                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Vendor Invoices', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => esc_html__( 'Missing API data', 'run-my-accounts-for-woocommerce' ),
                );

                self::write_log( $log_values );

                return false;

            }

            $query_args = self::date_range_query_args( $from, $to );
            if ( false === $query_args ) {
                return false;
            }
            $url      = add_query_arg( $query_args, self::get_caller_url() . RMA_MANDANT . '/payables' );
            $response = wp_remote_get( $url, self::get_rma_http_args( self::get_authenticated_http_args() ) );

            // Check response code.
            if ( 200 <> wp_remote_retrieve_response_code( $response ) ) {

                $message  = esc_html__( 'Response Code', 'run-my-accounts-for-woocommerce' ) . ' ' . wp_remote_retrieve_response_code( $response );
                $message .= ' ' . wp_remote_retrieve_response_message( $response );

                if ( is_wp_error( $response ) ) {
                    $message .= ' ' . $response->get_error_message();
                } else {
                    $message = self::append_response_url_to_message( $message, $response );
                }

                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Vendor Invoices', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => $message,
                );

                self::write_log( $log_values );

                return false;

            } else {

                libxml_use_internal_errors( true );

                $body = wp_remote_retrieve_body( $response );
                $xml  = simplexml_load_string( $body );

                if ( false === $xml ) {
                    // ToDO: Add this information to error log
                    foreach ( libxml_get_errors() as $error ) {
                        echo "\t", $error->message;
                    }

                    return false;

                } else {
                    // Parse response.
                    $payables = array();
                    foreach ( $xml->payable as $payable ) {
                        $payables[] = json_decode( json_encode( (array) $payable ), true );
                    }

                    return ( ! empty( $payables ) ? array( 'payable' => $payables ) : array( 'payable' => array() ) );

                }
            }
        }

        /**
         * Fetch PDF File for invoice
         *
         * This function also verifies that the fetched invoice belongs
         * to the current user.
         */
        public function get_invoice_pdf( $rma_invoice_number ) {
            if ( ! RMA_MANDANT || ! RMA_APIKEY ) {

                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Invoice PDF', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => esc_html__( 'Missing API data', 'run-my-accounts-for-woocommerce' ),
                );

                self::write_log( $log_values );

                return false;

            }

            $requested_rma_invoice_number = self::normalize_invoice_number( $rma_invoice_number );
            if ( '' === $requested_rma_invoice_number ) {
                return false;
            }

            $rma_user_current = self::normalize_customer_number( get_user_meta( get_current_user_id(), 'rma_customer', true ) );
            if ( '' === $rma_user_current ) {
                return false;
            }

            // The customer's invoice list (filtered by customer_number in Run my Accounts) already proves ownership.
            if ( ! self::cached_customer_has_invoice( $rma_user_current, $requested_rma_invoice_number ) && ! $this->remote_customer_has_invoice( $rma_user_current, $requested_rma_invoice_number ) ) {
                return false;
            }

            return $this->fetch_invoice_pdf_body( $requested_rma_invoice_number );
        }

        /**
         * Transient holding a customer's invoice list from get_customer_invoices().
         *
         * @param string $rma_customer_id Customer number.
         * @return string
         */
        public static function customer_invoices_transient_key( $rma_customer_id ) {
            return 'invoices_' . self::normalize_customer_number( $rma_customer_id );
        }

        /**
         * Whether the cached, customer-filtered invoice list contains this invoice.
         *
         * @param string $rma_customer_id Customer number.
         * @param string $invoice_number  Normalized invoice number.
         * @return bool
         */
        public static function cached_customer_has_invoice( $rma_customer_id, $invoice_number ) {
            $invoices = get_transient( self::customer_invoices_transient_key( $rma_customer_id ) );
            if ( ! is_array( $invoices ) || empty( $invoices['invoice'] ) || ! is_array( $invoices['invoice'] ) ) {
                return false;
            }

            foreach ( $invoices['invoice'] as $invoice ) {
                if ( is_array( $invoice ) && isset( $invoice['invnumber'] ) && self::normalize_invoice_number( $invoice['invnumber'] ) === $invoice_number ) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Customer number on a single invoice response.
         *
         * GET /invoices/{number} can nest the customer (as the list does) or carry
         * `customernumber` at the top level (as the invoice entity does).
         *
         * @param mixed $invoice Decoded invoice.
         * @return string
         */
        public static function invoice_customer_number( $invoice ) {
            if ( ! is_array( $invoice ) ) {
                return '';
            }
            if ( isset( $invoice['customer']['customernumber'] ) && is_scalar( $invoice['customer']['customernumber'] ) ) {
                return trim( (string) $invoice['customer']['customernumber'] );
            }
            if ( isset( $invoice['customernumber'] ) && is_scalar( $invoice['customernumber'] ) ) {
                return trim( (string) $invoice['customernumber'] );
            }

            return '';
        }

        /**
         * Ask Run my Accounts whether the invoice belongs to the customer.
         *
         * @param string $rma_customer_id Customer number.
         * @param string $invoice_number  Normalized invoice number.
         * @return bool
         */
        private function remote_customer_has_invoice( $rma_customer_id, $invoice_number ) {
            $url          = self::get_caller_url() . RMA_MANDANT . '/invoices/' . rawurlencode( $invoice_number );
            $xml_response = wp_remote_get( $url, self::get_rma_http_args( self::get_authenticated_http_args( array( 'headers' => array( 'Accept' => 'application/xml' ) ) ) ) );
            if ( is_wp_error( $xml_response ) ) {
                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Invoice PDF', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => $xml_response->get_error_message(),
                );

                self::write_log( $log_values );
                return false;
            }

            $xml_status = (int) wp_remote_retrieve_response_code( $xml_response );
            if ( 200 !== $xml_status ) {
                $message  = esc_html__( 'Response Code', 'run-my-accounts-for-woocommerce' ) . ' ' . $xml_status;
                $message .= ' ' . wp_remote_retrieve_response_message( $xml_response );
                $message  = self::append_response_url_to_message( $message, $xml_response );

                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Invoice PDF', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => $message,
                );

                self::write_log( $log_values );
                return false;
            }

            libxml_use_internal_errors( true );
            $body = wp_remote_retrieve_body( $xml_response );
            $xml  = simplexml_load_string( $body );
            if ( false === $xml ) {
                libxml_clear_errors();
                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Invoice PDF', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => 'Download failed / invoice response was not valid XML',
                );

                self::write_log( $log_values );
                return false;
            }

            $rma_user_remote = self::invoice_customer_number( json_decode( json_encode( (array) $xml ), true ) );

            // User does not match or invoice does not exist for this user.
            if ( '' === $rma_user_remote || self::normalize_customer_number( $rma_user_remote ) !== self::normalize_customer_number( $rma_customer_id ) ) {
                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Invoice PDF', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => 'Download failed / invoice does not exist for this user',
                );

                self::write_log( $log_values );
                return false;
            }

            return true;
        }

        /**
         * Download the PDF body. Returns false unless the response is a real PDF.
         *
         * @param string $invoice_number Normalized invoice number.
         * @return string|false
         */
        private function fetch_invoice_pdf_body( $invoice_number ) {
            $url      = self::get_caller_url() . RMA_MANDANT . '/invoices/' . rawurlencode( $invoice_number ) . '/pdf';
            $response = wp_remote_get( $url, self::get_rma_http_args( self::get_authenticated_http_args() ) );

            if ( is_wp_error( $response ) ) {
                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Invoice PDF', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => $response->get_error_message(),
                );

                self::write_log( $log_values );

                return false;
            }

            // Check response code.
            if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {

                $message  = esc_html__( 'Response Code', 'run-my-accounts-for-woocommerce' ) . ' ' . wp_remote_retrieve_response_code( $response );
                $message .= ' ' . wp_remote_retrieve_response_message( $response );

                $message = self::append_response_url_to_message( $message, $response );

                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Invoice PDF', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => $message,
                );

                self::write_log( $log_values );

                return false;

            }

            $body         = wp_remote_retrieve_body( $response );
            $content_type = wp_remote_retrieve_header( $response, 'content-type' );

            if ( ! self::is_pdf_content_type( $content_type ) || ! self::body_is_pdf( $body ) ) {
                $log_values = array(
                    'status'     => 'error',
                    'section_id' => '',
                    'section'    => esc_html_x( 'Get Invoice PDF', 'Log Section', 'run-my-accounts-for-woocommerce' ),
                    'mode'       => self::rma_mode(),
                    'message'    => 'Download failed / response was not a PDF',
                );

                self::write_log( $log_values );

                return false;
            }

            return $body;
        }

		/**
		 * Read parts list from RMA
		 *
		 * @return mixed
		 *
		 * @since 1.5.0
		 */
		public function get_parts() {

			if( !RMA_MANDANT || !RMA_APIKEY ) {

				$log_values = array(
					'status' => 'error',
					'section_id' => '',
					'section' => esc_html_x('Get Parts', 'Log Section', 'run-my-accounts-for-woocommerce'),
					'mode' => self::rma_mode(),
					'message' => esc_html__('Missing API data', 'run-my-accounts-for-woocommerce') );

				self::write_log($log_values);

				return false;

			}

			$url       = self::get_caller_url() . RMA_MANDANT . '/parts';

            $response  = wp_remote_get(
                $url,
                self::get_rma_http_args( self::get_authenticated_http_args() )
            );

			// Check response code
			if ( 200 <> wp_remote_retrieve_response_code( $response ) ){

				$message = esc_html__( 'Response Code', 'run-my-accounts-for-woocommerce') . ' '. wp_remote_retrieve_response_code( $response );
				$message .= ' '. wp_remote_retrieve_response_message( $response );

				$response = (array) $response[ 'http_response' ];

				$message = self::append_response_url_to_message( $message, $response );

				$log_values = array(
					'status' => 'error',
					'section_id' => '',
					'section' => esc_html_x('Get Parts', 'Log Section', 'run-my-accounts-for-woocommerce'),
					'mode' => self::rma_mode(),
					'message' => $message );

				self::write_log($log_values);

				return false;

			}
			else {

				libxml_use_internal_errors( true );

				$body = wp_remote_retrieve_body( $response );
				$xml  = simplexml_load_string( $body );

				if ( !$xml ) {
					// ToDO: Add this information to error log
					foreach( libxml_get_errors() as $error ) {
						echo "\t", esc_html( $error->message );
					}

					return false;

				}
				else {
					// Parse response
					$array = json_decode( json_encode( (array)$xml ), TRUE);

					$sku_list = array();

					foreach ( $array['part'] as $part ) {

						$sku_list[ $part['partnumber'] ] = $part['partnumber'];

					}

					return ( !empty( $sku_list ) ? $sku_list : false );
				}
			}
		}

		public function get_charts() {

			if ( ! RMA_MANDANT || ! RMA_APIKEY ) {

				$log_values = array(
					'status'     => 'error',
					'section_id' => '',
					'section'    => esc_html_x( 'Get Charts', 'Log Section', 'rma-wc' ),
					'mode'       => self::rma_mode(),
					'message'    => esc_html__( 'Missing API data', 'rma-wc' ),
				);

				self::write_log( $log_values );

				return false;

			}

			$url = self::get_caller_url() . RMA_MANDANT . '/charts';

			$response = wp_remote_get( $url, self::get_authenticated_http_args() );

			// Check response code
			if ( 200 <> wp_remote_retrieve_response_code( $response ) ) {

				$message  = esc_html__( 'Response Code', 'rma-wc' ) . ' ' . wp_remote_retrieve_response_code( $response );
				$message .= ' ' . wp_remote_retrieve_response_message( $response );

				$response = (array) $response['http_response'];

				foreach ( $response as $object ) {
					$message .= ' ' . $object->url;
					break;
				}

				$log_values = array(
					'status'     => 'error',
					'section_id' => '',
					'section'    => esc_html_x( 'Get Charts', 'Log Section', 'rma-wc' ),
					'mode'       => self::rma_mode(),
					'message'    => $message,
				);

				self::write_log( $log_values );

				return false;

			} else {

				libxml_use_internal_errors( true );

				$body = wp_remote_retrieve_body( $response );
				$xml  = simplexml_load_string( $body );

				if ( ! $xml ) {
					// ToDO: Add this information to error log
					foreach ( libxml_get_errors() as $error ) {
						echo "\t", $error->message;
					}

					return false;

				} else {
					// Parse response
					$array = json_decode( json_encode( (array) $xml ), true );

					return ( ! empty( $array ) ? $array : false );
				}
			}
		}

		public function get_project_bookings( $force_refresh = false, $year = null ) {

			/**
			 * Array of accounts where the description is replaced by the account name.
			 * Mainly used to make sure that expense recipients are not named in the "public"
			 * listing.
			 */
			$anonymizing_accounts = array( 2005 );

			if ( ! RMA_MANDANT || ! RMA_APIKEY ) {

				$log_values = array(
					'status'     => 'error',
					'section_id' => '',
					'section'    => esc_html_x( 'Get Invoices', 'Log Section', 'rma-wc' ),
					'mode'       => self::rma_mode(),
					'message'    => esc_html__( 'Missing API data', 'rma-wc' ),
				);

				self::write_log( $log_values );

				return false;

			}

			// Transient.
			$transient = 'rma_project_booking_transient_' . ( $year ?? 'all' );

			$bookings  = get_transient( $transient );

			// $force_refresh = true; // for debugging.

			if ( $force_refresh || false === $bookings ) {

				// first get the charts, so we have names to the accounts.
				$charts_raw = $this->get_charts();

				$chart_lookup = array();
				foreach ( $charts_raw['chart'] as $account ) {
					$a                           = $account['@attributes'];
					$chart_lookup[ $a['accno'] ] = $a['description'];
				}

				// this code runs when there is no valid transient set.
				$bookings = array();

				// Save memory - only load one month at a time starting 01.01.2022.

				$start_date = new \DateTime( $year ? $year . '-01-01' : '2022-01-01' );
				$end_date   = new \DateTime( $year ? $year . '-12-31' : 'first day of last month' );

				$interval = \DateInterval::createFromDateString( '1 month' );
				$period   = new \DatePeriod( $start_date, $interval, $end_date );

				foreach ( $period as $date ) {
					$from = $date->format( 'Y-m-d' );
					$to   = $date->modify( 'last day of' )->format( 'Y-m-d' );

					$customer_invoices = $this->get_customer_invoices( null, $from, $to );
					foreach ( $customer_invoices['invoice'] as $invoice ) {

						if ( ! isset( $invoice['parts'] ) ) {
							continue;
						}

						foreach ( $invoice['parts'] as $parts ) {

							// The xml parser cannot know if a single element should be an array.
							if ( array_key_exists( 0, $parts ) ) {
								$parts_array = $parts;
							} else {
								$parts_array = array( $parts );
							}

							foreach ( $parts_array as $part ) {

								if ( ! isset( $part['sellprice'] ) || 0 === absint( $part['sellprice'] ) ) {
									continue;
								}

								// If the project number is not set we are not interested in this transaction.
								if ( ! isset( $part['projectnumber'] ) ) {
									continue;
								}

								$bookings[] = array(
									'type'          => 'receivable',
									'accountnumber' => $part['income_accno'],
									'accountname'   => $chart_lookup[ $part['income_accno'] ],
									'projectnumber' => $part['projectnumber'],
									'date'          => $invoice['transdate'],
									'description'   => $part['description'],
									'value'         => $part['sellprice'],
								);
							}
						}
					}
				}

				$vendor_invoices        = $this->get_vendor_invoices( $start_date->format( 'Y-m-d' ), $end_date->format( 'Y-m-d' ) );
				$vendor_invoces_payable = $vendor_invoices['payable'] ?? array();
				foreach ( $vendor_invoces_payable as $payable ) {

					foreach ( $payable['expenseentries'] as $parts ) {

						// The xml parser cannot know if a single element should be an array.
						if ( array_key_exists( 0, $parts ) ) {
							$parts_array = $parts;
						} else {
							$parts_array = array( $parts );
						}

						foreach ( $parts_array as $part ) {

							if ( 0 === absint( $part['amount'] ) ) {
								continue;
							}

							// If the project number is not set we are not interested in this transaction.
							if ( ! isset( $part['projectNumber'] ) ) {
								continue;
							}

							if ( in_array( $part['expense_accno'], $anonymizing_accounts, true ) ) {
								$description_text = $chart_lookup[ $part['expense_accno'] ];
							} else {
								$description_text = wp_strip_all_tags( html_entity_decode( $payable['description'] ) );
							}

							$bookings[] = array(
								'type'          => 'payable',
								'accountnumber' => $part['expense_accno'],
								'accountname'   => $chart_lookup[ $part['expense_accno'] ],
								'projectnumber' => $part['projectNumber'],
								'date'          => $payable['transdate'],
								'description'   => $description_text,
								'value'         => $part['amount'],
							);
						}
					}
				}

				usort(
					$bookings,
					function ( $a, $b ) {
						return strtotime( $a['date'] ) - strtotime( $b['date'] );
					}
				);
				set_transient( $transient, $bookings, 3 * DAY_IN_SECONDS ); // Keep for 3 days.

			}
			return $bookings;
		}

		/**
		 * Invoice number => status for invoices dated within the window.
		 *
		 * Without `from`, Run my Accounts returns only invoices after the accounting
		 * close date, and without `to` only up to today in its own timezone.
		 *
		 * @param string $from yyyy-MM-dd.
		 * @param string $to   yyyy-MM-dd.
		 * @return array<string,string>|false
		 */
		public function get_invoice_status( $from = '1900-01-01', $to = null ) {

			self::$last_invoice_status_error = '';

			if ( ! RMA_MANDANT || ! RMA_APIKEY ) {

				$log_values = array(
					'status'     => 'error',
					'section_id' => '',
					'section'    => esc_html_x( 'Get Invoice', 'Log Section', 'rma-wc' ),
					'mode'       => self::rma_mode(),
					'message'    => esc_html__( 'Missing API data', 'rma-wc' ),
				);

				self::write_log( $log_values );
				self::$last_invoice_status_error = __( 'Missing API data', 'rma-wc' );

				return false;

			}

			if ( null === $to ) {
				$to = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
			}
			$query_args = self::date_range_query_args( $from, $to );
			if ( false === $query_args ) {
				self::$last_invoice_status_error = __( 'Invalid date range for the Run my Accounts request.', 'rma-wc' );
				return false;
			}
			$url      = add_query_arg( $query_args, self::get_caller_url() . RMA_MANDANT . '/invoices' );
			$response = wp_remote_get(
				$url,
				self::get_rma_http_args( self::get_authenticated_http_args( array( 'headers' => array( 'Accept' => 'application/xml' ) ) ) )
			);

			// A timeout is a WP_Error. Do not read it as an array: that fatals the cron callback on PHP 8.
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {

				$message  = esc_html__( 'Response Code', 'rma-wc' ) . ' ' . wp_remote_retrieve_response_code( $response );
				$message .= ' ' . wp_remote_retrieve_response_message( $response );

				if ( is_wp_error( $response ) ) {
					$message .= ' ' . $response->get_error_message();
				} else {
					$message = self::append_response_url_to_message( $message, $response );
				}

				$log_values = array(
					'status'     => 'error',
					'section_id' => '',
					'section'    => esc_html_x( 'Get Invoice', 'Log Section', 'rma-wc' ),
					'mode'       => self::rma_mode(),
					'message'    => $message,
				);

				self::write_log( $log_values );
				self::$last_invoice_status_error = trim( $message );

				return false;

			}

			libxml_use_internal_errors( true );

			$body = wp_remote_retrieve_body( $response );
			$xml  = simplexml_load_string( $body );

			if ( ! $xml ) {
				libxml_clear_errors();
				self::$last_invoice_status_error = __( 'Run my Accounts did not return valid XML.', 'rma-wc' );
				return false;
			}

			$status_array = array();
			foreach ( $xml->invoice as $invoice ) {
				$row = json_decode( json_encode( (array) $invoice ), true );
				if ( ! is_array( $row ) || ! isset( $row['invnumber'], $row['status'] ) ) {
					continue;
				}
				if ( ! is_scalar( $row['invnumber'] ) || ! is_scalar( $row['status'] ) ) {
					continue;
				}
				$invoice_number = trim( (string) $row['invnumber'] );
				$status         = trim( (string) $row['status'] );
				if ( '' === $invoice_number || '' === $status ) {
					continue;
				}
				$status_array[ $invoice_number ] = $status;
			}

			if ( empty( $status_array ) ) {
				self::$last_invoice_status_error = __( 'Run my Accounts returned no invoices.', 'rma-wc' );
				return false;
			}

			return $status_array;
		}

		/**
		 * Collect data for invoice
		 *
		 * @param $order_id
		 *
		 * @return array
		 * @throws DOMException
		 */
		private function get_invoice_values( $order_id ): array
		{

			$order_details = self::get_wc_order_details( $order_id );

			// add products to order
			$order_details_products = self::get_order_details_products( $order_id );

			// add shipping costs to order
			$order_details_products = self::get_order_details_shipping_costs( $order_id, $order_details_products );

			$invoice_id = RMA_INVOICE_PREFIX . str_pad( $order_id, max(intval(RMA_INVOICE_DIGITS) - strlen(RMA_INVOICE_PREFIX ), 0 ), '0', STR_PAD_LEFT );

			return self::get_invoice_data( $order_details, $order_details_products, $invoice_id, $order_id );

		}

		public static function get_invoice_data( $order_details, $order_details_products, $invoice_id, $order_id, $description = '' ): array {

			$settings     = get_option( 'wc_rma_settings' );
			$fallback_sku = $settings[ 'rma-product-fallback_id' ];

			if ( !empty( $fallback_sku ) )
				$rma_part_numbers = (new RMA_WC_API)->get_parts();

			if( empty( $description ) ) {

				$description = str_replace('[orderdate]', $order_details[ 'orderdate' ], $settings[ 'rma-invoice-description' ] ?? '' );

			}

			$data = array(
				'invoice' => array(
					'invnumber'      => $invoice_id,
					'ordnumber'      => $order_id,
					'status'         => 'OPEN',
					'currency'       => $order_details[ 'currency' ],
					'ar_accno'       => $order_details[ 'ar_accno' ],
					'transdate'      => gmdate(DateTimeInterface::RFC3339, time() ),
					'duedate'        => $order_details[ 'duedate' ], //gmdate( DateTime::RFC3339, time() ),
					'description'    => $description,
					'notes'          => '',
					'intnotes'       => $order_details[ 'notes' ],
					'taxincluded'    => $order_details[ 'taxincluded' ],
					'dcn'            => '',
					'customernumber' => $order_details[ 'customernumber' ],
					'payment_accno'  => $order_details[ 'payment_accno' ]
				),
				'part' => array()
			);

			// Add parts
			if ( count( $order_details_products ) > 0 ) :

				foreach ( $order_details_products as $part_key => $part ) :

					$part_number = $part['sku'] ?? $part_key;

					// check if fallback sku exist and part number does not exist in list of RMA part numbers
					if( !empty( $fallback_sku ) &&
					    !array_key_exists( $part_number, $rma_part_numbers ) )
						$part_number = $fallback_sku;

					$data['part'][] = apply_filters( 'rma_invoice_part', array (
						'partnumber'   => $part_number,
						'description'  => $part[ 'name' ],
						'unit'         => '',
						'quantity'     => $part[ 'quantity' ],
						'sellprice'    => $part[ 'price' ],
						'discount'     => '0.0',
						'itemnote'     => '',
						'price_update' => '',
					), $part[ 'item_id' ] ?? null );
				endforeach;

			endif;

            return apply_filters( 'rma_invoice_parts', $data );

		}

		/**
		 * Prepare data of a customer by user id for sending to Run my Accounts
		 *
		 * @param $user_id
		 *
		 * @return array
		 * @throws Exception
		 */
		private function get_customer_values_by_user_id( $user_id ) :array {

			$settings        = get_option( 'wc_rma_settings' );
			$customer_prefix = isset( $settings[ 'rma-customer-prefix' ] ) ? $settings[ 'rma-customer-prefix' ] : '';

			$customer        = new WC_Customer( $user_id );

			$is_company      = !empty( $customer->get_billing_company() ) ? true : false;
			$billing_account = get_user_meta( $user_id, 'rma_billing_account', true );

			return array(
				'customernumber'    => $customer_prefix . $user_id,
				'name'              => ( $is_company ? $customer->get_billing_company() : $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ),
				'created'           => gmdate('Y-m-d') . 'T00:00:00+01:00',
				'salutation'        => ( 1 == get_user_meta( $user_id, 'billing_title', true ) ? __('Mr.', 'run-my-accounts-for-woocommerce') : __('Ms.', 'run-my-accounts-for-woocommerce') ),
				'firstname'         => $customer->get_billing_first_name(),
				'lastname'          => $customer->get_billing_last_name(),
				'address1'          => $customer->get_billing_address_1(),
				'address2'          => $customer->get_billing_address_2(),
				'zipcode'           => $customer->get_billing_postcode(),
				'city'              => $customer->get_billing_city(),
				'state'             => $customer->get_billing_state(),
				'country'           => WC()->countries->countries[ $customer->get_billing_country() ],
				'phone'             => $customer->get_billing_phone(),
				'fax'               => '',
				'mobile'            => '',
				'email'             => $customer->get_billing_email(),
				'cc'                => '',
				'bcc'               => '',
				'language_code'     => '',
				'remittancevoucher' => 'false',
				'arap_accno'        => !empty ( $billing_account ) ? $billing_account : '', // Default accounts receivable account number - 1100
				'payment_accno'     => '', // Default payment account number	1020
				'notes'             => '',
				'terms'             => '0',
				'typeofcontact'     => ( $is_company ? 'company' : 'person' ),
				'gender'            => ( 1 == get_user_meta( $user_id, 'billing_title', true ) ? 'M' : 'F' ),
			);

		}

		/**
		 * Prepare data of a customer by order id for sending to Run my Accounts
		 *
		 * @param $order_id
		 *
		 * @return array
		 * @throws Exception
		 */
		private function get_customer_values_by_order_id( $order_id ) : array {

			$settings        = get_option( 'wc_rma_settings' );
			$customer_prefix = isset( $settings[ 'rma-guest-customer-prefix' ] ) ? $settings[ 'rma-guest-customer-prefix' ] : '';
			unset( $settings );

			$order                = new WC_Order( $order_id );

			$is_company           = !empty( $order->get_billing_company() );

			return array(
				'customernumber'    => $customer_prefix . $order_id,
				'name'              => ( $is_company ? $order->get_billing_company() : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'created'           => gmdate('Y-m-d') . 'T00:00:00+01:00',
				'salutation'        => ( 1 == $order->get_meta( '_billing_title', true ) ? __('Mr.', 'run-my-accounts-for-woocommerce') : __('Ms.', 'run-my-accounts-for-woocommerce') ),
				'firstname'         => $order->get_billing_first_name(),
				'lastname'          => $order->get_billing_last_name(),
				'address1'          => $order->get_billing_address_1(),
				'address2'          => $order->get_billing_address_2(),
				'zipcode'           => $order->get_billing_postcode(),
				'city'              => $order->get_billing_city(),
				'state'             => $order->get_billing_state(),
				'country'           => WC()->countries->countries[ $order->get_billing_country() ],
				'phone'             => $order->get_billing_phone(),
				'fax'               => '',
				'mobile'            => '',
				'email'             => $order->get_billing_email(),
				'cc'                => '',
				'bcc'               => '',
				'language_code'     => '',
				'remittancevoucher' => 'false',
				'arap_accno'        => '', // Default accounts receivable account number - 1100
				'payment_accno'     => '', // Default payment account number	1020
				'notes'             => '',
				'terms'             => '0',
				'typeofcontact'     => ( $is_company ? 'company' : 'person' ),
				'gender'            => ( 1 == $order->get_meta( '_billing_title' ) ? 'M' : 'F' ),

			);

		}

		/**
		 * get WooCommerce order details
		 *
		 * @param $order_id
		 *
		 * @return array
		 * @throws DOMException
		 */
		public static function get_wc_order_details( $order_id ): array {

			$order                = wc_get_order( $order_id );
			$option_accounting    = get_option( 'wc_rma_settings_accounting' );
			$order_payment_method = $order->get_payment_method();

			$option_billing       = get_option( 'wc_rma_settings' );

			// transfer price net
			if( isset( $option_billing[ 'rma-tax-transfer' ] ) && 'net' == $option_billing[ 'rma-tax-transfer' ] ) {
				$tax_included = 'false';
			}
			// transfer price gross
			elseif( isset( $option_billing[ 'rma-tax-transfer' ] ) && 'gross' == $option_billing[ 'rma-tax-transfer' ] ) {
				$tax_included = 'true';
			}
			// get the settings from WooCommerce
			else {
				$tax_included = $order->get_prices_include_tax() ? 'true' : 'false';
			}

			// if the order is done without a user account
			if ( 0 == $order->get_meta( '_customer_user', true ) ) {

				$settings = get_option('wc_rma_settings');

				if ( 1 == ( $settings[ 'rma-create-guest-customer' ] ?? 0 ) ) {

					$rma_customer_id = (new RMA_WC_API)->create_rma_customer('order', $order_id );

					if ( ! $rma_customer_id) {

						$log_values = array(
							'status' => 'error',
							'section_id' => $order_id,
							'section' => esc_html_x( 'Customer', 'Log Section', 'run-my-accounts-for-woocommerce'),
							'mode' => self::rma_mode(),
							'message' => __( 'Could not create RMA customer dedicated guest account', 'run-my-accounts-for-woocommerce' )
						);

						self::write_log($log_values);

					}

				}
				else {

					// customer id is equal to predefined catch all guest account
					$rma_customer_id = $settings[ 'rma-guest-catch-all' ];

				}

			}
			// ...or with a user account
			else {

				$rma_customer_id = get_user_meta( $order->get_customer_id(), 'rma_customer', true );

			}

			// Set order header
			$order_details[ 'currency' ]       = $order->get_currency();
			$order_details[ 'orderdate' ]      = wc_format_datetime( $order->get_date_created(),'d.m.Y' );
			$order_details[ 'taxincluded' ]    = $tax_included;
			$order_details[ 'customernumber' ] = $rma_customer_id;
			$order_details[ 'ar_accno' ]       = isset( $option_accounting[ $order_payment_method ] ) && !empty( $option_accounting[ $order_payment_method ] ) ? $option_accounting[ $order_payment_method ] : '';
			$order_details[ 'payment_accno' ]  = isset( $option_accounting[ $order_payment_method . '_payment_account' ] ) && !empty( $option_accounting[ $order_payment_method . '_payment_account' ] ) ? $option_accounting[ $order_payment_method . '_payment_account' ] : '' ;

			// Calculate due date
			$user_payment_period               = get_user_meta( $order->get_customer_id(), 'rma_payment_period', true );
			// Set payment period - if user payment period not exist set to global period
			$payment_period                    = $user_payment_period ? $user_payment_period : RMA_GLOBAL_PAYMENT_PERIOD;
			// Calculate duedate (now + payment period)
			$order_details[ 'duedate' ]        = gmdate( DateTime::RFC3339, time() + ( $payment_period*60*60*24 ) );

			// add shipping address if needed
			$order_details[ 'notes' ]          = '';
			if( $order->needs_shipping_address() ) {

				// first converts a break tag to a newline – no matter what kind of HTML is being processed.
				$order_details[ 'notes' ]      = preg_replace('/<br(\s+)?\/?>/i', "\n", $order->get_formatted_shipping_address());

			}

			return $order_details;

		}

		/**
		 * Collect products as order details
		 *
		 * @param int $order_id
		 * @param array $order_details_products
		 *
		 * @return array
		 *
		 * @since 1.7.0
		 */
		public static function get_order_details_products( int $order_id, array $order_details_products = array() ): array
		{

			$order = wc_get_order( $order_id );

			// add line items
			foreach ( $order->get_items() as $item_id => $item ) {
				$product       = $item->get_product();

				// make sure the product is still available in WooCommerce
				if( is_object( $product ) ) {

					$qty = $item->get_quantity();

					$order_details_products[ (string) $item_id ] = array(
						'name'     => $item->get_name(),
						'quantity' => $qty,
						'price'    => wc_format_decimal( $item->get_total() / $qty, 2 ),
						'sku'      => $product->get_sku(),
						'item_id'  => $item_id
					);

				}

			}

			return $order_details_products;

		}

		/**
		 * Add shipping costs as product, if necessary
		 *
		 * @param int $order_id
		 * @param array $order_details_products
		 *
		 * @return array
		 *
		 * @since 1.7.0
		 */
		public static function get_order_details_shipping_costs( int $order_id, array $order_details_products): array
		{

			$settings = get_option( 'wc_rma_settings' );
			$order    = wc_get_order( $order_id );

			// Add Shipping costs
			// @since 1.6.0
			$order_shipping_total_net  = (float) $order->get_shipping_total();
			$order_shipping_tax        = (float) $order->get_shipping_tax();
			$shipping_costs_product_id = $settings['rma-shipping-id'] ?? '';

			// Calculate shipping costs w/ or wo/ tax
			if( $order->get_prices_include_tax() ) {
				$order_shipping_total  = $order_shipping_total_net + $order_shipping_tax;
			}
			else {
				$order_shipping_total  = $order_shipping_total_net;
			}

			// do we have shipping costs and a product id on file?
			if( 0 < $order_shipping_total && !empty( $shipping_costs_product_id ) ) {

				// we get shipping text from settings page otherwise we take shipping method
				$shipping_text = ( isset( $settings['rma-shipping-text'] ) && !empty( $settings['rma-shipping-text'] ) ? $settings['rma-shipping-text'] : $order->get_shipping_method() );

				$order_details_products[ 'shipping_' . $order_id ] = array(
					'name'     => $shipping_text,
					'quantity' => 1,
					'price'    => $order_shipping_total,
					'sku'      => $shipping_costs_product_id,
				);

			}
			// do we have shipping costs but no product id on file?
			elseif ( 0 < $order_shipping_total && empty( $shipping_costs_product_id ) ) {

				$log_values = array(
					'status' => 'error',
					'section_id' => $order->get_id(),
					'section' => esc_html_x( 'Invoice', 'Log Section', 'run-my-accounts-for-woocommerce'),
					'mode' => (new RMA_WC_API)->rma_mode(),
					'message' => __( 'Could not add shipping costs to invoice because of missing shipping costs product sku', 'run-my-accounts-for-woocommerce' )
				);

				self::write_log($log_values);

			}

			return $order_details_products;

		}

		/**
		 * Create invoice in Run my Accounts
		 *
		 * @param string $order_id
		 *
		 * @return bool
		 *
		 * @throws DOMException
		 */
		public function create_invoice( string $order_id='' ): bool {

			$is_active = $this->is_activated( '$order_id ' . $order_id );

			// Continue only if an order_id is available and plugin function is activated
			if( !$order_id || !$is_active ) return false;

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || ! self::is_rma_invoice_route( $order ) ) {
				$log_values = array(
					'status'     => 'error',
					'section_id' => $order_id,
					'section'    => esc_html_x( 'Invoice', 'Log Section', 'run-my-accounts-for-woocommerce' ),
					'mode'       => self::rma_mode(),
					'message'    => 'Order is not invoiced through Run my Accounts (route: ' . ( $order instanceof WC_Order ? self::order_invoice_route( $order ) : 'unknown' ) . ')',
				);
				self::write_log( $log_values );
				return false;
			}

			$data = $this->get_invoice_values( $order_id );

			return self::create_xml_content( $data, array( $order_id ) );

		}

		/**
		 * Creates XML and send to Run My Accounts
		 *
		 * @param array $data              data of the complete invoice
		 * @param array $order_ids         an array of all affected orders for which we create the invoice
		 * @param bool $collective_invoice true if we create an collective invoice
		 *
		 * @return bool                    true if the invoice creation was successful otherwise false
		 * @throws DOMException
		 */
		public static function create_xml_content( array $data, array $order_ids, bool $collective_invoice = false ): bool {

			self::clear_last_invoice_error_message();

			$url  = self::get_caller_url() . RMA_MANDANT . '/invoices';

			//create the xml document
			$xml  = new DOMDocument('1.0', 'UTF-8');

			// create root element invoice and child
			$root = $xml->appendChild( $xml->createElement("invoice") );
			foreach( $data[ 'invoice' ] as $key => $value ) {
				if ( ! empty( $key ) )
					$root->appendChild( $xml->createElement( $key, $value ) );
			}

			$tab_invoice = $root->appendChild($xml->createElement('parts'));

			// create child elements part
			foreach( $data[ 'part' ] as $part ){
				if( !empty( $part ) ){
					$tab_part = $tab_invoice->appendChild($xml->createElement('part'));

					foreach( $part as $key=>$value ){
						$tab_part->appendChild($xml->createElement($key, $value));
					}
				}
			}

			// make the output pretty
			$xml->formatOutput = true;

			// create xml content
			$xml_str = $xml->saveXML() . "\n";

			// send xml content to RMA
			$response = self::send_xml_content( $xml_str, $url );

			// $response empty == no errors
			if ( 200 == self::first_key_of_array( $response ) ||
			     204 == self::first_key_of_array( $response )) {

				$status         = 'invoiced';
				$invoice_type   = $collective_invoice ? esc_html_x( 'Collective invoice', 'Order Note', 'run-my-accounts-for-woocommerce') : esc_html_x( 'Invoice', 'Order Note', 'run-my-accounts-for-woocommerce') ;

				$invoice_number = $data[ 'invoice' ][ 'invnumber' ];
				$message        = sprintf( esc_html_x( '%1$s %2$s created', 'Log', 'run-my-accounts-for-woocommerce'), $collective_invoice, $invoice_number);

				// add order note to each order
				foreach ( $order_ids as $order_id ) {

					$order          = wc_get_order(  $order_id );
					$note           = sprintf( esc_html_x( '%1$s %2$s created in Run my Accounts', 'Order Note', 'run-my-accounts-for-woocommerce'), $invoice_type, $invoice_number);
					$order->add_order_note( $note );
					$order->update_meta_data( '_rma_invoice', $invoice_number );

					$order->save();

					unset( $order );

				}

				$return = true;

			}
			else {

				$status  = 'error';
				$message = '[' . self::first_key_of_array( $response ) . '] ' . reset( $response ); // get value of first key = return message
				self::$last_invoice_error_message = (string) $message;

				// add order note to each order
				foreach ( $order_ids as $order_id ) {

					$order          = wc_get_order(  $order_id );
					$note           = sprintf( esc_html_x( 'Invoice creation failed: %s', 'Order Note', 'run-my-accounts-for-woocommerce'), __($message, 'run-my-accounts-for-woocommerce'));
					$order->add_order_note( $note );

					unset( $order );

				}


				$return = false;

			}

			if ( ( 'error' == LOGLEVEL && 'error' == $status ) || 'complete' == LOGLEVEL ) {
				$log_values = array(
					'status' => $status,
					'section_id' => $order_id,
					'section' => esc_html_x('Invoice', 'Log Section', 'run-my-accounts-for-woocommerce'),
					'mode' => self::rma_mode(),
					'message' => $message );

				self::write_log($log_values);

				// send email on error
				if ( 'error' == $status && SENDLOGEMAIL ) self::send_log_email($log_values);

			}

			return $return;

		}

		/**
		 * Create customer in Run my Accounts
		 *
		 * @param string $type
		 * @param string $id
		 * @param string $action new|update
		 *
		 * @return bool|string
		 *
		 * @throws DOMException
		 */
		public function create_rma_customer( $type, $id='', $action = 'new') {

			if( !$id || !$type)
				return false;

			// exit if plugin is not activated
			if ( !$this->is_activated('$user_id ' . $id ) )
				return false;

			// exit if a customer should not be created automatically
			if ( !$this->do_create_customer() )
				return false;

			// exit if user is already linked to a RMA customer account
			if ( 'user' == $type   &&
			     'new'  == $action &&
			     get_user_meta( $id, 'rma_customer', true ) )
				return false;

			$method = 'get_customer_values_by_' . $type . '_id';
			$data   = $this->$method( $id );

			// build REST api url for Run my Accounts
			$caller_url_customer = self::get_caller_url() . RMA_MANDANT . '/customers';

			//create the xml document
			$xml_doc = new DOMDocument('1.0', 'UTF-8');
			//make the output pretty
			$xml_doc->formatOutput = true;

			// create root element customer and child
			$root = $xml_doc->createElement('customer');
			$root = $xml_doc->appendChild( $root );

			foreach( $data as $key => $val) {

				if ( ! empty( $key ) ) {
					$child = $xml_doc->createElement( $key );
					$child = $root->appendChild( $child );

					$text = $xml_doc->createTextNode( $val );
					$text = $child->appendChild( $text );
				}

			}

			//create xml content
			$xml_str = $xml_doc->saveXML() . "\n";

			// send xml content to RMA with curl
			$response = self::send_xml_content( $xml_str, $caller_url_customer );

			// $response empty == no errors
			if ( 200 == self::first_key_of_array( $response ) ||
			     204 == self::first_key_of_array( $response )) {

				// add RMA customer number to user_meta
				$status         = 'created';
				$message        = sprintf( esc_html_x( 'Customer %s created', 'Log', 'run-my-accounts-for-woocommerce'), $data['customernumber']);

				if ( 'user' == $type )
					update_user_meta( $id, 'rma_customer', $data[ 'customernumber' ] );

			}
			else {

				$status  = 'error';
				$message = '[' . self::first_key_of_array( $response ) . '] ' . reset( $response ); // get value of first key = return message

			}

			if ( ( 'error' == LOGLEVEL && 'error' == $status ) || 'complete' == LOGLEVEL ) {

				$log_values = array(
					'status' => $status,
					'section_id' => $id,
					'section' => sprintf( esc_html_x('Customer by %s id', 'Log Section', 'run-my-accounts-for-woocommerce'), $type),
					'mode' => self::rma_mode(),
					'message' => $message );

				self::write_log($log_values);

			}

			return 'error' == $status ? 'false' : $data[ 'customernumber' ];
		}

		/**
		 * Return first key of an array
		 * can be replaced by array_key_first() when min. PHP is 7.3
		 *
		 * @param $array
		 *
		 * @return string
		 *
		 * @since 1.5.2
		 */
		// ToDo: replace this function as soon as min. PHP is 7.3 or above
		public static function first_key_of_array( $array ): string
		{

			// set point of the array
			reset( $array );

			// return the key
			return key( $array );

		}

		/**
		 * Send xml content to RMA with curl
		 *
		 * @param $xml string
		 * @param $url string
		 *
		 * @return array
		 */
		public static function send_xml_content( string $xml, string $url ): array {

			$response = wp_safe_remote_post(
				$url,
				self::get_rma_http_args(
					self::get_authenticated_http_args(
						array(
							'headers' => array(
								'Content-Type' => 'application/xml'
							),
							'body' => $xml
						)
					)
				)
			);

			$response_code    = wp_remote_retrieve_response_code( $response );
			$response_body    = wp_remote_retrieve_body( $response );

			return array( $response_code => $response_body );

		}

		/**
		 * Check if the plugin is activated on settings page
		 *
		 * @param $section_id
		 *
		 * @return string
		 */
		private function is_activated ( $section_id ): string {

			$settings  = get_option( 'wc_rma_settings' );
			$is_active = ( isset( $settings[ 'rma-active' ] ) ? $settings[ 'rma-active' ] : '');

			if ( !$is_active && 'complete' == LOGLEVEL ) {

				$log_values = array(
					'status'     => 'deactivated',
					'section_id' => $section_id,
					'section'    => esc_html_x( 'Activation', 'Log Section', 'run-my-accounts-for-woocommerce' ),
					'mode'       => self::rma_mode(),
					'message'    => esc_html_x( 'Plugin was not activated', 'Log', 'run-my-accounts-for-woocommerce' ) );

				self::write_log($log_values);
				// send email with log details
				if ( SENDLOGEMAIL ) self::send_log_email($log_values);
			}

			return $is_active;
		}

		/**
		 * Check if the customer should be created in Run my Accounts
		 *
		 * @return string
		 */
		private function do_create_customer(): string {

			$settings = get_option( 'wc_rma_settings' );
			return isset( $settings[ 'rma-create-customer' ] ) ? $settings[ 'rma-create-customer' ] : '';

		}

		/**
		 * @return string
		 */
		public static function rma_mode(): string
		{
			return RMA_CALLERSANDBOX ? 'Test' : 'Live' ;
		}

		/**
		 * Write Log in DB
		 *
		 * @param $values
		 *
		 * @return bool
		 */
		public static function write_log( &$values ): bool {

			If( ! function_exists( 'wc_get_logger' ) || empty( $values ) ) {
				return false;
			}

			// create the message
			$message = sprintf( esc_html__( 'Section %1$s: status %2$s message "%3$s".', 'run-my-accounts-for-woocommerce'),
			                    $values['section'],
			                    $values['status'],
			                    $values['message']
			);

			// array with additional log information
			$args = array(
				'source'  => 'Run My Accounts',
				'section' => $values['section'],
				'ID'      => $values['section_id'],
				'mode'    => $values['mode']

			);

			switch( $values['status'] ) {
				/*
				 * wc_get_logger()->emergency( '' ); system is unusable
				 * wc_get_logger()->alert( '' ); action must be taken immediately
				 * wc_get_logger()->critical( '' ); critical conditions
				 * wc_get_logger()->error( '' ); error conditions
				 * wc_get_logger()->warning( '' ); warning conditions
				 * wc_get_logger()->notice( '' ); normal but significant condition
				 * wc_get_logger()->info( '' ); informational messages
				 * wc_get_logger()->debug( '' ); debug-level messages
				 */
				case 'error':
					wc_get_logger()->error( $message, $args );

					// send email on error
					if ( SENDLOGEMAIL ) self::send_log_email($values);

					break;

				case 'failed':
					wc_get_logger()->warning( $message, $args );
					break;
				default:
					wc_get_logger()->info( $message, $args );
					break;
			}

			return true;
		}

		/**
		 * Send error log by email
		 *
		 * @param $values
		 *
		 * @return bool
		 */
		public static function send_log_email( &$values ): bool {

			ob_start();
			include( plugin_dir_path( __FILE__ ) . '../templates/email/error-email-template.php');
			$email_content = ob_get_contents();
			ob_end_clean();

			$headers = array('Content-Type: text/html; charset=UTF-8');
			if ( !wp_mail( LOGEMAIL, esc_html_x('An error occurred while connecting with Run my Accounts API', 'email', 'run-my-accounts-for-woocommerce'), $email_content, $headers) ) {

				$log_values = array(
					'status'     => 'failed',
					'section_id' => LOGEMAIL,
					'section'    => esc_html_x( 'Email', 'Log Section', 'run-my-accounts-for-woocommerce' ),
					'mode'       => self::rma_mode(),
					'message'    => esc_html_x( 'Failed to send email.' , 'Log', 'run-my-accounts-for-woocommerce') );

				self::write_log($log_values);

			} elseif ( 'complete' == LOGLEVEL) {

				$log_values = array(
					'status'     => 'send',
					'section_id' => LOGEMAIL,
					'section'    => esc_html_x( 'Email', 'Log Section', 'run-my-accounts-for-woocommerce' ),
					'mode'       => self::rma_mode(),
					'message'    => esc_html_x( 'Email sent successfully.' , 'Log', 'run-my-accounts-for-woocommerce') );

				self::write_log($log_values);

			}

			return true;
		}

		public static function delete_order_cache( $order ) {
			foreach ( $order->get_items() as $item ) {
				wp_cache_delete( 'item-' . $item->get_id(), 'order-items' );
			}
			wp_cache_delete( 'order-items-' . $order->get_id(), 'orders' );
		}

	}
}