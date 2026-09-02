<?php
declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('HOUR_IN_SECONDS', 3600);
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

    $GLOBALS['test_transients'] = [];
    $GLOBALS['test_http_get'] = [];
    $GLOBALS['test_http_head'] = [];
    $GLOBALS['test_http_post'] = [];
    $GLOBALS['test_http_calls'] = [];

    function is_wp_error(mixed $value): bool {
        return $value instanceof WP_Error;
    }

    function sanitize_key(string $value): string {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? '';
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
        return strip_tags($value);
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

    echo "All regression tests passed.\n";
}
