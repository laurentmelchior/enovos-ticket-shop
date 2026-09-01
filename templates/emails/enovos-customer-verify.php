<?php
defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p><?php printf(esc_html__('Hello %s,', 'enovos-ticket-shop'), esc_html($customer_name)); ?></p>
<p><?php esc_html_e('Please confirm your email address before signing in to your customer account.', 'enovos-ticket-shop'); ?></p>
<p style="margin: 24px 0;">
    <a class="button" href="<?php echo esc_url($verification_url); ?>"><?php esc_html_e('Confirm email address', 'enovos-ticket-shop'); ?></a>
</p>
<p><?php esc_html_e('This link is valid for 48 hours. If you did not create this account, you can ignore this email.', 'enovos-ticket-shop'); ?></p>
<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
