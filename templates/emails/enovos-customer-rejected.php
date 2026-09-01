<?php
defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p><?php printf(esc_html__('Hello %s,', 'enovos-ticket-shop'), esc_html($customer_name)); ?></p>
<p><?php esc_html_e('We reviewed your customer account, but it was not approved for access to the shop.', 'enovos-ticket-shop'); ?></p>
<p><?php esc_html_e('If you think this is a mistake, please contact the shop team.', 'enovos-ticket-shop'); ?></p>
<p style="margin: 24px 0;">
    <a class="button" href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Visit shop', 'enovos-ticket-shop'); ?></a>
</p>
<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
