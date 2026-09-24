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
            'delivery_order_status' => 'completed',
            'admin_page_size' => 200,
            'show_debug_log' => 1,
            'show_last_import' => 1,
            'enable_debug_logging' => 1,
            'enable_attach_me' => 1,
            'enable_native_email_attach' => 1,
            'enable_atelier_enrichment' => 1,
            'enable_order_ticket_box' => 1,
            'enable_ticket_resend' => 1,
            'enable_system_check' => 1,
            'enable_delivery_order_note' => 1,
            'enable_attachment_repair' => 1,
            'auto_select_ready_events' => 1,
            'enable_customer_approval' => 1,
            'approval_domain_whitelist' => '',
            'approval_admin_recipients' => '',
        ];
    }

    public static function settings(): array {
        return wp_parse_args(get_option('enovos_ticket_shop_settings', []), self::defaults());
    }

    public static function enabled(string $key): bool {
        $settings = self::settings();
        return !empty($settings[$key]);
    }

    private function __construct() {
        $this->maybe_upgrade();
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_enovos_ticket_analyze', [$this, 'handle_analyze']);
        add_action('admin_post_enovos_ticket_import', [$this, 'handle_import']);
        add_action('admin_post_enovos_ticket_clear_log', [$this, 'handle_clear_log']);
        add_action('admin_post_enovos_ticket_delete_packages', [$this, 'handle_delete_packages']);
        add_action('admin_post_enovos_ticket_download_package', [$this, 'handle_download_package']);
        add_filter('woocommerce_email_classes', [$this, 'register_customer_approval_emails']);

        add_action('woocommerce_checkout_order_created', [$this, 'reserve_order']);
        add_action('woocommerce_order_status_pending', [$this, 'reserve_order_by_id']);
        add_action('woocommerce_order_status_on-hold', [$this, 'reserve_order_by_id']);
        add_action('woocommerce_order_status_processing', [$this, 'reserve_order_by_id']);
        add_action('woocommerce_order_status_cancelled', [$this, 'release_order']);
        add_action('woocommerce_order_status_failed', [$this, 'release_order']);
        add_action('woocommerce_order_status_refunded', [$this, 'refund_order']);
        // Run late so Attach Me! / other attachment plugins can merge first.
        add_filter('woocommerce_email_attachments', [$this, 'email_attachments'], 999, 4);
        add_action('enovos_ticket_shop_sync_attach_me', ['\Enovos\TicketShop\AttachMe', 'handle_scheduled'], 10, 1);
        CustomerApproval::init();
        CustomerApprovalAdmin::init();
        DigestUnsubscribe::init();
        DigestAdmin::init();
        EmailTemplateEditor::init();
        OrderDelivery::init();
        if (self::enabled('enable_order_ticket_box')) {
            OrderTickets::init();
        }
        if (self::enabled('enable_delivery_order_note') || self::enabled('enable_ticket_resend')) {
            DeliveryTracking::init();
        }
        if (self::enabled('enable_attachment_repair')) {
            AttachMeRepair::init();
        }
    }

    public function enqueue_admin_assets(string $hook): void {
        unset($hook);
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_order_screen = $page === 'wc-orders'
            || ($screen instanceof \WP_Screen && $screen->post_type === 'shop_order');
        if (!$is_order_screen && !in_array($page, [
            'enovos-ticket-shop',
            'enovos-ticket-inventory',
            'enovos-pending-customers',
            'enovos-ticket-shop-settings',
            'enovos-ticket-shop-changelog',
        ], true)) {
            return;
        }
        wp_enqueue_style(
            'enovos-ticket-shop-admin',
            ENOVOS_TICKET_SHOP_URL . 'assets/admin.css',
            [],
            ENOVOS_TICKET_SHOP_VERSION
        );
        if ($page === 'enovos-ticket-shop-settings') {
            wp_enqueue_script(
                'enovos-ticket-shop-settings',
                ENOVOS_TICKET_SHOP_URL . 'assets/admin-settings.js',
                [],
                ENOVOS_TICKET_SHOP_VERSION,
                true
            );
        }
    }

    private function maybe_upgrade(): void {
        $stored = (string)get_option('enovos_ticket_shop_version', '0.0.0');
        if (version_compare($stored, ENOVOS_TICKET_SHOP_VERSION, '>=')) return;
        $settings = wp_parse_args(get_option('enovos_ticket_shop_settings', []), self::defaults());
        if (empty($settings['ai_provider'])) $settings['ai_provider'] = 'openai';
        // v0.3.3+: deliver ticket PDFs only when the order is Completed.
        if (version_compare($stored, '0.3.3', '<')) {
            $settings['delivery_order_status'] = 'completed';
        }
        update_option('enovos_ticket_shop_settings', $settings, false);
        TicketInventory::install();
        update_option('enovos_ticket_shop_version', ENOVOS_TICKET_SHOP_VERSION, false);
        Logger::log('STEP', 'Plugin upgraded', ['version' => ENOVOS_TICKET_SHOP_VERSION]);
    }

    public function admin_menu(): void {
        add_menu_page('Enovos WooCommerce Addons', 'Enovos WooCommerce Addons', 'manage_woocommerce', 'enovos-ticket-shop', [$this, 'render_dashboard'], 'dashicons-admin-plugins', 56);
        add_submenu_page('enovos-ticket-shop', 'Ticket Inventory', 'Ticket Inventory', 'manage_woocommerce', 'enovos-ticket-inventory', [$this, 'render_inventory']);
        add_submenu_page('enovos-ticket-shop', 'Settings', 'Settings', 'manage_woocommerce', 'enovos-ticket-shop-settings', [$this, 'render_settings']);
        add_submenu_page('enovos-ticket-shop', 'Changelog', 'Changelog', 'manage_woocommerce', 'enovos-ticket-shop-changelog', [$this, 'render_changelog']);
        add_submenu_page('woocommerce', 'Pending Customers', 'Pending Customers', 'manage_woocommerce', 'enovos-pending-customers', [CustomerApprovalAdmin::class, 'render']);
    }

    public function register_settings(): void {
        register_setting('enovos_ticket_shop_settings_group', 'enovos_ticket_shop_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => self::defaults(),
        ]);
    }

    public function sanitize_settings(array $input): array {
        $d = self::defaults();
        $settings = self::settings();
        $section = sanitize_key($input['_settings_section'] ?? 'ticket-shop');
        if ($section === 'customer-approval') {
            $settings['enable_customer_approval'] = !empty($input['enable_customer_approval']) ? 1 : 0;
            $settings['approval_domain_whitelist'] = CustomerApproval::sanitize_domains(wp_unslash((string) ($input['approval_domain_whitelist'] ?? '')));
            $settings['approval_admin_recipients'] = CustomerApproval::sanitize_recipient_list(wp_unslash((string) ($input['approval_admin_recipients'] ?? '')));
            return $settings;
        }
        $provider = sanitize_key($input['ai_provider'] ?? $d['ai_provider']);
        if (!in_array($provider, ['openai', 'gemini', 'custom'], true)) {
            $provider = 'openai';
        }
        $auth = sanitize_key($input['custom_ai_auth_type'] ?? 'bearer');
        if (!in_array($auth, ['bearer', 'api_key_header', 'none'], true)) {
            $auth = 'bearer';
        }
        $delivery = sanitize_key($input['delivery_order_status'] ?? 'completed');
        if (!in_array($delivery, ['processing', 'completed'], true)) {
            $delivery = 'completed';
        }
        $bool = static function (array $input, string $key): int {
            return !empty($input[$key]) ? 1 : 0;
        };
        $settings['ai_provider'] = $provider;
        $settings['openai_api_key'] = sanitize_text_field($input['openai_api_key'] ?? '');
        $settings['openai_model'] = sanitize_text_field($input['openai_model'] ?? $d['openai_model']);
        $settings['gemini_api_key'] = sanitize_text_field($input['gemini_api_key'] ?? '');
        $settings['gemini_model'] = sanitize_text_field($input['gemini_model'] ?? $d['gemini_model']);
        $settings['custom_ai_endpoint'] = esc_url_raw($input['custom_ai_endpoint'] ?? '');
        $settings['custom_ai_token'] = sanitize_text_field($input['custom_ai_token'] ?? '');
        $settings['custom_ai_model'] = sanitize_text_field($input['custom_ai_model'] ?? '');
        $settings['custom_ai_auth_type'] = $auth;
        $settings['custom_ai_auth_header'] = sanitize_text_field($input['custom_ai_auth_header'] ?? 'X-API-Key');
        $settings['publish_products'] = $bool($input, 'publish_products');
        $settings['delivery_order_status'] = $delivery;
        $settings['admin_page_size'] = max(10, min(500, (int) ($input['admin_page_size'] ?? $d['admin_page_size'])));
        $settings['show_debug_log'] = $bool($input, 'show_debug_log');
        $settings['show_last_import'] = $bool($input, 'show_last_import');
        $settings['enable_debug_logging'] = $bool($input, 'enable_debug_logging');
        $settings['enable_attach_me'] = $bool($input, 'enable_attach_me');
        $settings['enable_native_email_attach'] = $bool($input, 'enable_native_email_attach');
        $settings['enable_atelier_enrichment'] = $bool($input, 'enable_atelier_enrichment');
        $settings['enable_order_ticket_box'] = $bool($input, 'enable_order_ticket_box');
        $settings['enable_ticket_resend'] = $bool($input, 'enable_ticket_resend');
        $settings['enable_system_check'] = $bool($input, 'enable_system_check');
        $settings['enable_delivery_order_note'] = $bool($input, 'enable_delivery_order_note');
        $settings['enable_attachment_repair'] = $bool($input, 'enable_attachment_repair');
        $settings['auto_select_ready_events'] = $bool($input, 'auto_select_ready_events');
        return $settings;
    }

    public function register_customer_approval_emails(array $emails): array {
        require_once ENOVOS_TICKET_SHOP_DIR . 'includes/emails/class-enovos-email-customer-verify.php';
        require_once ENOVOS_TICKET_SHOP_DIR . 'includes/emails/class-enovos-email-admin-approval.php';
        require_once ENOVOS_TICKET_SHOP_DIR . 'includes/emails/class-enovos-email-customer-approved.php';
        require_once ENOVOS_TICKET_SHOP_DIR . 'includes/emails/class-enovos-email-customer-rejected.php';
        $emails['Enovos_Email_Customer_Verify'] = new EmailCustomerVerify();
        $emails['Enovos_Email_Admin_Approval'] = new EmailAdminApproval();
        $emails['Enovos_Email_Customer_Approved'] = new EmailCustomerApproved();
        $emails['Enovos_Email_Customer_Rejected'] = new EmailCustomerRejected();
        return $emails;
    }

    public function render_dashboard(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $analysis = get_transient('enovos_ticket_shop_last_analysis_' . get_current_user_id());
        $last_import = get_transient('enovos_ticket_shop_last_import_' . get_current_user_id());
        $analysis_notice = get_transient('enovos_ticket_shop_analysis_notice_' . get_current_user_id());
        if ($analysis_notice) {
            delete_transient('enovos_ticket_shop_analysis_notice_' . get_current_user_id());
        }
        $settings = self::settings();
        $engine = PdfPackages::engine_status();
        $attach_me_on = AttachMe::is_active() && !empty($settings['enable_attach_me']);

        echo '<div class="wrap enovos-admin enovos-admin--wide"><h1>Enovos WooCommerce Addons</h1>';
        echo '<p class="enovos-admin-lead">Import concert ticket PDFs, create protected two-ticket packages, and deliver them with WooCommerce orders.</p>';
        if (is_array($analysis_notice)) {
            $notice_class = !empty($analysis_notice['success']) ? 'notice-success' : 'notice-warning';
            echo '<div class="notice ' . esc_attr($notice_class) . ' inline"><p>' . esc_html((string) ($analysis_notice['message'] ?? '')) . '</p></div>';
        }

        echo '<div class="enovos-status-grid">';
        echo '<div class="enovos-status-card"><span class="label">Version</span><span class="value">' . esc_html(ENOVOS_TICKET_SHOP_VERSION) . '</span></div>';
        echo '<div class="enovos-status-card"><span class="label">AI provider</span><span class="value">' . esc_html(ucfirst((string) $settings['ai_provider'])) . '</span></div>';
        echo '<div class="enovos-status-card"><span class="label">PDF engine</span><span class="value ' . (!empty($engine['available']) ? 'is-ok' : 'is-warn') . '">' . esc_html((string) ($engine['engine'] ?? 'unavailable')) . '</span></div>';
        echo '<div class="enovos-status-card"><span class="label">Attach Me!</span><span class="value ' . ($attach_me_on ? 'is-ok' : 'is-warn') . '">' . ($attach_me_on ? 'Active' : (AttachMe::is_active() ? 'Disabled in settings' : 'Not installed')) . '</span></div>';
        echo '<div class="enovos-status-card"><span class="label">Ticket delivery</span><span class="value">' . esc_html(ucfirst((string) $settings['delivery_order_status'])) . '</span></div>';
        echo '</div>';

        echo '<div class="enovos-panel"><p style="margin:0"><strong>Sales logic:</strong> 2 physical ticket pages = 1 protected ticket PDF package = 1 WooCommerce stock unit. Product price = reviewed one-ticket public price rounded upward to a full EUR amount.</p></div>';

        if (!empty($settings['show_debug_log'])) {
            $this->render_log((int) $settings['admin_page_size']);
        }
        if (!empty($settings['show_last_import'])) {
            $this->render_last_import($last_import);
        }
        $this->render_analysis($analysis, $engine, $settings);

        echo '<div class="enovos-panel"><h2>Analyze a ticket PDF</h2>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="enovos_ticket_analyze">';
        wp_nonce_field('enovos_ticket_analyze');
        echo '<p><input type="file" name="ticket_pdf" accept="application/pdf,.pdf" required> ';
        echo '<button class="button button-primary" type="submit">Analyze PDF</button></p></form></div>';
        echo '</div>';
    }

    private function render_analysis($analysis, array $engine, array $settings = []): void {
        if (!$analysis || empty($analysis['consensus'])) {
            return;
        }
        $auto_select = !empty($settings['auto_select_ready_events']);
        echo '<div class="enovos-panel"><h2>Import check</h2>';
        echo '<p>Review every concert before import. You can reduce the product quantity manually, but it cannot exceed the number of complete two-ticket packages detected in the PDF.</p>';
        $pdf_analysis = is_array($analysis['pdf_analysis'] ?? null) ? $analysis['pdf_analysis'] : [];
        $page_count = (int) ($pdf_analysis['page_count'] ?? 0);
        $assigned_count = count((array) ($pdf_analysis['assigned_pages'] ?? []));
        if ($page_count > 0) {
            echo '<p><strong>Page coverage:</strong> ' . esc_html(sprintf(
                '%d of %d ticket pages assigned to %d concerts.',
                $assigned_count,
                $page_count,
                count($analysis['consensus'])
            )) . '</p>';
        }
        if (!empty($pdf_analysis['unassigned_pages'])) {
            echo '<div class="notice notice-warning inline"><p><strong>Unassigned ticket pages:</strong> '
                . esc_html(implode(', ', array_map('intval', $pdf_analysis['unassigned_pages']))) . '</p></div>';
        }
        if (!empty($analysis['price_errors'])) {
            echo '<div class="notice notice-warning inline"><p><strong>Some prices require review:</strong></p><ul>';
            foreach ($analysis['price_errors'] as $price_error) {
                echo '<li>' . esc_html((string) ($price_error['title'] ?? 'Concert')) . ': '
                    . esc_html((string) ($price_error['error'] ?? 'Ticket price could not be verified.')) . '</li>';
            }
            echo '</ul></div>';
        }
        if (empty($engine['available'])) {
            echo '<div class="notice notice-error inline"><p><strong>Ticket PDF generation is blocked:</strong> ' . esc_html($engine['message']) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="enovos_ticket_import">';
        wp_nonce_field('enovos_ticket_import');
        echo '<p><label><input type="checkbox" id="enovos-select-all" ' . checked($auto_select, true, false) . '> <strong>Select / deselect all</strong></label></p>';
        echo '<table class="widefat striped"><thead><tr><th>Select</th><th>Concert</th><th>Date</th><th>Detected pages</th><th>Packages available</th><th>Product quantity</th><th>Price</th><th>Atelier</th><th>Image</th><th>Status</th><th>Reason</th></tr></thead><tbody>';
        foreach ($analysis['consensus'] as $event) {
            $key = $event['_import_key'] ?? $this->event_import_key($event);
            $pages = array_values(array_filter(array_map('intval', (array) ($event['page_numbers'] ?? []))));
            $possible = intdiv(count($pages), 2);
            $price = (float) ($event['price_per_ticket'] ?? 0);
            $structurally_valid = !empty($engine['available']) && !empty($event['title']) && !empty($event['date']) && $possible > 0;
            $blocked_reason = (string) ($event['_blocked_reason'] ?? '');
            if (!$structurally_valid && $blocked_reason === '') {
                $missing = [];
                if (empty($engine['available'])) $missing[] = 'PDF engine unavailable';
                if (empty($event['title'])) $missing[] = 'title missing';
                if (empty($event['date'])) $missing[] = 'date missing';
                if ($possible <= 0) $missing[] = 'fewer than two ticket pages';
                $blocked_reason = implode(', ', $missing);
            }
            if (!$structurally_valid) {
                $status = 'Blocked';
            } elseif (!empty($event['price_verified']) && $price > 0) {
                $status = 'Verified';
            } elseif (!empty($event['price_suggested']) && $price > 0) {
                $status = 'Suggested – review';
            } else {
                $status = 'Manual price required';
            }
            $warnings = [];
            if (empty($event['atelier_url'])) $warnings[] = 'Atelier URL missing';
            if (!empty($event['_analysis_warning'])) $warnings[] = (string) $event['_analysis_warning'];
            if (!empty($event['_price_warning'])) $warnings[] = (string) $event['_price_warning'];
            $image_status = !empty($event['image_url'])
                ? '<span style="color:green">Found</span><br><small>' . esc_html((string) ($event['image_source'] ?? 'verified')) . '</small>'
                : '<span style="color:#996800">Missing</span><br><small>' . esc_html((string) ($event['_image_warning'] ?? 'No verified artist image found.')) . '</small>';
            echo '<tr>';
            echo '<td><input type="checkbox" class="enovos-import-check" name="selected_events[]" value="' . esc_attr($key) . '" ' . checked($structurally_valid && !empty($event['price_verified']) && $price > 0 && $auto_select, true, false) . ' ' . disabled($structurally_valid, false, false) . '></td>';
            echo '<td><strong>' . esc_html($event['title'] ?? '') . '</strong></td>';
            echo '<td>' . esc_html($event['date'] ?? '') . '</td>';
            echo '<td>' . esc_html(count($pages) ? implode(', ', $pages) : 'Missing') . '</td>';
            echo '<td>' . esc_html((string) $possible) . '</td>';
            echo '<td><input type="number" min="1" max="' . esc_attr((string) max(1, $possible)) . '" step="1" name="product_quantity[' . esc_attr($key) . ']" value="' . esc_attr((string) max(1, $possible)) . '" style="width:80px" ' . disabled($structurally_valid, false, false) . '></td>';
            echo '<td><input type="text" inputmode="decimal" class="enovos-event-price" name="event_price[' . esc_attr($key) . ']" value="' . esc_attr($price > 0 ? number_format($price, 2, '.', '') : '') . '" placeholder="0.00" aria-label="' . esc_attr('Ticket price for ' . ($event['title'] ?? 'concert')) . '" ' . disabled($structurally_valid, false, false) . '> €';
            if (!empty($event['price_source']) && filter_var($event['price_source'], FILTER_VALIDATE_URL)) {
                echo '<br><a target="_blank" rel="noopener" href="' . esc_url($event['price_source']) . '">Price source</a>';
            }
            echo '</td>';
            echo '<td><input type="url" name="atelier_url[' . esc_attr($key) . ']" value="'
                . esc_attr((string) ($event['atelier_url'] ?? ''))
                . '" placeholder="https://www.atelier.lu/shows/..." aria-label="'
                . esc_attr('Atelier URL for ' . ($event['title'] ?? 'concert'))
                . '" style="min-width:280px">';
            if (!empty($event['atelier_url'])) {
                echo '<br><a target="_blank" rel="noopener" href="' . esc_url($event['atelier_url']) . '">Open</a>';
            }
            echo '</td>';
            echo '<td>' . $image_status . '</td>';
            echo '<td><span style="font-weight:600">' . esc_html($status) . '</span></td>';
            echo '<td>' . esc_html(!$structurally_valid ? $blocked_reason : ($warnings ? implode(', ', $warnings) : '—')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table><p><button class="button button-primary" type="submit">Import selected products and create ticket packages</button></p></form>';
        echo '<script>(function(){var a=document.getElementById("enovos-select-all");if(!a)return;a.addEventListener("change",function(){document.querySelectorAll(".enovos-import-check:not(:disabled)").forEach(function(x){x.checked=a.checked;});});})();</script>';
        echo '</div>';
    }

    private function render_last_import($last_import): void {
        if (!$last_import) {
            return;
        }
        echo '<div class="enovos-panel"><h2>Last import result</h2>';
        echo '<p><strong>Selected:</strong> ' . esc_html((string) ($last_import['selected'] ?? 0)) . ' | <strong>Imported:</strong> ' . esc_html((string) count($last_import['created'] ?? [])) . ' | <strong>Errors:</strong> ' . esc_html((string) count($last_import['errors'] ?? [])) . '</p>';
        if (!empty($last_import['created'])) {
            echo '<table class="widefat striped"><thead><tr><th>Product</th><th>ID</th><th>Price</th><th>Stock</th><th>Ticket packages</th><th>Result</th></tr></thead><tbody>';
            foreach ($last_import['created'] as $item) {
                echo '<tr><td>' . esc_html($item['title'] ?? '') . '</td><td>' . esc_html((string) ($item['id'] ?? 0)) . '</td><td>' . esc_html(number_format_i18n((float) ($item['price'] ?? 0), 2)) . ' €</td><td>' . esc_html((string) ($item['stock'] ?? 0)) . '</td><td>' . esc_html((string) ($item['ticket_packages'] ?? 0)) . '</td><td>' . (!empty($item['repaired']) ? 'Updated existing product' : 'Imported') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if (!empty($last_import['errors'])) {
            echo '<h3>Import errors</h3><table class="widefat striped"><thead><tr><th>Concert</th><th>Error</th></tr></thead><tbody>';
            foreach ($last_import['errors'] as $error) {
                echo '<tr><td>' . esc_html($error['event']['title'] ?? '') . '</td><td>' . esc_html($error['error'] ?? '') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public function render_inventory(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $rows = TicketInventory::rows((int) self::settings()['admin_page_size']);
        $notice = get_transient('enovos_ticket_shop_inventory_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('enovos_ticket_shop_inventory_notice_' . get_current_user_id());
        }

        echo '<div class="wrap enovos-admin enovos-admin--wide"><h1>Ticket Inventory</h1>';
        echo '<p class="enovos-admin-lead">Every row is one protected PDF containing exactly two physical ticket pages. Use Download to save the PDF with the concert name. Deleting a row removes the inventory record and the PDF file. RESERVED packages linked to an open order cannot be deleted.</p>';
        if (is_array($notice)) {
            $class = !empty($notice['errors']) ? 'notice-warning' : 'notice-success';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html((string) ($notice['message'] ?? '')) . '</p>';
            if (!empty($notice['errors']) && is_array($notice['errors'])) {
                echo '<ul>';
                foreach ($notice['errors'] as $error) {
                    echo '<li>' . esc_html((string) $error) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete the selected ticket package PDF(s)? This cannot be undone.\');">';
        echo '<input type="hidden" name="action" value="enovos_ticket_delete_packages">';
        wp_nonce_field('enovos_ticket_delete_packages');
        echo '<p><button type="submit" class="button button-secondary">Delete selected packages</button></p>';
        echo '<table class="widefat striped"><thead><tr><th style="width:36px"><input type="checkbox" id="enovos-inventory-select-all"></th><th>ID</th><th>Concert</th><th>Date</th><th>Package</th><th>PDF pages</th><th>PDF size</th><th>Status</th><th>Product</th><th>Order</th><th>Reserved</th><th>Delivered</th><th>Download</th><th>Delete</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="14"><em>No ticket packages yet.</em></td></tr>';
        }
        $order_numbers = [];
        foreach ($rows as $row) {
            $package_id = (int) $row['id'];
            $order_id = (int) $row['order_id'];
            $product_link = $row['product_id'] ? admin_url('post.php?post=' . (int) $row['product_id'] . '&action=edit') : '';
            $order_link = $order_id > 0 ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id) : '';
            if ($order_id > 0 && !isset($order_numbers[$order_id])) {
                $order = wc_get_order($order_id);
                $order_numbers[$order_id] = $order instanceof \WC_Order
                    ? $order->get_order_number()
                    : (string) $order_id;
            }
            $order_number = $order_id > 0 ? $order_numbers[$order_id] : '';
            $size_label = '—';
            $can_download = !empty($row['pdf_path']) && is_readable($row['pdf_path']);
            if ($can_download) {
                $bytes = (int) filesize($row['pdf_path']);
                $size_label = $bytes >= 1048576
                    ? number_format_i18n($bytes / 1048576, 1) . ' MB'
                    : number_format_i18n($bytes / 1024, 0) . ' KB';
            }
            $can_delete = !($row['status'] === TicketInventory::STATUS_RESERVED && (int) $row['order_id'] > 0);
            $download_url = wp_nonce_url(
                admin_url('admin-post.php?action=enovos_ticket_download_package&package_id=' . $package_id),
                'enovos_ticket_download_package_' . $package_id
            );
            $single_url = wp_nonce_url(
                admin_url('admin-post.php?action=enovos_ticket_delete_packages&package_ids[]=' . $package_id),
                'enovos_ticket_delete_packages'
            );
            echo '<tr>';
            echo '<td>';
            if ($can_delete) {
                echo '<input type="checkbox" class="enovos-inventory-check" name="package_ids[]" value="' . esc_attr((string) $package_id) . '">';
            }
            echo '</td>';
            echo '<td>' . esc_html((string) $package_id) . '</td>';
            echo '<td>' . esc_html($row['concert_title']) . '</td>';
            echo '<td>' . esc_html($row['concert_date']) . '</td>';
            echo '<td>' . esc_html((string) $row['package_no']) . '</td>';
            echo '<td>' . esc_html($row['ticket_pages']) . '</td>';
            echo '<td>' . esc_html($size_label) . '</td>';
            echo '<td><strong>' . esc_html($row['status']) . '</strong></td>';
            echo '<td>' . ($product_link ? '<a href="' . esc_url($product_link) . '">#' . esc_html((string) $row['product_id']) . '</a>' : '—') . '</td>';
            echo '<td>' . ($order_link ? '<a href="' . esc_url($order_link) . '">' . esc_html($order_number) . '</a>' : '—') . '</td>';
            echo '<td>' . esc_html($row['reserved_at'] ?: '—') . '</td>';
            echo '<td>' . esc_html($row['delivered_at'] ?: '—') . '</td>';
            echo '<td>';
            if ($can_download) {
                echo '<a class="button button-small button-primary" href="' . esc_url($download_url) . '">Download</a>';
            } else {
                echo '<span class="description">Missing</span>';
            }
            echo '</td>';
            echo '<td>';
            if ($can_delete) {
                echo '<a class="button button-small" href="' . esc_url($single_url) . '" onclick="return confirm(\'Delete package #' . esc_js((string) $package_id) . ' and its PDF?\');">Delete</a>';
            } else {
                echo '<span class="description">Reserved</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-secondary">Delete selected packages</button></p>';
        echo '</form>';
        echo '<script>(function(){var a=document.getElementById("enovos-inventory-select-all");if(!a)return;a.addEventListener("change",function(){document.querySelectorAll(".enovos-inventory-check").forEach(function(x){x.checked=a.checked;});});})();</script>';
        echo '</div>';
    }

    public function handle_delete_packages(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('enovos_ticket_delete_packages');
        $ids = isset($_REQUEST['package_ids']) && is_array($_REQUEST['package_ids'])
            ? array_map('intval', wp_unslash($_REQUEST['package_ids']))
            : [];
        $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
        if (!$ids) {
            set_transient('enovos_ticket_shop_inventory_notice_' . get_current_user_id(), [
                'message' => 'No ticket packages selected.',
                'errors' => [],
            ], 60);
            wp_safe_redirect(admin_url('admin.php?page=enovos-ticket-inventory'));
            exit;
        }
        $result = TicketInventory::delete_packages($ids);
        set_transient('enovos_ticket_shop_inventory_notice_' . get_current_user_id(), [
            'message' => sprintf('Deleted %d ticket package(s).', (int) $result['deleted']),
            'errors' => $result['errors'],
        ], 60);
        wp_safe_redirect(admin_url('admin.php?page=enovos-ticket-inventory'));
        exit;
    }

    public function handle_download_package(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        $package_id = isset($_GET['package_id']) ? (int) $_GET['package_id'] : 0;
        check_admin_referer('enovos_ticket_download_package_' . $package_id);
        $row = TicketInventory::get_package($package_id);
        if (!$row) {
            wp_die('Ticket package not found.');
        }
        $path = (string) ($row['pdf_path'] ?? '');
        if ($path === '' || !is_readable($path)) {
            wp_die('Ticket PDF file is missing or unreadable.');
        }
        $download_name = TicketInventory::package_filename(
            (string) ($row['concert_title'] ?? ''),
            (int) ($row['package_no'] ?? 1),
            (int) ($row['product_id'] ?? 0)
        );
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $download_name . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function render_settings(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $s = self::settings();
        $section = sanitize_key((string) ($_GET['section'] ?? 'ticket-shop'));
        if (!in_array($section, ['ticket-shop', 'customer-approval', 'email-templates'], true)) {
            $section = 'ticket-shop';
        }
        $base_url = admin_url('admin.php?page=enovos-ticket-shop-settings');
        echo '<div class="wrap enovos-admin"><h1>Settings</h1>';
        settings_errors();
        $section_descriptions = [
            'ticket-shop' => 'Configure ticket imports, AI providers, product delivery, and the Enovos WooCommerce Addons dashboard.',
            'customer-approval' => 'Configure email verification, automatic domain approval, and administrator notifications.',
            'email-templates' => 'Paste Beefree HTML and manage placeholders for every registered WooCommerce email.',
        ];
        echo '<p class="enovos-admin-lead">' . esc_html($section_descriptions[$section]) . '</p>';
        echo '<nav class="enovos-settings-tabs" aria-label="Settings sections">';
        foreach (['ticket-shop' => 'Ticket Shop', 'customer-approval' => 'Customer Approval', 'email-templates' => 'Email Templates'] as $tab => $label) {
            $active = $section === $tab;
            echo '<a class="enovos-settings-tab' . ($active ? ' is-active' : '') . '" href="' . esc_url(add_query_arg('section', $tab, $base_url)) . '"' . ($active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if ($section === 'email-templates') {
            EmailTemplateEditor::render_notice();
            echo '<div class="enovos-settings-grid">';
            EmailTemplateEditor::render_settings();
            echo '</div><div class="enovos-actions-bar"><a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=enovos-ticket-shop')) . '">Back to Enovos WooCommerce Addons</a></div></div>';
            return;
        }
        echo '<form method="post" action="options.php">';
        settings_fields('enovos_ticket_shop_settings_group');
        echo '<input type="hidden" name="enovos_ticket_shop_settings[_settings_section]" value="' . esc_attr($section) . '">';
        echo '<div class="enovos-settings-grid">';

        if ($section === 'ticket-shop') {
            echo '<div class="enovos-card"><div class="enovos-card__header"><h2>AI provider</h2><p>Only the selected provider is used for PDF analysis, Atelier enrichment and price verification.</p></div><div class="enovos-card__body">';
            echo '<table class="form-table" role="presentation"><tr><th><label for="enovos_ai_provider">Provider</label></th><td><select id="enovos_ai_provider" name="enovos_ticket_shop_settings[ai_provider]">';
            foreach (['openai' => 'OpenAI', 'gemini' => 'Gemini', 'custom' => 'Custom AI'] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '" ' . selected($s['ai_provider'], $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></td></tr></table>';

            echo '<div class="enovos-provider-fields" data-enovos-provider="openai"><table class="form-table" role="presentation">';
            $this->field('OpenAI API Key', 'openai_api_key', $s['openai_api_key'], 'password');
            $this->field('OpenAI Model', 'openai_model', $s['openai_model']);
            echo '</table></div>';

            echo '<div class="enovos-provider-fields" data-enovos-provider="gemini" hidden><table class="form-table" role="presentation">';
            $this->field('Gemini API Key', 'gemini_api_key', $s['gemini_api_key'], 'password');
            $this->field('Gemini Model', 'gemini_model', $s['gemini_model']);
            echo '</table></div>';

            echo '<div class="enovos-provider-fields" data-enovos-provider="custom" hidden><table class="form-table" role="presentation">';
            $this->field('Custom AI Endpoint', 'custom_ai_endpoint', $s['custom_ai_endpoint']);
            $this->field('Custom AI Token / API Key', 'custom_ai_token', $s['custom_ai_token'], 'password');
            $this->field('Custom AI Model', 'custom_ai_model', $s['custom_ai_model']);
            echo '<tr><th>Authentication</th><td><select name="enovos_ticket_shop_settings[custom_ai_auth_type]"><option value="bearer" ' . selected($s['custom_ai_auth_type'], 'bearer', false) . '>Bearer token</option><option value="api_key_header" ' . selected($s['custom_ai_auth_type'], 'api_key_header', false) . '>API key header</option><option value="none" ' . selected($s['custom_ai_auth_type'], 'none', false) . '>None</option></select></td></tr>';
            $this->field('API Key Header name', 'custom_ai_auth_header', $s['custom_ai_auth_header']);
            echo '</table></div></div></div>';

            echo '<div class="enovos-card"><div class="enovos-card__header"><h2>Products &amp; delivery</h2><p>How imported products are published and when ticket PDFs leave with the customer email.</p></div><div class="enovos-card__body">';
            echo '<table class="form-table" role="presentation">';
            echo '<tr><th>Ticket delivery status</th><td><select name="enovos_ticket_shop_settings[delivery_order_status]"><option value="completed" ' . selected($s['delivery_order_status'], 'completed', false) . '>Completed</option><option value="processing" ' . selected($s['delivery_order_status'], 'processing', false) . '>Processing</option></select><p class="description">Ticket PDFs are sent only with this customer order email.</p></td></tr>';
            echo '<tr><th>Admin list size</th><td><input class="small-text" type="number" min="10" max="500" name="enovos_ticket_shop_settings[admin_page_size]" value="' . esc_attr((string) $s['admin_page_size']) . '"> <span class="description">Rows shown in Ticket Inventory and lines in the debug log view.</span></td></tr>';
            echo '</table><ul class="enovos-toggle-list">';
            $this->toggle('publish_products', (int) $s['publish_products'], 'Publish products immediately', 'When off, imported concerts stay as drafts until you publish them.');
            $this->toggle('enable_attach_me', (int) $s['enable_attach_me'], 'Use Attach Me! when available', 'Register reserved ticket PDFs on the WooCommerce order Attachments box.');
            $this->toggle('enable_native_email_attach', (int) $s['enable_native_email_attach'], 'Native email PDF attachment', 'Attach ticket PDFs directly to the WooCommerce customer email when Attach Me! is not used.');
            $this->toggle('enable_atelier_enrichment', (int) $s['enable_atelier_enrichment'], 'Atelier enrichment', 'Fetch artist/group image and extra Atelier page data during PDF analysis.');
            echo '</ul></div></div>';

            echo '<div class="enovos-card"><div class="enovos-card__header"><h2>Order support &amp; diagnostics</h2><p>Each addition can be disabled independently without removing ticket or order data.</p></div><div class="enovos-card__body"><ul class="enovos-toggle-list">';
            $this->toggle('enable_order_ticket_box', (int) $s['enable_order_ticket_box'], 'Ticket status box on orders', 'Show Enovos package status beside the Attach Me! box.');
            $this->toggle('enable_ticket_resend', (int) $s['enable_ticket_resend'], 'Resend ticket email', 'Allow administrators to trigger the existing Attach Me! customer email again.');
            $this->toggle('enable_system_check', (int) $s['enable_system_check'], 'System check', 'Show the PDF, tax, category, AI, uploads and Attach Me! preflight checks.');
            $this->toggle('enable_delivery_order_note', (int) $s['enable_delivery_order_note'], 'Ticket delivery order note', 'Record successful original and resent ticket emails in the order notes.');
            $this->toggle('enable_attachment_repair', (int) $s['enable_attachment_repair'], 'Existing attachment repair', 'Audit and repair customer visibility for Enovos ticket attachments created before version 0.7.0.');
            echo '</ul></div></div>';

            if (!empty($s['enable_system_check'])) {
                SystemCheck::render();
            }

            echo '<div class="enovos-card"><div class="enovos-card__header"><h2>Enovos WooCommerce Addons dashboard</h2><p>Show or hide sections on the main Enovos WooCommerce Addons page.</p></div><div class="enovos-card__body"><ul class="enovos-toggle-list">';
            $this->toggle('show_debug_log', (int) $s['show_debug_log'], 'Import / Debug Log', 'Show the processing log panel on the Enovos WooCommerce Addons dashboard.');
            $this->toggle('show_last_import', (int) $s['show_last_import'], 'Last import result', 'Show the last import summary table on the dashboard.');
            $this->toggle('enable_debug_logging', (int) $s['enable_debug_logging'], 'Write debug log entries', 'When off, no new log lines are written (existing log stays until cleared).');
            $this->toggle('auto_select_ready_events', (int) $s['auto_select_ready_events'], 'Auto-select ready concerts', 'Pre-check concerts that are ready for import in the import check table.');
            echo '</ul></div></div>';
        } else {
            echo '<div class="enovos-card"><div class="enovos-card__header"><h2>Customer approval</h2><p>Require new WooCommerce customers to verify their email address and manually review domains outside the whitelist.</p></div><div class="enovos-card__body">';
            echo '<ul class="enovos-toggle-list">';
            $this->toggle('enable_customer_approval', (int) $s['enable_customer_approval'], 'Enable customer approval', 'Existing users are unaffected. New customers cannot sign in or check out until approved.');
            echo '</ul><table class="form-table" role="presentation">';
            echo '<tr><th><label for="approval_domain_whitelist">Domain whitelist</label></th><td><textarea class="large-text code" rows="6" id="approval_domain_whitelist" name="enovos_ticket_shop_settings[approval_domain_whitelist]" placeholder="company.com&#10;partner.lu">' . esc_textarea((string) $s['approval_domain_whitelist']) . '</textarea><p class="description">Enter one exact domain per line, without @. Subdomains must be listed separately. Customers from these domains are approved automatically after email verification.</p></td></tr>';
            echo '<tr><th><label for="approval_admin_recipients">Approval recipients</label></th><td><input class="large-text" type="text" id="approval_admin_recipients" name="enovos_ticket_shop_settings[approval_admin_recipients]" value="' . esc_attr((string) $s['approval_admin_recipients']) . '" placeholder="shop@example.com, manager@example.com"><p class="description">Comma-separated email addresses notified when manual approval is required. The WordPress administration email is used when empty.</p></td></tr>';
            echo '</table><p><a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=email')) . '">Edit approval emails in WooCommerce</a></p>';
            echo '</div></div>';
        }

        echo '</div><div class="enovos-actions-bar"><button class="button button-primary button-large">Save settings</button>';
        echo '<a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=enovos-ticket-shop')) . '">Back to Enovos WooCommerce Addons</a></div>';
        echo '</form>';
        if ($section === 'ticket-shop' && !empty($s['enable_attachment_repair'])) {
            AttachMeRepair::render();
        }
        echo '</div>';
    }

    private function field(string $label, string $key, string $value, string $type = 'text'): void {
        echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" type="' . esc_attr($type) . '" id="' . esc_attr($key) . '" name="enovos_ticket_shop_settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" autocomplete="off"></td></tr>';
    }

    private function toggle(string $key, int $value, string $label, string $description): void {
        $id = 'enovos_toggle_' . $key;
        echo '<li class="enovos-toggle-item">';
        echo '<label class="enovos-toggle" for="' . esc_attr($id) . '"><input type="checkbox" id="' . esc_attr($id) . '" name="enovos_ticket_shop_settings[' . esc_attr($key) . ']" value="1" ' . checked(1, $value, false) . '><span></span></label>';
        echo '<div class="enovos-toggle-copy"><strong>' . esc_html($label) . '</strong><span>' . esc_html($description) . '</span></div>';
        echo '</li>';
    }

    public function render_changelog(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $path = ENOVOS_TICKET_SHOP_DIR . 'CHANGELOG.md';
        $contents = is_readable($path) ? (string) file_get_contents($path) : '';
        $entries = $this->parse_changelog($contents);

        echo '<div class="wrap enovos-admin"><h1>Changelog</h1>';
        echo '<p class="enovos-admin-lead">Current version <strong>' . esc_html(ENOVOS_TICKET_SHOP_VERSION) . '</strong> · WordPress 6.4+ · PHP 8.0+</p>';

        if (!$entries) {
            echo '<div class="enovos-panel"><p>The changelog file could not be read.</p>';
            if ($contents !== '') {
                echo '<textarea class="enovos-changelog-raw" readonly>' . esc_textarea($contents) . '</textarea>';
            }
            echo '</div></div>';
            return;
        }

        echo '<div class="enovos-changelog-list">';
        foreach ($entries as $index => $entry) {
            $is_current = $index === 0;
            echo '<article class="enovos-changelog-card' . ($is_current ? ' is-current' : '') . '">';
            echo '<div class="enovos-changelog-card__head"><h2>Version ' . esc_html($entry['version']) . '</h2>';
            if ($entry['date'] !== '') {
                echo '<span class="enovos-changelog-card__date">' . esc_html($entry['date']) . '</span>';
            }
            if ($is_current) {
                echo '<span class="enovos-badge">Current</span>';
            }
            echo '</div>';
            if (!empty($entry['items'])) {
                echo '<ul>';
                foreach ($entry['items'] as $item) {
                    echo '<li>' . esc_html($item) . '</li>';
                }
                echo '</ul>';
            }
            echo '</article>';
        }
        echo '</div></div>';
    }

    /**
     * @return list<array{version:string,date:string,items:list<string>}>
     */
    private function parse_changelog(string $contents): array {
        $entries = [];
        $current = null;
        foreach (preg_split("/\R/", $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^#\s+/', $line)) {
                continue;
            }
            if (preg_match('/^##\s+Version\s+([0-9.]+)(?:\s*[–—-]\s*(.+))?$/u', $line, $m)) {
                if ($current) {
                    $entries[] = $current;
                }
                $current = [
                    'version' => $m[1],
                    'date' => isset($m[2]) ? trim($m[2]) : '',
                    'items' => [],
                ];
                continue;
            }
            if ($current && preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
                $current['items'][] = $m[1];
            }
        }
        if ($current) {
            $entries[] = $current;
        }
        return $entries;
    }

    private function render_log(int $limit = 250): void {
        echo '<div class="enovos-panel"><h2>Import / Debug Log</h2><p>This log shows every processing step and failure point.</p>';
        $lines = Logger::lines(max(10, min(500, $limit)));
        echo $lines
            ? '<textarea class="enovos-log-box" readonly>' . esc_textarea(implode("\n", $lines)) . '</textarea>'
            : '<p><em>No log entries yet.</em></p>';
        $url = wp_nonce_url(admin_url('admin-post.php?action=enovos_ticket_clear_log'), 'enovos_ticket_clear_log');
        echo '<p><a class="button" href="' . esc_url($url) . '">Clear log</a></p></div>';
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
        if (empty($_FILES['ticket_pdf']['tmp_name'])) {
            Logger::log('FAIL', 'PDF analysis stopped because no file was uploaded');
            $this->redirect_analysis_notice('No PDF was uploaded.');
        }
        $file = $_FILES['ticket_pdf'];
        if (($file['type'] ?? '') !== 'application/pdf' && !preg_match('/\.pdf$/i', $file['name'] ?? '')) {
            Logger::log('FAIL', 'PDF analysis rejected a non-PDF upload', ['filename' => sanitize_file_name($file['name'] ?? '')]);
            $this->redirect_analysis_notice('Only PDF files are allowed.');
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload($file, ['test_form'=>false, 'mimes'=>['pdf'=>'application/pdf']]);
        if (isset($upload['error'])) {
            Logger::log('FAIL', 'WordPress could not accept the PDF upload', ['error' => sanitize_text_field($upload['error'])]);
            $this->redirect_analysis_notice('The PDF upload failed: ' . sanitize_text_field($upload['error']));
        }

        $import_id = gmdate('YmdHis') . '-' . wp_generate_password(10, false, false);
        $dir = PdfPackages::create_import_dir($import_id);
        $source_pdf = trailingslashit($dir) . 'master.pdf';
        if (!@rename($upload['file'], $source_pdf)) {
            if (!@copy($upload['file'], $source_pdf)) {
                Logger::log('FAIL', 'Uploaded PDF could not be moved into protected storage', ['import_id' => $import_id]);
                $this->redirect_analysis_notice('The uploaded PDF could not be moved into protected storage.');
            }
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
            $result = new \WP_Error('analysis_exception', 'PDF analysis failed: ' . $e->getMessage());
        }
        if (is_wp_error($result)) {
            $message = $result->get_error_message();
            $result = $this->blocked_analysis_from_pdf($source_pdf, $settings, $message);
            set_transient('enovos_ticket_shop_last_analysis_' . get_current_user_id(), $result, 6 * HOUR_IN_SECONDS);
            Logger::log('FAIL', 'PDF analysis completed with deterministic groups requiring manual review', [
                'filename' => $settings['_source_filename'],
                'pages' => (int) ($result['pdf_analysis']['page_count'] ?? 0),
                'groups' => count($result['consensus'] ?? []),
                'error' => $message,
            ]);
            $this->redirect_analysis_notice('AI analysis failed. Detected ticket groups remain available for manual pricing: ' . $message);
        }

        $enriched = [];
        $price_errors = [];
        $used_image_urls = [];
        foreach (($result['consensus'] ?? []) as $event) {
            if (!empty($settings['enable_atelier_enrichment'])) {
                $event = AI::enrich_atelier($event, $settings, array_keys($used_image_urls));
            }
            if (!empty($event['image_url'])) {
                $image_key = AI::image_key((string) $event['image_url']);
                if (isset($used_image_urls[$image_key])) {
                    Logger::log('FAIL', 'Duplicate concert image rejected during analysis', [
                        'title' => $event['title'] ?? '',
                        'url' => $event['image_url'],
                        'already_used_by' => $used_image_urls[$image_key],
                    ]);
                    $event['image_url'] = '';
                    $event['image_source'] = 'rejected-duplicate';
                    $event['_image_warning'] = 'The same image was already assigned to another concert in this import.';
                } else {
                    $used_image_urls[$image_key] = (string) ($event['title'] ?? '');
                }
            }
            $verified = AI::verify_atelier_price($event, $settings);
            if (is_wp_error($verified)) {
                Logger::log('FAIL', 'Price verification failed during analysis', ['title'=>$event['title'] ?? '', 'error'=>$verified->get_error_message()]);
                Logger::log('START', 'Broader web price search started', ['title' => $event['title'] ?? '']);
                $suggested = AI::suggest_event_price($event, $settings);
                if (is_wp_error($suggested)) {
                    $warning = $verified->get_error_message() . ' ' . $suggested->get_error_message();
                    $price_errors[] = ['title' => $event['title'] ?? '', 'error' => $warning];
                    $event['_price_warning'] = $warning;
                    Logger::log('FAIL', 'No broader web price suggestion found', [
                        'title' => $event['title'] ?? '',
                        'error' => $suggested->get_error_message(),
                    ]);
                } else {
                    $event = array_merge($event, $suggested);
                    $event['_price_warning'] = 'Price found outside the official Atelier verification flow; review before import.';
                    Logger::log('OK', 'Broader web price suggestion found', [
                        'title' => $event['title'] ?? '',
                        'price' => $event['price_per_ticket'] ?? 0,
                        'source' => $event['price_source'] ?? '',
                    ]);
                }
            } else {
                $event = array_merge($event, $verified);
            }
            $event['_import_key'] = $this->event_import_key($event);
            $event['_source_filename'] = $settings['_source_filename'];
            $enriched[] = $event;
        }
        $result['consensus'] = $enriched;
        $result['price_errors'] = $price_errors;
        $result['source_pdf_path'] = $source_pdf;
        $result['source_filename'] = $settings['_source_filename'];
        $result['import_id'] = $import_id;
        set_transient('enovos_ticket_shop_last_analysis_' . get_current_user_id(), $result, 6 * HOUR_IN_SECONDS);
        $ready = count(array_filter($enriched, static fn($event) => (float) ($event['price_per_ticket'] ?? 0) > 0));
        $provider_events = 0;
        foreach (($result['providers'] ?? []) as $provider_result) {
            $provider_events += count($provider_result['events'] ?? []);
        }
        Logger::log($ready > 0 ? 'OK' : 'FAIL', 'PDF analysis finished', [
            'filename' => $settings['_source_filename'],
            'pages' => (int) ($result['pdf_analysis']['page_count'] ?? 0),
            'groups' => count($result['pdf_analysis']['groups'] ?? []),
            'ai_events' => $provider_events,
            'reconciled_events' => count($enriched),
            'events_with_price' => $ready,
            'events_requiring_manual_price' => count($enriched) - $ready,
            'unassigned_pages' => $result['pdf_analysis']['unassigned_pages'] ?? [],
        ]);
        $message = $ready > 0
            ? sprintf('Analysis completed: %d concert(s) have a price and %d require manual pricing.', $ready, count($enriched) - $ready)
            : 'Analysis completed. Enter a positive ticket price for the concerts you want to import.';
        $this->redirect_analysis_notice($message, $ready > 0);
    }

    private function blocked_analysis_from_pdf(string $source_pdf, array $settings, string $reason): array {
        $pdf_analysis = PdfText::analyze($source_pdf);
        unset($pdf_analysis['pages']);
        $events = [];
        foreach (($pdf_analysis['groups'] ?? []) as $group) {
            $event = [
                'title' => (string) ($group['title_guess'] ?? ''),
                'date' => (string) ($group['date'] ?? ''),
                'venue' => (string) ($group['venue'] ?? ''),
                'ticket_count' => count($group['page_numbers'] ?? []),
                'page_numbers' => array_values(array_map('intval', (array) ($group['page_numbers'] ?? []))),
                'atelier_url' => '',
                'price_per_ticket' => 0,
                'currency' => 'EUR',
                'description' => '',
                'image_url' => '',
                '_needs_review' => 1,
                '_price_warning' => $reason,
            ];
            $event['_import_key'] = $this->event_import_key($event);
            $events[] = $event;
        }
        return [
            'providers' => [],
            'errors' => [['provider' => $settings['ai_provider'] ?? '', 'error' => $reason]],
            'consensus' => $events,
            'selected_provider' => $settings['ai_provider'] ?? '',
            'pdf_analysis' => $pdf_analysis,
            'price_errors' => [],
            'source_pdf_path' => $source_pdf,
            'source_filename' => $settings['_source_filename'] ?? '',
            'import_id' => $settings['_import_id'] ?? '',
        ];
    }

    private function redirect_analysis_notice(string $message, bool $success = false): void {
        set_transient('enovos_ticket_shop_analysis_notice_' . get_current_user_id(), [
            'message' => $message,
            'success' => $success,
        ], 5 * MINUTE_IN_SECONDS);
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
        $posted_prices = isset($_POST['event_price']) && is_array($_POST['event_price']) ? wp_unslash($_POST['event_price']) : [];
        $posted_atelier_urls = isset($_POST['atelier_url']) && is_array($_POST['atelier_url']) ? wp_unslash($_POST['atelier_url']) : [];
        $settings = wp_parse_args(get_option('enovos_ticket_shop_settings', []), self::defaults());
        $selected_events = [];
        $used_image_urls = [];
        foreach ($analysis['consensus'] as $event) {
            $key = $event['_import_key'] ?? $this->event_import_key($event);
            if (!in_array($key, $selected_keys, true)) continue;
            if (
                !empty($event['_blocked_reason'])
                || empty($event['title'])
                || empty($event['date'])
            ) {
                wp_die(esc_html('Blocked concert cannot be imported: ' . ($event['title'] ?? 'Unknown concert') . '.'));
            }
            $pages = array_values(array_filter(array_map('intval', (array)($event['page_numbers'] ?? []))));
            $possible = intdiv(count($pages), 2);
            $qty = isset($posted_quantities[$key]) ? max(1, (int)$posted_quantities[$key]) : $possible;
            if ($possible <= 0 || $qty > $possible) wp_die(esc_html('Invalid product quantity for ' . ($event['title'] ?? 'concert') . '. Maximum available ticket packages: ' . $possible));
            $reviewed_event = self::apply_reviewed_price($event, $posted_prices[$key] ?? '');
            if (is_wp_error($reviewed_event)) {
                wp_die(esc_html('Invalid ticket price for ' . ($event['title'] ?? 'concert') . ': ' . $reviewed_event->get_error_message()));
            }
            $event = $reviewed_event;
            $reviewed_event = self::apply_reviewed_atelier_url(
                $event,
                $posted_atelier_urls[$key] ?? '',
                array_keys($used_image_urls)
            );
            if (is_wp_error($reviewed_event)) {
                wp_die(esc_html(
                    'Invalid Atelier URL for ' . ($event['title'] ?? 'concert') . ': '
                    . $reviewed_event->get_error_message()
                ));
            }
            $event = $reviewed_event;
            if (!empty($event['image_url'])) {
                $used_image_urls[AI::image_key((string) $event['image_url'])] = true;
            }
            $event['product_quantity'] = $qty;
            $selected_events[] = $event;
        }
        if (!$selected_events) wp_die('No valid concerts selected.');

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

    private static function parse_event_price($raw): float|\WP_Error {
        if (!is_scalar($raw)) {
            return new \WP_Error('invalid_manual_price', 'Enter a positive amount using at most two decimal places.');
        }
        $value = trim((string) $raw);
        $value = str_replace(["\xc2\xa0", ' '], '', $value);
        if (!preg_match('/^\d{1,4}(?:[.,]\d{1,2})?$/', $value)) {
            return new \WP_Error('invalid_manual_price', 'Enter a positive amount using at most two decimal places.');
        }
        $price = (float) str_replace(',', '.', $value);
        if (!is_finite($price) || $price <= 0 || $price > 5000) {
            return new \WP_Error('invalid_manual_price', 'The ticket price must be greater than 0 and no more than 5000 EUR.');
        }
        return $price;
    }

    private static function apply_reviewed_price(array $event, $raw): array|\WP_Error {
        $price = self::parse_event_price($raw);
        if (is_wp_error($price)) {
            return $price;
        }
        $original_price = (float) ($event['price_per_ticket'] ?? 0);
        $price_changed = abs($original_price - $price) > 0.00001;
        $event['verified_price_before_rounding'] = $price;
        $event['price_per_ticket'] = AI::round_price_up($price);
        $event['price_approved'] = 1;
        if ($price_changed || (empty($event['price_verified']) && empty($event['price_suggested']))) {
            $event['price_verified'] = 0;
            $event['price_suggested'] = 0;
            $event['price_source'] = 'manual';
            $event['price_verification_method'] = 'manual';
        } elseif (!empty($event['price_suggested'])) {
            $event['price_verified'] = 0;
            $event['price_source'] = (string) ($event['price_source'] ?? '');
            $event['price_verification_method'] = 'suggested_approved';
        }
        unset($event['_price_warning']);
        return $event;
    }

    /**
     * @param mixed $raw
     * @param array<int,string> $excluded_image_urls
     */
    private static function apply_reviewed_atelier_url(
        array $event,
        $raw,
        array $excluded_image_urls = []
    ): array|\WP_Error {
        if (!is_scalar($raw)) {
            return new \WP_Error('invalid_manual_atelier_url', 'Enter a valid Atelier concert URL.');
        }
        $url = trim((string) $raw);
        if ($url === '') {
            $event['atelier_url'] = '';
            return $event;
        }
        return AI::enrich_manual_atelier_url($event, $url, $excluded_image_urls);
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
        if (!$object instanceof \WC_Order) {
            return $attachments;
        }

        // Ensure packages exist before deciding how to deliver them.
        TicketInventory::reserve_for_order($object);
        if (self::enabled('enable_attach_me')) {
            AttachMe::sync_order($object);
        }

        $settings = self::settings();
        $status = $settings['delivery_order_status'] ?? 'completed';
        $allowed_email = $status === 'processing' ? 'customer_processing_order' : 'customer_completed_order';
        if ($email_id !== $allowed_email) {
            return $attachments;
        }

        // When Attach Me! already owns email embedding for this order, avoid
        // duplicate PDF attachments in the same message.
        if (
            self::enabled('enable_attach_me')
            && AttachMe::is_active()
            && $object->get_meta('_enovos_wcam_synced_package_ids')
        ) {
            Logger::log('STEP', 'Ticket PDFs left to Attach Me! email embedding', [
                'order_id' => $object->get_id(),
                'email_id' => $email_id,
            ]);
            TicketInventory::mark_delivered($object->get_id());
            return $attachments;
        }

        if (!self::enabled('enable_native_email_attach')) {
            Logger::log('STEP', 'Native email PDF attachment disabled in settings', [
                'order_id' => $object->get_id(),
                'email_id' => $email_id,
            ]);
            return $attachments;
        }

        $paths = TicketInventory::attachments_for_order($object);
        if (!$paths) {
            Logger::log('FAIL', 'No ticket PDF paths available for customer email', [
                'order_id' => $object->get_id(),
                'email_id' => $email_id,
            ]);
            return $attachments;
        }
        foreach ($paths as $path) {
            $attachments[] = $path;
        }
        TicketInventory::mark_delivered($object->get_id());
        Logger::log('OK', 'Ticket PDFs attached to WooCommerce customer email', [
            'order_id' => $object->get_id(),
            'email_id' => $email_id,
            'attachments' => count($paths),
        ]);
        return array_values(array_unique($attachments));
    }
}
