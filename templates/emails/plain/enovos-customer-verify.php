<?php
defined('ABSPATH') || exit;

echo '= ' . wp_strip_all_tags($email_heading) . " =\n\n";
printf(esc_html__('Hello %s,', 'enovos-ticket-shop'), wp_strip_all_tags($customer_name));
echo "\n\n";
esc_html_e('Please confirm your email address before signing in to your customer account.', 'enovos-ticket-shop');
echo "\n\n" . esc_url_raw($verification_url) . "\n\n";
esc_html_e('This link is valid for 48 hours. If you did not create this account, you can ignore this email.', 'enovos-ticket-shop');
echo "\n";
if ($additional_content) {
    echo "\n" . wp_strip_all_tags(wptexturize($additional_content)) . "\n";
}
