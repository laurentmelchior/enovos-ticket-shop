<?php
defined('ABSPATH') || exit;

echo '= ' . wp_strip_all_tags($email_heading) . " =\n\n";
esc_html_e('A customer has confirmed their email address and requires manual approval because the email domain is not on the whitelist.', 'enovos-ticket-shop');
echo "\n\n";
printf(esc_html__('Customer: %s', 'enovos-ticket-shop'), wp_strip_all_tags($customer_name));
echo "\n";
printf(esc_html__('Email: %s', 'enovos-ticket-shop'), sanitize_email($customer_email));
echo "\n";
printf(esc_html__('Domain: %s', 'enovos-ticket-shop'), wp_strip_all_tags($customer_domain));
echo "\n\n";
printf(esc_html__('Approve: %s', 'enovos-ticket-shop'), esc_url_raw($approve_url));
echo "\n";
printf(esc_html__('Reject: %s', 'enovos-ticket-shop'), esc_url_raw($reject_url));
echo "\n\n";
esc_html_e('You must sign in as a WooCommerce administrator and confirm the selected decision.', 'enovos-ticket-shop');
echo "\n";
if ($additional_content) {
    echo "\n" . wp_strip_all_tags(wptexturize($additional_content)) . "\n";
}
