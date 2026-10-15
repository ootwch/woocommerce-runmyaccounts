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

$GLOBALS['rma_guard_filters'] = array();

/**
 * Minimal apply_filters(): returns the value registered in $GLOBALS['rma_guard_filters'], if any.
 *
 * @param string $hook  Filter name.
 * @param mixed  $value Default value.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['rma_guard_filters'] ) ? $GLOBALS['rma_guard_filters'][ $hook ] : $value;
}

/**
 * Untranslated __().
 *
 * @param string $text Text.
 * @return string
 */
function __( $text ) {
	return $text;
}

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

rma_guard_same( 'N12345', RMA_WC_API::normalize_customer_number( ' N12345 ' ), 'keeps the case of customer numbers' );
rma_guard_same( 'SCK.789', RMA_WC_API::normalize_customer_number( 'SCK.789' ), 'keeps dots in customer numbers' );
rma_guard_same( 'A&B', RMA_WC_API::normalize_customer_number( 'A&B' ), 'keeps characters that are encoded later' );
rma_guard_same( '', RMA_WC_API::normalize_customer_number( 'N1/2' ), 'rejects a slash in a customer number' );
rma_guard_same( '', RMA_WC_API::normalize_customer_number( '..' ), 'rejects traversal in a customer number' );
rma_guard_same( '', RMA_WC_API::normalize_customer_number( "N1\n2" ), 'rejects a newline in a customer number' );
rma_guard_same( '', RMA_WC_API::normalize_customer_number( '' ), 'rejects an empty customer number' );
rma_guard_same( '', RMA_WC_API::normalize_customer_number( array( 'N1' ) ), 'rejects a non-scalar customer number' );
rma_guard_same( RMA_WC_API::customer_invoices_transient_key( 'N12345' ), RMA_WC_API::customer_invoices_transient_key( ' N12345 ' ), 'cache key ignores surrounding spaces' );
rma_guard_same( 'rma_wc_invoices_N12345', RMA_WC_API::customer_invoices_transient_key( 'N12345' ), 'cache key has the rma_wc_ prefix that uninstall.php removes' );

rma_guard_same( 'Overdue', RMA_WC_API::invoice_status_label( 'OVERDUE' ), 'overdue label' );
rma_guard_same( 'Paid', RMA_WC_API::invoice_status_label( ' paid ' ), 'status label ignores case and spaces' );
rma_guard_same( 'PARTIAL', RMA_WC_API::invoice_status_label( 'partial' ), 'unknown status shows its code' );

rma_guard_same( '2026-02-28', RMA_WC_API::normalize_api_date( '2026-02-28' ), 'valid API date' );
rma_guard_same( '', RMA_WC_API::normalize_api_date( '2026-02-30' ), 'rejects an impossible date' );
rma_guard_same( '', RMA_WC_API::normalize_api_date( '2026-2-1' ), 'rejects a date without leading zeros' );
rma_guard_same( '', RMA_WC_API::normalize_api_date( '2026-02-01&x=1' ), 'rejects extra query text' );
rma_guard_same( array( 'from' => '1900-01-01' ), RMA_WC_API::date_range_query_args( '1900-01-01' ), 'open date range' );
rma_guard_same( array( 'from' => '2026-01-01', 'to' => '2026-01-31' ), RMA_WC_API::date_range_query_args( '2026-01-01', '2026-01-31' ), 'closed date range' );
rma_guard_same( false, RMA_WC_API::date_range_query_args( 'yesterday' ), 'invalid from date' );
rma_guard_same( false, RMA_WC_API::date_range_query_args( '2026-01-01', '31.01.2026' ), 'invalid to date' );

if ( class_exists( 'DOMDocument' ) ) {
	$xml_doc = new DOMDocument( '1.0', 'UTF-8' );
	$xml_out = function ( $value ) use ( $xml_doc ) {
		return $xml_doc->saveXML( RMA_WC_API::xml_text_element( $xml_doc, 'v', $value ) );
	};
	rma_guard_same( '<v>Müller &amp; Co</v>', $xml_out( 'Müller & Co' ), 'ampersand is escaped, not cut off' );
	rma_guard_same( '<v>Segeln &amp; Kurse</v>', $xml_out( 'Segeln &amp; Kurse' ), 'stored entity is not double-escaped' );
	rma_guard_same( '<v>Kurs – Herbst</v>', $xml_out( 'Kurs &#8211; Herbst' ), 'numeric entity is decoded' );
	rma_guard_same( '<v>&lt;b&gt;x&lt;/b&gt;</v>', $xml_out( '<b>x</b>' ), 'markup is escaped as text' );
	rma_guard_same( '<v>12.5</v>', $xml_out( 12.5 ), 'numbers are kept' );
	rma_guard_same( '<v></v>', $xml_out( array( 'x' ) ), 'non-scalar becomes empty' );
} else {
	echo "SKIP: XML element checks need the PHP DOM extension (php-xml).\n";
}

// Invoicing routes: plugin default sends everything to Run my Accounts.
rma_guard_same( 'rma', RMA_WC_API::payment_method_invoice_route( 'cod' ), 'default route is Run my Accounts' );
$params = array();
rma_guard_same(
	"( r.v = %s OR ( COALESCE(r.v, '') = '' AND 1=1 ) )",
	RMA_WC_API::rma_invoice_route_sql( 'o.pm', 'r.v', $params ),
	'default SQL accepts every payment method without an override'
);
rma_guard_same( array( 'rma' ), $params, 'default SQL params' );

// SailCom setup: only sailcom-invoice is invoiced through Run my Accounts.
$GLOBALS['rma_guard_filters'] = array(
	'rma_wc_payment_method_invoice_routes' => array(
		'sailcom-invoice'        => 'rma',
		'sailcom-cash2organizer' => 'organizer',
		'sailcom-third-party'    => 'partner',
	),
	'rma_wc_default_invoice_route'         => 'unassigned',
);
rma_guard_same( 'rma', RMA_WC_API::payment_method_invoice_route( 'sailcom-invoice' ), 'mapped Run my Accounts method' );
rma_guard_same( 'partner', RMA_WC_API::payment_method_invoice_route( 'sailcom-third-party' ), 'mapped partner method' );
rma_guard_same( 'unassigned', RMA_WC_API::payment_method_invoice_route( '' ), 'missing payment method uses the default route' );
$params = array( 'earlier' );
rma_guard_same(
	"( r.v = %s OR ( COALESCE(r.v, '') = '' AND COALESCE(o.pm, '') IN (%s) ) )",
	RMA_WC_API::rma_invoice_route_sql( 'o.pm', 'r.v', $params ),
	'non-RMA default SQL lists the Run my Accounts methods'
);
rma_guard_same( array( 'earlier', 'rma', 'sailcom-invoice' ), $params, 'params follow placeholder order after earlier params' );
$params = array();
rma_guard_same(
	"CASE WHEN COALESCE(r.v, '') <> '' THEN r.v WHEN COALESCE(o.pm, '') = %s THEN %s WHEN COALESCE(o.pm, '') = %s THEN %s WHEN COALESCE(o.pm, '') = %s THEN %s ELSE %s END",
	RMA_WC_API::invoice_route_sql_expression( 'o.pm', 'r.v', $params ),
	'route SQL expression maps each payment method, then the default'
);
rma_guard_same(
	array( 'sailcom-invoice', 'rma', 'sailcom-cash2organizer', 'organizer', 'sailcom-third-party', 'partner', 'unassigned' ),
	$params,
	'route SQL expression params'
);
rma_guard_same( array( 'rma', 'organizer', 'partner', 'unassigned' ), RMA_WC_API::invoice_routes(), 'known routes for the orders-list filter' );

$GLOBALS['rma_guard_filters'] = array(
	'rma_wc_payment_method_invoice_routes' => array( 'cod' => 'cash' ),
);
$params = array();
rma_guard_same(
	"( r.v = %s OR ( COALESCE(r.v, '') = '' AND COALESCE(o.pm, '') NOT IN (%s) ) )",
	RMA_WC_API::rma_invoice_route_sql( 'o.pm', 'r.v', $params ),
	'RMA default SQL excludes the methods billed elsewhere'
);
rma_guard_same( array( 'rma', 'cod' ), $params, 'excluded method params' );

echo "OK\n";
