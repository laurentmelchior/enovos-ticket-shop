<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class EmailTemplateEditor {
    private const OPTION = 'enovos_ticket_shop_email_templates';
    private static ?\WC_Email $active_email = null;

    public static function init(): void {
        add_action('admin_post_enovos_save_email_template', [self::class, 'handle_save']);
        add_filter('woocommerce_email_styles', [self::class, 'capture_email'], 1, 2);
        add_filter('woocommerce_mail_content', [self::class, 'replace_mail_content'], 999);
    }

    /**
     * @param mixed $email
     */
    public static function capture_email(string $css, $email): string {
        self::$active_email = $email instanceof \WC_Email ? $email : null;
        return $css;
    }

    public static function replace_mail_content(string $content): string {
        $email = self::$active_email;
        self::$active_email = null;
        if (!$email instanceof \WC_Email) {
            return $content;
        }
        $settings = self::settings();
        $template = (string) ($settings['templates'][$email->id] ?? '');
        if (empty($settings['enabled']) || trim($template) === '') {
            return $content;
        }
        return self::format_template($template, $email);
    }

    public static function render_settings(): void {
        $emails = self::emails();
        $selected_id = sanitize_key((string) ($_GET['email_id'] ?? ''));
        if (!isset($emails[$selected_id])) {
            $selected_id = (string) array_key_first($emails);
        }
        $settings = self::settings();
        $template = (string) ($settings['templates'][$selected_id] ?? '');
        $selected_email = $emails[$selected_id] ?? null;

        echo '<div class="enovos-card"><div class="enovos-card__header"><h2>' . esc_html__('Beefree HTML templates', 'enovos-ticket-shop') . '</h2>';
        echo '<p>' . esc_html__('Export the complete HTML from Beefree, select a WooCommerce email and paste it below.', 'enovos-ticket-shop') . '</p></div>';
        echo '<div class="enovos-card__body">';
        if (!$selected_email instanceof \WC_Email) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('No WooCommerce emails are registered.', 'enovos-ticket-shop') . '</p></div></div></div>';
            return;
        }

        echo '<form method="get" class="enovos-email-selector">';
        echo '<input type="hidden" name="page" value="enovos-ticket-shop-settings">';
        echo '<input type="hidden" name="section" value="email-templates">';
        echo '<label for="enovos_email_id"><strong>' . esc_html__('WooCommerce email', 'enovos-ticket-shop') . '</strong></label>';
        echo '<select id="enovos_email_id" name="email_id">';
        foreach ($emails as $id => $email) {
            echo '<option value="' . esc_attr($id) . '" ' . selected($selected_id, $id, false) . '>' . esc_html($email->get_title() . ' (' . $id . ')') . '</option>';
        }
        echo '</select><button class="button" type="submit">' . esc_html__('Open template', 'enovos-ticket-shop') . '</button></form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="enovos_save_email_template">';
        echo '<input type="hidden" name="email_id" value="' . esc_attr($selected_id) . '">';
        wp_nonce_field('enovos_save_email_template_' . $selected_id);
        echo '<div class="enovos-email-editor-layout"><div class="enovos-email-editor-main">';
        echo '<ul class="enovos-toggle-list">';
        self::render_toggle(!empty($settings['enabled']));
        echo '</ul>';
        echo '<p><label for="enovos_email_template"><strong>' . esc_html($selected_email->get_title()) . '</strong></label></p>';
        echo '<textarea id="enovos_email_template" name="email_template" class="large-text code enovos-email-template-code" spellcheck="false" placeholder="<!doctype html>">' . esc_textarea($template) . '</textarea>';
        echo '<p class="description">' . esc_html__('An empty field uses the original WooCommerce template. Custom HTML is sent as the complete email body without the WooCommerce header or footer.', 'enovos-ticket-shop') . '</p>';
        echo '<p><button class="button button-primary" type="submit">' . esc_html__('Save email template', 'enovos-ticket-shop') . '</button></p></div>';
        self::render_placeholder_reference($selected_email);
        echo '</div></form></div></div>';
    }

    public static function handle_save(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to edit WooCommerce email templates.', 'enovos-ticket-shop'));
        }
        $email_id = sanitize_key((string) ($_POST['email_id'] ?? ''));
        check_admin_referer('enovos_save_email_template_' . $email_id);
        $emails = self::emails();
        if (!isset($emails[$email_id])) {
            wp_die(esc_html__('The selected WooCommerce email is invalid.', 'enovos-ticket-shop'));
        }

        $settings = self::settings();
        $settings['enabled'] = !empty($_POST['custom_templates_enabled']) ? 1 : 0;
        $settings['templates'][$email_id] = wp_unslash((string) ($_POST['email_template'] ?? ''));
        update_option(self::OPTION, $settings, false);

        wp_safe_redirect(add_query_arg([
            'page' => 'enovos-ticket-shop-settings',
            'section' => 'email-templates',
            'email_id' => $email_id,
            'enovos_email_template_notice' => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    public static function render_notice(): void {
        if (sanitize_key((string) ($_GET['enovos_email_template_notice'] ?? '')) !== 'saved') {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Email template saved.', 'enovos-ticket-shop') . '</p></div>';
    }

    /**
     * @return array{enabled:int,templates:array<string,string>}
     */
    private static function settings(): array {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return [
            'enabled' => !empty($stored['enabled']) ? 1 : 0,
            'templates' => is_array($stored['templates'] ?? null) ? $stored['templates'] : [],
        ];
    }

    /**
     * @return array<string,\WC_Email>
     */
    private static function emails(): array {
        if (!function_exists('WC') || !WC()->mailer()) {
            return [];
        }
        $emails = [];
        foreach (WC()->mailer()->get_emails() as $email) {
            if ($email instanceof \WC_Email && $email->id !== '') {
                $emails[$email->id] = $email;
            }
        }
        uasort($emails, static fn(\WC_Email $a, \WC_Email $b): int => strcasecmp($a->get_title(), $b->get_title()));
        return $emails;
    }

    private static function format_template(string $template, \WC_Email $email): string {
        $formatted = $email->format_string($template);
        return strtr($formatted, self::replacement_values($email));
    }

    /**
     * @return array<string,string>
     */
    private static function replacement_values(\WC_Email $email): array {
        $object = $email->object ?? null;
        $order = $object instanceof \WC_Order ? $object : null;
        $user = $object instanceof \WP_User ? $object : null;
        $native = is_array($email->placeholders ?? null) ? $email->placeholders : [];

        $customer_email = '';
        $first_name = '';
        $last_name = '';
        if ($order instanceof \WC_Order) {
            $customer_email = $order->get_billing_email();
            $first_name = $order->get_billing_first_name();
            $last_name = $order->get_billing_last_name();
        } elseif ($user instanceof \WP_User) {
            $customer_email = $user->user_email;
            $first_name = (string) get_user_meta($user->ID, 'first_name', true);
            $last_name = (string) get_user_meta($user->ID, 'last_name', true);
        }
        $customer_name = trim($first_name . ' ' . $last_name);
        if ($customer_name === '' && $user instanceof \WP_User) {
            $customer_name = $user->display_name;
        }

        $store_address = array_filter([
            (string) get_option('woocommerce_store_address', ''),
            (string) get_option('woocommerce_store_address_2', ''),
            trim((string) get_option('woocommerce_store_postcode', '') . ' ' . (string) get_option('woocommerce_store_city', '')),
        ]);
        $site_url = home_url('/');
        $shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : $site_url;
        $login_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : $site_url;
        $reset_url = (string) ($native['{set_password_url}'] ?? $native['{reset_password_url}'] ?? '');

        $values = [
            '{site_title}' => esc_html((string) get_bloginfo('name')),
            '{site_address}' => esc_url($site_url),
            '{site_url}' => esc_url($site_url),
            '{store_address}' => esc_html(implode(', ', $store_address)),
            '{store_email}' => sanitize_email((string) get_option('woocommerce_email_from_address', get_option('admin_email'))),
            '{shop_url}' => esc_url(is_string($shop_url) && $shop_url !== '' ? $shop_url : $site_url),
            '{customer_name}' => esc_html($customer_name),
            '{customer_email}' => sanitize_email($customer_email),
            '{customer_first_name}' => esc_html($first_name),
            '{customer_last_name}' => esc_html($last_name),
            '{login_url}' => esc_url(is_string($login_url) && $login_url !== '' ? $login_url : $site_url),
            '{reset_password_url}' => esc_url($reset_url),
        ];

        if ($order instanceof \WC_Order) {
            $date = $order->get_date_created();
            $view_url = wc_get_endpoint_url('view-order', (string) $order->get_id(), wc_get_page_permalink('myaccount'));
            $values += [
                '{order_number}' => esc_html($order->get_order_number()),
                '{order_date}' => esc_html($date ? wc_format_datetime($date) : ''),
                '{order_total}' => wp_kses_post($order->get_formatted_order_total()),
                '{order_subtotal}' => wp_kses_post(wc_price((float) $order->get_subtotal(), ['currency' => $order->get_currency()])),
                '{order_status}' => esc_html(wc_get_order_status_name($order->get_status())),
                '{payment_method}' => esc_html($order->get_payment_method_title()),
                '{order_billing_full_name}' => esc_html(trim($order->get_formatted_billing_full_name())),
                '{billing_first_name}' => esc_html($order->get_billing_first_name()),
                '{billing_last_name}' => esc_html($order->get_billing_last_name()),
                '{billing_address}' => wp_kses_post($order->get_formatted_billing_address()),
                '{billing_phone}' => esc_html($order->get_billing_phone()),
                '{shipping_address}' => wp_kses_post($order->get_formatted_shipping_address()),
                '{view_order_url}' => esc_url($view_url),
                '{order_items}' => self::order_items_html($order),
            ];
        }
        return $values;
    }

    private static function order_items_html(\WC_Order $order): string {
        $rows = '';
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $rows .= '<tr><td style="padding:8px;border-bottom:1px solid #ddd;">' . esc_html($item->get_name()) . '</td>';
            $rows .= '<td style="padding:8px;border-bottom:1px solid #ddd;text-align:center;">' . esc_html((string) $item->get_quantity()) . '</td>';
            $rows .= '<td style="padding:8px;border-bottom:1px solid #ddd;text-align:right;">' . wp_kses_post($order->get_formatted_line_subtotal($item)) . '</td></tr>';
        }
        if ($rows === '') {
            return '';
        }
        return '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">'
            . '<thead><tr><th style="padding:8px;text-align:left;">' . esc_html__('Product', 'woocommerce') . '</th>'
            . '<th style="padding:8px;text-align:center;">' . esc_html__('Quantity', 'woocommerce') . '</th>'
            . '<th style="padding:8px;text-align:right;">' . esc_html__('Price', 'woocommerce') . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';
    }

    private static function render_toggle(bool $enabled): void {
        echo '<li class="enovos-toggle-item">';
        echo '<label class="enovos-toggle" for="enovos_custom_templates_enabled"><input type="checkbox" id="enovos_custom_templates_enabled" name="custom_templates_enabled" value="1" ' . checked(true, $enabled, false) . '><span></span></label>';
        echo '<div class="enovos-toggle-copy"><strong>' . esc_html__('Use custom HTML templates', 'enovos-ticket-shop') . '</strong>';
        echo '<span>' . esc_html__('When disabled, every email uses its original WooCommerce or plugin template.', 'enovos-ticket-shop') . '</span></div></li>';
    }

    private static function render_placeholder_reference(\WC_Email $email): void {
        $groups = self::placeholder_groups();
        $applicable = self::applicable_placeholders($email);
        echo '<aside class="enovos-placeholder-reference"><h3>' . esc_html__('Shortcodes / placeholders', 'enovos-ticket-shop') . '</h3>';
        echo '<p>' . esc_html__('Click a token to copy it. Highlighted tokens apply to the selected email.', 'enovos-ticket-shop') . '</p>';
        foreach ($groups as $label => $tokens) {
            echo '<h4>' . esc_html($label) . '</h4><ul>';
            foreach ($tokens as $token => $description) {
                $is_applicable = in_array($token, $applicable, true);
                echo '<li' . ($is_applicable ? ' class="is-applicable"' : '') . '><button type="button" class="enovos-copy-token" data-token="' . esc_attr($token) . '"><code>' . esc_html($token) . '</code></button><span>' . esc_html($description) . '</span></li>';
            }
            echo '</ul>';
        }
        $known = [];
        foreach ($groups as $tokens) {
            $known = array_merge($known, array_keys($tokens));
        }
        $native = is_array($email->placeholders ?? null) ? array_keys($email->placeholders) : [];
        $extra_native = array_diff($native, $known);
        if ($extra_native) {
            echo '<h4>' . esc_html__('Native placeholders for this email', 'enovos-ticket-shop') . '</h4><ul>';
            foreach ($extra_native as $token) {
                echo '<li class="is-applicable"><button type="button" class="enovos-copy-token" data-token="' . esc_attr($token) . '"><code>' . esc_html($token) . '</code></button><span>' . esc_html__('Provided by WooCommerce or another plugin', 'enovos-ticket-shop') . '</span></li>';
            }
            echo '</ul>';
        }
        echo '</aside>';
    }

    /**
     * @return array<string,array<string,string>>
     */
    private static function placeholder_groups(): array {
        return [
            __('Shop', 'enovos-ticket-shop') => [
                '{site_title}' => __('Site title', 'enovos-ticket-shop'),
                '{site_address}' => __('Website URL', 'enovos-ticket-shop'),
                '{site_url}' => __('Website URL', 'enovos-ticket-shop'),
                '{store_address}' => __('Store postal address', 'enovos-ticket-shop'),
                '{store_email}' => __('Store sender email', 'enovos-ticket-shop'),
                '{shop_url}' => __('Shop page URL', 'enovos-ticket-shop'),
            ],
            __('Customer', 'enovos-ticket-shop') => [
                '{customer_name}' => __('Customer full or display name', 'enovos-ticket-shop'),
                '{customer_email}' => __('Customer email address', 'enovos-ticket-shop'),
                '{customer_first_name}' => __('Customer first name', 'enovos-ticket-shop'),
                '{customer_last_name}' => __('Customer last name', 'enovos-ticket-shop'),
            ],
            __('Order', 'enovos-ticket-shop') => [
                '{order_number}' => __('Order number', 'enovos-ticket-shop'),
                '{order_date}' => __('Order creation date', 'enovos-ticket-shop'),
                '{order_total}' => __('Formatted order total', 'enovos-ticket-shop'),
                '{order_subtotal}' => __('Formatted order subtotal', 'enovos-ticket-shop'),
                '{order_status}' => __('Order status', 'enovos-ticket-shop'),
                '{payment_method}' => __('Payment method title', 'enovos-ticket-shop'),
                '{order_billing_full_name}' => __('Billing full name', 'enovos-ticket-shop'),
                '{billing_first_name}' => __('Billing first name', 'enovos-ticket-shop'),
                '{billing_last_name}' => __('Billing last name', 'enovos-ticket-shop'),
                '{billing_address}' => __('Formatted billing address', 'enovos-ticket-shop'),
                '{billing_phone}' => __('Billing phone number', 'enovos-ticket-shop'),
                '{shipping_address}' => __('Formatted shipping address', 'enovos-ticket-shop'),
                '{view_order_url}' => __('Customer order URL', 'enovos-ticket-shop'),
                '{order_items}' => __('HTML order items table', 'enovos-ticket-shop'),
            ],
            __('Account', 'enovos-ticket-shop') => [
                '{login_url}' => __('My account URL', 'enovos-ticket-shop'),
                '{reset_password_url}' => __('Password setup/reset URL when available', 'enovos-ticket-shop'),
            ],
            __('Enovos approval', 'enovos-ticket-shop') => [
                '{verification_url}' => __('Email verification URL', 'enovos-ticket-shop'),
                '{customer_domain}' => __('Customer email domain', 'enovos-ticket-shop'),
                '{approve_url}' => __('Administrator approval URL', 'enovos-ticket-shop'),
                '{reject_url}' => __('Administrator rejection URL', 'enovos-ticket-shop'),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function applicable_placeholders(\WC_Email $email): array {
        $tokens = ['{site_title}', '{site_address}', '{site_url}', '{store_address}', '{store_email}', '{shop_url}'];
        $object = $email->object ?? null;
        if ($object instanceof \WC_Order) {
            $tokens = array_merge($tokens, [
                '{customer_name}', '{customer_email}', '{customer_first_name}', '{customer_last_name}',
                '{order_number}', '{order_date}', '{order_total}', '{order_subtotal}', '{order_status}',
                '{payment_method}', '{order_billing_full_name}', '{billing_first_name}', '{billing_last_name}',
                '{billing_address}', '{billing_phone}', '{shipping_address}', '{view_order_url}', '{order_items}',
            ]);
        }
        if ($object instanceof \WP_User || str_contains($email->id, 'customer_new_account') || str_contains($email->id, 'reset_password')) {
            $tokens = array_merge($tokens, [
                '{customer_name}', '{customer_email}', '{customer_first_name}', '{customer_last_name}',
                '{login_url}', '{reset_password_url}',
            ]);
        }
        if (str_starts_with($email->id, 'enovos_')) {
            $tokens = array_merge($tokens, array_keys(self::placeholder_groups()[__('Enovos approval', 'enovos-ticket-shop')]));
        }
        $native = is_array($email->placeholders ?? null) ? array_keys($email->placeholders) : [];
        return array_values(array_unique(array_merge($tokens, $native)));
    }
}
