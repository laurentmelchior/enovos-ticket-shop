<?php
defined('ABSPATH') || exit;

echo '= ' . wp_strip_all_tags($email_heading) . " =\n\n";
printf(esc_html__('Hello %s,', 'enovos-ticket-shop'), wp_strip_all_tags($customer_name));
echo "\n\n";
esc_html_e('We reviewed your customer account, but it was not approved for access to the shop.', 'enovos-ticket-shop');
echo "\n";
esc_html_e('If you think this is a mistake, please contact the shop team.', 'enovos-ticket-shop');
echo "\n\n" . esc_url_raw($shop_url) . "\n";
if ($additional_content) {
    echo "\n" . wp_strip_all_tags(wptexturize($additional_content)) . "\n";
}
