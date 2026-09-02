<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class AI {
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'events' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'date' => ['type' => 'string'],
                        'ticket_count' => ['type' => 'integer'],
                        'page_numbers' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'atelier_url' => ['type' => 'string'],
                        'price_per_ticket' => ['type' => 'number'],
                        'currency' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'image_url' => ['type' => 'string'],
                    ],
                    'required' => ['title','date','ticket_count','page_numbers','atelier_url','price_per_ticket','currency','description','image_url'],
                ],
            ],
        ],
        'required' => ['events'],
        'additionalProperties' => false,
    ];

    public static function extract_from_pdf(string $pdf_path, array $settings): array|\WP_Error {
        $provider = self::selected_provider($settings);
        $pdf_analysis = PdfText::analyze($pdf_path);
        Logger::log('STEP', 'Deterministic PDF analysis completed', [
            'pages' => $pdf_analysis['page_count'],
            'groups' => count($pdf_analysis['groups']),
            'assigned_pages' => count($pdf_analysis['assigned_pages']),
            'unassigned_pages' => $pdf_analysis['unassigned_pages'],
        ]);
        Logger::log('STEP', 'Selected AI provider', ['provider' => $provider]);
        if (!$provider) {
            return new \WP_Error('no_ai_provider', 'No AI provider is selected.');
        }

        if ($provider === 'openai') {
            if (empty($settings['openai_api_key'])) return new \WP_Error('openai_not_configured', 'OpenAI is selected but no API key is configured.');
            Logger::log('START', 'OpenAI analysis started');
            $result = self::openai($pdf_path, $settings, $pdf_analysis);
        } elseif ($provider === 'gemini') {
            if (empty($settings['gemini_api_key'])) return new \WP_Error('gemini_not_configured', 'Gemini is selected but no API key is configured.');
            Logger::log('START', 'Gemini analysis started');
            $result = self::gemini($pdf_path, $settings, $pdf_analysis);
        } else {
            if (empty($settings['custom_ai_endpoint'])) return new \WP_Error('custom_not_configured', 'Custom AI is selected but no endpoint is configured.');
            Logger::log('START', 'Custom AI analysis started');
            $result = self::custom($pdf_path, $settings, $pdf_analysis);
        }

        if (is_wp_error($result)) {
            Logger::log('FAIL', ucfirst($provider) . ' analysis failed', ['error' => $result->get_error_message()]);
            return $result;
        }
        $ai_event_count = count($result['events'] ?? []);
        $result = self::reconcile($result, $pdf_analysis);
        unset($pdf_analysis['pages']);
        Logger::log('OK', ucfirst($provider) . ' analysis completed', [
            'ai_events' => $ai_event_count,
            'reconciled_events' => count($result['events'] ?? []),
        ]);
        return [
            'providers' => [$provider => $result],
            'errors' => [],
            'consensus' => self::consensus([$provider => $result]),
            'selected_provider' => $provider,
            'pdf_analysis' => $pdf_analysis,
        ];
    }

    private static function selected_provider(array $settings): string {
        $provider = sanitize_key((string)($settings['ai_provider'] ?? 'openai'));
        return in_array($provider, ['openai','gemini','custom'], true) ? $provider : 'openai';
    }

    /**
     * @param array<int,string> $excluded_image_urls
     */
    public static function enrich_atelier(array $event, array $settings, array $excluded_image_urls = []): array {
        $title = (string) ($event['title'] ?? '');
        $date = (string) ($event['date'] ?? '');
        $provider = self::selected_provider($settings);
        $candidate_url = (string) ($event['atelier_url'] ?? '');
        Logger::log('STEP', 'Atelier enrichment started', [
            'title' => $title,
            'url' => $candidate_url,
            'date' => $date,
        ]);

        $resolved = self::resolve_atelier_url($candidate_url, $title, $date);
        $url = $resolved['url'];
        if ($url === '') {
            $event['atelier_url'] = '';
            $event['_needs_review'] = 1;
            $event['_analysis_warning'] = sprintf(
                'No Atelier page matches the ticket date %s (%s).',
                $date,
                $resolved['reason']
            );
            Logger::log('FAIL', 'No Atelier page matches the PDF ticket date', [
                'title' => $title,
                'date' => $date,
                'candidate_url' => $candidate_url,
                'reason' => $resolved['reason'],
            ]);
        }

        if ($url !== '') {
            $page_image = self::atelier_header_image($resolved['html'], $url, $excluded_image_urls);
            $page_image_reason = $page_image !== '' ? 'atelier-page-header' : 'atelier-header-image-missing';
            if ($page_image !== '') {
                $page_image = self::validate_atelier_image_url($page_image, $title, $excluded_image_urls);
                if ($page_image === '') {
                    $page_image_reason = 'atelier-image-download-validation-failed';
                }
            }

            if ($provider === 'openai' && !empty($settings['openai_api_key'])) {
                $r = self::openai_atelier($url, $settings, $event);
                if (!is_wp_error($r)) {
                    $event = array_merge($event, $r);
                    Logger::log('OK', 'Atelier enrichment from OpenAI applied', ['title' => $title]);
                } else {
                    Logger::log('FAIL', 'OpenAI Atelier enrichment failed', ['error' => $r->get_error_message()]);
                }
            }

            if ($provider === 'gemini' && !empty($settings['gemini_api_key'])) {
                $r = self::gemini_atelier($url, $settings, $event);
                if (!is_wp_error($r)) {
                    $event = self::merge_best($event, $r);
                    Logger::log('OK', 'Atelier data cross-checked with Gemini', ['title' => $title]);
                } else {
                    Logger::log('FAIL', 'Gemini Atelier enrichment failed', ['error' => $r->get_error_message()]);
                }
            }

            // The AI may echo another concert URL; the date-verified page always wins.
            $event['atelier_url'] = $url;

            if ($page_image !== '') {
                $event['image_url'] = $page_image;
                $event['image_source'] = 'atelier-page';
                unset($event['_image_warning']);
                Logger::log('OK', 'Official Atelier page header image found', ['url' => $page_image]);
            } else {
                $event['_image_warning'] = $page_image_reason;
                Logger::log('STEP', 'No verified Atelier artist/group image found', ['reason' => $page_image_reason]);
            }
        } else {
            $event['_image_warning'] = 'No date-matched Atelier page; web artist image fallback required.';
        }

        if (!empty($event['image_url']) && (($event['image_source'] ?? '') !== 'atelier-page')) {
            $unvalidated_url = (string) $event['image_url'];
            $validated = self::validate_image_url($unvalidated_url, $title, $excluded_image_urls);
            if ($validated !== '') {
                $event['image_url'] = $validated;
                $event['image_source'] = 'ai-validated';
                unset($event['_image_warning']);
                Logger::log('OK', 'AI artist/group image URL validated', ['url' => $validated]);
            } else {
                $event['image_url'] = '';
                $event['_image_warning'] = 'AI image URL could not be downloaded or was not a unique image.';
                Logger::log('FAIL', 'AI image URL could not be validated as a unique artist image', ['url' => $unvalidated_url]);
            }
        }

        if (
            empty($event['image_url'])
            && $provider === 'gemini'
            && !empty($settings['gemini_api_key'])
            && $title !== ''
        ) {
            $sources = self::gemini_artist_image_source($event, $settings);
            if (!is_wp_error($sources) && !empty($sources['sources'])) {
                foreach (array_slice($sources['sources'], 0, 3) as $source) {
                    $source_url = self::safe_source_page_url((string) ($source['source_page_url'] ?? ''));
                    $web_image = $source_url !== ''
                        ? self::extract_official_artist_image($source_url, $title, $excluded_image_urls)
                        : ['url' => '', 'score' => 0, 'reason' => 'unsafe-source-page'];
                    $validated_web_image = $web_image['url'] !== ''
                        ? self::validate_image_url($web_image['url'], $title, $excluded_image_urls)
                        : '';
                    if ($validated_web_image !== '') {
                        $event['image_url'] = $validated_web_image;
                        $event['image_source'] = 'gemini-web-page';
                        $event['image_source_url'] = $source_url;
                        unset($event['_image_warning']);
                        Logger::log('OK', 'Gemini web artist image found and validated', [
                            'title' => $title,
                            'source_url' => $source_url,
                            'image_url' => $validated_web_image,
                        ]);
                        break;
                    }
                    Logger::log('FAIL', 'Gemini artist image source did not yield a valid image', [
                        'title' => $title,
                        'source_url' => $source_url,
                        'reason' => $web_image['reason'],
                    ]);
                }
                if (empty($event['image_url'])) {
                    $event['_image_warning'] = 'Gemini found source pages, but none yielded a matching downloadable artist image.';
                }
            } else {
                $event['_image_warning'] = is_wp_error($sources)
                    ? $sources->get_error_message()
                    : 'Gemini did not find a verifiable artist image source page.';
                Logger::log('FAIL', 'Gemini artist image web fallback failed', [
                    'title' => $title,
                    'error' => $event['_image_warning'],
                ]);
            }
        }

        if (empty($event['image_url']) && empty($event['_image_warning'])) {
            $event['_image_warning'] = 'No verified artist image found.';
        }

        return $event;
    }

    /**
     * Apply an administrator-entered Atelier URL without discarding it when
     * Cloudflare or a network error prevents server-side verification.
     *
     * @param array<int,string> $excluded_image_urls
     */
    public static function enrich_manual_atelier_url(
        array $event,
        string $manual_url,
        array $excluded_image_urls = []
    ): array|\WP_Error {
        $url = self::official_atelier_url($manual_url);
        if ($url === '') {
            return new \WP_Error(
                'invalid_manual_atelier_url',
                'Enter an HTTPS URL hosted on atelier.lu.'
            );
        }
        $pdf_date = (string) ($event['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pdf_date)) {
            return new \WP_Error('invalid_manual_atelier_date', 'The concert date is missing or invalid.');
        }

        $event['atelier_url'] = $url;
        $page = self::fetch_atelier_page($url);
        if (!$page['ok']) {
            if ($page['blocked'] || $page['status'] === 0) {
                $event['_analysis_warning'] = $page['blocked']
                    ? 'The manual Atelier URL was kept, but Cloudflare blocked date verification.'
                    : 'The manual Atelier URL was kept, but the page could not be reached for date verification.';
                return $event;
            }
            return new \WP_Error(
                'manual_atelier_page_unavailable',
                sprintf('The Atelier page returned HTTP %d and could not be verified.', $page['status'])
            );
        }

        $page_date = self::atelier_page_date($page['html']);
        if ($page_date !== '' && !hash_equals($pdf_date, $page_date)) {
            return new \WP_Error(
                'manual_atelier_date_mismatch',
                sprintf('The Atelier page date %s does not match the ticket date %s.', $page_date, $pdf_date)
            );
        }
        if ($page_date === '') {
            $event['_analysis_warning'] = 'The manual Atelier URL was kept, but its concert date could not be read.';
            return $event;
        }

        $image = self::atelier_header_image($page['html'], $url, $excluded_image_urls);
        $image = $image !== ''
            ? self::validate_atelier_image_url(
                $image,
                (string) ($event['title'] ?? ''),
                $excluded_image_urls
            )
            : '';
        if ($image !== '') {
            $event['image_url'] = $image;
            $event['image_source'] = 'atelier-page';
            unset($event['_image_warning']);
        } else {
            $event['_image_warning'] = 'The date-matched manual Atelier page has no downloadable header image.';
        }
        unset($event['_analysis_warning']);
        return $event;
    }

    /**
     * Accept an Atelier concert page only when its own date matches the PDF ticket date.
     * Atelier keeps past shows on the same /shows/<slug>/ path, so an artist name alone
     * can resolve to a concert from years ago.
     *
     * @return array{url:string,html:string,date:string,reason:string}
     */
    private static function resolve_atelier_url(string $candidate_url, string $title, string $pdf_date): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pdf_date)) {
            return ['url' => '', 'html' => '', 'date' => '', 'reason' => 'ticket date missing'];
        }

        $candidates = [];
        $candidate_url = self::official_atelier_url($candidate_url);
        if ($candidate_url !== '') {
            $candidates[] = $candidate_url;
        }

        $slug = sanitize_title($title);
        foreach (self::rank_atelier_show_urls(self::atelier_show_urls_from_sitemap(), $title) as $url) {
            $candidates[] = $url;
        }
        if ($slug !== '') {
            $candidates[] = 'https://www.atelier.lu/shows/' . $slug . '-' . substr($pdf_date, 0, 4) . '/';
            $candidates[] = 'https://www.atelier.lu/shows/' . $slug . '-2/';
        }

        $blocked = false;
        foreach (array_slice(array_values(array_unique($candidates)), 0, 5) as $url) {
            $page = self::fetch_atelier_page($url);
            $blocked = $blocked || $page['blocked'];
            if (!$page['ok']) {
                continue;
            }
            $page_date = self::atelier_page_date($page['html']);
            Logger::log('STEP', 'Atelier candidate date checked', [
                'url' => $url,
                'page_date' => $page_date,
                'pdf_date' => $pdf_date,
            ]);
            if ($page_date !== '' && hash_equals($pdf_date, $page_date)) {
                return ['url' => $url, 'html' => $page['html'], 'date' => $page_date, 'reason' => ''];
            }
        }

        return [
            'url' => '',
            'html' => '',
            'date' => '',
            'reason' => $blocked ? 'Atelier blocked the page request with Cloudflare' : 'no exact date match',
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function atelier_show_urls_from_sitemap(): array {
        $cache_key = 'enovos_atelier_sitemap_show_urls_v2';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return array_values(array_filter($cached, 'is_string'));
        }

        $show_urls = [];
        foreach ([
            'https://www.atelier.lu/ate_show-sitemap.xml',
            'https://www.atelier.lu/sitemap.xml',
            'https://www.atelier.lu/wp-sitemap.xml',
        ] as $root_url) {
            $root = self::fetch_atelier_page($root_url);
            if (!$root['ok']) {
                continue;
            }
            $parsed = self::parse_atelier_sitemap_xml($root['html']);
            $show_urls = array_merge($show_urls, $parsed['show_urls']);
            foreach (array_slice($parsed['child_urls'], 0, 5) as $child_url) {
                $child = self::fetch_atelier_page($child_url);
                if (!$child['ok']) {
                    continue;
                }
                $child_parsed = self::parse_atelier_sitemap_xml($child['html']);
                $show_urls = array_merge($show_urls, $child_parsed['show_urls']);
            }
            if ($show_urls) {
                break;
            }
        }

        $show_urls = array_values(array_unique($show_urls));
        set_transient($cache_key, $show_urls, 6 * HOUR_IN_SECONDS);
        Logger::log($show_urls ? 'OK' : 'FAIL', 'Atelier sitemap show URLs loaded', [
            'count' => count($show_urls),
        ]);
        return $show_urls;
    }

    /**
     * @return array{show_urls:array<int,string>,child_urls:array<int,string>}
     */
    private static function parse_atelier_sitemap_xml(string $xml): array {
        $result = ['show_urls' => [], 'child_urls' => []];
        $xml = trim($xml);
        if (
            $xml === ''
            || $xml[0] !== '<'
            || stripos($xml, '<!DOCTYPE') !== false
            || stripos($xml, '<html') !== false
        ) {
            return $result;
        }

        $previous_errors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_errors);
        if (!$loaded || !$dom->documentElement) {
            return $result;
        }

        $is_index = strtolower($dom->documentElement->localName) === 'sitemapindex';
        $xpath = new \DOMXPath($dom);
        $locations = $xpath->query('//*[local-name()="loc"]');
        if ($locations === false) {
            return $result;
        }
        foreach ($locations as $location) {
            $url = self::official_atelier_url(trim((string) $location->textContent));
            if ($url === '') {
                continue;
            }
            $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
            if ($is_index) {
                if (
                    str_contains($path, 'sitemap')
                    && preg_match('/(?:post|show|page|wp-sitemap-posts)/', $path)
                ) {
                    $result['child_urls'][] = $url;
                }
            } elseif (preg_match('~^/shows/[^/]+/?$~', $path)) {
                $result['show_urls'][] = $url;
            }
        }

        $result['show_urls'] = array_values(array_unique($result['show_urls']));
        $result['child_urls'] = array_slice(array_values(array_unique($result['child_urls'])), 0, 5);
        return $result;
    }

    /**
     * @param array<int,string> $urls
     * @return array<int,string>
     */
    private static function rank_atelier_show_urls(array $urls, string $title): array {
        $title_slug = sanitize_title($title);
        $tokens = array_values(array_filter(
            preg_split('/-+/', $title_slug) ?: [],
            static fn(string $token): bool => strlen($token) >= 2
        ));
        $ranked = [];
        foreach ($urls as $url) {
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            $url_slug = sanitize_title((string) basename(untrailingslashit($path)));
            $score = $url_slug === $title_slug && $title_slug !== '' ? 1000 : 0;
            if ($score === 0 && $title_slug !== '' && str_contains($url_slug, $title_slug)) {
                $score = 500;
            }
            foreach ($tokens as $token) {
                if (preg_match('/(?:^|-)' . preg_quote($token, '/') . '(?:-|$)/', $url_slug)) {
                    $score += 10;
                }
            }
            if ($score > 0) {
                $ranked[] = ['url' => $url, 'score' => $score];
            }
        }
        usort($ranked, static function (array $left, array $right): int {
            return $right['score'] <=> $left['score'] ?: strcmp($left['url'], $right['url']);
        });
        return array_column($ranked, 'url');
    }

    /**
     * @return array{ok:bool,blocked:bool,status:int,html:string,error:string}
     */
    private static function fetch_atelier_page(string $url): array {
        $url = self::official_atelier_url($url);
        if ($url === '') {
            return ['ok' => false, 'blocked' => false, 'status' => 0, 'html' => '', 'error' => 'invalid Atelier URL'];
        }
        $cache_key = 'enovos_atelier_page_' . md5($url);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['ok'], $cached['blocked'], $cached['status'], $cached['html'], $cached['error'])) {
            return $cached;
        }

        $response = wp_safe_remote_get($url, [
            'timeout' => 30,
            'redirection' => 5,
            'limit_response_size' => 1024 * 1024,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => 'https://www.atelier.lu/',
            ],
        ]);
        if (is_wp_error($response)) {
            $result = ['ok' => false, 'blocked' => false, 'status' => 0, 'html' => '', 'error' => $response->get_error_message()];
            set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
            return $result;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $html = (string) wp_remote_retrieve_body($response);
        $cf_mitigated = strtolower((string) wp_remote_retrieve_header($response, 'cf-mitigated'));
        $blocked = $status === 403 && (
            $cf_mitigated === 'challenge'
            || stripos($html, 'Just a moment...') !== false
            || stripos($html, 'challenges.cloudflare.com') !== false
        );
        $result = [
            'ok' => $status >= 200 && $status < 300 && $html !== '',
            'blocked' => $blocked,
            'status' => $status,
            'html' => $html,
            'error' => '',
        ];
        if ($blocked) {
            Logger::log('FAIL', 'Atelier page blocked by Cloudflare', ['url' => $url, 'status' => $status]);
        } elseif (!$result['ok']) {
            Logger::log('FAIL', 'Atelier page returned an unexpected response', ['url' => $url, 'status' => $status]);
        }
        set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
        return $result;
    }

    private static function official_atelier_url(string $url): string {
        $url = self::safe_source_page_url($url);
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, ['atelier.lu', 'www.atelier.lu'], true)) {
            return '';
        }
        $path = (string) ($parts['path'] ?? '/');
        return 'https://www.atelier.lu' . ($path !== '' ? $path : '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private static function atelier_page_date(string $html): string {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $hero_dates = $xpath->query(
            '//*[contains(concat(" ",normalize-space(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"))," ")," hero-wrapper ")'
            . ' or contains(concat(" ",normalize-space(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"))," ")," hero-slide-content ")]'
            . '//*[self::p and contains(concat(" ",normalize-space(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"))," ")," date ")]'
        );
        if ($hero_dates !== false) {
            foreach ($hero_dates as $hero_date) {
                $date = self::normalize_english_page_date((string) $hero_date->textContent);
                if ($date !== '') {
                    return $date;
                }
            }
        }
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (strtolower(trim((string) $script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }
            $json = json_decode((string) $script->textContent, true);
            if (is_array($json)) {
                $date = self::json_ld_event_date($json);
                if ($date !== '') {
                    return $date;
                }
            }
        }
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            $property = strtolower((string) ($meta->getAttribute('property') ?: $meta->getAttribute('name')));
            if ($property === 'event:start_time') {
                $date = self::normalize_page_date((string) $meta->getAttribute('content'));
                if ($date !== '') {
                    return $date;
                }
            }
        }
        foreach ($dom->getElementsByTagName('time') as $time) {
            $date = self::normalize_page_date((string) $time->getAttribute('datetime'));
            if ($date !== '') {
                return $date;
            }
        }

        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $date = self::normalize_english_page_date($text);
        if ($date !== '') {
            return $date;
        }
        if (preg_match('/\b(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})\b/', $text, $match)) {
            return checkdate((int) $match[2], (int) $match[1], (int) $match[3])
                ? sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1])
                : '';
        }
        return '';
    }

    private static function normalize_english_page_date(string $value): string {
        if (!preg_match(
            '/(?:(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s*)?'
            . '(January|February|March|April|May|June|July|August|September|October|November|December)'
            . '\s+(\d{1,2}),\s*(\d{4})/i',
            html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $match
        )) {
            return '';
        }
        $timestamp = strtotime($match[1] . ' ' . $match[2] . ' ' . $match[3] . ' UTC');
        return $timestamp !== false ? gmdate('Y-m-d', $timestamp) : '';
    }

    /**
     * @param array<mixed> $node
     */
    private static function json_ld_event_date(array $node): string {
        if (self::json_ld_is_event($node) && isset($node['startDate']) && is_string($node['startDate'])) {
            return self::normalize_page_date($node['startDate']);
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $date = self::json_ld_event_date($value);
                if ($date !== '') {
                    return $date;
                }
            }
        }
        return '';
    }

    /**
     * @param array<mixed> $node
     */
    private static function json_ld_is_event(array $node): bool {
        $type = $node['@type'] ?? '';
        foreach (is_array($type) ? $type : [$type] as $item) {
            if (is_string($item) && str_ends_with(strtolower($item), 'event')) {
                return true;
            }
        }
        return false;
    }

    private static function normalize_page_date(string $value): string {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $match)) {
            return checkdate((int) $match[2], (int) $match[3], (int) $match[1])
                ? sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3])
                : '';
        }
        return '';
    }

    /**
     * Read the concert image straight from the page header instead of scoring filenames,
     * because Atelier upload names rarely contain the artist name.
     *
     * @param array<int,string> $excluded_image_urls
     */
    private static function atelier_header_image(string $html, string $base_url, array $excluded_image_urls = []): string {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $candidates = [];
        foreach (['og:image', 'twitter:image'] as $wanted) {
            foreach ($dom->getElementsByTagName('meta') as $meta) {
                $property = strtolower((string) ($meta->getAttribute('property') ?: $meta->getAttribute('name')));
                if ($property === $wanted) {
                    self::add_image_candidate($candidates, (string) $meta->getAttribute('content'), 'social-meta', '');
                }
            }
        }
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (strtolower(trim((string) $script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }
            $json = json_decode((string) $script->textContent, true);
            if (is_array($json)) {
                self::collect_json_ld_event_images($json, $candidates);
            }
        }

        $hero_images = (new \DOMXPath($dom))->query(
            '//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"hero")'
            . ' or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"header")'
            . ' or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"banner")]//img'
        );
        if ($hero_images !== false) {
            foreach ($hero_images as $img) {
                if (!$img instanceof \DOMElement) {
                    continue;
                }
                foreach (['src', 'data-src', 'data-lazy-src'] as $attribute) {
                    self::add_image_candidate(
                        $candidates,
                        (string) $img->getAttribute($attribute),
                        'hero-image',
                        (string) $img->getAttribute('alt')
                    );
                }
            }
        }

        foreach ($candidates as $candidate) {
            $url = self::absolute_url($base_url, html_entity_decode($candidate['url']));
            if (
                $url !== ''
                && self::is_allowed_atelier_image_candidate($url, $candidate['context'])
                && !self::image_is_excluded($url, $excluded_image_urls)
            ) {
                return $url;
            }
        }
        return '';
    }

    /**
     * @param array<mixed> $node
     * @param array<int,array{url:string,source:string,context:string}> $candidates
     */
    private static function collect_json_ld_event_images(array $node, array &$candidates): void {
        if (self::json_ld_is_event($node) && isset($node['image'])) {
            self::collect_json_ld_image_value($node['image'], (string) ($node['name'] ?? ''), $candidates);
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::collect_json_ld_event_images($value, $candidates);
            }
        }
    }

    /**
     * @return array{ok:bool,blocked:bool,status:int,date:string,image_url:string,error:string}
     */
    public static function atelier_page_diagnostic(string $url): array {
        $page = self::fetch_atelier_page($url);
        return [
            'ok' => $page['ok'],
            'blocked' => $page['blocked'],
            'status' => $page['status'],
            'date' => $page['ok'] ? self::atelier_page_date($page['html']) : '',
            'image_url' => $page['ok'] ? self::atelier_header_image($page['html'], $url) : '',
            'error' => $page['error'],
        ];
    }

    /**
     * @param array<int,string> $excluded_image_urls
     * @return array{url:string,score:int,reason:string}
     */
    private static function extract_official_artist_image(string $url, string $title, array $excluded_image_urls): array {
        $url = self::safe_source_page_url($url);
        if ($url === '') {
            return ['url' => '', 'score' => 0, 'reason' => 'unsafe-source-page'];
        }
        $response = wp_safe_remote_get($url, [
            'timeout' => 30,
            'redirection' => 5,
            'headers' => ['User-Agent' => 'Enovos-Ticket-Shop/' . ENOVOS_TICKET_SHOP_VERSION . ' WordPress'],
        ]);
        if (is_wp_error($response)) {
            Logger::log('FAIL', 'Could not fetch Atelier page for image extraction', ['error' => $response->get_error_message()]);
            return ['url' => '', 'score' => 0, 'reason' => 'request-failed'];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            Logger::log('FAIL', 'Atelier page returned unexpected status for image extraction', ['status' => $code]);
            return ['url' => '', 'score' => 0, 'reason' => 'unexpected-status'];
        }
        $html = wp_remote_retrieve_body($response);
        if (!$html) {
            return ['url' => '', 'score' => 0, 'reason' => 'empty-page'];
        }

        $candidates = [];
        $page_context = self::page_identity_context($html);
        if (preg_match_all('/<meta[^>]+(?:property|name)=[\"\'](?:og:image|twitter:image)[\"\'][^>]+content=[\"\']([^\"\']+)[\"\']/i', $html, $m)) {
            foreach ($m[1] as $candidate) {
                self::add_image_candidate($candidates, (string) $candidate, 'social-meta', $page_context);
            }
        }
        if (preg_match_all('/<meta[^>]+content=[\"\']([^\"\']+)[\"\'][^>]+(?:property|name)=[\"\'](?:og:image|twitter:image)[\"\']/i', $html, $m2)) {
            foreach ($m2[1] as $candidate) {
                self::add_image_candidate($candidates, (string) $candidate, 'social-meta', $page_context);
            }
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (strtolower(trim((string) $script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }
            $json = json_decode((string) $script->textContent, true);
            if (is_array($json)) {
                self::collect_json_ld_images($json, $candidates);
            }
        }
        foreach ($dom->getElementsByTagName('img') as $img) {
            $context = implode(' ', [
                (string) $img->getAttribute('alt'),
                (string) $img->getAttribute('title'),
            ]);
            foreach (['src', 'data-src', 'data-lazy-src'] as $attribute) {
                self::add_image_candidate($candidates, (string) $img->getAttribute($attribute), 'content-image', $context);
            }
        }

        $ranked = [];
        $rejected_count = 0;
        foreach ($candidates as $candidate) {
            $candidate_url = self::absolute_url($url, html_entity_decode($candidate['url']));
            if ($candidate_url === '') {
                continue;
            }
            [$score, $reason] = self::score_artist_image(
                $candidate_url,
                $title,
                $candidate['source'],
                $candidate['context'],
                $excluded_image_urls
            );
            if ($score <= 0) {
                if ($rejected_count < 10) {
                    Logger::log('STEP', 'Atelier image candidate rejected', [
                        'url' => $candidate_url,
                        'reason' => $reason,
                    ]);
                }
                $rejected_count++;
                continue;
            }
            $ranked[] = ['url' => $candidate_url, 'score' => $score, 'reason' => $reason];
        }
        usort($ranked, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        return $ranked[0] ?? ['url' => '', 'score' => 0, 'reason' => 'no-title-match'];
    }

    private static function page_identity_context(string $html): string {
        $context = [];
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $title_match)) {
            $context[] = wp_strip_all_tags(html_entity_decode((string) $title_match[1]));
        }
        foreach (['og:title', 'twitter:title'] as $property) {
            $quoted_property = preg_quote($property, '/');
            if (preg_match('/<meta[^>]+(?:property|name)=[\"\']' . $quoted_property . '[\"\'][^>]+content=[\"\']([^\"\']+)[\"\']/i', $html, $match)) {
                $context[] = html_entity_decode((string) $match[1]);
            }
            if (preg_match('/<meta[^>]+content=[\"\']([^\"\']+)[\"\'][^>]+(?:property|name)=[\"\']' . $quoted_property . '[\"\']/i', $html, $match)) {
                $context[] = html_entity_decode((string) $match[1]);
            }
        }
        if (preg_match_all('/<script[^>]+type=[\"\']application\/ld\+json[\"\'][^>]*>(.*?)<\/script>/is', $html, $scripts)) {
            foreach ($scripts[1] as $script) {
                $json = json_decode((string) $script, true);
                if (is_array($json)) {
                    self::collect_json_ld_identity($json, $context);
                }
            }
        }
        return trim(implode(' ', array_unique(array_filter($context))));
    }

    /**
     * @param array<mixed> $node
     * @param array<int,string> $context
     */
    private static function collect_json_ld_identity(array $node, array &$context): void {
        foreach (['name', 'headline', 'alternateName'] as $key) {
            if (isset($node[$key]) && is_string($node[$key])) {
                $context[] = $node[$key];
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::collect_json_ld_identity($value, $context);
            }
        }
    }

    /**
     * @param array<int,array{url:string,source:string,context:string}> $candidates
     */
    private static function add_image_candidate(array &$candidates, string $url, string $source, string $context): void {
        $url = trim($url);
        if ($url === '') {
            return;
        }
        $candidates[] = [
            'url' => $url,
            'source' => $source,
            'context' => trim($context),
        ];
    }

    /**
     * @param array<mixed> $node
     * @param array<int,array{url:string,source:string,context:string}> $candidates
     */
    private static function collect_json_ld_images(array $node, array &$candidates): void {
        $context_parts = [];
        foreach (['name', 'headline', 'alternateName'] as $context_key) {
            if (isset($node[$context_key]) && is_string($node[$context_key])) {
                $context_parts[] = $node[$context_key];
            }
        }
        $context = implode(' ', $context_parts);

        foreach ($node as $key => $value) {
            if (in_array(strtolower((string) $key), ['image', 'thumbnailurl'], true)) {
                self::collect_json_ld_image_value($value, $context, $candidates);
            }
            if (is_array($value)) {
                self::collect_json_ld_images($value, $candidates);
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<int,array{url:string,source:string,context:string}> $candidates
     */
    private static function collect_json_ld_image_value($value, string $context, array &$candidates): void {
        if (is_string($value)) {
            self::add_image_candidate($candidates, $value, 'json-ld', $context);
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            $normalized_key = strtolower((string) $key);
            if (
                is_string($item)
                && (is_int($key) || in_array($normalized_key, ['url', 'contenturl'], true))
            ) {
                self::add_image_candidate($candidates, $item, 'json-ld', $context);
            } elseif (is_array($item)) {
                self::collect_json_ld_image_value($item, $context, $candidates);
            }
        }
    }

    /**
     * @param array<int,string> $excluded_image_urls
     * @return array{0:int,1:string}
     */
    private static function score_artist_image(
        string $url,
        string $title,
        string $source,
        string $context,
        array $excluded_image_urls
    ): array {
        if (self::image_is_excluded($url, $excluded_image_urls)) {
            return [0, 'already-used-by-another-concert'];
        }
        if (!self::is_likely_artist_image($url, $title, $context)) {
            return [0, 'generic-or-non-artist-image'];
        }

        $tokens = self::artist_title_tokens($title);
        $normalized_url = self::normalize_image_text(rawurldecode($url));
        $normalized_context = self::normalize_image_text($context);
        $url_matches = 0;
        $context_matches = 0;
        foreach ($tokens as $token) {
            $url_matches += self::image_text_contains_token($normalized_url, $token) ? 1 : 0;
            $context_matches += self::image_text_contains_token($normalized_context, $token) ? 1 : 0;
        }
        if ($url_matches + $context_matches === 0) {
            return [0, 'no-artist-title-match'];
        }

        $source_score = [
            'json-ld' => 35,
            'social-meta' => 25,
            'content-image' => 15,
        ][$source] ?? 0;
        $score = $source_score + ($url_matches * 25) + ($context_matches * 15);
        return [
            $score,
            sprintf('%s; %d URL and %d context title-token matches', $source, $url_matches, $context_matches),
        ];
    }

    /**
     * @param array<int,string> $excluded_image_urls
     */
    private static function validate_image_url(string $url, string $title, array $excluded_image_urls = []): string {
        $url = self::safe_source_page_url($url);
        if (!$url || !self::is_likely_artist_image($url, $title) || self::image_is_excluded($url, $excluded_image_urls)) {
            return '';
        }
        return self::validate_downloadable_image($url);
    }

    /**
     * Ticketmatic is trusted only for image URLs extracted from date-matched
     * Atelier HTML. General AI image URLs still use validate_image_url().
     *
     * @param array<int,string> $excluded_image_urls
     */
    private static function validate_atelier_image_url(
        string $url,
        string $title,
        array $excluded_image_urls = []
    ): string {
        $url = self::safe_source_page_url($url);
        if (
            !$url
            || !self::is_allowed_atelier_image_candidate($url)
            || self::image_is_excluded($url, $excluded_image_urls)
        ) {
            return '';
        }
        unset($title);
        return self::validate_downloadable_image($url);
    }

    private static function validate_downloadable_image(string $url): string {
        $response = wp_safe_remote_head($url, [
            'timeout' => 20,
            'redirection' => 5,
            'headers' => ['User-Agent' => 'Enovos-Ticket-Shop/' . ENOVOS_TICKET_SHOP_VERSION . ' WordPress'],
        ]);
        if (!self::remote_response_is_image($response)) {
            $response = wp_safe_remote_get($url, [
                'timeout' => 20,
                'redirection' => 5,
                'limit_response_size' => 65536,
                'headers' => ['User-Agent' => 'Enovos-Ticket-Shop/' . ENOVOS_TICKET_SHOP_VERSION . ' WordPress'],
            ]);
        }
        return self::remote_response_is_image($response) ? $url : '';
    }

    private static function is_allowed_atelier_image_candidate(string $url, string $context = ''): bool {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === 'apps.ticketmatic.com') {
            return strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) === 'https';
        }
        return self::is_likely_artist_image($url, '', $context);
    }

    /**
     * @param array<string,mixed>|\WP_Error $response
     */
    private static function remote_response_is_image($response): bool {
        if (is_wp_error($response)) {
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        return $code >= 200 && $code < 300 && str_starts_with($type, 'image/');
    }

    private static function absolute_url(string $base, string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $url)) {
            return esc_url_raw($url);
        }
        $parts = wp_parse_url($base);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (str_starts_with($url, '//')) {
            return esc_url_raw($parts['scheme'] . ':' . $url);
        }
        if (str_starts_with($url, '/')) {
            return esc_url_raw($origin . $url);
        }
        $path = isset($parts['path']) ? dirname($parts['path']) : '/';
        return esc_url_raw($origin . '/' . ltrim($path . '/' . $url, '/'));
    }

    private static function safe_source_page_url(string $url): string {
        $url = esc_url_raw(trim($url));
        if ($url === '' || strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return '';
        }
        $validated = wp_http_validate_url($url);
        return is_string($validated) ? $validated : '';
    }

    public static function image_key(string $url): string {
        $parts = wp_parse_url(html_entity_decode($url));
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return strtolower(trim($url));
        }
        return strtolower((string) $parts['host'] . rawurldecode((string) $parts['path']));
    }

    /**
     * @param array<int,string> $excluded_image_urls
     */
    private static function image_is_excluded(string $url, array $excluded_image_urls): bool {
        $key = self::image_key($url);
        foreach ($excluded_image_urls as $excluded_url) {
            if ($key === self::image_key((string) $excluded_url)) {
                return true;
            }
        }
        return false;
    }

    private static function is_likely_artist_image(string $url, string $title, string $context = ''): bool {
        unset($title);
        $lower = strtolower(rawurldecode($url . ' ' . $context));
        foreach ([
            'logo', 'sponsor', 'footer', 'cookie', 'icon', 'avatar',
            'ticket', 'qr', 'barcode', 'map',
            'placeholder', 'default-image', 'default_image',
        ] as $bad) {
            if (str_contains($lower, $bad)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<int,string>
     */
    private static function artist_title_tokens(string $title): array {
        $normalized = self::normalize_image_text($title);
        $stop_words = [
            'and', 'at', 'avec', 'concert', 'den', 'feat', 'featuring', 'live',
            'luxembourg', 'presents', 'the', 'tour', 'with',
        ];
        $tokens = array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', $normalized) ?: [],
            static fn(string $token): bool => strlen($token) >= 2
        )));
        $meaningful_tokens = array_values(array_filter(
            $tokens,
            static fn(string $token): bool => !in_array($token, $stop_words, true)
        ));
        return $meaningful_tokens;
    }

    private static function normalize_image_text(string $value): string {
        return strtolower(remove_accents($value));
    }

    private static function image_text_contains_token(string $text, string $token): bool {
        return preg_match('/(?:^|[^a-z0-9])' . preg_quote($token, '/') . '(?:$|[^a-z0-9])/', $text) === 1;
    }

    private static function prompt(array $pdf_analysis = []): string {
        $page_count = (int) ($pdf_analysis['page_count'] ?? 0);
        $groups = [];
        foreach (($pdf_analysis['groups'] ?? []) as $group) {
            $groups[] = sprintf(
                '- pages %s: title hint "%s", date %s, venue "%s"',
                implode(',', $group['page_numbers'] ?? []),
                (string) ($group['title_guess'] ?? ''),
                (string) ($group['date'] ?? ''),
                (string) ($group['venue'] ?? '')
            );
        }
        $group_context = $groups ? implode("\n", $groups) : '- no deterministic text groups available';
        return <<<TXT
You are an extraction engine for a Luxembourg concert ticket ecommerce importer.
Analyze the attached PDF ticket(s) and return ONLY JSON matching the supplied schema.
The PDF contains {$page_count} physical ticket pages.
Deterministic text extraction found these authoritative page groups:
{$group_context}
Rules:
- Each PDF page is one physical ticket.
- Group tickets belonging to the same concert using concert title + date + venue.
- ticket_count is the actual number of physical tickets in the PDF for that concert.
- page_numbers must contain the exact 1-based PDF page number of every physical ticket belonging to that concert. Never infer missing pages from a printed "Ticket X of Y" counter.
- Treat the deterministic page groups above as authoritative for page assignment.
- Ticket dates may be printed as DD/MM/YY. Return them as YYYY-MM-DD.
- Ignore the event advertisements at the bottom of each ticket. They mention other artists and dates but are not tickets in this PDF.
- Different venues and ticket types can identify different events even when the date is the same. Different ticket types for the same title, date and venue remain one event.
- The PDF price is NOT the sell price. It may be 0 because tickets are complimentary.
- Find the concrete concert page on atelier.lu for each event.
- On that Atelier concert page, determine the current public ticket price for one ticket.
- Use the direct Atelier concert URL.
- Extract a concise group/concert description from Atelier.
- Extract a direct public image URL showing the artist or group (preferred), not a ticket graphic, sponsor logo, venue logo, or generic concert poster. Prefer the official Atelier artist/group image when available; otherwise use a credible artist press photo or official artist image.
- Date format must be YYYY-MM-DD.
- price_per_ticket is numeric only. Currency should be EUR where applicable.
- Do not invent data. If a field cannot be found, use an empty string or 0 only when the schema requires it. Never use the PDF ticket image as the product image.
TXT;
    }

    private static function openai(string $pdf_path, array $settings, array $pdf_analysis = []) {
        $api_key = trim($settings['openai_api_key']);
        $model = trim($settings['openai_model'] ?: 'gpt-5');

        $upload = self::multipart('https://api.openai.com/v1/files', $api_key, $pdf_path, [
            'purpose' => 'user_data',
        ]);
        if (is_wp_error($upload)) {
            return $upload;
        }
        $file_id = $upload['id'] ?? '';
        if (!$file_id) {
            return new \WP_Error('openai_file_upload', 'OpenAI file upload did not return a file ID.');
        }

        $body = [
            'model' => $model,
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => self::prompt($pdf_analysis)],
                    ['type' => 'input_file', 'file_id' => $file_id],
                ],
            ]],
            'tools' => [['type' => 'web_search']],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'concert_import',
                    'strict' => true,
                    'schema' => self::SCHEMA,
                ],
            ],
        ];

        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 180,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code >= 300 || !is_array($data)) {
            return new \WP_Error('openai_response', 'OpenAI API error.', ['status' => $code, 'body' => $data]);
        }
        $text = self::openai_output_text($data);
        $json = json_decode($text, true);
        if (!is_array($json)) {
            return new \WP_Error('openai_json', 'OpenAI did not return valid JSON.', ['text' => $text]);
        }
        return $json;
    }

    private static function gemini_schema(): array {
        // Gemini's REST responseSchema supports only a subset of JSON Schema on this endpoint. Keep a Gemini-specific schema without unsupported object keywords.
        return [
            'type' => 'object',
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'date' => ['type' => 'string'],
                            'ticket_count' => ['type' => 'integer'],
                            'page_numbers' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'atelier_url' => ['type' => 'string'],
                            'price_per_ticket' => ['type' => 'number'],
                            'currency' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'image_url' => ['type' => 'string'],
                        ],
                        'required' => ['title','date','ticket_count','page_numbers','atelier_url','price_per_ticket','currency','description','image_url'],
                    ],
                ],
            ],
            'required' => ['events'],
        ];
    }

    private static function gemini(string $pdf_path, array $settings, array $pdf_analysis = []) {
        $key = trim($settings['gemini_api_key']);
        $model = trim($settings['gemini_model'] ?: 'gemini-3.6-flash');
        $size = filesize($pdf_path);
        $bytes = file_get_contents($pdf_path);
        if ($bytes === false) {
            return new \WP_Error('gemini_file_read', 'PDF could not be read.');
        }

        if ($size > 50 * 1024 * 1024) {
            return new \WP_Error('gemini_pdf_too_large', 'Gemini PDF size limit of 50 MB exceeded.');
        }

        $body = [
            'contents' => [[
                'parts' => [
                    ['inlineData' => ['mimeType' => 'application/pdf', 'data' => base64_encode($bytes)]],
                    ['text' => self::prompt($pdf_analysis)],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => self::gemini_schema(),
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $response = wp_remote_post($url, [
            'timeout' => 180,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $key,
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);
        if ($code >= 300 || !is_array($data)) {
            Logger::log('FAIL', 'Gemini HTTP/API error', [
                'status' => $code,
                'model' => $model,
            ]);
            return new \WP_Error('gemini_response', 'Gemini API error.', ['status' => $code, 'body' => $data]);
        }
        $text = '';
        foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        $json = json_decode(trim($text), true);
        if (!is_array($json)) {
            Logger::log('FAIL', 'Gemini response could not be parsed as JSON', [
                'model' => $model,
                'text' => self::safe_excerpt($text),
            ]);
            return new \WP_Error('gemini_json', 'Gemini did not return valid JSON.', ['text' => $text]);
        }
        return $json;
    }

    private static function custom(string $pdf_path, array $settings, array $pdf_analysis = []) {
        $bytes = file_get_contents($pdf_path);
        if ($bytes === false) {
            return new \WP_Error('custom_file_read', 'PDF could not be read.');
        }
        $headers = self::custom_headers($settings);
        $body = [
            'model' => trim($settings['custom_ai_model'] ?? ''),
            'prompt' => self::prompt($pdf_analysis),
            'pdf_base64' => base64_encode($bytes),
            'mime_type' => 'application/pdf',
            'schema' => self::SCHEMA,
        ];
        $response = wp_remote_post(esc_url_raw($settings['custom_ai_endpoint']), [
            'timeout' => 180,
            'headers' => $headers,
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code >= 300 || !is_array($data)) {
            return new \WP_Error('custom_response', 'Custom AI API error.', ['status' => $code, 'body' => $data]);
        }
        if (isset($data['events']) && is_array($data['events'])) {
            return $data;
        }
        if (isset($data['output']) && is_string($data['output'])) {
            $json = json_decode($data['output'], true);
            if (is_array($json)) {
                return $json;
            }
        }
        return new \WP_Error('custom_json', 'Custom AI response could not be interpreted.');
    }

    private static function openai_atelier(string $url, array $settings, array $event) {
        $body = [
            'model' => trim($settings['openai_model'] ?: 'gpt-5'),
            'tools' => [['type' => 'web_search']],
            'input' => self::atelier_prompt($url, $event),
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'atelier_enrichment',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'price_per_ticket' => ['type' => 'number'],
                            'description' => ['type' => 'string'],
                            'image_url' => ['type' => 'string'],
                            'atelier_url' => ['type' => 'string'],
                        ],
                        'required' => ['price_per_ticket','description','image_url','atelier_url'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . trim($settings['openai_api_key']),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = self::openai_output_text($data);
        $json = json_decode($text, true);
        return is_array($json) ? $json : new \WP_Error('openai_enrichment', 'OpenAI Atelier enrichment is invalid.');
    }

    private static function gemini_atelier(string $url, array $settings, array $event) {
        // Use direct page fetch as reliable context, then let Gemini normalize it.
        $page = self::fetch_atelier_page($url);
        if (!$page['ok']) {
            return new \WP_Error('atelier_page_unavailable', 'The date-matched Atelier page could not be read.');
        }
        $text = wp_strip_all_tags($page['html']);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = substr($text, 0, 30000);
        $prompt = self::atelier_prompt($url, $event) . "\n\nPAGE CONTENT:\n" . $text;
        $body = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'tools' => [['google_search' => new \stdClass()]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'price_per_ticket' => ['type' => 'number'],
                        'description' => ['type' => 'string'],
                        'image_url' => ['type' => 'string'],
                        'atelier_url' => ['type' => 'string'],
                    ],
                    'required' => ['price_per_ticket','description','image_url','atelier_url'],
                ],
            ],
        ];
        $url_api = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(trim($settings['gemini_model'] ?: 'gemini-3.6-flash')) . ':generateContent';
        $response = wp_remote_post($url_api, [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => trim($settings['gemini_api_key']),
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);
        if ($code >= 300 || !is_array($data)) {
            Logger::log('FAIL', 'Gemini Atelier HTTP/API error', [
                'status' => $code,
                'model' => trim($settings['gemini_model'] ?: 'gemini-3.6-flash'),
            ]);
            return new \WP_Error('gemini_enrichment_response', 'Gemini Atelier API error.', ['status' => $code, 'body' => $data]);
        }
        $text_out = '';
        foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            $text_out .= $part['text'] ?? '';
        }
        $json = json_decode(trim($text_out), true);
        if (!is_array($json)) {
            Logger::log('FAIL', 'Gemini Atelier response is not valid JSON', [
                'text' => self::safe_excerpt($text_out),
            ]);
            return new \WP_Error('gemini_enrichment', 'Gemini Atelier enrichment is invalid.');
        }
        return $json;
    }

    private static function gemini_artist_image_source(array $event, array $settings): array|\WP_Error {
        $title = trim((string) ($event['title'] ?? ''));
        $date = trim((string) ($event['date'] ?? ''));
        $prompt = sprintf(
            'Find up to three public HTTPS pages with an official artist or group press photo for "%s"%s. '
            . 'Use the official artist website, record label, management, or a dedicated artist profile or interview from a reputable press publication. '
            . 'The page title or metadata must clearly identify the exact artist or group. '
            . 'Do not return an event organizer, venue, event listing, image-search result, social network, ticket graphic, logo, poster, or generic event image. '
            . 'Return source web page URLs, not guessed direct image URLs. Order them: official artist first, then label or management, then reputable press.',
            $title,
            $date !== '' ? ' (concert date ' . $date . ')' : ''
        );
        $body = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'tools' => [['google_search' => new \stdClass()]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'sources' => [
                            'type' => 'array',
                            'maxItems' => 3,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'source_page_url' => ['type' => 'string'],
                                    'source_type' => [
                                        'type' => 'string',
                                        'enum' => ['artist', 'label', 'management', 'press'],
                                    ],
                                    'evidence' => ['type' => 'string'],
                                ],
                                'required' => ['source_page_url', 'source_type', 'evidence'],
                            ],
                        ],
                    ],
                    'required' => ['sources'],
                ],
            ],
        ];
        $model = trim($settings['gemini_model'] ?: 'gemini-3.6-flash');
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $response = wp_remote_post($endpoint, [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => trim($settings['gemini_api_key']),
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            Logger::log('FAIL', 'Gemini artist image search HTTP/API error', [
                'status' => $code,
                'model' => $model,
            ]);
            return new \WP_Error('gemini_artist_image_response', 'Gemini artist image search failed.');
        }
        $text = '';
        foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            $text .= $part['text'] ?? '';
        }
        if ($text === '') {
            Logger::log('FAIL', 'Gemini artist image search returned no content', [
                'finish_reason' => (string) ($data['candidates'][0]['finishReason'] ?? ''),
                'block_reason' => (string) ($data['promptFeedback']['blockReason'] ?? ''),
            ]);
        }
        $result = json_decode(trim($text), true);
        if (!is_array($result) || !is_array($result['sources'] ?? null)) {
            return new \WP_Error('gemini_artist_image_json', 'Gemini did not return artist image source pages.');
        }
        $sources = [];
        foreach (array_slice($result['sources'], 0, 3) as $source) {
            if (
                !is_array($source)
                || !in_array((string) ($source['source_type'] ?? ''), ['artist', 'label', 'management', 'press'], true)
            ) {
                continue;
            }
            $safe_url = self::safe_source_page_url((string) ($source['source_page_url'] ?? ''));
            if ($safe_url === '') {
                continue;
            }
            $source['source_page_url'] = $safe_url;
            $sources[] = $source;
        }
        if (!$sources) {
            return new \WP_Error('gemini_artist_image_unsafe_url', 'Gemini returned no safe artist image source page.');
        }
        return ['sources' => $sources];
    }

    private static function atelier_prompt(string $url, array $event): string {
        return sprintf(
            "Find and verify the official Atelier Luxembourg concert page for %s on %s. Candidate URL: %s. Return JSON with the current public ticket price for ONE ticket in EUR, a concise description based on Atelier, the main image URL, and the exact official Atelier concert URL. The PDF price must never be used. The price must be current and match the exact event title and date. Never guess or infer a price from unrelated events. If the exact price cannot be verified from the official Atelier event page or the official ticketing page reached from it, return an empty/unknown price. The administrator can review a broader web suggestion or enter the price manually.",
            $event['title'] ?? '',
            $event['date'] ?? '',
            $url
        );
    }


    /** Round a reviewed ticket price upward to the next full EUR amount. */
    public static function round_price_up(float $price): float {
        if ($price <= 0) {
            return 0.0;
        }
        return (float) ceil($price);
    }

    public static function verify_atelier_price(array $event, array $settings): array|\WP_Error {
        $title = trim((string)($event['title'] ?? ''));
        $date = trim((string)($event['date'] ?? ''));
        $url = esc_url_raw((string)($event['atelier_url'] ?? ''));

        Logger::log('START', 'Atelier ticket price verification started', [
            'title' => $title,
            'date' => $date,
            'url' => $url,
        ]);

        if (!$title || !$date || !$url) {
            Logger::log('FAIL', 'Atelier ticket price verification cannot start because title, date or URL is missing');
            return new \WP_Error('price_verification_missing_context', 'Ticket price verification requires the event title, date and Atelier URL.');
        }

        $candidates = [];

        $page = self::fetch_atelier_page($url);
        if ($page['ok']) {
            $direct = self::extract_direct_price_candidates($page['html']);
            if ($direct) {
                Logger::log('OK', 'Direct Atelier page price candidates found', ['count' => count($direct), 'prices' => $direct]);
                foreach ($direct as $price) {
                    $candidates[] = ['price' => $price, 'source' => $url, 'method' => 'direct_page'];
                }
            } else {
                Logger::log('STEP', 'No direct ticket price found in Atelier page HTML; AI web verification required');
            }
        } else {
            Logger::log('STEP', 'Direct Atelier page fetch failed; continuing with AI web verification', [
                'status' => $page['status'],
                'blocked' => $page['blocked'],
                'error' => $page['error'],
            ]);
        }

        if (self::selected_provider($settings) === 'openai' && !empty($settings['openai_api_key'])) {
            Logger::log('START', 'OpenAI web price verification started');
            $r = self::openai_price_verification($event, $settings);
            if (is_wp_error($r)) {
                Logger::log('FAIL', 'OpenAI web price verification failed', ['error' => $r->get_error_message()]);
            } elseif (!empty($r['price_per_ticket']) && (float)$r['price_per_ticket'] > 0) {
                $r['method'] = 'openai_web_search';
                $candidates[] = $r;
                Logger::log('OK', 'OpenAI verified a ticket price', ['price' => (float)$r['price_per_ticket'], 'source' => $r['source_url'] ?? '']);
            } else {
                Logger::log('FAIL', 'OpenAI did not verify a positive ticket price', ['evidence' => $r['evidence'] ?? '']);
            }
        }

        if (self::selected_provider($settings) === 'gemini' && !empty($settings['gemini_api_key'])) {
            Logger::log('START', 'Gemini web price verification started');
            $r = self::gemini_price_verification($event, $settings);
            if (is_wp_error($r)) {
                Logger::log('FAIL', 'Gemini web price verification failed', ['error' => $r->get_error_message()]);
            } elseif (!empty($r['price_per_ticket']) && (float)$r['price_per_ticket'] > 0) {
                $r['method'] = 'gemini_google_search';
                $candidates[] = $r;
                Logger::log('OK', 'Gemini verified a ticket price', ['price' => (float)$r['price_per_ticket'], 'source' => $r['source_url'] ?? '']);
            } else {
                Logger::log('FAIL', 'Gemini did not verify a positive ticket price', ['evidence' => $r['evidence'] ?? '']);
            }
        }


        if (self::selected_provider($settings) === 'custom' && !empty($settings['custom_ai_endpoint'])) {
            Logger::log('START', 'Custom AI price verification started');
            $r = self::custom_price_verification($event, $settings);
            if (is_wp_error($r)) {
                Logger::log('FAIL', 'Custom AI price verification failed', ['error' => $r->get_error_message()]);
            } elseif (!empty($r['price_per_ticket']) && (float)$r['price_per_ticket'] > 0) {
                $r['method'] = 'custom_ai';
                $candidates[] = $r;
                Logger::log('OK', 'Custom AI verified a ticket price', ['price' => (float)$r['price_per_ticket'], 'source' => $r['source_url'] ?? '']);
            } else {
                Logger::log('FAIL', 'Custom AI did not verify a positive ticket price', ['evidence' => $r['evidence'] ?? '']);
            }
        }

        $verified = self::select_verified_price($candidates, $url);
        if (is_wp_error($verified)) {
            Logger::log('FAIL', 'Atelier ticket price could not be verified', ['candidates' => $candidates]);
            return $verified;
        }

        $verified_price = (float) $verified['price_per_ticket'];
        $rounded_price = self::round_price_up($verified_price);
        Logger::log('OK', 'Atelier ticket price verified and rounded upward', [
            'verified_price' => $verified_price,
            'rounded_price' => $rounded_price,
            'source' => $verified['source_url'] ?? $url,
        ]);
        return [
            'price_per_ticket' => $rounded_price,
            'verified_price_before_rounding' => $verified_price,
            'price_rounding' => 'ceil_to_next_full_eur',
            'currency' => $verified['currency'] ?? 'EUR',
            'price_source' => $verified['source_url'] ?? $url,
            'price_verification_method' => $verified['method'] ?? 'verified',
            'price_verified' => 1,
        ];
    }

    /**
     * Search beyond Atelier for an exact-event price suggestion.
     *
     * Suggestions are deliberately not marked as verified. An administrator must
     * approve the editable value in the import check before product creation.
     */
    public static function suggest_event_price(array $event, array $settings): array|\WP_Error {
        $title = trim((string) ($event['title'] ?? ''));
        $date = trim((string) ($event['date'] ?? ''));
        if ($title === '' || $date === '') {
            return new \WP_Error('price_suggestion_missing_context', 'A title and date are required for the broader price search.');
        }

        $provider = self::selected_provider($settings);
        if ($provider === 'openai' && !empty($settings['openai_api_key'])) {
            $result = self::openai_price_verification($event, $settings, true);
            $method = 'openai_web_suggestion';
        } elseif ($provider === 'gemini' && !empty($settings['gemini_api_key'])) {
            $result = self::gemini_price_verification($event, $settings, true);
            $method = 'gemini_web_suggestion';
        } elseif ($provider === 'custom' && !empty($settings['custom_ai_endpoint'])) {
            $result = self::custom_price_verification($event, $settings, true);
            $method = 'custom_web_suggestion';
        } else {
            return new \WP_Error('price_suggestion_provider_missing', 'The selected AI provider is not configured for broader price search.');
        }

        if (is_wp_error($result)) {
            return $result;
        }
        $price = (float) ($result['price_per_ticket'] ?? 0);
        $source = esc_url_raw((string) ($result['source_url'] ?? ''));
        $source_scheme = strtolower((string) wp_parse_url($source, PHP_URL_SCHEME));
        if ($price <= 0 || $price > 5000 || $source === '' || $source_scheme !== 'https') {
            return new \WP_Error('price_suggestion_not_found', 'No credible price suggestion was found for the exact concert.');
        }

        return [
            'price_per_ticket' => self::round_price_up($price),
            'verified_price_before_rounding' => $price,
            'price_rounding' => 'ceil_to_next_full_eur',
            'currency' => strtoupper((string) ($result['currency'] ?? 'EUR')),
            'price_source' => $source,
            'price_verification_method' => $method,
            'price_verified' => 0,
            'price_suggested' => 1,
            'price_evidence' => sanitize_text_field((string) ($result['evidence'] ?? '')),
        ];
    }

    private static function openai_price_verification(array $event, array $settings, bool $broader_search = false) {
        $schema = [
            'type' => 'object',
            'properties' => [
                'price_per_ticket' => ['type' => 'number'],
                'currency' => ['type' => 'string'],
                'source_url' => ['type' => 'string'],
                'evidence' => ['type' => 'string'],
            ],
            'required' => ['price_per_ticket','currency','source_url','evidence'],
            'additionalProperties' => false,
        ];
        $prompt = self::price_search_prompt($event, $broader_search);
        $body = [
            'model' => trim($settings['openai_model'] ?: 'gpt-5'),
            'tools' => [['type' => 'web_search']],
            'input' => $prompt,
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'price_verification', 'strict' => true, 'schema' => $schema]],
        ];
        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . trim($settings['openai_api_key']),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if ($code >= 300 || !is_array($data)) {
            return new \WP_Error('openai_price_http', 'OpenAI price verification API error.', ['status' => $code, 'body' => self::safe_excerpt($raw)]);
        }
        $text = self::openai_output_text($data);
        $json = json_decode($text, true);
        if (!is_array($json)) return new \WP_Error('openai_price_json', 'OpenAI price verification returned invalid JSON.');
        return $json;
    }

    private static function gemini_price_verification(array $event, array $settings, bool $broader_search = false) {
        $schema = [
            'type' => 'object',
            'properties' => [
                'price_per_ticket' => ['type' => 'number'],
                'currency' => ['type' => 'string'],
                'source_url' => ['type' => 'string'],
                'evidence' => ['type' => 'string'],
            ],
            'required' => ['price_per_ticket','currency','source_url','evidence'],
        ];
        $prompt = self::price_search_prompt($event, $broader_search);
        $body = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'tools' => [['google_search' => new \stdClass()]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];
        $model = trim($settings['gemini_model'] ?: 'gemini-3.6-flash');
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $response = wp_remote_post($endpoint, [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => trim($settings['gemini_api_key']),
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if ($code >= 300 || !is_array($data)) {
            return new \WP_Error('gemini_price_http', 'Gemini price verification API error.', ['status' => $code, 'body' => self::safe_excerpt($raw)]);
        }
        $text = '';
        foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) $text .= $part['text'] ?? '';
        $json = json_decode(trim($text), true);
        if (!is_array($json)) return new \WP_Error('gemini_price_json', 'Gemini price verification returned invalid JSON.');
        return $json;
    }


    private static function custom_price_verification(array $event, array $settings, bool $broader_search = false) {
        $endpoint = esc_url_raw((string)($settings['custom_ai_endpoint'] ?? ''));
        if (!$endpoint) {
            return new \WP_Error('custom_price_endpoint_missing', 'Custom AI endpoint is missing.');
        }
        $atelier_url = esc_url_raw((string) ($event['atelier_url'] ?? ''));
        $page = $atelier_url !== '' ? self::fetch_atelier_page($atelier_url) : null;
        $page_text = '';
        if (is_array($page) && $page['ok']) {
            $page_text = wp_strip_all_tags($page['html']);
            $page_text = preg_replace('/\s+/u', ' ', $page_text);
            $page_text = substr($page_text, 0, 30000);
        }
        $headers = self::custom_headers($settings);
        $prompt = self::price_search_prompt($event, $broader_search);
        $body = [
            'model' => trim($settings['custom_ai_model'] ?? ''),
            'prompt' => $prompt,
            'page_url' => esc_url_raw((string)($event['atelier_url'] ?? '')),
            'page_content' => $page_text,
            'response_format' => [
                'type' => 'json',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'price_per_ticket' => ['type' => 'number'],
                        'currency' => ['type' => 'string'],
                        'source_url' => ['type' => 'string'],
                        'evidence' => ['type' => 'string'],
                    ],
                    'required' => ['price_per_ticket','currency','source_url','evidence'],
                ],
            ],
        ];
        $response = wp_remote_post($endpoint, [
            'timeout' => 120,
            'headers' => $headers,
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if ($code >= 300 || !is_array($data)) {
            return new \WP_Error('custom_price_http', 'Custom AI price verification API error.', ['status' => $code, 'body' => self::safe_excerpt($raw)]);
        }
        if (isset($data['price_per_ticket'])) return $data;
        if (isset($data['output']) && is_string($data['output'])) {
            $json = json_decode($data['output'], true);
            if (is_array($json)) return $json;
        }
        return new \WP_Error('custom_price_json', 'Custom AI price verification response could not be interpreted.');
    }

    private static function price_search_prompt(array $event, bool $broader_search): string {
        $base = sprintf(
            'Find the public price for exactly this concert: %s on %s at %s in Luxembourg. Candidate Atelier URL: %s. The result must match the exact artist or event, date and venue. Return the price for ONE standard ticket in EUR, not a package, fee, resale markup, donation, or another event. Never use the uploaded PDF price and never invent a value.',
            (string) ($event['title'] ?? ''),
            (string) ($event['date'] ?? ''),
            (string) ($event['venue'] ?? ''),
            (string) ($event['atelier_url'] ?? '')
        );
        if ($broader_search) {
            return $base
                . ' Search beyond atelier.lu using credible official organizers, primary ticket sellers, venue pages, or exact-event listings. '
                . 'Return a positive price only when the source explicitly identifies this exact concert. Include the public source URL and short evidence. '
                . 'If no exact-event price is found, return -1 and explain why.';
        }
        return $base
            . ' Prefer the official Atelier page and the primary ticketing page linked from Atelier. '
            . 'If the exact current price cannot be verified there, return -1 and explain why.';
    }

    private static function extract_direct_price_candidates(string $html): array {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $matches = [];
        if (preg_match_all('/(?:€\s*|EUR\s*)([0-9]{1,4}(?:[.,][0-9]{1,2})?)/iu', $text, $m)) {
            foreach ($m[1] as $raw) {
                $value = (float) str_replace(',', '.', $raw);
                if ($value > 0 && $value < 500) $matches[] = $value;
            }
        }
        return array_values(array_unique($matches));
    }

    private static function select_verified_price(array $candidates, string $official_url) {
        $positive = [];
        foreach ($candidates as $candidate) {
            $price = (float)($candidate['price'] ?? $candidate['price_per_ticket'] ?? 0);
            if ($price <= 0) continue;
            $positive[] = [
                'price_per_ticket' => $price,
                'currency' => strtoupper((string)($candidate['currency'] ?? 'EUR')),
                'source_url' => esc_url_raw((string)($candidate['source_url'] ?? $candidate['source'] ?? $official_url)),
                'method' => (string)($candidate['method'] ?? 'unknown'),
            ];
        }
        if (!$positive) {
            return new \WP_Error('price_not_verified', 'The current Atelier ticket price could not be verified. The product was not created.');
        }

        $ai_verified = array_values(array_filter($positive, static function ($candidate) {
            return in_array($candidate['method'], ['openai_web_search', 'gemini_google_search', 'custom_ai'], true);
        }));
        if (!$ai_verified) {
            return new \WP_Error('price_not_verified', 'The direct Atelier page did not provide enough evidence to verify the current ticket price. The product was not created.');
        }

        $positive = $ai_verified;
        $counts = [];
        foreach ($positive as $candidate) {
            $key = number_format($candidate['price_per_ticket'], 2, '.', '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        $selected_key = array_key_first($counts);
        $selected = null;
        foreach ($positive as $candidate) {
            if (number_format($candidate['price_per_ticket'], 2, '.', '') === $selected_key) {
                $selected = $candidate;
                break;
            }
        }
        if (!$selected) {
            return new \WP_Error('price_not_verified', 'The current Atelier ticket price could not be verified.');
        }
        if (count($counts) > 1 && reset($counts) < 2) {
            return new \WP_Error('price_conflict', 'Price verification produced conflicting ticket prices. The product was not created.');
        }
        $selected['verification_count'] = $counts[$selected_key];
        return $selected;
    }

    private static function multipart(string $url, string $api_key, string $file_path, array $fields) {
        $boundary = wp_generate_password(24, false, false);
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
            $body .= $value . "\r\n";
        }
        $filename = basename($file_path);
        $mime = 'application/pdf';
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . "\"\r\n";
        $body .= 'Content-Type: ' . $mime . "\r\n\r\n";
        $body .= file_get_contents($file_path);
        $body .= "\r\n--{$boundary}--\r\n";

        $response = wp_remote_post($url, [
            'timeout' => 180,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code >= 300 || !is_array($data)) {
            return new \WP_Error('multipart_error', 'File upload failed.', ['status' => $code, 'body' => $data]);
        }
        return $data;
    }

    private static function openai_output_text(array $data): string {
        if (!empty($data['output_text']) && is_string($data['output_text'])) {
            return $data['output_text'];
        }
        $text = '';
        foreach ((array)($data['output'] ?? []) as $item) {
            foreach ((array)($item['content'] ?? []) as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    $text .= $content['text'];
                }
            }
        }
        return $text;
    }

    private static function custom_headers(array $settings): array {
        $headers = ['Content-Type' => 'application/json'];
        $type = sanitize_key((string)($settings['custom_ai_auth_type'] ?? 'bearer'));
        $token = trim((string)($settings['custom_ai_token'] ?? ''));
        if ($token === '' || $type === 'none') {
            return $headers;
        }
        if ($type === 'api_key_header') {
            $header = trim((string)($settings['custom_ai_auth_header'] ?? 'X-API-Key'));
            $header = preg_replace('/[^A-Za-z0-9\-]/', '', $header) ?: 'X-API-Key';
            $headers[$header] = $token;
        } else {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    private static function safe_excerpt(string $value, int $max = 2000): string {
        $value = preg_replace('/\s+/', ' ', $value);
        if (strlen($value) > $max) {
            return substr($value, 0, $max) . '...';
        }
        return $value;
    }

    private static function reconcile(array $payload, array $pdf_analysis): array {
        $groups = $pdf_analysis['groups'] ?? [];
        if (!$groups) {
            return $payload;
        }

        $events = [];
        foreach (($payload['events'] ?? []) as $event) {
            if (is_array($event)) {
                $events[] = self::normalize_event($event);
            }
        }
        $used = [];
        $reconciled = [];

        foreach ($groups as $group) {
            $best_index = null;
            $best_score = 0;
            foreach ($events as $index => $event) {
                if (isset($used[$index])) {
                    continue;
                }
                $overlap = count(array_intersect(
                    (array) ($group['page_numbers'] ?? []),
                    (array) ($event['page_numbers'] ?? [])
                ));
                $date_match = !empty($group['date']) && $group['date'] === ($event['date'] ?? '');
                $title_match = self::titles_match(
                    (string) ($group['title_guess'] ?? ''),
                    (string) ($event['title'] ?? '')
                );
                $score = ($overlap * 100) + ($date_match ? 10 : 0) + ($title_match ? 5 : 0);
                if ($score > $best_score) {
                    $best_score = $score;
                    $best_index = $index;
                }
            }

            if ($best_index !== null && $best_score >= 5) {
                $event = $events[$best_index];
                $used[$best_index] = true;
            } else {
                $event = [
                    'title' => (string) ($group['title_guess'] ?? ''),
                    'date' => (string) ($group['date'] ?? ''),
                    'atelier_url' => '',
                    'price_per_ticket' => 0,
                    'currency' => 'EUR',
                    'description' => '',
                    'image_url' => '',
                    '_needs_review' => 1,
                    '_analysis_warning' => 'The AI did not return enrichment data for this detected ticket group.',
                ];
                Logger::log('FAIL', 'AI event missing for deterministic ticket group', [
                    'title' => $event['title'],
                    'date' => $event['date'],
                    'pages' => $group['page_numbers'] ?? [],
                ]);
            }

            $event['title'] = $event['title'] ?: (string) ($group['title_guess'] ?? '');
            $event['date'] = (string) ($group['date'] ?? $event['date'] ?? '');
            $event['venue'] = (string) ($group['venue'] ?? '');
            $event['page_numbers'] = array_values(array_map('intval', (array) ($group['page_numbers'] ?? [])));
            $event['ticket_count'] = count($event['page_numbers']);
            $reconciled[] = $event;
        }

        foreach ($events as $index => $event) {
            if (!isset($used[$index])) {
                Logger::log('FAIL', 'AI event discarded because it does not match a ticket page group', [
                    'title' => $event['title'] ?? '',
                    'date' => $event['date'] ?? '',
                    'pages' => $event['page_numbers'] ?? [],
                ]);
            }
        }

        $payload['events'] = $reconciled;
        return $payload;
    }

    private static function titles_match(string $left, string $right): bool {
        $normalize = static function (string $value): string {
            $value = strtolower(remove_accents($value));
            return preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
        };
        $left = $normalize($left);
        $right = $normalize($right);
        return $left !== '' && $right !== '' && (
            $left === $right
            || str_contains($left, $right)
            || str_contains($right, $left)
        );
    }

    private static function normalize_event(array $event): array {
        $event['title'] = trim((string)($event['title'] ?? ''));
        $event['date'] = self::normalize_date((string) ($event['date'] ?? ''));
        $event['ticket_count'] = max(0, (int)($event['ticket_count'] ?? 0));
        $event['page_numbers'] = array_values(array_unique(array_filter(array_map('intval', (array)($event['page_numbers'] ?? [])), static fn($v) => $v > 0)));
        sort($event['page_numbers'], SORT_NUMERIC);
        if ($event['page_numbers']) {
            $event['ticket_count'] = count($event['page_numbers']);
        }
        $event['atelier_url'] = esc_url_raw((string)($event['atelier_url'] ?? ''));
        // PDF/AI extraction never determines the sell price; Atelier verification does.
        $event['price_per_ticket'] = 0.0;
        unset($event['price_source'], $event['price_verified'], $event['price_verification_method']);
        $event['currency'] = strtoupper(trim((string)($event['currency'] ?? 'EUR')));
        $event['description'] = wp_kses_post((string)($event['description'] ?? ''));
        $event['image_url'] = esc_url_raw((string)($event['image_url'] ?? ''));
        return $event;
    }

    private static function normalize_date(string $value): string {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T\s].*)?$/', $value, $match)) {
            $year = (int) $match[1];
            $month = (int) $match[2];
            $day = (int) $match[3];
        } elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{2}|\d{4})$/', $value, $match)) {
            $day = (int) $match[1];
            $month = (int) $match[2];
            $year = (int) $match[3];
            if ($year < 100) {
                $year += 2000;
            }
        } else {
            Logger::log('FAIL', 'AI event date could not be normalized', ['date' => $value]);
            return '';
        }
        if (!checkdate($month, $day, $year)) {
            Logger::log('FAIL', 'AI event date is invalid', ['date' => $value]);
            return '';
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function consensus(array $providers): array {
        $all = [];
        foreach ($providers as $provider => $payload) {
            foreach (($payload['events'] ?? []) as $event) {
                $event = self::normalize_event($event);
                if (!$event['title'] || !$event['date']) {
                    continue;
                }
                $key = strtolower($event['title']) . '|' . $event['date'];
                $all[$key]['events'][] = $event;
                $all[$key]['providers'][] = $provider;
            }
        }
        $final = [];
        foreach ($all as $bucket) {
            $events = $bucket['events'];
            usort($events, static fn($a, $b) => strcmp($b['title'], $a['title']));
            $base = $events[0];
            $page_numbers = [];
            foreach ($events as $event) {
                $page_numbers = array_merge($page_numbers, (array) ($event['page_numbers'] ?? []));
            }
            $page_numbers = array_values(array_unique(array_filter(array_map('intval', $page_numbers), static fn($page) => $page > 0)));
            sort($page_numbers, SORT_NUMERIC);
            $base['page_numbers'] = $page_numbers;
            $base['ticket_count'] = count($page_numbers);
            foreach (['atelier_url','price_per_ticket','currency','description','image_url'] as $field) {
                $values = [];
                foreach ($events as $event) {
                    $v = $event[$field] ?? '';
                    if ($v !== '' && $v !== 0 && $v !== null) {
                        $values[] = (string)$v;
                    }
                }
                if ($values) {
                    $counts = array_count_values($values);
                    arsort($counts);
                    $chosen = array_key_first($counts);
                    if (is_numeric($chosen) && $field === 'price_per_ticket') {
                        $base[$field] = (float)$chosen;
                    } else {
                        $base[$field] = $chosen;
                    }
                }
            }
            $base['_ai_providers'] = array_values(array_unique($bucket['providers']));
            $base['_ai_confidence'] = count(array_unique($bucket['providers'])) / max(1, count($providers));
            $final[] = $base;
        }
        return $final;
    }

    private static function merge_best(array $base, array $extra): array {
        foreach (['description','image_url','atelier_url'] as $field) {
            if (!empty($extra[$field])) {
                $base[$field] = $extra[$field];
            }
        }
        // A price from enrichment is only accepted after verify_atelier_price().
        return $base;
    }
}
