<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class EmailTemplateEditor {
    private const OPTION = 'enovos_ticket_shop_email_templates';
    private const DEFAULT_LINK_COLOR = '#7f54b3';
    public const ESCH_GADGETS_COMPLETED_ORDER_ID = 'customer_completed_order_esch_gadgets';
    private const COMPLETED_ORDER_ID = 'customer_completed_order';
    private const ESCH_DELIVERY = 'Esch-sur-Alzette';
    /** @var list<string> */
    private const ESCH_GADGETS_CATEGORY_SLUGS = ['gadgets', 'others'];
    private static ?\WC_Email $active_email = null;

    public static function init(): void {
        add_action('admin_post_enovos_save_email_template', [self::class, 'handle_save']);
        add_filter('woocommerce_email_headers', [self::class, 'capture_email_from_headers'], 999, 4);
        add_filter('woocommerce_email_styles', [self::class, 'capture_email'], 1, 2);
        add_filter('woocommerce_mail_content', [self::class, 'replace_mail_content'], 999);
        $subject_ids = array_keys(self::settings()['subjects']);
        $subject_ids[] = self::COMPLETED_ORDER_ID;
        foreach (array_unique($subject_ids) as $email_id) {
            if ($email_id === self::ESCH_GADGETS_COMPLETED_ORDER_ID) {
                // Virtual template key: WooCommerce still sends customer_completed_order.
                continue;
            }
            add_filter('woocommerce_email_subject_' . $email_id, [self::class, 'filter_subject'], 999, 3);
        }
    }

    /**
     * @param mixed $email
     */
    public static function capture_email(string $css, $email): string {
        self::$active_email = $email instanceof \WC_Email && $email->id !== '' ? $email : null;
        return $css;
    }

    /**
     * @param mixed $object
     * @param mixed $email
     */
    public static function capture_email_from_headers(string $headers, string $email_id, $object, $email): string {
        unset($email_id, $object);
        self::$active_email = $email instanceof \WC_Email && $email->id !== '' ? $email : null;
        return $headers;
    }

    public static function replace_mail_content(string $content): string {
        $email = self::$active_email;
        self::$active_email = null;
        if (!$email instanceof \WC_Email || $email->get_email_type() !== 'html') {
            return $content;
        }
        $settings = self::settings();
        $template_id = self::content_template_id($email);
        $template = (string) ($settings['templates'][$template_id] ?? '');
        $has_preheader_placeholder = str_contains($template, '{preheader}');
        if (!empty($settings['enabled']) && trim($template) !== '') {
            $content = self::format_template($template, $email, true, $template_id);
        }
        $preheader_id = self::meta_template_id($email, 'preheaders');
        if ($preheader_id !== $email->id) {
            $settings['preheaders'][$email->id] = (string) ($settings['preheaders'][$preheader_id] ?? '');
        }
        return self::apply_preheader($content, $email, $settings, $has_preheader_placeholder);
    }

    /**
     * @param mixed $object
     * @param mixed $email
     */
    public static function filter_subject(string $subject, $object, $email): string {
        unset($object);
        if (!$email instanceof \WC_Email || $email->id === '') {
            return $subject;
        }
        $template_id = self::meta_template_id($email, 'subjects');
        $configured_subject = trim((string) (self::settings()['subjects'][$template_id] ?? ''));
        if ($configured_subject === '') {
            return $subject;
        }
        $formatted = self::format_template($configured_subject, $email, true, $template_id);
        $formatted = trim(wp_specialchars_decode(wp_strip_all_tags($formatted), ENT_QUOTES));
        return $formatted !== '' ? $formatted : $subject;
    }

    public static function render_settings(): void {
        $choices = self::email_choices();
        $selected_id = sanitize_key((string) ($_GET['email_id'] ?? ''));
        if (!isset($choices[$selected_id])) {
            $selected_id = (string) array_key_first($choices);
        }
        $settings = self::settings();
        $template = (string) ($settings['templates'][$selected_id] ?? '');
        $subject = (string) ($settings['subjects'][$selected_id] ?? '');
        $preheader = (string) ($settings['preheaders'][$selected_id] ?? '');
        $selected = $choices[$selected_id] ?? null;
        $selected_email = is_array($selected) ? ($selected['email'] ?? null) : null;

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
        foreach ($choices as $id => $choice) {
            echo '<option value="' . esc_attr($id) . '" ' . selected($selected_id, $id, false) . '>'
                . esc_html((string) $choice['label']) . '</option>';
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
        echo '<div class="enovos-new-products-settings">';
        echo '<p><label for="enovos_new_products_hours"><strong>' . esc_html__('New products time window (hours)', 'enovos-ticket-shop') . '</strong></label><br>';
        echo '<input type="number" id="enovos_new_products_hours" name="new_products_hours" min="1" max="720" value="' . esc_attr((string) $settings['new_products_hours']) . '"></p>';
        echo '<p><label for="enovos_new_products_limit"><strong>' . esc_html__('Maximum new products', 'enovos-ticket-shop') . '</strong></label><br>';
        echo '<input type="number" id="enovos_new_products_limit" name="new_products_limit" min="1" max="50" value="' . esc_attr((string) $settings['new_products_limit']) . '"></p>';
        echo '<p class="description">' . esc_html__('These values control the new-products placeholders in every custom email template.', 'enovos-ticket-shop') . '</p>';
        echo '<p><label for="enovos_link_color"><strong>' . esc_html__('Email link color', 'enovos-ticket-shop') . '</strong></label><br>';
        echo '<input type="color" id="enovos_link_color" name="link_color" value="' . esc_attr($settings['link_color']) . '"></p>';
        echo '<p class="description">' . esc_html__('Used by links in the ready-made new-products table and available as {link_color} in custom HTML.', 'enovos-ticket-shop') . '</p>';
        echo '</div>';
        if ($selected_id === self::ESCH_GADGETS_COMPLETED_ORDER_ID) {
            echo '<div class="notice notice-info inline"><p>'
                . esc_html__(
                    'Sent instead of the standard Completed order email when the customer delivery field is Esch-sur-Alzette and every product in the order belongs to the gadgets or others category. Leave empty to keep the standard Completed order template for those orders.',
                    'enovos-ticket-shop'
                )
                . '</p></div>';
        }
        $default_subject = (string) $selected_email->get_option('subject', $selected_email->get_default_subject());
        echo '<p><label for="enovos_email_subject"><strong>' . esc_html__('Subject', 'woocommerce') . '</strong></label><br>';
        echo '<input type="text" id="enovos_email_subject" name="email_subject" class="large-text" value="' . esc_attr($subject) . '" placeholder="' . esc_attr($default_subject) . '"></p>';
        echo '<p class="description">' . esc_html__('Leave empty to use the WooCommerce subject. The placeholders listed on this page are supported.', 'enovos-ticket-shop') . '</p>';
        echo '<p><label for="enovos_email_preheader"><strong>' . esc_html__('Preheader text', 'enovos-ticket-shop') . '</strong></label><br>';
        echo '<input type="text" id="enovos_email_preheader" name="email_preheader" class="large-text" value="' . esc_attr($preheader) . '"></p>';
        echo '<p class="description">' . esc_html__('Preview text shown by email clients; 40–90 characters are recommended. Use {preheader} in custom HTML to control its position.', 'enovos-ticket-shop') . '</p>';
        echo '<p><label for="enovos_email_template"><strong>' . esc_html((string) $selected['label']) . '</strong></label></p>';
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
        $choices = self::email_choices();
        if (!isset($choices[$email_id])) {
            wp_die(esc_html__('The selected WooCommerce email is invalid.', 'enovos-ticket-shop'));
        }

        $settings = self::settings();
        $settings['enabled'] = !empty($_POST['custom_templates_enabled']) ? 1 : 0;
        $settings['new_products_hours'] = max(1, min(720, absint($_POST['new_products_hours'] ?? 24)));
        $settings['new_products_limit'] = max(1, min(50, absint($_POST['new_products_limit'] ?? 12)));
        $settings['link_color'] = self::sanitize_link_color(wp_unslash((string) ($_POST['link_color'] ?? '')));
        $settings['templates'][$email_id] = wp_unslash((string) ($_POST['email_template'] ?? ''));
        $subject = sanitize_text_field(wp_unslash((string) ($_POST['email_subject'] ?? '')));
        $preheader = sanitize_text_field(wp_unslash((string) ($_POST['email_preheader'] ?? '')));
        if ($subject === '') {
            unset($settings['subjects'][$email_id]);
        } else {
            $settings['subjects'][$email_id] = $subject;
        }
        if ($preheader === '') {
            unset($settings['preheaders'][$email_id]);
        } else {
            $settings['preheaders'][$email_id] = $preheader;
        }
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
     * @return array{enabled:int,new_products_hours:int,new_products_limit:int,link_color:string,templates:array<string,string>,subjects:array<string,string>,preheaders:array<string,string>}
     */
    private static function settings(): array {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return [
            'enabled' => !empty($stored['enabled']) ? 1 : 0,
            'new_products_hours' => max(1, min(720, absint($stored['new_products_hours'] ?? 24))),
            'new_products_limit' => max(1, min(50, absint($stored['new_products_limit'] ?? 12))),
            'link_color' => self::sanitize_link_color((string) ($stored['link_color'] ?? '')),
            'templates' => is_array($stored['templates'] ?? null) ? $stored['templates'] : [],
            'subjects' => is_array($stored['subjects'] ?? null) ? $stored['subjects'] : [],
            'preheaders' => is_array($stored['preheaders'] ?? null) ? $stored['preheaders'] : [],
        ];
    }

    private static function sanitize_link_color(string $value): string {
        $value = trim($value);
        if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            $value = trim((string) get_option('woocommerce_email_base_color', self::DEFAULT_LINK_COLOR));
        }
        if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            $value = self::DEFAULT_LINK_COLOR;
        }
        if (strlen($value) === 4) {
            $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
        }
        return strtolower($value);
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

    /**
     * @return array<string,array{email:\WC_Email,label:string}>
     */
    private static function email_choices(): array {
        $choices = [];
        foreach (self::emails() as $id => $email) {
            $choices[$id] = [
                'email' => $email,
                'label' => $email->get_title() . ' (' . $id . ')',
            ];
            if ($id === self::COMPLETED_ORDER_ID) {
                $choices[self::ESCH_GADGETS_COMPLETED_ORDER_ID] = [
                    'email' => $email,
                    'label' => __(
                        'Completed order – Esch-sur-Alzette (gadgets & others)',
                        'enovos-ticket-shop'
                    ) . ' (' . self::ESCH_GADGETS_COMPLETED_ORDER_ID . ')',
                ];
            }
        }
        return $choices;
    }

    private static function format_template(
        string $template,
        \WC_Email $email,
        bool $include_preheader = true,
        ?string $template_id = null
    ): string {
        $uses_new_products = preg_match(
            '/\{(?:#|\/)?(?:new_products|no_new_products)(?:_count|_date)?\}|\{product_[a-z_]+\}/',
            $template
        ) === 1;
        $products = $uses_new_products ? self::new_products() : [];
        $formatted = self::expand_repeatable_blocks($template, $products);
        $formatted = strtr(
            $formatted,
            self::replacement_values(
                $email,
                $products,
                $uses_new_products,
                $include_preheader,
                str_contains($template, '{password_reset_url}'),
                $template_id ?? $email->id
            )
        );
        return $email->format_string($formatted);
    }

    /**
     * Template storage key for the HTML body of a completed-order email.
     */
    private static function content_template_id(\WC_Email $email): string {
        if (!self::is_esch_gadgets_completed_order($email)) {
            return $email->id;
        }
        $variant = trim((string) (self::settings()['templates'][self::ESCH_GADGETS_COMPLETED_ORDER_ID] ?? ''));
        return $variant !== '' ? self::ESCH_GADGETS_COMPLETED_ORDER_ID : $email->id;
    }

    /**
     * Template storage key for subject or preheader overrides.
     *
     * @param 'subjects'|'preheaders' $meta_key
     */
    private static function meta_template_id(\WC_Email $email, string $meta_key): string {
        if (!self::is_esch_gadgets_completed_order($email)) {
            return $email->id;
        }
        $variant = trim((string) (self::settings()[$meta_key][self::ESCH_GADGETS_COMPLETED_ORDER_ID] ?? ''));
        return $variant !== '' ? self::ESCH_GADGETS_COMPLETED_ORDER_ID : $email->id;
    }

    private static function is_esch_gadgets_completed_order(\WC_Email $email): bool {
        if ($email->id !== self::COMPLETED_ORDER_ID) {
            return false;
        }
        $order = $email->object ?? null;
        return $order instanceof \WC_Order && self::order_matches_esch_gadgets($order);
    }

    private static function order_matches_esch_gadgets(\WC_Order $order): bool {
        return self::order_has_esch_delivery($order) && self::order_products_are_gadgets_or_others($order);
    }

    private static function order_has_esch_delivery(\WC_Order $order): bool {
        $delivery = OrderDelivery::for_order($order);
        if ($delivery === '') {
            return false;
        }
        $normalized = strtolower(trim((string) preg_replace('/\s+/u', ' ', $delivery)));
        $target = strtolower(self::ESCH_DELIVERY);
        return $normalized === $target || str_contains($normalized, $target);
    }

    private static function order_products_are_gadgets_or_others(\WC_Order $order): bool {
        $has_product = false;
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $product_id = absint($item->get_product_id());
            if ($product_id <= 0) {
                continue;
            }
            $has_product = true;
            if (!self::product_in_esch_gadgets_categories($product_id)) {
                return false;
            }
        }
        return $has_product;
    }

    private static function product_in_esch_gadgets_categories(int $product_id): bool {
        $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);
        if (!is_array($terms) || $terms === []) {
            return false;
        }
        $slugs = array_map('strval', $terms);
        return array_intersect(self::ESCH_GADGETS_CATEGORY_SLUGS, $slugs) !== [];
    }

    /**
     * @param array{preheaders:array<string,string>} $settings
     */
    private static function apply_preheader(
        string $content,
        \WC_Email $email,
        array $settings,
        bool $has_preheader_placeholder = false
    ): string {
        $preheader = trim((string) ($settings['preheaders'][$email->id] ?? ''));
        if ($preheader === '' || $has_preheader_placeholder) {
            return $content;
        }
        $html = self::preheader_html(self::format_template($preheader, $email, false));
        if ($html === '') {
            return $content;
        }
        if (preg_match('/<body\b[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $body = $matches[0][0];
            $offset = $matches[0][1] + strlen($body);
            return substr($content, 0, $offset) . $html . substr($content, $offset);
        }
        return $html . $content;
    }

    private static function preheader_html(string $preheader): string {
        $preheader = trim(wp_specialchars_decode(wp_strip_all_tags($preheader), ENT_QUOTES));
        if ($preheader === '') {
            return '';
        }
        return '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;'
            . 'overflow:hidden;mso-hide:all;">' . esc_html($preheader)
            . '&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;'
            . '</div>';
    }

    /**
     * @param list<\WC_Product> $products
     */
    private static function expand_repeatable_blocks(string $template, array $products): string {
        $template = (string) preg_replace_callback(
            '/\{#new_products\}(.*?)\{\/new_products\}/s',
            static function (array $matches) use ($products): string {
                $rendered = '';
                foreach ($products as $index => $product) {
                    $rendered .= strtr($matches[1], self::product_replacement_values($product, $index + 1));
                }
                return $rendered;
            },
            $template
        );

        return (string) preg_replace_callback(
            '/\{#no_new_products\}(.*?)\{\/no_new_products\}/s',
            static fn(array $matches): string => $products === [] ? $matches[1] : '',
            $template
        );
    }

    /**
     * @param list<\WC_Product> $new_products
     * @return array<string,string>
     */
    private static function replacement_values(
        \WC_Email $email,
        array $new_products = [],
        bool $include_new_products = false,
        bool $include_preheader = true,
        bool $include_password_reset = false,
        ?string $template_id = null
    ): array {
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
        $reset_url = self::reset_password_url($email, $native);
        $email_user = self::email_user($email, $order, $user);
        $settings = self::settings();
        $link_color = $settings['link_color'];
        $preheader_key = $template_id ?? $email->id;
        $preheader = trim((string) ($settings['preheaders'][$preheader_key] ?? ''));
        $values = [
            '{site_title}' => esc_html((string) get_bloginfo('name')),
            '{site_address}' => esc_url($site_url),
            '{site_url}' => esc_url($site_url),
            '{store_address}' => esc_html(implode(', ', $store_address)),
            '{store_email}' => sanitize_email((string) get_option('woocommerce_email_from_address', get_option('admin_email'))),
            '{shop_url}' => esc_url(is_string($shop_url) && $shop_url !== '' ? $shop_url : $site_url),
            '{link_color}' => esc_attr($link_color),
            '{preheader}' => $include_preheader && $preheader !== ''
                ? self::preheader_html(self::format_template($preheader, $email, false))
                : '',
            '{customer_name}' => esc_html($customer_name),
            '{customer_email}' => sanitize_email($customer_email),
            '{customer_first_name}' => esc_html($first_name),
            '{customer_last_name}' => esc_html($last_name),
            '{login_url}' => esc_url(is_string($login_url) && $login_url !== '' ? $login_url : $site_url),
            '{reset_password_url}' => esc_url($reset_url),
            '{password_reset_url}' => $include_password_reset
                ? esc_url(self::password_reset_url($reset_url, $email_user))
                : '',
            '{unsubscribe_url}' => $email_user instanceof \WP_User
                ? esc_url(DigestUnsubscribe::unsubscribe_url($email_user))
                : '',
        ];
        if ($include_new_products) {
            $values += [
                '{new_products}' => self::new_products_html($new_products),
                '{new_products_count}' => esc_html((string) count($new_products)),
                '{new_products_date}' => self::no_wrap(esc_html(date_i18n(
                    (string) get_option('date_format', 'F j, Y'),
                    current_time('timestamp')
                ))),
            ];
        }
        foreach (['{verification_url}', '{approve_url}', '{reject_url}'] as $url_token) {
            if (isset($native[$url_token])) {
                $values[$url_token] = esc_url((string) $native[$url_token]);
            }
        }
        if (isset($native['{customer_domain}'])) {
            $values['{customer_domain}'] = esc_html((string) $native['{customer_domain}']);
        }
        if ($values['{customer_name}'] === '' && isset($native['{customer_name}'])) {
            $values['{customer_name}'] = esc_html((string) $native['{customer_name}']);
        }
        if ($values['{customer_email}'] === '' && isset($native['{customer_email}'])) {
            $values['{customer_email}'] = sanitize_email((string) $native['{customer_email}']);
        }

        if ($order instanceof \WC_Order) {
            $date = $order->get_date_created();
            $view_url = wc_get_endpoint_url('view-order', (string) $order->get_id(), wc_get_page_permalink('myaccount'));
            $delivery = self::order_delivery($order);
            $values += [
                '{order_number}' => self::no_wrap(esc_html($order->get_order_number())),
                '{order_date}' => self::no_wrap(esc_html($date ? wc_format_datetime($date) : '')),
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
                '{admin_order_url}' => esc_url($order->get_edit_order_url()),
                '{delivery}' => nl2br(esc_html($delivery)),
                '{delivery_block}' => self::delivery_block($order, $delivery),
                '{order_items}' => self::order_items_html($order),
            ];
        }
        return $values;
    }

    private static function order_delivery(\WC_Order $order): string {
        return OrderDelivery::for_order($order);
    }

    private static function delivery_block(\WC_Order $order, string $delivery): string {
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if ($name === '' && $delivery === '') {
            return '';
        }
        $content = esc_html($name);
        if ($delivery !== '') {
            $content .= ($content !== '' ? '<br />' : '') . nl2br(esc_html($delivery));
        }
        return '<div class="delivery-name" style="font-size:22px;font-weight:bold;text-transform:uppercase;'
            . 'line-height:0.9;margin-bottom:30px;">' . $content . '</div>';
    }

    /**
     * @param array<string,mixed> $native
     */
    private static function reset_password_url(\WC_Email $email, array $native): string {
        foreach (['{set_password_url}', '{reset_password_url}', '{reset_url}'] as $token) {
            $url = trim((string) ($native[$token] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        $set_password_url = trim((string) ($email->set_password_url ?? ''));
        if ($set_password_url !== '') {
            return $set_password_url;
        }

        $reset_key = trim((string) ($email->reset_key ?? ''));
        $user_id = absint($email->user_id ?? 0);
        $user_login = trim((string) ($email->user_login ?? ''));
        if ($reset_key === '' || $user_id === 0 || $user_login === '') {
            return '';
        }

        return add_query_arg([
            'key' => $reset_key,
            'id' => $user_id,
            'login' => rawurlencode($user_login),
        ], wc_get_endpoint_url('lost-password', '', wc_get_page_permalink('myaccount')));
    }

    /**
     * Reset link for emails that carry no WooCommerce reset data. A fresh key is
     * issued for the recipient so the token never renders an unusable link.
     */
    private static function password_reset_url(string $reset_url, ?\WP_User $user): string {
        if ($reset_url !== '') {
            return $reset_url;
        }

        $lost_password_url = wc_get_endpoint_url('lost-password', '', wc_get_page_permalink('myaccount'));
        if (!$user instanceof \WP_User) {
            return $lost_password_url;
        }

        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            return $lost_password_url;
        }

        return add_query_arg([
            'key' => (string) $key,
            'id' => $user->ID,
            'login' => rawurlencode($user->user_login),
        ], $lost_password_url);
    }

    private static function no_wrap(string $value): string {
        return (string) preg_replace('/[ \t\r\n\f\v]+/', "\u{00A0}", trim($value));
    }

    private static function email_user(
        \WC_Email $email,
        ?\WC_Order $order,
        ?\WP_User $user
    ): ?\WP_User {
        if ($user instanceof \WP_User) {
            return $user;
        }
        if ($order instanceof \WC_Order && $order->get_customer_id() > 0) {
            $order_user = get_userdata($order->get_customer_id());
            if ($order_user instanceof \WP_User) {
                return $order_user;
            }
        }

        $recipients = array_values(array_filter(array_map(
            'trim',
            preg_split('/[,;]+/', $email->get_recipient()) ?: []
        )));
        if (count($recipients) !== 1 || !is_email($recipients[0])) {
            return null;
        }
        $recipient_user = get_user_by('email', $recipients[0]);
        return $recipient_user instanceof \WP_User ? $recipient_user : null;
    }

    /**
     * @return list<\WC_Product>
     */
    private static function new_products(): array {
        $settings = self::settings();
        return NewProducts::get_products($settings['new_products_hours'], $settings['new_products_limit']);
    }

    /**
     * @return array<string,string>
     */
    private static function product_replacement_values(\WC_Product $product, int $index): array {
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'woocommerce_gallery_thumbnail') : false;

        return [
            '{product_name}' => esc_html($product->get_name()),
            '{product_price}' => self::email_price_html($product),
            '{product_url}' => esc_url($product->get_permalink()),
            '{product_image}' => wp_kses_post($product->get_image('woocommerce_gallery_thumbnail', [
                'style' => 'display:block;width:100px;max-width:100px;height:auto;',
                'width' => '100',
            ])),
            '{product_image_url}' => esc_url(is_string($image_url) ? $image_url : ''),
            '{product_sku}' => esc_html($product->get_sku()),
            '{product_description}' => wp_kses_post(wpautop($product->get_description())),
            '{product_concert_date}' => self::concert_date($product),
            '{product_index}' => esc_html((string) $index),
        ];
    }

    /**
     * Email clients do not load the WooCommerce stylesheet, so assistive-only
     * markup such as the "Price range: … through …" duplicate of a variable
     * product price would render next to the visible amount.
     */
    private static function email_price_html(\WC_Product $product): string {
        $price_html = (string) $product->get_price_html();
        $visible = self::strip_assistive_markup($price_html);
        if (trim(wp_strip_all_tags($visible)) === '') {
            $visible = $price_html;
        }
        return trim(wp_kses_post((string) preg_replace('/[ \t\r\n\f\v]+/', ' ', $visible)));
    }

    private static function strip_assistive_markup(string $html): string {
        $offset = 0;
        while (preg_match('/<([a-z][a-z0-9]*)\b([^>]*)>/i', $html, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = (int) $matches[0][1];
            $after_open = $start + strlen($matches[0][0]);
            if (!self::is_assistive_markup((string) $matches[2][0])) {
                $offset = $after_open;
                continue;
            }
            $end = self::closing_tag_offset($html, strtolower((string) $matches[1][0]), $after_open);
            if ($end === null) {
                $offset = $after_open;
                continue;
            }
            $html = substr($html, 0, $start) . substr($html, $end);
            $offset = $start;
        }
        return $html;
    }

    private static function is_assistive_markup(string $attributes): bool {
        return preg_match(
            '/\bclass\s*=\s*["\'][^"\']*\b(?:screen-reader-text|sr-only|visually-hidden|visuallyhidden)\b/i',
            $attributes
        ) === 1;
    }

    private static function closing_tag_offset(string $html, string $tag, int $offset): ?int {
        $depth = 1;
        $pattern = '/<(\/?)' . preg_quote($tag, '/') . '\b[^>]*>/i';
        while (preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $offset = (int) $matches[0][1] + strlen($matches[0][0]);
            $depth += $matches[1][0] === '/' ? -1 : 1;
            if ($depth === 0) {
                return $offset;
            }
        }
        return null;
    }

    /**
     * @param list<\WC_Product> $products
     */
    private static function new_products_html(array $products): string {
        if ($products === []) {
            return '';
        }

        $link_color = esc_attr(self::settings()['link_color']);
        $rows = '';
        foreach ($products as $index => $product) {
            $values = self::product_replacement_values($product, $index + 1);
            $image = $values['{product_image}'];
            $rows .= '<tr><td style="padding:16px 0;border-bottom:1px solid #e5e5e5;">';
            $rows .= '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;" border="0"><tr>';
            if ($image !== '') {
                $rows .= '<td style="width:100px;vertical-align:top;padding-right:16px;"><a href="'
                    . $values['{product_url}'] . '" style="text-decoration:none;">' . $image . '</a></td>';
            }
            $rows .= '<td style="vertical-align:top;"><p style="margin:0 0 8px;font-size:16px;font-weight:bold;"><a href="'
                . $values['{product_url}'] . '" style="color:' . $link_color . ';text-decoration:none;">'
                . $values['{product_name}'] . '</a></p>';
            if ($values['{product_concert_date}'] !== '') {
                $rows .= '<p style="margin:0 0 8px;"><strong>' . esc_html__('Concert date:', 'enovos-ticket-shop')
                    . '</strong> ' . $values['{product_concert_date}'] . '</p>';
            }
            if ($values['{product_price}'] !== '') {
                $rows .= '<p style="margin:0 0 12px;">' . $values['{product_price}'] . '</p>';
            }
            $rows .= '<a href="' . $values['{product_url}'] . '" style="display:inline-block;color:' . $link_color
                . ';text-decoration:none;">'
                . esc_html__('View product', 'enovos-ticket-shop') . '</a></td></tr></table></td></tr>';
        }

        return '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;margin:20px 0;" border="0">'
            . $rows . '</table>';
    }

    private static function concert_date(\WC_Product $product): string {
        $raw_date = trim((string) $product->get_meta('date_of_concert'));
        if ($raw_date === '') {
            return '';
        }

        $date = false;
        foreach (['!Ymd', '!Y-m-d'] as $format) {
            $candidate = \DateTimeImmutable::createFromFormat($format, $raw_date);
            $output_format = str_replace('!', '', $format);
            if ($candidate instanceof \DateTimeImmutable && $candidate->format($output_format) === $raw_date) {
                $date = $candidate;
                break;
            }
        }
        if (!$date instanceof \DateTimeImmutable) {
            $timestamp = strtotime($raw_date);
            if ($timestamp === false) {
                return '';
            }
        } else {
            $timestamp = $date->getTimestamp();
        }

        return self::no_wrap(esc_html(date_i18n((string) get_option('date_format', 'F j, Y'), $timestamp)));
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
        echo '<p>' . esc_html__('Place product field tokens between {#new_products} and {/new_products}. The enclosed HTML is repeated once per product.', 'enovos-ticket-shop') . '</p>';
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
                '{link_color}' => __('Configured email link color', 'enovos-ticket-shop'),
                '{preheader}' => __('Configured hidden email preview text', 'enovos-ticket-shop'),
            ],
            __('New products', 'enovos-ticket-shop') => [
                '{new_products}' => __('Complete HTML product table', 'enovos-ticket-shop'),
                '{new_products_count}' => __('Number of new products', 'enovos-ticket-shop'),
                '{new_products_date}' => __('Current localized date', 'enovos-ticket-shop'),
                '{#new_products}' => __('Start repeated product block', 'enovos-ticket-shop'),
                '{/new_products}' => __('End repeated product block', 'enovos-ticket-shop'),
                '{#no_new_products}' => __('Start fallback block when no products exist', 'enovos-ticket-shop'),
                '{/no_new_products}' => __('End fallback block', 'enovos-ticket-shop'),
                '{product_name}' => __('Product name (inside repeated block)', 'enovos-ticket-shop'),
                '{product_price}' => __('Formatted product price (inside repeated block)', 'enovos-ticket-shop'),
                '{product_url}' => __('Product URL (inside repeated block)', 'enovos-ticket-shop'),
                '{product_image}' => __('Product image HTML (inside repeated block)', 'enovos-ticket-shop'),
                '{product_image_url}' => __('Product image URL (inside repeated block)', 'enovos-ticket-shop'),
                '{product_sku}' => __('Product SKU (inside repeated block)', 'enovos-ticket-shop'),
                '{product_description}' => __('Full product description (inside repeated block)', 'enovos-ticket-shop'),
                '{product_concert_date}' => __('Formatted concert date (inside repeated block)', 'enovos-ticket-shop'),
                '{product_index}' => __('Product position starting at 1 (inside repeated block)', 'enovos-ticket-shop'),
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
                '{admin_order_url}' => __('Administrator / shop manager order edit URL', 'enovos-ticket-shop'),
                '{delivery}' => __('Customer delivery ACF field', 'enovos-ticket-shop'),
                '{delivery_block}' => __('Billing name and customer delivery field HTML block', 'enovos-ticket-shop'),
                '{order_items}' => __('HTML order items table', 'enovos-ticket-shop'),
            ],
            __('Account', 'enovos-ticket-shop') => [
                '{login_url}' => __('My account URL', 'enovos-ticket-shop'),
                '{reset_password_url}' => __('WooCommerce password setup/reset URL when the email provides one', 'enovos-ticket-shop'),
                '{password_reset_url}' => __('Password reset URL that also works in emails without WooCommerce reset data', 'enovos-ticket-shop'),
                '{unsubscribe_url}' => __('Signed daily digest unsubscribe URL', 'enovos-ticket-shop'),
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
        $tokens = [
            '{site_title}', '{site_address}', '{site_url}', '{store_address}', '{store_email}', '{shop_url}',
            '{link_color}', '{preheader}',
            '{new_products}', '{new_products_count}', '{new_products_date}',
            '{#new_products}', '{/new_products}', '{#no_new_products}', '{/no_new_products}',
            '{product_name}', '{product_price}', '{product_url}', '{product_image}', '{product_image_url}',
            '{product_sku}', '{product_description}', '{product_concert_date}', '{product_index}',
        ];
        $object = $email->object ?? null;
        $order_email_ids = [
            'new_order', 'cancelled_order', 'failed_order', 'customer_on_hold_order',
            'customer_processing_order', 'customer_completed_order', 'customer_refunded_order',
            'customer_invoice', 'customer_note',
        ];
        if ($object instanceof \WC_Order || in_array($email->id, $order_email_ids, true)) {
            $tokens = array_merge($tokens, [
                '{customer_name}', '{customer_email}', '{customer_first_name}', '{customer_last_name}',
                '{order_number}', '{order_date}', '{order_total}', '{order_subtotal}', '{order_status}',
                '{payment_method}', '{order_billing_full_name}', '{billing_first_name}', '{billing_last_name}',
                '{billing_address}', '{billing_phone}', '{shipping_address}', '{view_order_url}',
                '{admin_order_url}', '{delivery}', '{delivery_block}', '{order_items}',
            ]);
        }
        if (
            $object instanceof \WP_User
            || !empty($email->customer_email)
            || in_array($email->id, $order_email_ids, true)
            || str_contains($email->id, 'customer_new_account')
            || str_contains($email->id, 'reset_password')
        ) {
            $tokens = array_merge($tokens, [
                '{customer_name}', '{customer_email}', '{customer_first_name}', '{customer_last_name}',
                '{login_url}', '{reset_password_url}', '{password_reset_url}', '{unsubscribe_url}',
            ]);
        }
        $enovos_tokens = [
            'enovos_customer_verify' => ['{customer_name}', '{customer_email}', '{verification_url}'],
            'enovos_admin_customer_approval' => ['{customer_name}', '{customer_email}', '{customer_domain}', '{approve_url}', '{reject_url}'],
            'enovos_customer_approved' => ['{customer_name}', '{customer_email}', '{login_url}', '{password_reset_url}'],
            'enovos_customer_rejected' => ['{customer_name}', '{customer_email}', '{shop_url}'],
        ];
        if (isset($enovos_tokens[$email->id])) {
            $tokens = array_merge($tokens, $enovos_tokens[$email->id]);
        }
        $native = is_array($email->placeholders ?? null) ? array_keys($email->placeholders) : [];
        return array_values(array_unique(array_merge($tokens, $native)));
    }
}
