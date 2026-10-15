<?php
/**
 * Guards for invoice status matching and PDF download checks.
 *
 * These do not call Run my Accounts. Run from the repository root:
 *
 *     php wp-content/plugins/woocommerce-runmyaccounts/tests/invoice-status-pdf-guards.php
 *
 * @package RMA_WC
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

define( 'ABSPATH', __DIR__ );

require_once dirname( __DIR__ ) . '/classes/class-RMA_WC_API.php';

/**
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure label.
 * @return void
 */
function rma_guard_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

rma_guard_same( 'INV-100', RMA_WC_API::normalize_invoice_number( 'inv-100' ), 'uppercases invoice numbers' );
rma_guard_same( 'INV.100', RMA_WC_API::normalize_invoice_number( 'INV.100' ), 'keeps dots that sanitize_key() would remove' );
rma_guard_same( 'INV 100', RMA_WC_API::normalize_invoice_number( 'INV%20100' ), 'decodes a path segment once' );
rma_guard_same( '', RMA_WC_API::normalize_invoice_number( 'INV/100' ), 'rejects a slash' );
rma_guard_same( '', RMA_WC_API::normalize_invoice_number( '../INV' ), 'rejects traversal' );
rma_guard_same( '', RMA_WC_API::normalize_invoice_number( "INV\n100" ), 'rejects a newline' );
rma_guard_same( '', RMA_WC_API::normalize_invoice_number( '' ), 'rejects an empty number' );

$statuses = array(
	'INV-1' => 'PAID',
	'INV-2' => 'OPEN',
	''      => 'OVERDUE',
);

rma_guard_same( 'PAID', RMA_WC_API::matching_invoice_status( 'INV-1', $statuses ), 'matching invoice returns its status' );
rma_guard_same( 'OPEN', RMA_WC_API::matching_invoice_status( ' INV-2 ', $statuses ), 'trims the stored invoice number' );
rma_guard_same( null, RMA_WC_API::matching_invoice_status( 'INV-9', $statuses ), 'unknown invoice is not updated' );
rma_guard_same( null, RMA_WC_API::matching_invoice_status( '', $statuses ), 'empty stored invoice is not updated' );
rma_guard_same( null, RMA_WC_API::matching_invoice_status( 'INV-1', array( 'INV-1' => '  ' ) ), 'blank status is not stored' );

rma_guard_same( true, RMA_WC_API::is_pdf_content_type( 'application/pdf' ), 'exact PDF content type' );
rma_guard_same( true, RMA_WC_API::is_pdf_content_type( 'application/pdf; charset=binary' ), 'PDF content type with a parameter' );
rma_guard_same( true, RMA_WC_API::is_pdf_content_type( 'Application/PDF; charset=binary' ), 'PDF content type is case-insensitive' );
rma_guard_same( true, RMA_WC_API::is_pdf_content_type( 'application/octet-stream' ), 'binary content type is accepted with a PDF body check' );
rma_guard_same( false, RMA_WC_API::is_pdf_content_type( 'text/html' ), 'HTML is not a PDF' );
rma_guard_same( false, RMA_WC_API::is_pdf_content_type( 'application/pdf-evil' ), 'prefix lookalikes are not PDFs' );
rma_guard_same( false, RMA_WC_API::is_pdf_content_type( '' ), 'missing content type is not treated as PDF by itself' );

rma_guard_same( 'K-001', RMA_WC_API::invoice_customer_number( array( 'customer' => array( 'customernumber' => 'K-001' ) ) ), 'nested customer as in the invoice list' );
rma_guard_same( 'K-001', RMA_WC_API::invoice_customer_number( array( 'customernumber' => ' K-001 ' ) ), 'top-level customernumber as in the invoice entity' );
rma_guard_same( '', RMA_WC_API::invoice_customer_number( array( 'customer' => array() ) ), 'missing customer number' );
rma_guard_same( '', RMA_WC_API::invoice_customer_number( 'error page' ), 'non-array response' );

rma_guard_same( true, RMA_WC_API::body_is_pdf( "%PDF-1.4\n%..." ), 'PDF magic in the body' );
rma_guard_same( false, RMA_WC_API::body_is_pdf( 'cURL error 28: Operation timed out after 120000 milliseconds' ), 'timeout text is not a PDF' );
rma_guard_same( false, RMA_WC_API::body_is_pdf( '' ), 'empty body is not a PDF' );
rma_guard_same( false, RMA_WC_API::body_is_pdf( false ), 'false is not a PDF' );

echo "OK\n";
