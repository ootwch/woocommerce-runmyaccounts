<p><?php echo esc_html_X('While connecting with Run my Accounts API the following error occurred:', 'error email body', 'run-my-accounts-for-woocommerce') ?></p>
<table border="1">
    <tr>
        <th><?php echo esc_html_x('Mode', 'error email body', 'run-my-accounts-for-woocommerce') ?></th>
        <th><?php echo esc_html_x('Section', 'error email body', 'run-my-accounts-for-woocommerce') ?></th>
        <th><?php echo esc_html_x('Section ID', 'error email body', 'run-my-accounts-for-woocommerce') ?></th>
        <th><?php echo esc_html_x('Message', 'error email body', 'run-my-accounts-for-woocommerce') ?></th>
    </tr>
    <tr>
        <td><?php sanitize_text_field( $values['mode'] ); ?></td>
        <td><?php sanitize_text_field( $values['section'] ); ?></td>
        <td><?php sanitize_text_field( $values['section_id'] ); ?></td>
        <td><?php sanitize_text_field( $values['message'] ); ?></td>
    </tr>
</table>
