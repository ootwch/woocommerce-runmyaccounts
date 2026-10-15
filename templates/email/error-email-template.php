<p><?php echo esc_html_X('While connecting with Run my Accounts API the following error occurred:', 'error email body', 'run-my-accounts-for-woocommerce') ?></p>
<table border="1">
    <tr>
        <th><?php echo esc_html_x('Mode', 'error email body', 'run-my-accounts-for-woocommerce') ?></th>
        <th><?php echo esc_html_x('Section', 'error email body', 'run-my-accounts-for-woocommerce') ?></th>
        <th><?php echo esc_html_x('Section ID', 'error email body', 'run-my-accounts-for-woocommerce') ?></th>
        <th><?php echo esc_html_x('Message', 'error email body', 'run-my-accounts-for-woocommerce') ?></th>
    </tr>
    <tr>
        <td><?php echo esc_html( (string) ( $values['mode'] ?? '' ) ); ?></td>
        <td><?php echo esc_html( (string) ( $values['section'] ?? '' ) ); ?></td>
        <td><?php echo esc_html( (string) ( $values['section_id'] ?? '' ) ); ?></td>
        <td><?php echo esc_html( (string) ( $values['message'] ?? '' ) ); ?></td>
    </tr>
</table>
<p><?php esc_html_e( 'Further errors in this section during the next hour are only written to the WooCommerce log (source "Run My Accounts").', 'rma-wc' ); ?></p>
