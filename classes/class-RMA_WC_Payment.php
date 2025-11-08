<?php

if ( ! defined('ABSPATH')) exit;

/**
 * Class for sending payment to Run My Accounts
 *
 * @since 1.6.0
 */
class RMA_WC_Payment {

    public $order_id;
    private $settings;
    private $invoice;

    public function __construct() {

        // read rma settings
        $this->settings = get_option('wc_rma_settings');

        // define constants only if they are not defined yet
        if ( !defined( 'RMA_MANDANT' ) )
            self::define_constants();

    }

    /**
     * define default constants
     *
     * @since 1.6.0
     */
    private function define_constants() {
        //ToDO: create a central definition which can be used by all classes

        // check if operation mode is set and is live
        if( isset( $this->settings['rma-mode'] ) && 'live' == $this->settings['rma-mode'] ) {

            // define constants with live values
            DEFINE( 'RMA_MANDANT', ( isset( $this->settings['rma-live-client'] ) ? $this->settings['rma-live-client'] : '' ) );
            DEFINE( 'RMA_APIKEY', ( isset( $this->settings['rma-live-apikey'] ) ? $this->settings['rma-live-apikey'] : '' ) );
            DEFINE( 'RMA_CALLERSANDBOX', FALSE );

        }
        else {

            // set default operation mode to test
            DEFINE( 'RMA_MANDANT', ( isset( $this->settings['rma-test-client'] ) ? $this->settings['rma-test-client'] : '' ) );
            DEFINE( 'RMA_APIKEY', ( isset( $this->settings['rma-test-apikey'] ) ? $this->settings['rma-test-apikey'] : '' ) );
            DEFINE( 'RMA_CALLERSANDBOX', TRUE );

        }

        // if rma-loglevel ist not set, LOGLEVEL is set to error by default
        if( isset( $this->settings['rma-loglevel'] ) ) {
            if( 'error' == $this->settings['rma-loglevel']  || empty( $this->settings['rma-loglevel'] ) ) {
                DEFINE( 'LOGLEVEL' , 'error' );
            }
            elseif ( $this->settings['rma-loglevel'] == 'complete' ) {
                DEFINE( 'LOGLEVEL' , 'complete' );
            }
        } else {
            DEFINE( 'LOGLEVEL' , 'error' );
        }

    }

    /**
     * Send payment
     *
     * @return array|bool
     *
     * @since 1.6.0
     */
    public function send_payment() {

        // bail if the payment method is for payment booking with Run my Accounts
        if( self::check_excluded_payment_options() ) return false;

        // continue only if an order_id is available
        if( !$this->order_id ) return false;

        // get the Run My Accounts invoice number
	    $order = wc_get_order( $this->order_id );
	    $this->invoice = $order->get_meta('_rma_invoice', true );

        $data = self::get_payment_details();
        $url  = RMA_WC_API::get_caller_url() . RMA_MANDANT . '/invoices/' . $this->invoice . '/payments?api_key=' . RMA_APIKEY;

        //create the xml document
        $xml  = new DOMDocument('1.0', 'UTF-8');

        // create root element for payment
        $root = $xml->appendChild( $xml->createElement('payment' ) );
        foreach( $data['payment'] as $key => $value ) {
            if ( ! empty( $key ) )
                $root->appendChild( $xml->createElement( $key, $value ) );
        }

        // make the output pretty
        $xml->formatOutput = true;

        // create xml content
        $xml_str = $xml->saveXML() . "\n";

        // send xml content to RMA
        $response = RMA_WC_API::send_xml_content( $xml_str, $url );

        // $response empty == no errors
        if ( 200 == RMA_WC_API::first_key_of_array( $response ) ||
             204 == RMA_WC_API::first_key_of_array( $response )) {

            $status         = 'paid';

            $message        = sprintf( esc_html_x( 'Payment %1$s %2$s booked on account %3$s', 'Order Note', 'run-my-accounts-for-woocommerce'), $data['payment']['amount_paid'], $data['payment']['currency'], $data['payment']['payment_accno'] );

            // add order note
            $order          = wc_get_order(  $this->order_id );
            $order->add_order_note( $message );

            unset( $order );

        }
        else {

            $status  = 'error';
            $message = '[' . RMA_WC_API::first_key_of_array( $response ) . '] ' . reset( $response ); // get value of first key = return message

        }

        if ( ( 'error' == LOGLEVEL && 'error' == $status ) || 'complete' == LOGLEVEL ) {

            $log_values = array(
                'status'     => $status,
                'section_id' => $this->order_id,
                'section'    => esc_html_x('Payment', 'Log Section', 'run-my-accounts-for-woocommerce'),
                'mode'       => (new RMA_WC_API)->rma_mode(),
                'message'    => $message );

            (new RMA_WC_API)->write_log($log_values);

        }

        return $response;

    }

    /**
     * Check if the order payment is excluded for payment booking with Run my Accounts
     *
     * @return bool
     *
     * @since 1.6.2
     */
    private function check_excluded_payment_options(): bool {

        $order = new WC_Order( $this->order_id );
        $name  = 'rma-payment-trigger-exclude-values-' . $order->get_payment_method();

        if ( isset( $this->settings[ $name ] ) && 1 == $this->settings[ $name ] ) {

            $message        = esc_html_x( 'Payment not booked in Run my Accounts. Payment method is excluded for payment booking.', 'Order Note', 'run-my-accounts-for-woocommerce');
            // add order note
            $order->add_order_note( $message );

            return true;
        }

        return false;

    }

    /**
     * Create payment details
     *
     * @return array
     *
     * @since 1.6.0
     * @author Sandro Lucifora
     */
    private function get_payment_details(): array {

        $option        = get_option( 'wc_rma_settings_accounting' );

        $order         = new WC_Order( $this->order_id );

        $payment_accno = isset( $option[ $order->get_payment_method() . '_payment_account' ] ) && !empty( $option[ $order->get_payment_method() . '_payment_account' ] ) ? $option[ $order->get_payment_method() . '_payment_account' ] : 1020 ;

        return array(
            'payment' => array(
                'id'             => $this->order_id,
                'invnumber'      => $this->invoice,
                'datepaid'       => gmdate(DATE_RFC3339), //$order->get_date_paid() ,//gmdate( DateTime::RFC3339, time() ),
                'amount_paid'    => $order->get_total(),
                'source'         => 'Shop-Payment',
                'memo'           => $order->get_payment_method_title(),
                'payment_accno'  => $payment_accno,
                'currency'       => $order->get_currency(),
                'exchangerate'   => '1.0',
                'flag'           => 'NEW',
            ),
        );

    }

}