<?php
defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p><?php esc_html_e('A customer has confirmed their email address and requires manual approval because the email domain is not on the whitelist.', 'enovos-ticket-shop'); ?></p>
<table cellspacing="0" cellpadding="6" style="width: 100%; margin-bottom: 24px;" border="1">
    <tr>
        <th scope="row" style="text-align: left;"><?php esc_html_e('Customer', 'enovos-ticket-shop'); ?></th>
        <td><?php echo esc_html($customer_name); ?></td>
    </tr>
    <tr>
        <th scope="row" style="text-align: left;"><?php esc_html_e('Email', 'enovos-ticket-shop'); ?></th>
        <td><?php echo esc_html($customer_email); ?></td>
    </tr>
    <tr>
        <th scope="row" style="text-align: left;"><?php esc_html_e('Domain', 'enovos-ticket-shop'); ?></th>
        <td><?php echo esc_html($customer_domain); ?></td>
    </tr>
</table>
<p style="margin: 24px 0;">
    <a class="button" href="<?php echo esc_url($approval_url); ?>"><?php esc_html_e('Review customer', 'enovos-ticket-shop'); ?></a>
</p>
<p><?php esc_html_e('You must sign in as a WooCommerce administrator and confirm the approval.', 'enovos-ticket-shop'); ?></p>
<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
