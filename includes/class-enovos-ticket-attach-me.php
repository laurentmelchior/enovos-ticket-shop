<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers reserved ticket PDFs in WooCommerce Attach Me! (WCAM)
 * so they appear on the order Attachments box / My Account and can be
 * embedded in the Completed customer email by Attach Me! itself.
 */
final class AttachMe {
    public static function is_active(): bool {
        if (defined('WCAM_PLUGIN_ABS_PATH') || class_exists('WCAM_File') || class_exists('WCAM_Order')) {
            return true;
        }
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach ([
            'woocommerce-attach-me/attach-me.php',
            'woocommerce-attach-me/attachme.php',
            'woocommerce-attach-me/woocommerce-attach-me.php',
        ] as $plugin) {
            if (is_plugin_active($plugin)) {
                return true;
            }
        }
        return false;
    }

    public static function sync_order(\WC_Order $order): void {
        $settings = wp_parse_args(get_option('enovos_ticket_shop_settings', []), Plugin::defaults());
        if (empty($settings['enable_attach_me'])) {
            return;
        }
        if (!self::is_active()) {
            return;
        }
        // Avoid re-entrancy during Attach Me! AJAX itself.
        if (wp_doing_ajax() && isset($_REQUEST['action']) && sanitize_key((string) $_REQUEST['action']) === 'upload_attachments') {
            return;
        }
        if (!has_action('wp_ajax_upload_attachments')) {
            Logger::log('FAIL', 'Attach Me! is active but upload_attachments AJAX action is missing');
            self::schedule($order->get_id());
            return;
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, pdf_path, package_no, concert_title FROM " . TicketInventory::table() . "
             WHERE order_id = %d AND status IN (%s,%s) ORDER BY id ASC",
            $order->get_id(),
            TicketInventory::STATUS_RESERVED,
            TicketInventory::STATUS_DELIVERED
        ), ARRAY_A) ?: [];

        $synced = array_map('intval', (array) $order->get_meta('_enovos_wcam_synced_package_ids'));
        $pending = [];
        foreach ($rows as $row) {
            $package_id = (int) $row['id'];
            if (in_array($package_id, $synced, true)) {
                continue;
            }
            if (empty($row['pdf_path']) || !is_readable($row['pdf_path'])) {
                Logger::log('FAIL', 'Attach Me! sync skipped unreadable ticket PDF', [
                    'order_id' => $order->get_id(),
                    'package_id' => $package_id,
                ]);
                continue;
            }
            $pending[] = $row;
        }

        if (!$pending) {
            return;
        }

        $media_items = [];
        foreach ($pending as $row) {
            $media = self::ensure_media_attachment((string) $row['pdf_path'], (string) ($row['concert_title'] ?? 'Ticket'), (int) $row['package_no']);
            if (is_wp_error($media)) {
                Logger::log('FAIL', 'Attach Me! media preparation failed', [
                    'order_id' => $order->get_id(),
                    'package_id' => (int) $row['id'],
                    'error' => $media->get_error_message(),
                ]);
                continue;
            }
            $media_items[] = [
                'package_id' => (int) $row['id'],
                'title' => sprintf(
                    '%s – Ticket package %d',
                    sanitize_text_field((string) ($row['concert_title'] ?: 'Concert tickets')),
                    (int) $row['package_no']
                ),
                'media_id' => (int) $media['id'],
                'url' => (string) $media['url'],
            ];
        }

        if (!$media_items) {
            return;
        }

        $ok = self::register_gallery_attachments($order->get_id(), $media_items);
        if (!$ok) {
            Logger::log('FAIL', 'Attach Me! upload_attachments call failed', ['order_id' => $order->get_id()]);
            self::schedule($order->get_id());
            return;
        }

        foreach ($media_items as $item) {
            $synced[] = (int) $item['package_id'];
        }
        $order->update_meta_data('_enovos_wcam_synced_package_ids', array_values(array_unique($synced)));
        $order->update_meta_data('_enovos_wcam_synced_at', current_time('mysql'));
        $order->save();
        $order->add_order_note(sprintf(
            'Enovos WooCommerce Addons: %d ticket PDF(s) registered in Attach Me! attachments.',
            count($media_items)
        ));
        Logger::log('OK', 'Ticket PDFs registered in Attach Me!', [
            'order_id' => $order->get_id(),
            'packages' => count($media_items),
        ]);
    }

    public static function schedule(int $order_id): void {
        if ($order_id <= 0 || !self::is_active()) {
            return;
        }
        $hook = 'enovos_ticket_shop_sync_attach_me';
        $args = [$order_id];
        if (!wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event(time() + 15, $hook, $args);
            Logger::log('STEP', 'Scheduled deferred Attach Me! sync', ['order_id' => $order_id]);
        }
    }

    public static function handle_scheduled(int $order_id): void {
        $order = wc_get_order($order_id);
        if ($order) {
            self::sync_order($order);
        }
    }

    /**
     * Statuses in which Attach Me! must hide a customer ticket download.
     * Unknown/custom statuses are denied by default.
     *
     * @return list<string>
     */
    public static function hidden_order_statuses(string $delivery_status): array {
        $statuses = function_exists('wc_get_order_statuses')
            ? array_keys(wc_get_order_statuses())
            : [
                'wc-pending',
                'wc-processing',
                'wc-on-hold',
                'wc-completed',
                'wc-cancelled',
                'wc-refunded',
                'wc-failed',
                'wc-checkout-draft',
            ];
        $allowed = $delivery_status === 'processing'
            ? ['wc-processing', 'wc-completed']
            : ['wc-completed'];
        return array_values(array_diff(array_unique($statuses), $allowed));
    }

    private static function ensure_media_attachment(string $pdf_path, string $title, int $package_no) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new \WP_Error('upload_dir', (string) $uploads['error']);
        }

        $base = TicketInventory::package_filename($title, $package_no);
        $filename = preg_replace('/\.pdf$/i', '', $base) . '-' . wp_generate_password(6, false, false) . '.pdf';
        $dest = trailingslashit($uploads['path']) . $filename;
        if (!@copy($pdf_path, $dest)) {
            return new \WP_Error('copy_failed', 'Could not copy ticket PDF into the media uploads directory.');
        }

        $attachment = [
            'post_mime_type' => 'application/pdf',
            'post_title' => sanitize_text_field(sprintf('%s – Ticket package %d', $title ?: 'Concert tickets', $package_no)),
            'post_content' => '',
            'post_status' => 'inherit',
        ];
        $attachment_id = wp_insert_attachment($attachment, $dest, 0, true);
        if (is_wp_error($attachment_id) || !$attachment_id) {
            @unlink($dest);
            return is_wp_error($attachment_id)
                ? $attachment_id
                : new \WP_Error('attachment_failed', 'WordPress could not create a media attachment for the ticket PDF.');
        }

        $meta = wp_generate_attachment_metadata($attachment_id, $dest);
        if ($meta) {
            wp_update_attachment_metadata($attachment_id, $meta);
        }

        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return new \WP_Error('attachment_url_missing', 'Media attachment URL is missing.');
        }

        return ['id' => (int) $attachment_id, 'url' => $url];
    }

    /**
     * @param array<int, array{package_id:int,title:string,media_id:int,url:string}> $items
     */
    private static function register_gallery_attachments(int $order_id, array $items): bool {
        $next = self::next_index($order_id);
        $settings = wp_parse_args(get_option('enovos_ticket_shop_settings', []), Plugin::defaults());
        $delivery = $settings['delivery_order_status'] ?? 'completed';
        $hidden_statuses = self::hidden_order_statuses($delivery);

        $backup_post = $_POST;
        $backup_files = $_FILES;
        $backup_user = get_current_user_id();

        $_POST = [
            'action' => 'upload_attachments',
            'wcam-orderid' => (string) $order_id,
        ];
        $_FILES = [];

        foreach ($items as $offset => $item) {
            $index = $next + $offset;
            $_POST['wcam_id'][$index] = (string) $index;
            $_POST['wcam_attachment_title'][$index] = $item['title'];
            $_POST['wcam_attachment_gallery_url'][$index] = $item['url'];
            $_POST['wcam_attachment_gallery_media_id'][$index] = (string) $item['media_id'];
            $_POST['wcam_expiration_strategy'][$index] = 'never';
            $_POST['wcam-secure-download'][$index] = 'yes';
            $_POST['wcam-order-attachment-hide-by-status'][$index] = $hidden_statuses;
            // Ask Attach Me! to embed the file only in the Completed customer email.
            // Unchecked WCAM checkboxes must be omitted (not sent as "no").
            $_POST['wcam-attach-file-to-complete-order-email'][$index] = 'yes';
        }

        // Honour plugin setting if an older install still uses Processing.
        if ($delivery === 'processing') {
            foreach ($items as $offset => $_item) {
                $index = $next + $offset;
                unset($_POST['wcam-attach-file-to-complete-order-email'][$index]);
                $_POST['wcam-attach-file-to-processing-order-email'][$index] = 'yes';
            }
        }

        $manager_id = self::shop_manager_user_id();
        if ($manager_id > 0) {
            wp_set_current_user($manager_id);
        }

        $ok = false;
        $die_handler = static function ($message = '', $title = '', $args = []) {
            throw new \RuntimeException('wcam_wp_die');
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        add_filter('wp_die_ajax_handler', $die_ajax_filter, 99);
        add_filter('wp_die_handler', $die_filter, 99);

        try {
            ob_start();
            try {
                do_action('wp_ajax_upload_attachments');
                $response = (string) ob_get_clean();
            } catch (\RuntimeException $e) {
                $response = (string) ob_get_clean();
                if ($e->getMessage() !== 'wcam_wp_die') {
                    throw $e;
                }
            }
            // Attach Me! re-renders the attachments metabox HTML on success.
            $ok = $response !== '' && (
                str_contains($response, 'wcam-attachments-box')
                || str_contains($response, 'wcam-attachment-box')
                || str_contains($response, 'data-already-uploaded')
            );
            Logger::log('STEP', 'Attach Me! upload_attachments invoked', [
                'order_id' => $order_id,
                'items' => count($items),
                'next_index' => $next,
                'response_bytes' => strlen($response),
                'accepted' => $ok,
            ]);
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            Logger::log('FAIL', 'Attach Me! upload_attachments exception', [
                'order_id' => $order_id,
                'error' => $e->getMessage(),
            ]);
            $ok = false;
        } finally {
            remove_filter('wp_die_ajax_handler', $die_ajax_filter, 99);
            remove_filter('wp_die_handler', $die_filter, 99);
            $_POST = $backup_post;
            $_FILES = $backup_files;
            wp_set_current_user($backup_user);
        }

        return $ok;
    }

    private static function next_index(int $order_id): int {
        global $wcam_order_model;
        if (isset($wcam_order_model) && is_object($wcam_order_model)) {
            foreach (['get_next_id', 'get_next_attachment_id', 'get_attachments_next_id'] as $method) {
                if (method_exists($wcam_order_model, $method)) {
                    $value = (int) $wcam_order_model->{$method}($order_id);
                    if ($value >= 0) {
                        return $value;
                    }
                }
            }
        }

        // Fallback: count already synced packages for this order.
        $order = wc_get_order($order_id);
        if ($order) {
            $synced = (array) $order->get_meta('_enovos_wcam_synced_package_ids');
            return count($synced);
        }
        return 0;
    }

    private static function shop_manager_user_id(): int {
        $users = get_users([
            'role__in' => ['administrator', 'shop_manager'],
            'number' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ID',
        ]);
        return !empty($users[0]) ? (int) $users[0] : 0;
    }
}
