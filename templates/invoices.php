<?php
/**
 * Invoices (based on woocommerce/myaccount/orders.php)
 *
 * Shows invoices on the account page.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/myaccount/invoices.php.
 *
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 3.7.0
 */

defined( 'ABSPATH' ) || exit;

$current_page = (int) basename( wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ) ?: 1;
$invoices = $args['invoices']['invoice'];
$has_invoices = ! empty( $invoices ); 

echo '<div><strong>Customer Name:</strong> ' . esc_html( $args['customer_info']['name'] ) . '</div>';
echo '<div><strong>Invoicing ID:</strong> ' . esc_html( $args['customer_info']['customernumber'] ) . '</div>';
echo '<div><strong>Invoicing Email:</strong> ' . esc_html( $args['customer_info']['email'] ) . '</div>';

$max_num_per_page = 20;
$max_num_pages = intdiv( count( $invoices), $max_num_per_page ) + 1;

do_action( 'woocommerce_before_account_invoices', $has_invoices ); 

$invoice_columns = array(
	'invnumber' => __('Invoice #', 'wc-rma' ),
	'amount' => __('Invoice Amount', 'wc-rma' ),
	'duedate' => __('Due Date', 'wc-rma' ),
	'status' => __('Invoice Status', 'wc-rma' ),
	'pdf' => __('Download Invoice (PDF)'),
);

?>

<?php if ( $has_invoices ) : ?>

	<table class="woocommerce-invoices-table woocommerce-MyAccount-invoices shop_table shop_table_responsive my_account_invoices account-invoices-table">
		<thead>
			<tr>
				<?php foreach ( $invoice_columns as $column_id => $column_name ) : ?>
					<th class="woocommerce-invoices-table__header woocommerce-invoices-table__header-<?php echo esc_attr( $column_id ); ?>"><span class="nobr"><?php echo esc_html( $column_name ); ?></span></th>
				<?php endforeach; ?>
			</tr>
		</thead>

		<tbody>
			<?php
			foreach ( array_slice( $invoices, ( $current_page -1 ) * $max_num_per_page, $max_num_per_page, true ) as $invoice ) {
				
				?>
				<tr class="woocommerce-invoices-table__row woocommerce-invoices-table__row--status-<?php echo esc_attr( $invoice['status'] ); ?> invoice">
					<?php foreach ( $invoice_columns as $column_id => $column_name ) : ?>
						<td class="woocommerce-invoices-table__cell woocommerce-invoices-table__cell-<?php echo esc_attr( $column_id ); ?>" data-title="<?php echo esc_attr( $column_name ); ?>">
							<?php if ( has_action( 'woocommerce_my_account_my_invoices_column_' . $column_id ) ) : ?>
								<?php do_action( 'woocommerce_my_account_my_invoices_column_' . $column_id, $invoice ); ?>

							<?php elseif ( 'invnumber' === $column_id ) : ?>
								<a href="<?php echo esc_url( $invoice['url'] ); ?>">
									<?php echo esc_html( _x( '#', 'hash before invoice number', 'woocommerce' ) . $invoice['invnumber'] ); ?>
								</a>
								<?php echo esc_html( $invoice['customer']['name']); ?>

							<?php elseif ( 'duedate' === $column_id ) : ?>
								<time datetime="<?php echo esc_attr( $invoice['duedate'] ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $invoice['duedate'] ) ) ) ; ?></time>

							<?php elseif ( 'status' === $column_id ) : ?>
								<?php echo esc_html( __( $invoice['status'], 'wc-rma' ) ); ?>

							<?php elseif ( 'amount' === $column_id ) : ?>
								<?php
								/* translators: 1: formatted invoice total 2: total invoice items */
								echo  wc_price( esc_html( $invoice['amount'] ) );

								?>

							<?php elseif ( 'pdf' === $column_id ) : ?>
								<?php
								$nonce = wp_create_nonce( 'download-pdf-nonce-' . strtoupper( $invoice['invnumber'] ) );
								$download_link  = '<a href="/my-account/invoices/pdf/' . $invoice['invnumber'];
								$download_link .= '?_wpnonce=' . $nonce . '';
								$download_link .= '" download="' . $invoice['invnumber'] . '.pdf"';
								$download_link .= 'target="_blank"';
								$download_link .= '>';
								$download_link .= 'Download PDF';
								$download_link .= '</a>';
								echo $download_link;
								?>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
				<?php
			}
			?>
		</tbody>
	</table>

	<?php do_action( 'woocommerce_before_account_invoices_pagination' ); ?>

	<?php if ( 1 < $max_num_pages ) : ?>
		<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">
			<?php if ( 1 !== $current_page ) : ?>
				<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button" href="<?php echo esc_url( wc_get_endpoint_url( 'invoices', $current_page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'woocommerce' ); ?></a>
			<?php endif; ?>

			<?php if ( intval( $max_num_pages ) !== $current_page ) : ?>
				<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button" href="<?php echo esc_url( wc_get_endpoint_url( 'invoices', $current_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'woocommerce' ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

<?php else : ?>
	<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
		<a class="woocommerce-Button button" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>"><?php esc_html_e( 'Browse products', 'woocommerce' ); ?></a>
		<?php esc_html_e( 'No invoice has been made yet.', 'woocommerce' ); ?>
	</div>
<?php endif; ?>

<?php do_action( 'woocommerce_after_account_invoices', $has_invoices ); ?>
