<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin {
    private static $instance;

    public static function instance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public static function defaults(): array {
        return [
            'ai_provider' => 'openai',
            'openai_api_key' => '',
            'openai_model' => 'gpt-5',
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-3.6-flash',
            'custom_ai_endpoint' => '',
            'custom_ai_token' => '',
            'custom_ai_model' => '',
            'custom_ai_auth_type' => 'bearer',
            'custom_ai_auth_header' => 'X-API-Key',
            'publish_products' => 0,
            'delivery_order_status' => 'processing',
            'admin_page_size' => 50,
        ];
    }

    private function __construct() {
        $this->maybe_upgrade();
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_enovos_ticket_analyze', [$this, 'handle_analyze']);
        add_action('admin_post_enovos_ticket_import', [$this, 'handle_import']);
        add_action('admin_post_enovos_ticket_clear_log', [$this, 'handle_clear_log']);

        add_action('woocommerce_checkout_order_created', [$this, 'reserve_order']);
        add_action('woocommerce_order_status_pending', [$this, 'reserve_order_by_id']);
        add_action('woocommerce_order_status_on-hold', [$this, 'reserve_order_by_id']);
        add_action('woocommerce_order_status_processing', [$this, 'reserve_order_by_id']);
        add_action('woocommerce_order_status_cancelled', [$this, 'release_order']);
        add_action('woocommerce_order_status_failed', [$this, 'release_order']);
        add_action('woocommerce_order_status_refunded', [$this, 'refund_order']);
        add_filter('woocommerce_email_attachments', [$this, 'email_attachments'], 20, 4);
    }

    private function maybe_upgrade(): void {
        $stored = (string)get_option('enovos_ticket_shop_version', '0.0.0');
        if (version_compare($stored, ENOVOS_TICKET_SHOP_VERSION, '>=')) return;
        $settings = wp_parse_args(get_option('enovos_ticket_shop_settings', []), self::defaults());
        if (empty($settings['ai_provider'])) $settings['ai_provider'] = 'openai';
        update_option('enovos_ticket_shop_settings', $settings, false);
        TicketInventory::install();
        update_option('enovos_ticket_shop_version', ENOVOS_TICKET_SHOP_VERSION, false);
        Logger::log('STEP', 'Plugin upgraded', ['version' => ENOVOS_TICKET_SHOP_VERSION]);
    }

    public function admin_menu(): void {
        add_menu_page('Enovos Ticket Shop', 'Enovos Tickets', 'manage_woocommerce', 'enovos-ticket-shop', [$this, 'render_dashboard'], 'dashicons-tickets-alt', 56);
        add_submenu_page('enovos-ticket-shop', 'Ticket Inventory', 'Ticket Inventory', 'manage_woocommerce', 'enovos-ticket-inventory', [$this, 'render_inventory']);
        add_submenu_page('enovos-ticket-shop', 'AI Settings', 'AI Settings', 'manage_woocommerce', 'enovos-ticket-shop-settings', [$this, 'render_settings']);
        add_submenu_page('enovos-ticket-shop', 'Changelog', 'Changelog', 'manage_woocommerce', 'enovos-ticket-shop-changelog', [$this, 'render_changelog']);
    }

    public function register_settings(): void {
        register_setting('enovos_ticket_shop_settings_group', 'enovos_ticket_shop_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => self::defaults(),
        ]);
    }

    public function sanitize_settings(array $input): array {
        $d = self::defaults();
        $provider = sanitize_key($input['ai_provider'] ?? $d['ai_provider']);
        if (!in_array($provider, ['openai','gemini','custom'], true)) $provider = 'openai';
        $auth = sanitize_key($input['custom_ai_auth_type'] ?? 'bearer');
        if (!in_array($auth, ['bearer','api_key_header','none'], true)) $auth = 'bearer';
        $delivery = sanitize_key($input['delivery_order_status'] ?? 'processing');
        if (!in_array($delivery, ['processing','completed'], true)) $delivery = 'processing';
        return [
            'ai_provider' => $provider,
            'openai_api_key' => sanitize_text_field($input['openai_api_key'] ?? ''),
            'openai_model' => sanitize_text_field($input['openai_model'] ?? $d['openai_model']),
            'gemini_api_key' => sanitize_text_field($input['gemini_api_key'] ?? ''),
            'gemini_model' => sanitize_text_field($input['gemini_model'] ?? $d['gemini_model']),
            'custom_ai_endpoint' => esc_url_raw($input['custom_ai_endpoint'] ?? ''),
            'custom_ai_token' => sanitize_text_field($input['custom_ai_token'] ?? ''),
            'custom_ai_model' => sanitize_text_field($input['custom_ai_model'] ?? ''),
            'custom_ai_auth_type' => $auth,
            'custom_ai_auth_header' => sanitize_text_field($input['custom_ai_auth_header'] ?? 'X-API-Key'),
            'publish_products' => !empty($input['publish_products']) ? 1 : 0,
            'delivery_order_status' => $delivery,
            'admin_page_size' => max(10, min(500, (int)($input['admin_page_size'] ?? 50))),
        ];
    }

    public function render_dashboard(): void {
        if (!current_user_can('manage_woocommerce')) return;
        $analysis = get_transient('enovos_ticket_shop_last_analysis_' . get_current_user_id());
        $last_import = get_transient('enovos_ticket_shop_last_import_' . get_current_user_id());
        $settings = wp_parse_args(get_option('enovos_ticket_shop_settings', []), self::defaults());
        $engine = PdfPackages::engine_status();

        echo '<div class="wrap"><h1>Enovos Concert Ticket Shop Importer</h1>';
        echo '<p><strong>Version:</strong> ' . esc_html(ENOVOS_TICKET_SHOP_VERSION) . ' | WordPress 6.4+ | PHP 8.0+</p>';
        echo '<p><strong>Selected AI provider:</strong> ' . esc_html(ucfirst($settings['ai_provider'])) . ' | <strong>PDF package engine:</strong> ' . esc_html($engine['message']) . '</p>';
        echo '<p><strong>Sales logic:</strong> 2 physical ticket pages = 1 protected ticket PDF package = 1 WooCommerce stock unit. Product price = verified one-ticket public price rounded upward to a full EUR amount.</p>';

        $this->render_log();
        $this->render_last_import($last_import);
        $this->render_analysis($analysis, $engine);

        echo '<hr><h2>Analyze a ticket PDF</h2>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="enovos_ticket_analyze">';
        wp_nonce_field('enovos_ticket_analyze');
        echo '<input type="file" name="ticket_pdf" accept="application/pdf,.pdf" required> ';
        echo '<button class="button button-primary" type="submit">Analyze PDF</button></form>';
        echo '</div>';
    }

    private function render_analysis($analysis, array $engine): void {
        if (!$analysis || empty($analysis['consensus'])) return;
        echo '<h2>Import check</h2>';
        echo '<p>Review every concert before import. You can reduce the product quantity manually, but it cannot exceed the number of complete two-ticket packages detected in the PDF.</p>';
        if (empty($engine['available'])) {
            echo '<div class="notice notice-error inline"><p><strong>Ticket PDF generation is blocked:</strong> ' . esc_html($engine['message']) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="enovos_ticket_import">';
        wp_nonce_field('enovos_ticket_import');
        echo '<p><label><input type="checkbox" id="enovos-select-all" checked> <strong>Select / deselect all</strong></label></p>';
        echo '<table class="widefat striped"><thead><tr><th>Select</th><th>Concert</th><th>Date</th><th>Detected pages</th><th>Packages available</th><th>Product quantity</th><th>Price</th><th>Atelier</th><th>Image</th><th>Status</th></tr></thead><tbody>';
        foreach ($analysis['consensus'] as $event) {
            $key = $event['_import_key'] ?? $this->event_import_key($event);
            $pages = array_values(array_filter(array_map('intval', (array)($event['page_numbers'] ?? []))));
            $possible = intdiv(count($pages), 2);
            $valid = !empty($engine['available']) && !empty($event['title']) && !empty($event['date']) && !empty($event['atelier_url']) && !empty($event['price_verified']) && (float)($event['price_per_ticket'] ?? 0) > 0 && $possible > 0;
            echo '<tr>';
            echo '<td><input type="checkbox" class="enovos-import-check" name="selected_events[]" value="' . esc_attr($key) . '" ' . checked($valid, true, false) . ' ' . disabled($valid, false, false) . '></td>';
            echo '<td><strong>' . esc_html($event['title'] ?? '') . '</strong></td>';
            echo '<td>' . esc_html($event['date'] ?? '') . '</td>';
            echo '<td>' . esc_html(count($pages) ? implode(', ', $pages) : 'Missing') . '</td>';
            echo '<td>' . esc_html((string)$possible) . '</td>';
            echo '<td><input type="number" min="1" max="' . esc_attr((string)max(1, $possible)) . '" step="1" name="product_quantity[' . esc_attr($key) . ']" value="' . esc_attr((string)max(1, $possible)) . '" style="width:80px" ' . disabled($valid, false, false) . '></td>';
            echo '<td><strong>' . esc_html(number_format_i18n((float)($event['price_per_ticket'] ?? 0), 2)) . ' €</strong></td>';
            echo '<td>' . (!empty($event['atelier_url']) ? '<a target="_blank" rel="noopener" href="' . esc_url($event['atelier_url']) . '">Open</a>' : 'Missing') . '</td>';
            echo '<td>' . (!empty($event['image_url']) ? '<span style="color:green">Found</span>' : '<span style="color:#996800">Missing</span>') . '</td>';
            echo '<td>' . ($valid ? '<span style="color:green;font-weight:600">Ready</span>' : '<span style="color:#b32d2e;font-weight:600">Blocked</span>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table><p><button class="button button-primary" type="submit">Import selected products and create ticket packages</button></p></form>';
        echo '<script>(function(){var a=document.getElementById("enovos-select-all");if(!a)return;a.addEventListener("change",function(){document.querySelectorAll(".enovos-import-check:not(:disabled)").forEach(function(x){x.checked=a.checked;});});})();</script>';
    }

    private function render_last_import($last_import): void {
        if (!$last_import) return;
        echo '<h2>Last import result</h2>';
        echo '<p><strong>Selected:</strong> ' . esc_html((string)($last_import['selected'] ?? 0)) . ' | <strong>Imported:</strong> ' . esc_html((string)count($last_import['created'] ?? [])) . ' | <strong>Errors:</strong> ' . esc_html((string)count($last_import['errors'] ?? [])) . '</p>';
        if (!empty($last_import['created'])) {
            echo '<table class="widefat striped"><thead><tr><th>Product</th><th>ID</th><th>Price</th><th>Stock</th><th>Ticket packages</th><th>Result</th></tr></thead><tbody>';
            foreach ($last_import['created'] as $item) {
                echo '<tr><td>' . esc_html($item['title'] ?? '') . '</td><td>' . esc_html((string)($item['id'] ?? 0)) . '</td><td>' . esc_html(number_format_i18n((float)($item['price'] ?? 0), 2)) . ' €</td><td>' . esc_html((string)($item['stock'] ?? 0)) . '</td><td>' . esc_html((string)($item['ticket_packages'] ?? 0)) . '</td><td>' . (!empty($item['repaired']) ? 'Updated existing product' : 'Imported') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if (!empty($last_import['errors'])) {
            echo '<h3>Import errors</h3><table class="widefat striped"><thead><tr><th>Concert</th><th>Error</th></tr></thead><tbody>';
            foreach ($last_import['errors'] as $error) echo '<tr><td>' . esc_html($error['event']['title'] ?? '') . '</td><td>' . esc_html($error['error'] ?? '') . '</td></tr>';
            echo '</tbody></table>';
        }
    }

    public function render_inventory(): void {
        if (!current_user_can('manage_woocommerce')) return;
        $rows = TicketInventory::rows(500);
        echo '<div class="wrap"><h1>Ticket Inventory</h1>';
        echo '<p>Every row is one protected PDF containing exactly two physical ticket pages.</p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Concert</th><th>Date</th><th>Package</th><th>PDF pages</th><th>Status</th><th>Product</th><th>Order</th><th>Reserved</th><th>Delivered</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="10"><em>No ticket packages yet.</em></td></tr>';
        foreach ($rows as $row) {
            $product_link = $row['product_id'] ? admin_url('post.php?post=' . (int)$row['product_id'] . '&action=edit') : '';
            $order_link = $row['order_id'] ? admin_url('admin.php?page=wc-orders&action=edit&id=' . (int)$row['order_id']) : '';
            echo '<tr><td>' . esc_html((string)$row['id']) . '</td><td>' . esc_html($row['concert_title']) . '</td><td>' . esc_html($row['concert_date']) . '</td><td>' . esc_html((string)$row['package_no']) . '</td><td>' . esc_html($row['ticket_pages']) . '</td><td><strong>' . esc_html($row['status']) . '</strong></td><td>' . ($product_link ? '<a href="' . esc_url($product_link) . '">#' . esc_html((string)$row['product_id']) . '</a>' : '—') . '</td><td>' . ($order_link ? '<a href="' . esc_url($order_link) . '">#' . esc_html((string)$row['order_id']) . '</a>' : '—') . '</td><td>' . esc_html($row['reserved_at'] ?: '—') . '</td><td>' . esc_html($row['delivered_at'] ?: '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function render_settings(): void {
        if (!current_user_can('manage_woocommerce')) return;
        $s = wp_parse_args(get_option('enovos_ticket_shop_settings', []), self::defaults());
        echo '<div class="wrap"><h1>Enovos Ticket Shop – AI Settings</h1><form method="post" action="options.php">';
        settings_fields('enovos_ticket_shop_settings_group');
        echo '<table class="form-table">';
        echo '<tr><th><label for="ai_provider">AI Provider</label></th><td><select id="ai_provider" name="enovos_ticket_shop_settings[ai_provider]">';
        foreach (['openai'=>'OpenAI','gemini'=>'Gemini','custom'=>'Custom AI'] as $value=>$label) echo '<option value="' . esc_attr($value) . '" ' . selected($s['ai_provider'], $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select><p class="description">Only the selected provider is used for PDF analysis, Atelier enrichment and price verification.</p></td></tr>';
        $this->field('OpenAI API Key', 'openai_api_key', $s['openai_api_key'], 'password');
        $this->field('OpenAI Model', 'openai_model', $s['openai_model']);
        $this->field('Gemini API Key', 'gemini_api_key', $s['gemini_api_key'], 'password');
        $this->field('Gemini Model', 'gemini_model', $s['gemini_model']);
        $this->field('Custom AI Endpoint', 'custom_ai_endpoint', $s['custom_ai_endpoint']);
        $this->field('Custom AI Token / API Key', 'custom_ai_token', $s['custom_ai_token'], 'password');
        $this->field('Custom AI Model', 'custom_ai_model', $s['custom_ai_model']);
        echo '<tr><th>Custom AI Authentication</th><td><select name="enovos_ticket_shop_settings[custom_ai_auth_type]"><option value="bearer" ' . selected($s['custom_ai_auth_type'],'bearer',false) . '>Bearer token</option><option value="api_key_header" ' . selected($s['custom_ai_auth_type'],'api_key_header',false) . '>API key header</option><option value="none" ' . selected($s['custom_ai_auth_type'],'none',false) . '>None</option></select></td></tr>';
        $this->field('Custom AI API Key Header', 'custom_ai_auth_header', $s['custom_ai_auth_header']);
        echo '<tr><th>Ticket delivery status</th><td><select name="enovos_ticket_shop_settings[delivery_order_status]"><option value="processing" ' . selected($s['delivery_order_status'],'processing',false) . '>Processing</option><option value="completed" ' . selected($s['delivery_order_status'],'completed',false) . '>Completed</option></select><p class="description">Ticket PDFs are attached to the matching customer order email. The same file remains assigned to the order for later emails.</p></td></tr>';
        echo '<tr><th>Publish products</th><td><label><input type="checkbox" name="enovos_ticket_shop_settings[publish_products]" value="1" ' . checked(1,$s['publish_products'],false) . '> publish immediately</label><p class="description">Products are drafts by default.</p></td></tr>';
        echo '</table><p class="submit"><button class="button button-primary">Save settings</button></p></form></div>';
    }

    private function field(string $label, string $key, string $value, string $type='text'): void {
        echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" type="' . esc_attr($type) . '" id="' . esc_attr($key) . '" name="enovos_ticket_shop_settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '"></td></tr>';
    }

    public function render_changelog(): void {
        if (!current_user_can('manage_woocommerce')) return;
        $path = ENOVOS_TICKET_SHOP_DIR . 'changelog.txt';
        $contents = is_readable($path) ? (string)file_get_contents($path) : 'The changelog file could not be read.';
        echo '<div class="wrap"><h1>Enovos Ticket Shop – Changelog</h1><p><strong>Current version:</strong> ' . esc_html(ENOVOS_TICKET_SHOP_VERSION) . ' | WordPress 6.4+ | PHP 8.0+</p><textarea readonly style="width:100%;min-height:540px;font-family:monospace">' . esc_textarea($contents) . '</textarea></div>';
    }

    private function render_log(): void {
        echo '<h2>Import / Debug Log</h2><p>This log shows every processing step and failure point.</p>';
        $lines = Logger::lines(250);
        echo $lines ? '<textarea readonly style="width:100%;min-height:300px;font-family:monospace">' . esc_textarea(implode("\n", $lines)) . '</textarea>' : '<p><em>No log entries yet.</em></p>';
        $url = wp_nonce_url(admin_url('admin-post.php?action=enovos_ticket_clear_log'), 'enovos_ticket_clear_log');
        echo '<p><a class="button" href="' . esc_url($url) . '">Clear log</a></p>';
    }

    public function handle_clear_log(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');
        check_admin_referer('enovos_ticket_clear_log');
        Logger::clear();
        wp_safe_redirect(admin_url('admin.php?page=enovos-ticket-shop'));
        exit;
    }

    public function handle_analyze(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');
        check_admin_referer('enovos_ticket_analyze');
        Logger::log('START', 'PDF analysis started');
        if (empty($_FILES['ticket_pdf']['tmp_name'])) wp_die('No PDF uploaded.');
        $file = $_FILES['ticket_pdf'];
        if (($file['type'] ?? '') !== 'application/pdf' && !preg_match('/\.pdf$/i', $file['name'] ?? '')) wp_die('Only PDF files are allowed.');
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload($file, ['test_form'=>false, 'mimes'=>['pdf'=>'application/pdf']]);
        if (isset($upload['error'])) wp_die(esc_html($upload['error']));

        $import_id = gmdate('YmdHis') . '-' . wp_generate_password(10, false, false);
        $dir = PdfPackages::create_import_dir($import_id);
        $source_pdf = trailingslashit($dir) . 'master.pdf';
        if (!@rename($upload['file'], $source_pdf)) {
            if (!@copy($upload['file'], $source_pdf)) wp_die('The uploaded PDF could not be moved into protected storage.');
            @unlink($upload['file']);
        }
        @chmod($source_pdf, 0640);

        $settings = wp_parse_args(get_option('enovos_ticket_shop_settings', []), self::defaults());
        $settings['_source_filename'] = sanitize_file_name($file['name'] ?? 'tickets.pdf');
        $settings['_import_id'] = $import_id;
        Logger::log('STEP', 'PDF saved in protected ticket storage', ['filename'=>$settings['_source_filename'], 'import_id'=>$import_id]);
        try {
            $result = AI::extract_from_pdf($source_pdf, $settings);
        } catch (\Throwable $e) {
            Logger::log('FAIL', 'AI analysis exception', ['error'=>$e->getMessage()]);
            wp_die(esc_html('PDF analysis failed: ' . $e->getMessage()));
        }
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()));

        $enriched = [];
        $price_errors = [];
        foreach (($result['consensus'] ?? []) as $event) {
            $event = AI::enrich_atelier($event, $settings);
            $verified = AI::verify_atelier_price($event, $settings);
            if (is_wp_error($verified)) {
                $price_errors[] = ['title'=>$event['title'] ?? '', 'error'=>$verified->get_error_message()];
                Logger::log('FAIL', 'Price verification failed during analysis', ['title'=>$event['title'] ?? '', 'error'=>$verified->get_error_message()]);
                continue;
            }
            $event = array_merge($event, $verified);
            $event['_import_key'] = $this->event_import_key($event);
            $event['_source_filename'] = $settings['_source_filename'];
            $enriched[] = $event;
        }
        $result['consensus'] = $enriched;
        $result['price_errors'] = $price_errors;
        $result['source_pdf_path'] = $source_pdf;
        $result['source_filename'] = $settings['_source_filename'];
        $result['import_id'] = $import_id;
        if (!$enriched) wp_die('No concert with a verified positive ticket price was found. Check the import log.');
        set_transient('enovos_ticket_shop_last_analysis_' . get_current_user_id(), $result, 6 * HOUR_IN_SECONDS);
        Logger::log('OK', 'Analysis completed and import check prepared', ['events'=>count($enriched), 'provider'=>$settings['ai_provider']]);
        wp_safe_redirect(admin_url('admin.php?page=enovos-ticket-shop'));
        exit;
    }

    public function handle_import(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');
        check_admin_referer('enovos_ticket_import');
        $transient_key = 'enovos_ticket_shop_last_analysis_' . get_current_user_id();
        $analysis = get_transient($transient_key);
        if (!$analysis || empty($analysis['consensus']) || empty($analysis['source_pdf_path']) || !is_readable($analysis['source_pdf_path'])) wp_die('No valid analysis or protected source PDF is available. Please analyze the PDF again.');
        $selected_keys = isset($_POST['selected_events']) && is_array($_POST['selected_events']) ? array_map('sanitize_text_field', wp_unslash($_POST['selected_events'])) : [];
        $posted_quantities = isset($_POST['product_quantity']) && is_array($_POST['product_quantity']) ? wp_unslash($_POST['product_quantity']) : [];
        $selected_events = [];
        foreach ($analysis['consensus'] as $event) {
            $key = $event['_import_key'] ?? $this->event_import_key($event);
            if (!in_array($key, $selected_keys, true)) continue;
            $pages = array_values(array_filter(array_map('intval', (array)($event['page_numbers'] ?? []))));
            $possible = intdiv(count($pages), 2);
            $qty = isset($posted_quantities[$key]) ? max(1, (int)$posted_quantities[$key]) : $possible;
            if ($possible <= 0 || $qty > $possible) wp_die(esc_html('Invalid product quantity for ' . ($event['title'] ?? 'concert') . '. Maximum available ticket packages: ' . $possible));
            $event['product_quantity'] = $qty;
            $selected_events[] = $event;
        }
        if (!$selected_events) wp_die('No valid concerts selected.');

        $settings = wp_parse_args(get_option('enovos_ticket_shop_settings', []), self::defaults());
        $settings['_source_filename'] = $analysis['source_filename'] ?? '';
        $settings['_import_id'] = $analysis['import_id'] ?? '';
        $result = Importer::build_products($selected_events, $settings);

        $event_map = [];
        foreach ($selected_events as $event) $event_map[$event['_import_key']] = $event;
        foreach ($result['created'] as &$created) {
            $event_key = $created['event_key'] ?? '';
            $event = $event_map[$event_key] ?? null;
            if (!$event) continue;
            $packages = TicketInventory::create_packages((int)$created['id'], $event, $analysis['source_pdf_path'], (string)$analysis['import_id']);
            if (is_wp_error($packages)) {
                $product = wc_get_product((int)$created['id']);
                if ($product) {
                    $product->set_stock_quantity(0);
                    $product->set_stock_status('outofstock');
                    $product->set_status('draft');
                    $product->save();
                }
                $result['errors'][] = ['event'=>$event, 'error'=>'Product created but ticket package generation failed: ' . $packages->get_error_message()];
                $created['ticket_packages'] = 0;
                Logger::log('FAIL', 'Ticket package generation failed; product forced to draft/out of stock', ['product_id'=>$created['id'], 'error'=>$packages->get_error_message()]);
            } else {
                $created['ticket_packages'] = (int)$packages;
            }
        }
        unset($created);
        $result['selected'] = count($selected_events);
        set_transient('enovos_ticket_shop_last_import_' . get_current_user_id(), $result, 6 * HOUR_IN_SECONDS);
        Logger::log('OK', 'Product import and ticket package generation completed', ['selected'=>count($selected_events), 'created'=>count($result['created']), 'errors'=>count($result['errors'])]);
        wp_safe_redirect(admin_url('admin.php?page=enovos-ticket-shop'));
        exit;
    }

    private function event_import_key(array $event): string {
        return md5(wp_json_encode([(string)($event['title'] ?? ''), (string)($event['date'] ?? ''), (string)($event['atelier_url'] ?? '')]));
    }

    public function reserve_order($order): void {
        if ($order instanceof \WC_Order) TicketInventory::reserve_for_order($order);
    }

    public function reserve_order_by_id($order_id): void {
        $order = wc_get_order($order_id);
        if ($order) TicketInventory::reserve_for_order($order);
    }

    public function release_order($order_id): void {
        TicketInventory::release_order((int)$order_id);
    }

    public function refund_order($order_id): void {
        TicketInventory::invalidate_refunded_order((int)$order_id);
    }

    public function email_attachments(array $attachments, string $email_id, $object, $email = null): array {
        if (!$object instanceof \WC_Order) return $attachments;
        $settings = wp_parse_args(get_option('enovos_ticket_shop_settings', []), self::defaults());
        $status = $settings['delivery_order_status'];
        $allowed_email = $status === 'completed' ? 'customer_completed_order' : 'customer_processing_order';
        if ($email_id !== $allowed_email) return $attachments;
        $paths = TicketInventory::attachments_for_order($object);
        if (!$paths) return $attachments;
        foreach ($paths as $path) $attachments[] = $path;
        TicketInventory::mark_delivered($object->get_id());
        Logger::log('OK', 'Ticket PDFs attached to WooCommerce customer email', ['order_id'=>$object->get_id(), 'email_id'=>$email_id, 'attachments'=>count($paths)]);
        return array_values(array_unique($attachments));
    }
}
