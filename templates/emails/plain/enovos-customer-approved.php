<?php
defined('ABSPATH') || exit;

echo '= ' . wp_strip_all_tags($email_heading) . " =\n\n";
printf(esc_html__('Hello %s,', 'enovos-ticket-shop'), wp_strip_all_tags($customer_name));
echo "\n\n";
esc_html_e('An administrator has approved your customer account. You can now sign in and place orders.', 'enovos-ticket-shop');
echo "\n\n" . esc_url_raw($login_url) . "\n";
if ($additional_content) {
    echo "\n" . wp_strip_all_tags(wptexturize($additional_content)) . "\n";
}
