<?php
declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('HOUR_IN_SECONDS', 3600);
    define('DAY_IN_SECONDS', 86400);
    define('ENOVOS_TICKET_SHOP_VERSION', '0.7.5-test');

    final class WP_Error {
        public function __construct(
            private string $code = '',
            private string $message = '',
            private mixed $data = null
        ) {
        }

        public function get_error_message(): string {
            return $this->message;
        }
    }

    class WC_Email {
        public string $id = 'test_email';
        public mixed $object = null;
        public array $placeholders = [];
        public bool $customer_email = true;
        public string $recipient = '';
        public string $set_password_url = '';
        public string $reset_key = '';
        public int $user_id = 0;
        public string $user_login = '';

        public function format_string(string $value): string {
            return strtr($value, $this->placeholders);
        }

        public function get_recipient(): string {
            return $this->recipient;
        }
    }

    class WP_User {
        public function __construct(
            public int $ID,
            public string $user_email,
            public string $display_name = '',
            public string $user_login = ''
        ) {
        }
    }

    class WC_Order {
        public function __construct(
            private WP_User|false $user = false,
            private string $first_name = 'Test',
            private string $last_name = 'Customer'
        ) {
        }

        public function get_user(): WP_User|false {
            return $this->user;
        }

        public function get_customer_id(): int {
            return $this->user instanceof WP_User ? $this->user->ID : 0;
        }

        public function get_id(): int {
            return 123;
        }

        public function get_order_number(): string {
            return 'ORDER-123';
        }

        public function get_date_created(): null {
            return null;
        }

        public function get_formatted_order_total(): string {
            return '25 EUR';
        }

        public function get_subtotal(): float {
            return 25.0;
        }

        public function get_currency(): string {
            return 'EUR';
        }

        public function get_status(): string {
            return 'processing';
        }

        public function get_payment_method_title(): string {
            return 'Invoice';
        }

        public function get_formatted_billing_full_name(): string {
            return trim($this->first_name . ' ' . $this->last_name);
        }

        public function get_billing_first_name(): string {
            return $this->first_name;
        }

        public function get_billing_last_name(): string {
            return $this->last_name;
        }

        public function get_billing_email(): string {
            return $this->user instanceof WP_User ? $this->user->user_email : 'guest@example.test';
        }

        public function get_formatted_billing_address(): string {
            return '';
        }

        public function get_billing_phone(): string {
            return '';
        }

        public function get_formatted_shipping_address(): string {
            return '';
        }

        public function get_edit_order_url(): string {
            return 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=123';
        }

        public function get_items(): array {
            return [];
        }
    }

    class WC_Product {
        public function __construct(
            private string $name,
            private string $price,
            private string $url,
            private string $image,
            private int $image_id,
            private string $sku,
            private string $description,
            private string $concert_date
        ) {
        }

        public function get_name(): string {
            return $this->name;
        }

        public function get_price_html(): string {
            return $this->price;
        }

        public function get_permalink(): string {
            return $this->url;
        }

        public function get_image(string $size, array $attributes = []): string {
            $GLOBALS['test_image_requests'][] = [$size, $attributes];
            return $this->image;
        }

        public function get_image_id(): int {
            return $this->image_id;
        }

        public function get_sku(): string {
            return $this->sku;
        }

        public function get_description(): string {
            return $this->description;
        }

        public function get_meta(string $key): string {
            return $key === 'date_of_concert' ? $this->concert_date : '';
        }
    }

    $GLOBALS['test_transients'] = [];
    $GLOBALS['test_http_get'] = [];
    $GLOBALS['test_http_head'] = [];
    $GLOBALS['test_http_post'] = [];
    $GLOBALS['test_http_calls'] = [];
    $GLOBALS['test_options'] = [];
    $GLOBALS['test_products'] = [];
    $GLOBALS['test_product_query'] = [];
    $GLOBALS['test_image_requests'] = [];
    $GLOBALS['test_users'] = [];
    $GLOBALS['test_user_meta'] = [];
    $GLOBALS['test_acf_updates'] = [];
    $GLOBALS['test_acf_fields'] = [];
    $GLOBALS['test_reset_keys'] = [];

    function is_wp_error(mixed $value): bool {
        return $value instanceof WP_Error;
    }

    function sanitize_key(string $value): string {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? '';
    }

    function sanitize_text_field(string $value): string {
        return trim(strip_tags($value));
    }

    function wp_unslash(string $value): string {
        return stripslashes($value);
    }

    function sanitize_title(string $value): string {
        $value = strtolower(remove_accents($value));
        return trim(preg_replace('/[^a-z0-9]+/', '-', $value) ?? '', '-');
    }

    function esc_url_raw(string $value): string {
        return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }

    function wp_http_validate_url(string $value): string|false {
        return filter_var($value, FILTER_VALIDATE_URL) ? $value : false;
    }

    function wp_parse_url(string $value, int $component = -1): array|string|int|null|false {
        return parse_url($value, $component);
    }

    function untrailingslashit(string $value): string {
        return rtrim($value, '/\\');
    }

    function remove_accents(string $value): string {
        return $value;
    }

    function wp_strip_all_tags(string $value): string {
        $value = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $value) ?? '';
        return strip_tags($value);
    }

    function wp_specialchars_decode(string $value, int $quote_style = ENT_NOQUOTES): string {
        return htmlspecialchars_decode($value, $quote_style);
    }

    function absint(mixed $value): int {
        return abs((int) $value);
    }

    function get_option(string $name, mixed $default = false): mixed {
        return $GLOBALS['test_options'][$name] ?? $default;
    }

    function is_admin(): bool {
        return !empty($GLOBALS['test_is_admin']);
    }

    function current_user_can(string $capability): bool {
        return in_array($capability, $GLOBALS['test_caps'] ?? [], true);
    }

    function selected(mixed $selected, mixed $current = true, bool $display = true): string {
        $result = (string) $selected === (string) $current ? ' selected="selected"' : '';
        if ($display) {
            echo $result;
        }
        return $result;
    }

    class WP_User_Query {
        public function __construct(private array $query_vars = []) {
        }

        public function get(string $key): mixed {
            return $this->query_vars[$key] ?? '';
        }

        public function set(string $key, mixed $value): void {
            $this->query_vars[$key] = $value;
        }

        public function query_vars(): array {
            return $this->query_vars;
        }
    }

    function esc_html(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function esc_attr(string $value): string {
        return esc_html($value);
    }

    function esc_html__(string $value, string $domain = ''): string {
        unset($domain);
        return esc_html($value);
    }

    function __(string $value, string $domain = ''): string {
        unset($domain);
        return $value;
    }

    function esc_url(string $value): string {
        return filter_var($value, FILTER_VALIDATE_URL) ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '';
    }

    function wp_kses_post(string $value): string {
        return preg_replace('#<script\b[^>]*>.*?</script>#is', '', $value) ?? '';
    }

    function wpautop(string $value): string {
        return $value === '' ? '' : '<p>' . $value . '</p>';
    }

    function wp_get_attachment_image_url(int $image_id, string $size): string|false {
        unset($size);
        return $image_id > 0 ? 'https://example.test/media/' . $image_id . '.jpg' : false;
    }

    function wc_get_products(array $query): array {
        $GLOBALS['test_product_query'] = $query;
        return $GLOBALS['test_products'];
    }

    function current_time(string $type): int {
        unset($type);
        return 1_788_390_000;
    }

    function date_i18n(string $format, int $timestamp): string {
        return gmdate($format, $timestamp);
    }

    function get_bloginfo(string $show): string {
        unset($show);
        return 'Example Shop';
    }

    function home_url(string $path = ''): string {
        return 'https://example.test' . $path;
    }

    function add_query_arg(array $args, string $url): string {
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }

    function wp_salt(string $scheme): string {
        return 'test-salt-' . $scheme;
    }

    function is_email(string $email): string|false {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
    }

    function get_userdata(int $user_id): WP_User|false {
        return $GLOBALS['test_users'][$user_id] ?? false;
    }

    function get_user_by(string $field, string $value): WP_User|false {
        if ($field !== 'email') {
            return false;
        }
        foreach ($GLOBALS['test_users'] as $user) {
            if (strcasecmp($user->user_email, $value) === 0) {
                return $user;
            }
        }
        return false;
    }

    function get_user_meta(int $user_id, string $key, bool $single = false): mixed {
        unset($single);
        return $GLOBALS['test_user_meta'][$user_id][$key] ?? '';
    }

    function update_user_meta(int $user_id, string $key, mixed $value): bool {
        $GLOBALS['test_user_meta'][$user_id][$key] = $value;
        return true;
    }

    function update_field(string $field_key, mixed $value, string $user_reference): bool {
        $GLOBALS['test_acf_updates'][] = [$field_key, $value, $user_reference];
        return true;
    }

    function get_field(string $field_name, string $reference): mixed {
        return $GLOBALS['test_acf_fields'][$reference][$field_name] ?? null;
    }

    function wc_get_page_permalink(string $page): string {
        return 'https://example.test/' . $page;
    }

    function wc_price(float $price, array $args = []): string {
        return (string) $price . ' ' . ($args['currency'] ?? '');
    }

    function wc_get_order_status_name(string $status): string {
        return ucfirst($status);
    }

    function get_password_reset_key(WP_User $user): string|WP_Error {
        $GLOBALS['test_reset_keys'][] = $user->ID;
        return $GLOBALS['test_reset_key'] ?? ('reset-key-' . $user->ID);
    }

    function wc_get_endpoint_url(string $endpoint, string $value, string $permalink): string {
        $suffix = $value === '' ? $endpoint : $endpoint . '/' . $value;
        return rtrim($permalink, '/') . '/' . $suffix;
    }

    function sanitize_email(string $value): string {
        return filter_var($value, FILTER_SANITIZE_EMAIL);
    }

    function wp_json_encode(mixed $value): string|false {
        return json_encode($value);
    }

    function get_transient(string $key): mixed {
        return $GLOBALS['test_transients'][$key] ?? false;
    }

    function set_transient(string $key, mixed $value, int $expiration): bool {
        unset($expiration);
        $GLOBALS['test_transients'][$key] = $value;
        return true;
    }

    function wp_safe_remote_get(string $url, array $args = []): array|WP_Error {
        unset($args);
        $GLOBALS['test_http_calls'][] = ['GET', $url];
        return $GLOBALS['test_http_get'][$url] ?? new WP_Error('unmapped_get', 'Unmapped GET');
    }

    function wp_safe_remote_head(string $url, array $args = []): array|WP_Error {
        unset($args);
        $GLOBALS['test_http_calls'][] = ['HEAD', $url];
        return $GLOBALS['test_http_head'][$url] ?? new WP_Error('unmapped_head', 'Unmapped HEAD');
    }

    function wp_remote_post(string $url, array $args = []): array|WP_Error {
        $GLOBALS['test_http_calls'][] = ['POST', $url];
        $response = array_shift($GLOBALS['test_http_post']);
        return $response ?? new WP_Error('unmapped_post', 'Unmapped POST');
    }

    function wp_remote_retrieve_response_code(array|WP_Error $response): int {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
    }

    function wp_remote_retrieve_body(array|WP_Error $response): string {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }

    function wp_remote_retrieve_header(array|WP_Error $response, string $name): string {
        return is_array($response) ? (string) ($response['headers'][strtolower($name)] ?? '') : '';
    }

    function http_response(int $code, string $body = '', string $content_type = 'text/html'): array {
        return [
            'response' => ['code' => $code],
            'headers' => ['content-type' => $content_type],
            'body' => $body,
        ];
    }
}

namespace Enovos\TicketShop {
    final class Logger {
        public static function log(string $level, string $message, array $context = []): void {
            unset($level, $message, $context);
        }
    }

    require_once dirname(__DIR__) . '/includes/class-enovos-ticket-ai.php';
    require_once dirname(__DIR__) . '/includes/class-enovos-digest-unsubscribe.php';
    require_once dirname(__DIR__) . '/includes/class-enovos-digest-admin.php';
    require_once dirname(__DIR__) . '/includes/class-enovos-new-products.php';
    require_once dirname(__DIR__) . '/includes/class-enovos-email-template-editor.php';
    require_once dirname(__DIR__) . '/includes/class-enovos-ticket-shop.php';

    function invoke_private(string $class, string $method, mixed ...$arguments): mixed {
        $reflection = new \ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, ...$arguments);
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void {
        if ($expected !== $actual) {
            throw new \RuntimeException(
                $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
            );
        }
    }

    function assert_true(bool $condition, string $message): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function reset_http(): void {
        $GLOBALS['test_transients'] = [];
        $GLOBALS['test_http_get'] = [];
        $GLOBALS['test_http_head'] = [];
        $GLOBALS['test_http_post'] = [];
        $GLOBALS['test_http_calls'] = [];
    }

    $hero_html = <<<'HTML'
<html><body>
<section class="recommendations"><p class="date">January 1, 2030</p></section>
<div class="hero-wrapper"><div class="hero-slide-content">
<p class="day-name">Thursday,</p><p class="date">September 24, 2026</p>
</div></div>
</body></html>
HTML;
    assert_same(
        '2026-09-24',
        invoke_private(AI::class, 'atelier_page_date', $hero_html),
        'The scoped hero p.date must win over recommendation dates.'
    );

    reset_http();
    $show_sitemap = 'https://www.atelier.lu/ate_show-sitemap.xml';
    $GLOBALS['test_http_get'][$show_sitemap] = \http_response(
        200,
        '<?xml version="1.0"?><urlset><url><loc>https://www.atelier.lu/shows/cake-4/</loc></url></urlset>',
        'application/xml'
    );
    $urls = invoke_private(AI::class, 'atelier_show_urls_from_sitemap');
    assert_same(['https://www.atelier.lu/shows/cake-4/'], $urls, 'The direct show sitemap must provide show URLs.');
    assert_same(['GET', $show_sitemap], $GLOBALS['test_http_calls'][0], 'The direct show sitemap must be fetched first.');
    assert_same(1, count($GLOBALS['test_http_calls']), 'Generic sitemap fallbacks must not run after a direct sitemap hit.');

    reset_http();
    $ticketmatic_image = 'https://apps.ticketmatic.com/obj/filestore/prod/10328/events/14259/image.697726f2968d3.jpg';
    $GLOBALS['test_http_head'][$ticketmatic_image] = \http_response(200, '', 'image/jpeg');
    $atelier_html = '<html><head><meta property="og:image" content="' . $ticketmatic_image . '"></head></html>';
    assert_same(
        $ticketmatic_image,
        invoke_private(AI::class, 'atelier_header_image', $atelier_html, 'https://www.atelier.lu/shows/cake-4/', []),
        'Ticketmatic og:image must be accepted when extracted from Atelier HTML.'
    );
    assert_same(
        $ticketmatic_image,
        invoke_private(AI::class, 'validate_atelier_image_url', $ticketmatic_image, 'CAKE', []),
        'Ticketmatic images from date-matched Atelier pages must pass download validation.'
    );
    assert_same(
        '',
        invoke_private(AI::class, 'validate_image_url', $ticketmatic_image, 'CAKE', []),
        'The Ticketmatic exception must not apply to arbitrary AI image URLs.'
    );

    reset_http();
    $valid_manual_url = 'https://www.atelier.lu/shows/cake-4/';
    $valid_manual_html = str_replace(
        '</body>',
        '<meta property="og:image" content="' . $ticketmatic_image . '"></body>',
        $hero_html
    );
    $GLOBALS['test_http_get'][$valid_manual_url] = \http_response(200, $valid_manual_html);
    $GLOBALS['test_http_head'][$ticketmatic_image] = \http_response(200, '', 'image/jpeg');
    $manual_event = AI::enrich_manual_atelier_url(
        ['title' => 'CAKE', 'date' => '2026-09-24', 'image_url' => ''],
        $valid_manual_url
    );
    assert_true(!\is_wp_error($manual_event), 'A valid manual Atelier URL must be accepted.');
    assert_same($ticketmatic_image, $manual_event['image_url'], 'A date-matched manual URL must provide its header image.');

    reset_http();
    $network_url = 'https://www.atelier.lu/shows/network-blocked/';
    $GLOBALS['test_http_get'][$network_url] = new \WP_Error('network', 'Network unavailable');
    $network_event = AI::enrich_manual_atelier_url(
        ['title' => 'CAKE', 'date' => '2026-09-24'],
        $network_url
    );
    assert_true(!\is_wp_error($network_event), 'A manual URL must be retained after a network failure.');
    assert_same($network_url, $network_event['atelier_url'], 'The unreachable administrator-entered URL must be retained.');

    reset_http();
    $cloudflare_url = 'https://www.atelier.lu/shows/cloudflare-blocked/';
    $GLOBALS['test_http_get'][$cloudflare_url] = [
        'response' => ['code' => 403],
        'headers' => ['content-type' => 'text/html', 'cf-mitigated' => 'challenge'],
        'body' => '<html><title>Just a moment...</title></html>',
    ];
    $cloudflare_event = AI::enrich_manual_atelier_url(
        ['title' => 'CAKE', 'date' => '2026-09-24'],
        $cloudflare_url
    );
    assert_true(!\is_wp_error($cloudflare_event), 'A manual URL must be retained after a Cloudflare challenge.');
    assert_same($cloudflare_url, $cloudflare_event['atelier_url'], 'The Cloudflare-blocked URL must be retained.');

    reset_http();
    $mismatch_url = 'https://www.atelier.lu/shows/wrong-date/';
    $GLOBALS['test_http_get'][$mismatch_url] = \http_response(
        200,
        '<div class="hero-wrapper"><p class="date">September 25, 2026</p></div>'
    );
    assert_true(
        \is_wp_error(AI::enrich_manual_atelier_url(['title' => 'CAKE', 'date' => '2026-09-24'], $mismatch_url)),
        'A proven manual Atelier date mismatch must be rejected.'
    );
    assert_true(
        \is_wp_error(AI::enrich_manual_atelier_url(['title' => 'CAKE', 'date' => '2026-09-24'], 'http://www.atelier.lu/shows/cake-4/')),
        'HTTP manual URLs must be rejected.'
    );
    assert_true(
        \is_wp_error(AI::enrich_manual_atelier_url(['title' => 'CAKE', 'date' => '2026-09-24'], 'https://example.com/shows/cake-4/')),
        'Foreign manual URL hosts must be rejected.'
    );
    reset_http();
    $missing_url = 'https://www.atelier.lu/shows/does-not-exist/';
    $GLOBALS['test_http_get'][$missing_url] = \http_response(404);
    assert_true(
        \is_wp_error(AI::enrich_manual_atelier_url(['title' => 'CAKE', 'date' => '2026-09-24'], $missing_url)),
        'A reachable non-success Atelier response must be rejected.'
    );

    reset_http();
    $first_source = 'https://artist.example/press';
    $second_source = 'https://label.example/cake';
    $duplicate_image = 'https://cdn.example/images/cake-duplicate.jpg';
    $web_image = 'https://cdn.example/images/cake-press.jpg';
    $GLOBALS['test_http_post'][] = \http_response(
        200,
        json_encode([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'sources' => [
                            ['source_page_url' => $first_source, 'source_type' => 'artist', 'evidence' => 'Official site'],
                            ['source_page_url' => $second_source, 'source_type' => 'label', 'evidence' => 'Label profile'],
                            ['source_page_url' => 'https://press.example/cake', 'source_type' => 'press', 'evidence' => 'Interview'],
                        ],
                    ]),
                ]]],
            ]],
        ]),
        'application/json'
    );
    $GLOBALS['test_http_get'][$first_source] = \http_response(
        200,
        '<title>CAKE official</title><meta property="og:image" content="' . $duplicate_image . '">'
    );
    $GLOBALS['test_http_get'][$second_source] = \http_response(
        200,
        '<title>CAKE artist profile</title><meta property="og:image" content="' . $web_image . '">'
    );
    $GLOBALS['test_http_head'][$web_image] = \http_response(200, '', 'image/jpeg');
    $gemini_event = AI::enrich_atelier(
        ['title' => 'CAKE', 'date' => '2026-09-24', 'atelier_url' => '', 'image_url' => ''],
        ['ai_provider' => 'gemini', 'gemini_api_key' => 'test', 'gemini_model' => 'gemini-3.6-flash'],
        [$duplicate_image]
    );
    assert_same($web_image, $gemini_event['image_url'], 'Gemini must continue when the first source image is already used.');
    assert_same($second_source, $gemini_event['image_source_url'], 'The successful Gemini source page must be recorded.');

    reset_http();
    $GLOBALS['test_http_post'][] = \http_response(
        200,
        json_encode([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'sources' => [
                            ['source_page_url' => $first_source, 'source_type' => 'artist', 'evidence' => 'Official site'],
                            ['source_page_url' => $second_source, 'source_type' => 'label', 'evidence' => 'Label profile'],
                        ],
                    ]),
                ]]],
            ]],
        ]),
        'application/json'
    );
    $GLOBALS['test_http_get'][$first_source] = \http_response(200, '<title>CAKE official</title>');
    $GLOBALS['test_http_get'][$second_source] = \http_response(200, '<title>CAKE label profile</title>');
    $missing_image_event = AI::enrich_atelier(
        ['title' => 'CAKE', 'date' => '2026-09-24', 'atelier_url' => '', 'image_url' => ''],
        ['ai_provider' => 'gemini', 'gemini_api_key' => 'test', 'gemini_model' => 'gemini-3.6-flash']
    );
    assert_same('', $missing_image_event['image_url'], 'Gemini must leave the image empty when all source pages fail.');
    assert_true(
        str_contains($missing_image_event['_image_warning'], 'none yielded'),
        'Gemini must explain when all source pages fail.'
    );

    reset_http();
    $GLOBALS['test_http_get'][$network_url] = new \WP_Error('network', 'Network unavailable');
    $posted_review = invoke_private(
        Plugin::class,
        'apply_reviewed_atelier_url',
        ['title' => 'CAKE', 'date' => '2026-09-24', 'atelier_url' => ''],
        $network_url,
        []
    );
    assert_true(!\is_wp_error($posted_review), 'A newly entered import URL must be processed.');
    assert_same($network_url, $posted_review['atelier_url'], 'A newly entered import URL must replace the analyzed value.');

    $reviewed = invoke_private(
        Plugin::class,
        'apply_reviewed_atelier_url',
        ['title' => 'CAKE', 'date' => '2026-09-24', 'atelier_url' => $valid_manual_url],
        '',
        []
    );
    assert_same('', $reviewed['atelier_url'], 'An empty reviewed import field must clear the Atelier URL.');

    $GLOBALS['test_options']['enovos_ticket_shop_email_templates'] = [
        'enabled' => 1,
        'new_products_hours' => 24,
        'new_products_limit' => 12,
        'link_color' => '#005ca9',
        'templates' => [],
    ];
    $GLOBALS['test_options']['woocommerce_email_base_color'] = '#7F54B3';
    $GLOBALS['test_options']['date_format'] = 'Y-m-d';
    assert_same(
        '#aabbcc',
        invoke_private(EmailTemplateEditor::class, 'sanitize_link_color', '#abc'),
        'A valid three-digit link color must be expanded for the HTML color input.'
    );
    assert_same(
        '#abcdef',
        invoke_private(EmailTemplateEditor::class, 'sanitize_link_color', '#ABCDEF'),
        'A valid six-digit link color must be normalized.'
    );
    assert_same(
        '#7f54b3',
        invoke_private(EmailTemplateEditor::class, 'sanitize_link_color', 'red'),
        'An invalid link color must fall back to the WooCommerce email base color.'
    );
    $GLOBALS['test_options']['woocommerce_email_base_color'] = 'invalid';
    assert_same(
        '#7f54b3',
        invoke_private(EmailTemplateEditor::class, 'sanitize_link_color', '#12345'),
        'An invalid WooCommerce base color must fall back to the plugin default.'
    );
    $GLOBALS['test_options']['woocommerce_email_base_color'] = '#7F54B3';
    $GLOBALS['test_products'] = [
        new \WC_Product(
            '<script>alert(1)</script>First product',
            '<span class="amount">10 EUR</span>',
            'https://example.test/product/first',
            '<img src="https://example.test/first.jpg" alt="">',
            101,
            'SKU-1',
            'First <strong>description</strong> ' . str_repeat('a', 210) . ' FULL-END',
            '20260924'
        ),
        new \WC_Product(
            'Second product',
            '<span class="amount">20 EUR</span>',
            'https://example.test/product/second',
            '',
            102,
            'SKU-2',
            '<script>unsafe()</script>Second description',
            '2026-09-25'
        ),
    ];
    $email = new \WC_Email();
    assert_same(
        "7\u{00A0}September\u{00A0}2026",
        invoke_private(EmailTemplateEditor::class, 'no_wrap', " 7  September\n2026 "),
        'Non-breaking tokens must replace whitespace runs with non-breaking spaces.'
    );
    $GLOBALS['test_options']['date_format'] = 'F j, Y';
    $localized_dates = invoke_private(
        EmailTemplateEditor::class,
        'format_template',
        '{new_products_date}|{#new_products}{product_concert_date}{/new_products}',
        $email
    );
    assert_true(
        str_contains($localized_dates, "\u{00A0}") && !str_contains($localized_dates, ' '),
        'Localized current and concert dates must use non-breaking spaces.'
    );
    $GLOBALS['test_options']['date_format'] = 'Y-m-d';

    $reset_email = new \WC_Email();
    $reset_email->id = 'customer_reset_password';
    $reset_email->reset_key = 'reset-key';
    $reset_email->user_id = 42;
    $reset_email->user_login = 'customer-name';
    assert_same(
        'https://example.test/myaccount/lost-password?key=reset-key&amp;id=42&amp;login=customer-name',
        invoke_private(EmailTemplateEditor::class, 'format_template', '{reset_password_url}', $reset_email),
        'Reset-password emails must build the signed WooCommerce password-reset URL.'
    );

    $new_account_email = new \WC_Email();
    $new_account_email->id = 'customer_new_account';
    $new_account_email->set_password_url = 'https://example.test/myaccount/lost-password?action=newaccount&key=abc';
    assert_same(
        'https://example.test/myaccount/lost-password?action=newaccount&amp;key=abc',
        invoke_private(EmailTemplateEditor::class, 'format_template', '{reset_password_url}', $new_account_email),
        'New-account emails must expose WooCommerce set_password_url through the reset token.'
    );
    assert_same(
        '',
        invoke_private(EmailTemplateEditor::class, 'format_template', '{reset_password_url}', new \WC_Email()),
        'The reset-password token must stay empty when no signed URL data is available.'
    );

    assert_same(
        'https://example.test/myaccount/lost-password?key=reset-key&amp;id=42&amp;login=customer-name',
        invoke_private(EmailTemplateEditor::class, 'format_template', '{password_reset_url}', $reset_email),
        'The own reset token must reuse WooCommerce reset data instead of issuing a new key.'
    );
    assert_same([], $GLOBALS['test_reset_keys'], 'WooCommerce reset data must not trigger a new reset key.');

    $approved_email = new \WC_Email();
    $approved_email->id = 'enovos_customer_approved';
    $approved_email->object = new \WP_User(9, 'approved@example.test', 'Approved Customer', 'approved-customer');
    assert_same(
        'https://example.test/myaccount/lost-password?key=reset-key-9&amp;id=9&amp;login=approved-customer',
        invoke_private(EmailTemplateEditor::class, 'format_template', '{password_reset_url}', $approved_email),
        'The own reset token must issue a reset key for emails without WooCommerce reset data.'
    );
    assert_same([9], $GLOBALS['test_reset_keys'], 'A reset key must be issued exactly once for the recipient.');

    $GLOBALS['test_reset_keys'] = [];
    invoke_private(EmailTemplateEditor::class, 'format_template', '{login_url}', $approved_email);
    assert_same([], $GLOBALS['test_reset_keys'], 'Templates without the own reset token must not issue reset keys.');

    $GLOBALS['test_reset_key'] = new \WP_Error('no_key', 'Key generation failed');
    assert_same(
        'https://example.test/myaccount/lost-password',
        invoke_private(EmailTemplateEditor::class, 'format_template', '{password_reset_url}', $approved_email),
        'A failed reset key must fall back to the lost-password page.'
    );
    unset($GLOBALS['test_reset_key']);
    assert_same(
        'https://example.test/myaccount/lost-password',
        invoke_private(EmailTemplateEditor::class, 'format_template', '{password_reset_url}', new \WC_Email()),
        'The own reset token must fall back to the lost-password page without a recipient.'
    );
    $GLOBALS['test_reset_keys'] = [];

    $GLOBALS['test_options']['enovos_ticket_shop_email_templates']['subjects']['test_email']
        = 'Tickets for {site_title}: <strong>{customer_name}</strong>';
    $email->object = new \WP_User(8, 'subject@example.test', 'Subject Customer');
    assert_same(
        'Tickets for Example Shop: Subject Customer',
        EmailTemplateEditor::filter_subject('WooCommerce fallback', null, $email),
        'The configured subject must replace placeholders and strip HTML.'
    );
    $GLOBALS['test_options']['enovos_ticket_shop_email_templates']['subjects']['test_email'] = ' <strong></strong> ';
    assert_same(
        'WooCommerce fallback',
        EmailTemplateEditor::filter_subject('WooCommerce fallback', null, $email),
        'An empty formatted subject must retain the WooCommerce fallback.'
    );
    unset($GLOBALS['test_options']['enovos_ticket_shop_email_templates']['subjects']['test_email']);

    $GLOBALS['test_options']['enovos_ticket_shop_email_templates']['preheaders']['test_email']
        = 'Preview for {site_title} & tickets';
    $settings = invoke_private(EmailTemplateEditor::class, 'settings');
    $content = invoke_private(
        EmailTemplateEditor::class,
        'apply_preheader',
        '<!doctype html><html><body class="mail"><p>Body</p></body></html>',
        $email,
        $settings
    );
    assert_true(
        str_contains($content, '<body class="mail"><div style="display:none;'),
        'The preheader must be inserted immediately after the opening body tag.'
    );
    assert_true(
        str_contains($content, 'Preview for Example Shop &amp; tickets'),
        'The preheader must render placeholders and escape its text.'
    );
    $custom_preheader = invoke_private(EmailTemplateEditor::class, 'format_template', '{preheader}<p>Body</p>', $email);
    $without_duplicate = invoke_private(
        EmailTemplateEditor::class,
        'apply_preheader',
        $custom_preheader,
        $email,
        $settings,
        true
    );
    assert_same(
        1,
        substr_count($without_duplicate, 'display:none;font-size:1px'),
        'A custom {preheader} placeholder must not be duplicated automatically.'
    );
    unset($GLOBALS['test_options']['enovos_ticket_shop_email_templates']['preheaders']['test_email']);
    $settings = invoke_private(EmailTemplateEditor::class, 'settings');
    assert_same(
        '<p>Body</p>',
        invoke_private(EmailTemplateEditor::class, 'apply_preheader', '<p>Body</p>', $email, $settings),
        'Content must remain unchanged when no preheader is configured.'
    );
    $email->object = null;

    assert_same(
        '#005ca9',
        invoke_private(EmailTemplateEditor::class, 'format_template', '{link_color}', $email),
        'The link-color placeholder must contain the configured color.'
    );
    $order_user = new \WP_User(44, 'order@example.test', 'Order Customer');
    $GLOBALS['test_acf_fields']['user_44']['delivery'] = "Gate <script>alert(1)</script>\nSecond floor";
    $order_email = new \WC_Email();
    $order_email->id = 'customer_processing_order';
    $order_email->object = new \WC_Order($order_user, 'Ada <Admin>', 'Lovelace');
    $order_tokens = invoke_private(
        EmailTemplateEditor::class,
        'format_template',
        '{admin_order_url}|{delivery}|{delivery_block}',
        $order_email
    );
    assert_true(
        str_starts_with(
            $order_tokens,
            'https://example.test/wp-admin/admin.php?page=wc-orders&amp;action=edit&amp;id=123|'
        ),
        'The admin-order URL must use and safely escape the WooCommerce edit URL.'
    );
    assert_true(
        str_contains($order_tokens, 'Gate &lt;script&gt;alert(1)&lt;/script&gt;<br />' . "\n" . 'Second floor'),
        'The delivery token must escape ACF content and preserve line breaks.'
    );
    assert_true(
        str_contains($order_tokens, 'ADA &lt;ADMIN&gt; LOVELACE') === false
        && str_contains($order_tokens, 'Ada &lt;Admin&gt; Lovelace<br />Gate'),
        'The delivery block must safely render the billing name and delivery field for email clients.'
    );
    assert_true(!str_contains($order_tokens, '<script>'), 'Order email tokens must not render executable ACF or name HTML.');

    $guest_email = new \WC_Email();
    $guest_email->id = 'customer_processing_order';
    $guest_email->object = new \WC_Order(false, 'Guest', 'Customer');
    assert_same(
        '|<div class="delivery-name" style="font-size:22px;font-weight:bold;text-transform:uppercase;'
        . 'line-height:0.9;margin-bottom:30px;">Guest Customer</div>',
        invoke_private(EmailTemplateEditor::class, 'format_template', '{delivery}|{delivery_block}', $guest_email),
        'Guest orders must leave delivery empty while retaining the billing name block.'
    );
    $product_template = '{new_products_count}|{#new_products}'
        . '{product_index}:{product_name}:{product_price}:{product_url}:{product_image_url}:'
        . '{product_sku}:{product_concert_date}:{product_description};{/new_products}'
        . '{#no_new_products}No products{/no_new_products}|{new_products}';
    $rendered_products = invoke_private(EmailTemplateEditor::class, 'format_template', $product_template, $email);
    assert_true(str_starts_with($rendered_products, '2|'), 'The flat product count token must contain the query result count.');
    assert_true(
        str_contains($rendered_products, '1:&lt;script&gt;alert(1)&lt;/script&gt;First product'),
        'Repeated product blocks must escape product names.'
    );
    assert_true(
        str_contains($rendered_products, '2:Second product') && !str_contains($rendered_products, 'unsafe()'),
        'Repeated product blocks must render each product and sanitize descriptions.'
    );
    assert_true(
        str_contains($rendered_products, 'SKU-1:2026-09-24:')
        && str_contains($rendered_products, 'SKU-2:2026-09-25:'),
        'Concert dates in Ymd and Y-m-d formats must be localized consistently.'
    );
    assert_true(!str_contains($rendered_products, 'No products'), 'The empty-result block must be removed when products exist.');
    assert_true(
        str_contains($rendered_products, '<table role="presentation"')
        && str_contains($rendered_products, 'View product'),
        'The flat new-products token must render the ready-made HTML table.'
    );
    assert_true(
        str_contains($rendered_products, 'style="color:#005ca9;text-decoration:none;"')
        && str_contains($rendered_products, 'style="display:inline-block;color:#005ca9;text-decoration:none;"'),
        'The ready-made product table must apply the configured color to both text links.'
    );
    assert_same(
        'woocommerce_gallery_thumbnail',
        $GLOBALS['test_image_requests'][0][0],
        'Email product images must use the smaller WooCommerce gallery thumbnail.'
    );
    assert_same('100', $GLOBALS['test_image_requests'][0][1]['width'], 'Email product images must be limited to 100 pixels.');
    assert_true(
        str_contains($rendered_products, '<strong>Concert date:</strong> 2026-09-24'),
        'The ready-made product table must display the concert date.'
    );
    $first_product_values = invoke_private(
        EmailTemplateEditor::class,
        'product_replacement_values',
        $GLOBALS['test_products'][0],
        1
    );
    assert_true(
        str_contains($first_product_values['{product_description}'], 'FULL-END'),
        'The repeated-block product description token must contain the full description.'
    );
    $product_table = invoke_private(EmailTemplateEditor::class, 'new_products_html', [$GLOBALS['test_products'][0]]);
    assert_true(
        !str_contains($product_table, 'FULL-END') && !str_contains($product_table, 'First description'),
        'The ready-made product table must omit product descriptions.'
    );
    $undated_product = new \WC_Product('Undated', '', 'https://example.test/undated', '', 0, '', 'Description', '');
    assert_same(
        '',
        invoke_private(EmailTemplateEditor::class, 'concert_date', $undated_product),
        'Products without a concert date must render an empty date token.'
    );
    assert_same('publish', $GLOBALS['test_product_query']['status'], 'The product query must only include published products.');
    assert_same('catalog', $GLOBALS['test_product_query']['visibility'], 'The product query must exclude catalog-hidden products.');
    assert_same(12, $GLOBALS['test_product_query']['limit'], 'The configured product limit must reach the query.');
    assert_true(
        str_starts_with($GLOBALS['test_product_query']['date_created'], '>'),
        'The product query must include a lower creation-date boundary.'
    );

    $GLOBALS['test_options']['enovos_ticket_shop_email_templates']['new_products_hours'] = 25;
    $GLOBALS['test_products'] = [];
    $empty_template = '{new_products_count}|{#new_products}{product_name}{/new_products}'
        . '{#no_new_products}No products{/no_new_products}|{new_products}';
    $rendered_empty = invoke_private(EmailTemplateEditor::class, 'format_template', $empty_template, $email);
    assert_same('0|No products|', $rendered_empty, 'An empty query must remove product output and render its fallback block.');

    $price_prompt = invoke_private(AI::class, 'price_search_prompt', [
        'title' => 'CAKE',
        'date' => '2026-09-24',
        'venue' => 'Rockhal',
        'atelier_url' => 'https://www.atelier.lu/shows/cake/',
    ], false);
    assert_true(
        str_contains($price_prompt, 'ticketmatic.com'),
        'The AI price search prompt must identify Ticketmatic as an exact-event price source.'
    );
    $atelier_prompt = invoke_private(AI::class, 'atelier_prompt', 'https://www.atelier.lu/shows/cake/', [
        'title' => 'CAKE',
        'date' => '2026-09-24',
    ]);
    assert_true(
        str_contains($atelier_prompt, 'ticketmatic.com'),
        'The Atelier enrichment prompt must identify Ticketmatic as an exact-event price source.'
    );

    $digest_user = new \WP_User(7, 'digest@example.test', 'Digest Customer');
    $GLOBALS['test_users'][$digest_user->ID] = $digest_user;
    $GLOBALS['test_user_meta'][$digest_user->ID]['send_daily_digest'] = '1';
    $unsubscribe_url = DigestUnsubscribe::unsubscribe_url($digest_user);
    assert_same(
        '1',
        $GLOBALS['test_user_meta'][$digest_user->ID]['send_daily_digest'],
        'Creating or viewing an unsubscribe URL must not change the subscription.'
    );
    parse_str((string) parse_url($unsubscribe_url, PHP_URL_QUERY), $unsubscribe_query);
    $validated_user = invoke_private(
        DigestUnsubscribe::class,
        'validate_signature',
        (int) $unsubscribe_query['user_id'],
        (int) $unsubscribe_query['expires'],
        (string) $unsubscribe_query['signature']
    );
    assert_same($digest_user, $validated_user, 'A valid unsubscribe signature must resolve the intended user.');
    assert_same(
        null,
        invoke_private(
            DigestUnsubscribe::class,
            'validate_signature',
            $digest_user->ID,
            (int) $unsubscribe_query['expires'],
            'tampered' . $unsubscribe_query['signature']
        ),
        'A modified unsubscribe signature must be rejected.'
    );
    $expired_at = time() - 1;
    $expired_signature = invoke_private(DigestUnsubscribe::class, 'signature', $digest_user, $expired_at);
    assert_same(
        null,
        invoke_private(DigestUnsubscribe::class, 'validate_signature', $digest_user->ID, $expired_at, $expired_signature),
        'An expired unsubscribe signature must be rejected.'
    );

    invoke_private(DigestUnsubscribe::class, 'unsubscribe_user', $digest_user);
    invoke_private(DigestUnsubscribe::class, 'unsubscribe_user', $digest_user);
    assert_same(
        '0',
        $GLOBALS['test_user_meta'][$digest_user->ID]['send_daily_digest'],
        'A valid unsubscribe submission must disable the daily digest idempotently.'
    );
    assert_same(
        ['field_6a450c952f711', false, 'user_7'],
        $GLOBALS['test_acf_updates'][0],
        'The unsubscribe submission must update the configured ACF user field.'
    );

    $digest_email = new \WC_Email();
    $digest_email->object = $digest_user;
    $rendered_unsubscribe_url = invoke_private(
        EmailTemplateEditor::class,
        'format_template',
        '{unsubscribe_url}',
        $digest_email
    );
    assert_true(
        str_contains($rendered_unsubscribe_url, 'user_id=7'),
        'The unsubscribe placeholder must create a URL for the email user.'
    );
    $digest_email->object = null;
    $digest_email->recipient = 'digest@example.test';
    assert_true(
        str_contains(
            invoke_private(EmailTemplateEditor::class, 'format_template', '{unsubscribe_url}', $digest_email),
            'user_id=7'
        ),
        'The unsubscribe placeholder must resolve one registered email recipient as a fallback.'
    );
    $digest_email->recipient = 'first@example.test, second@example.test';
    assert_same(
        '',
        invoke_private(EmailTemplateEditor::class, 'format_template', '{unsubscribe_url}', $digest_email),
        'The unsubscribe placeholder must remain empty when the recipient is ambiguous.'
    );

    $columns = DigestAdmin::add_column(['username' => 'Username']);
    assert_true(
        isset($columns[DigestAdmin::COLUMN]),
        'The users table must register a Daily Digest column.'
    );
    assert_same(
        DigestAdmin::COLUMN,
        DigestAdmin::add_sortable_column([])[DigestAdmin::COLUMN],
        'The Daily Digest column must be sortable.'
    );
    $GLOBALS['test_user_meta'][21]['send_daily_digest'] = '1';
    $GLOBALS['test_user_meta'][22]['send_daily_digest'] = '0';
    assert_same(
        'Subscribed',
        DigestAdmin::render_column('', DigestAdmin::COLUMN, 21),
        'A stored value of 1 must display as subscribed.'
    );
    assert_same(
        'Not subscribed',
        DigestAdmin::render_column('', DigestAdmin::COLUMN, 22),
        'A stored value of 0 must display as not subscribed.'
    );
    assert_same(
        'Not subscribed',
        DigestAdmin::render_column('', DigestAdmin::COLUMN, 23),
        'Missing digest meta must display as not subscribed.'
    );
    assert_same(
        'unchanged',
        DigestAdmin::render_column('unchanged', 'enovos_approval_status', 21),
        'Other user columns must keep their original output.'
    );

    $_GET[DigestAdmin::FILTER] = 'subscribed';
    assert_same(DigestAdmin::FILTER_SUBSCRIBED, DigestAdmin::requested_filter(), 'The subscribed filter value must be accepted.');
    $_GET[DigestAdmin::FILTER] = 'unsubscribed';
    assert_same(DigestAdmin::FILTER_UNSUBSCRIBED, DigestAdmin::requested_filter(), 'The unsubscribed filter value must be accepted.');
    $_GET[DigestAdmin::FILTER] = 'drop-table';
    assert_same('', DigestAdmin::requested_filter(), 'Unknown digest filter values must be ignored.');
    unset($_GET[DigestAdmin::FILTER]);

    $subscribed_query = DigestAdmin::query_modifications([], 'registered', 'DESC', DigestAdmin::FILTER_SUBSCRIBED);
    assert_same(
        [['key' => DigestUnsubscribe::FIELD_NAME, 'value' => '1', 'compare' => '=']],
        $subscribed_query['meta_query'],
        'The subscribed filter must require send_daily_digest=1.'
    );
    $unsubscribed_query = DigestAdmin::query_modifications([], 'registered', 'DESC', DigestAdmin::FILTER_UNSUBSCRIBED);
    assert_same(
        'OR',
        $unsubscribed_query['meta_query'][0]['relation'],
        'The unsubscribed filter must combine missing and non-subscribed meta values.'
    );
    assert_same(
        ['key' => DigestUnsubscribe::FIELD_NAME, 'value' => '1', 'compare' => '!='],
        $unsubscribed_query['meta_query'][0][0],
        'The unsubscribed filter must include users whose digest meta is not 1.'
    );
    assert_same(
        ['key' => DigestUnsubscribe::FIELD_NAME, 'compare' => 'NOT EXISTS'],
        $unsubscribed_query['meta_query'][0][1],
        'The unsubscribed filter must include users without digest meta.'
    );

    $sorted_query = DigestAdmin::query_modifications([], DigestAdmin::COLUMN, 'desc', '');
    assert_same(
        'OR',
        $sorted_query['meta_query'][0]['relation'],
        'Sorting must keep users with and without digest meta in the result set.'
    );
    assert_same(
        ['key' => DigestUnsubscribe::FIELD_NAME, 'compare' => 'EXISTS'],
        $sorted_query['meta_query'][0]['enovos_digest_sort'],
        'Sorting must join existing digest meta without requiring a value.'
    );
    assert_same(
        ['key' => DigestUnsubscribe::FIELD_NAME, 'compare' => 'NOT EXISTS'],
        $sorted_query['meta_query'][0]['enovos_digest_missing'],
        'Sorting must keep users who have never stored a digest preference.'
    );
    assert_same(
        ['enovos_digest_sort' => 'DESC', 'display_name' => 'ASC'],
        $sorted_query['orderby'],
        'Digest sorting must use the joined meta value and a stable name fallback.'
    );
    assert_same(
        [],
        DigestAdmin::query_modifications([], 'registered', 'DESC', ''),
        'The users query must stay unchanged without a digest filter or sort.'
    );

    $GLOBALS['test_is_admin'] = true;
    $GLOBALS['test_caps'] = ['list_users'];
    $GLOBALS['pagenow'] = 'users.php';
    $_GET[DigestAdmin::FILTER] = DigestAdmin::FILTER_SUBSCRIBED;
    $user_query = new \WP_User_Query(['orderby' => 'registered']);
    DigestAdmin::filter_users($user_query);
    assert_same(
        [['key' => DigestUnsubscribe::FIELD_NAME, 'value' => '1', 'compare' => '=']],
        $user_query->get('meta_query'),
        'The users list query must apply the requested digest filter.'
    );
    $_GET[DigestAdmin::FILTER] = DigestAdmin::FILTER_SUBSCRIBED;
    ob_start();
    DigestAdmin::render_filter('top');
    $filter_markup = (string) ob_get_clean();
    assert_true(
        str_contains($filter_markup, 'name="' . DigestAdmin::FILTER . '"'),
        'The users list must render a Daily Digest filter control.'
    );
    assert_true(
        str_contains($filter_markup, 'selected="selected"'),
        'The current digest filter must be marked selected.'
    );
    ob_start();
    DigestAdmin::render_filter('bottom');
    assert_same('', (string) ob_get_clean(), 'The digest filter must render only once at the top of the users list.');
    unset($_GET[DigestAdmin::FILTER], $GLOBALS['pagenow']);
    $GLOBALS['test_is_admin'] = false;
    $GLOBALS['test_caps'] = [];

    echo "All regression tests passed.\n";
}
