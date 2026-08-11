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
        Logger::log('STEP', 'Selected AI provider', ['provider' => $provider]);
        if (!$provider) {
            return new \WP_Error('no_ai_provider', 'No AI provider is selected.');
        }

        if ($provider === 'openai') {
            if (empty($settings['openai_api_key'])) return new \WP_Error('openai_not_configured', 'OpenAI is selected but no API key is configured.');
            Logger::log('START', 'OpenAI analysis started');
            $result = self::openai($pdf_path, $settings);
        } elseif ($provider === 'gemini') {
            if (empty($settings['gemini_api_key'])) return new \WP_Error('gemini_not_configured', 'Gemini is selected but no API key is configured.');
            Logger::log('START', 'Gemini analysis started');
            $result = self::gemini($pdf_path, $settings);
        } else {
            if (empty($settings['custom_ai_endpoint'])) return new \WP_Error('custom_not_configured', 'Custom AI is selected but no endpoint is configured.');
            Logger::log('START', 'Custom AI analysis started');
            $result = self::custom($pdf_path, $settings);
        }

        if (is_wp_error($result)) {
            Logger::log('FAIL', ucfirst($provider) . ' analysis failed', ['error' => $result->get_error_message()]);
            return $result;
        }
        Logger::log('OK', ucfirst($provider) . ' analysis completed', ['events' => count($result['events'] ?? [])]);
        return [
            'providers' => [$provider => $result],
            'errors' => [],
            'consensus' => self::consensus([$provider => $result]),
            'selected_provider' => $provider,
        ];
    }

    private static function selected_provider(array $settings): string {
        $provider = sanitize_key((string)($settings['ai_provider'] ?? 'openai'));
        return in_array($provider, ['openai','gemini','custom'], true) ? $provider : 'openai';
    }

    public static function enrich_atelier(array $event, array $settings): array {
        $url = esc_url_raw($event['atelier_url'] ?? '');
        Logger::log('STEP', 'Atelier enrichment started', ['title' => $event['title'] ?? '', 'url' => $url]);
        if (!$url) {
            return $event;
        }

        // First, extract the official page image directly. This is more reliable than asking an AI to
        // return a URL that may actually point to a web page instead of an image file.
        $page_image = self::extract_official_artist_image($url, (string) ($event['title'] ?? ''));
        if ($page_image) {
            $event['image_url'] = $page_image;
            $event['image_source'] = 'atelier-page';
            Logger::log('OK', 'Official Atelier artist/group image found', ['url' => $page_image]);
        } else {
            Logger::log('STEP', 'No official Atelier artist/group image found in page metadata');
        }

        if (self::selected_provider($settings) === 'openai' && !empty($settings['openai_api_key'])) {
            $r = self::openai_atelier($url, $settings, $event);
            if (!is_wp_error($r)) {
                $event = array_merge($event, $r);
                if ($page_image) {
                    $event['image_url'] = $page_image;
                    $event['image_source'] = 'atelier-page';
                }
                Logger::log('OK', 'Atelier enrichment from OpenAI applied', ['title' => $event['title'] ?? '']);
            } else {
                Logger::log('FAIL', 'OpenAI Atelier enrichment failed', ['error' => $r->get_error_message()]);
            }
        }

        if (self::selected_provider($settings) === 'gemini' && !empty($settings['gemini_api_key'])) {
            $r = self::gemini_atelier($url, $settings, $event);
            if (!is_wp_error($r)) {
                $event = self::merge_best($event, $r);
                if ($page_image) {
                    $event['image_url'] = $page_image;
                    $event['image_source'] = 'atelier-page';
                }
                Logger::log('OK', 'Atelier data cross-checked with Gemini', ['title' => $event['title'] ?? '']);
            } else {
                Logger::log('FAIL', 'Gemini Atelier enrichment failed', ['error' => $r->get_error_message()]);
            }
        }

        // If the official page did not expose an image, validate the AI-provided URL and reject page URLs.
        if (!empty($event['image_url']) && (($event['image_source'] ?? '') !== 'atelier-page')) {
            $validated = self::validate_image_url((string) $event['image_url'], (string) ($event['title'] ?? ''));
            if ($validated) {
                $event['image_url'] = $validated;
                $event['image_source'] = 'ai-validated';
                Logger::log('OK', 'AI artist/group image URL validated', ['url' => $validated]);
            } else {
                $event['image_url'] = '';
                Logger::log('FAIL', 'AI image URL could not be validated as an image', ['url' => (string) ($event['image_url'] ?? '')]);
            }
        }

        return $event;
    }

    private static function extract_official_artist_image(string $url, string $title): string {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'redirection' => 5,
            'headers' => ['User-Agent' => 'Enovos-Ticket-Shop/0.3.0 WordPress'],
        ]);
        if (is_wp_error($response)) {
            Logger::log('FAIL', 'Could not fetch Atelier page for image extraction', ['error' => $response->get_error_message()]);
            return '';
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            Logger::log('FAIL', 'Atelier page returned unexpected status for image extraction', ['status' => $code]);
            return '';
        }
        $html = wp_remote_retrieve_body($response);
        if (!$html) {
            return '';
        }

        $candidates = [];
        if (preg_match_all('/<meta[^>]+(?:property|name)=[\"\'](?:og:image|twitter:image)[\"\'][^>]+content=[\"\']([^\"\']+)[\"\']/i', $html, $m)) {
            $candidates = array_merge($candidates, $m[1]);
        }
        if (preg_match_all('/<meta[^>]+content=[\"\']([^\"\']+)[\"\'][^>]+(?:property|name)=[\"\'](?:og:image|twitter:image)[\"\']/i', $html, $m2)) {
            $candidates = array_merge($candidates, $m2[1]);
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = trim((string) $img->getAttribute('src'));
            if ($src !== '') {
                $candidates[] = $src;
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = self::absolute_url($url, html_entity_decode((string) $candidate));
            if (!$candidate) {
                continue;
            }
            if (self::is_likely_artist_image($candidate, $title)) {
                return $candidate;
            }
        }
        return '';
    }

    private static function validate_image_url(string $url, string $title): string {
        $url = esc_url_raw($url);
        if (!$url || !self::is_likely_artist_image($url, $title)) {
            return '';
        }
        $response = wp_remote_head($url, [
            'timeout' => 20,
            'redirection' => 5,
            'headers' => ['User-Agent' => 'Enovos-Ticket-Shop/0.3.0 WordPress'],
        ]);
        if (is_wp_error($response)) {
            $response = wp_remote_get($url, [
                'timeout' => 20,
                'redirection' => 5,
                'limit_response_size' => 65536,
                'headers' => ['User-Agent' => 'Enovos-Ticket-Shop/0.3.0 WordPress'],
            ]);
        }
        if (is_wp_error($response)) {
            return '';
        }
        $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        return str_starts_with($type, 'image/') ? $url : '';
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

    private static function is_likely_artist_image(string $url, string $title): bool {
        $lower = strtolower($url);
        foreach (['logo', 'sponsor', 'footer', 'cookie', 'icon', 'avatar'] as $bad) {
            if (str_contains($lower, $bad)) {
                return false;
            }
        }
        foreach (['ticket', 'qr', 'barcode', 'map'] as $bad) {
            if (str_contains($lower, $bad)) {
                return false;
            }
        }
        return true;
    }

    private static function prompt(): string {
        return <<<TXT
You are an extraction engine for a Luxembourg concert ticket ecommerce importer.
Analyze the attached PDF ticket(s) and return ONLY JSON matching the supplied schema.
Rules:
- Each PDF page is one physical ticket.
- Group tickets belonging to the same concert using concert title + date + venue.
- ticket_count is the actual number of physical tickets in the PDF for that concert.
- page_numbers must contain the exact 1-based PDF page number of every physical ticket belonging to that concert. Never infer missing pages from a printed "Ticket X of Y" counter.
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

    private static function openai(string $pdf_path, array $settings) {
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
                    ['type' => 'input_text', 'text' => self::prompt()],
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

    private static function gemini(string $pdf_path, array $settings) {
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
                    ['text' => self::prompt()],
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
                'body' => self::safe_excerpt($raw_body),
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

    private static function custom(string $pdf_path, array $settings) {
        $bytes = file_get_contents($pdf_path);
        if ($bytes === false) {
            return new \WP_Error('custom_file_read', 'PDF could not be read.');
        }
        $headers = self::custom_headers($settings);
        $body = [
            'model' => trim($settings['custom_ai_model'] ?? ''),
            'prompt' => self::prompt(),
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
        $page = wp_remote_get($url, ['timeout' => 30, 'redirection' => 3]);
        if (is_wp_error($page)) {
            return $page;
        }
        $html = wp_remote_retrieve_body($page);
        $text = wp_strip_all_tags($html);
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
                'body' => self::safe_excerpt($raw_body),
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

    private static function atelier_prompt(string $url, array $event): string {
        return sprintf(
            "Find and verify the official Atelier Luxembourg concert page for %s on %s. Candidate URL: %s. Return JSON with the current public ticket price for ONE ticket in EUR, a concise description based on Atelier, the main image URL, and the exact official Atelier concert URL. The PDF price must never be used. The price must be current and match the exact event title and date. Never guess or infer a price from unrelated events. If the exact price cannot be verified from the official Atelier event page or the official ticketing page reached from it, return an empty/unknown price and explain the missing verification in your internal reasoning. The importer will reject unverified prices.",
            $event['title'] ?? '',
            $event['date'] ?? '',
            $url
        );
    }


    /** Round a verified ticket price upward to the next full EUR amount. */
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

        $page = wp_remote_get($url, [
            'timeout' => 30,
            'redirection' => 5,
            'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
        ]);
        if (!is_wp_error($page)) {
            $html = wp_remote_retrieve_body($page);
            $direct = self::extract_direct_price_candidates($html);
            if ($direct) {
                Logger::log('OK', 'Direct Atelier page price candidates found', ['count' => count($direct), 'prices' => $direct]);
                foreach ($direct as $price) {
                    $candidates[] = ['price' => $price, 'source' => $url, 'method' => 'direct_page'];
                }
            } else {
                Logger::log('STEP', 'No direct ticket price found in Atelier page HTML; AI web verification required');
            }
        } else {
            Logger::log('STEP', 'Direct Atelier page fetch failed; continuing with AI web verification', ['error' => $page->get_error_message()]);
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

    private static function openai_price_verification(array $event, array $settings) {
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
        $prompt = sprintf(
            "Verify the current public ticket price for exactly this concert: %s on %s in Luxembourg. Official Atelier event URL: %s. Use web search. Prefer the official Atelier page and the official ticketing page linked from Atelier. The result must match the exact event title and exact date. Return the price for ONE standard ticket in EUR, not a package price, fee, donation, or another event. Never use the PDF price and never guess. If you cannot verify the exact current price, return -1 and explain why in evidence.",
            $event['title'] ?? '', $event['date'] ?? '', $event['atelier_url'] ?? ''
        );
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

    private static function gemini_price_verification(array $event, array $settings) {
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
        $prompt = sprintf(
            "Verify the current public ticket price for exactly this concert: %s on %s in Luxembourg. Official Atelier event URL: %s. Use Google Search grounding. Prefer the official Atelier page and the official ticketing page linked from Atelier. The result must match the exact event title and exact date. Return the price for ONE standard ticket in EUR, not a package price, fee, donation, or another event. Never use the PDF price and never guess. If you cannot verify the exact current price, return -1 and explain why in evidence.",
            $event['title'] ?? '', $event['date'] ?? '', $event['atelier_url'] ?? ''
        );
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


    private static function custom_price_verification(array $event, array $settings) {
        $endpoint = esc_url_raw((string)($settings['custom_ai_endpoint'] ?? ''));
        if (!$endpoint) {
            return new \WP_Error('custom_price_endpoint_missing', 'Custom AI endpoint is missing.');
        }
        $page = wp_remote_get(esc_url_raw((string)($event['atelier_url'] ?? '')), ['timeout' => 30, 'redirection' => 5]);
        $page_text = '';
        if (!is_wp_error($page)) {
            $page_text = wp_strip_all_tags(wp_remote_retrieve_body($page));
            $page_text = preg_replace('/\s+/u', ' ', $page_text);
            $page_text = substr($page_text, 0, 30000);
        }
        $headers = self::custom_headers($settings);
        $prompt = sprintf(
            "Verify the current public ticket price for exactly this concert: %s on %s in Luxembourg. Official Atelier URL: %s. Use only evidence matching the exact event title and date from the provided Atelier page content. Return the price for ONE standard ticket in EUR, not a package, fee, donation, or another event. Never use the PDF price and never guess. If the exact price cannot be verified from the provided content, return 0 and explain why.",
            $event['title'] ?? '', $event['date'] ?? '', $event['atelier_url'] ?? ''
        );
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
            $body .= 'Content-Disposition: form-data; name="' . $name . '"\r\n\r\n';
            $body .= $value . "\r\n";
        }
        $filename = basename($file_path);
        $mime = 'application/pdf';
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"\r\n';
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

    private static function normalize_event(array $event): array {
        $event['title'] = trim((string)($event['title'] ?? ''));
        $event['date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($event['date'] ?? '')) ? $event['date'] : '';
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
            foreach (['ticket_count','atelier_url','price_per_ticket','currency','description','image_url'] as $field) {
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
                    if (is_numeric($chosen) && in_array($field, ['ticket_count','price_per_ticket'], true)) {
                        $base[$field] = (float)$chosen;
                        if ($field === 'ticket_count') {
                            $base[$field] = (int)$base[$field];
                        }
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
