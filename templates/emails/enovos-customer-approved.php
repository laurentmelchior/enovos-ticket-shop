<?php
defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p><?php printf(esc_html__('Hello %s,', 'enovos-ticket-shop'), esc_html($customer_name)); ?></p>
<p><?php esc_html_e('An administrator has approved your customer account. You can now sign in and place orders.', 'enovos-ticket-shop'); ?></p>
<p style="margin: 24px 0;">
    <a class="button" href="<?php echo esc_url($login_url); ?>"><?php esc_html_e('Sign in', 'enovos-ticket-shop'); ?></a>
</p>
<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
