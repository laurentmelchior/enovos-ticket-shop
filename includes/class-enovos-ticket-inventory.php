<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class TicketInventory {
    public const STATUS_AVAILABLE = 'AVAILABLE';
    public const STATUS_RESERVED = 'RESERVED';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_INVALIDATED = 'INVALIDATED';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'enovos_ticket_packages';
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            import_id varchar(64) NOT NULL,
            event_key varchar(64) NOT NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            concert_title text NOT NULL,
            concert_date date NULL,
            package_no int unsigned NOT NULL,
            ticket_pages varchar(64) NOT NULL,
            pdf_path text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'AVAILABLE',
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reserved_at datetime NULL,
            delivered_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY product_status (product_id,status),
            KEY order_id (order_id),
            KEY import_event (import_id,event_key)
        ) {$charset};";
        dbDelta($sql);
    }

    /**
     * Build a filesystem-safe PDF filename that includes the concert name.
     * Example: coldplay-luxembourg-ticket-package-001.pdf
     */
    public static function package_filename(string $concert_title, int $package_no, int $product_id = 0): string {
        $slug = sanitize_title($concert_title);
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');
        if ($slug === '') {
            $slug = $product_id > 0 ? 'product-' . $product_id : 'concert';
        }
        if (strlen($slug) > 80) {
            $slug = rtrim(substr($slug, 0, 80), '-');
        }
        $package_no = max(1, $package_no);
        return sprintf('%s-ticket-package-%03d.pdf', $slug, $package_no);
    }

    public static function get_package(int $package_id): ?array {
        global $wpdb;
        if ($package_id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $package_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function create_packages(int $product_id, array $event, string $source_pdf, string $import_id) {
        global $wpdb;
        $event_key = sanitize_text_field((string)($event['_import_key'] ?? ''));
        $page_numbers = array_values(array_unique(array_filter(array_map('intval', (array)($event['page_numbers'] ?? [])), static fn($v) => $v > 0)));
        sort($page_numbers, SORT_NUMERIC);
        $requested = max(0, (int)($event['product_quantity'] ?? 0));
        $possible = intdiv(count($page_numbers), 2);
        if ($requested <= 0) {
            return new \WP_Error('invalid_product_quantity', 'Product quantity must be greater than zero before ticket packages are generated.');
        }
        if ($possible < $requested) {
            return new \WP_Error('insufficient_ticket_pages', sprintf('Only %d complete two-ticket packages can be created from %d detected PDF pages, but product quantity is %d.', $possible, count($page_numbers), $requested));
        }
        if (!$event_key) {
            return new \WP_Error('missing_event_key', 'Ticket package generation is missing the event key.');
        }

        $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table() . " WHERE product_id = %d", $product_id));
        if ($existing > 0) {
            Logger::log('STEP', 'Ticket packages already exist for product; generation skipped', ['product_id' => $product_id, 'packages' => $existing]);
            return $existing;
        }

        $concert_title = sanitize_text_field((string)($event['title'] ?? ''));
        $dir = PdfPackages::create_import_dir($import_id);
        $event_dir = trailingslashit($dir) . 'product-' . $product_id;
        if (!is_dir($event_dir)) {
            wp_mkdir_p($event_dir);
        }
        $created_ids = [];
        for ($i = 0; $i < $requested; $i++) {
            $pair = [$page_numbers[$i * 2], $page_numbers[$i * 2 + 1]];
            $package_no = $i + 1;
            $filename = self::package_filename($concert_title, $package_no, $product_id);
            $path = trailingslashit($event_dir) . $filename;
            $generated = PdfPackages::create_two_page_pdf($source_pdf, $pair, $path);
            if (is_wp_error($generated)) {
                foreach ($created_ids as $id) {
                    $old_path = $wpdb->get_var($wpdb->prepare("SELECT pdf_path FROM " . self::table() . " WHERE id = %d", $id));
                    if ($old_path) {
                        @unlink($old_path);
                    }
                    $wpdb->delete(self::table(), ['id' => $id], ['%d']);
                }
                return $generated;
            }
            $now = current_time('mysql');
            $inserted = $wpdb->insert(self::table(), [
                'import_id' => $import_id,
                'event_key' => $event_key,
                'product_id' => $product_id,
                'concert_title' => $concert_title,
                'concert_date' => !empty($event['date']) ? $event['date'] : null,
                'package_no' => $package_no,
                'ticket_pages' => implode(',', $pair),
                'pdf_path' => $path,
                'status' => self::STATUS_AVAILABLE,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%s','%s','%d','%s','%s','%d','%s','%s','%s','%s','%s']);
            if (!$inserted) {
                @unlink($path);
                return new \WP_Error('inventory_insert_failed', 'The ticket package was created but could not be saved to the ticket inventory.');
            }
            $created_ids[] = (int)$wpdb->insert_id;
        }

        update_post_meta($product_id, '_enovos_ticket_packages', count($created_ids));
        update_post_meta($product_id, '_enovos_ticket_import_id', $import_id);
        self::sync_product_stock($product_id);
        Logger::log('OK', 'Two-ticket PDF packages created', ['product_id' => $product_id, 'packages' => count($created_ids), 'pages' => array_slice($page_numbers, 0, $requested * 2)]);
        return count($created_ids);
    }

    public static function reserve_for_order($order): void {
        if (!$order instanceof \WC_Order) {
            return;
        }
        global $wpdb;
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $product_id = (int)$item->get_product_id();
            if (!$product_id || (int)get_post_meta($product_id, '_enovos_ticket_quantity_per_product', true) !== 2) {
                continue;
            }
            $already = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table() . " WHERE order_id = %d AND order_item_id = %d", $order->get_id(), $item_id));
            if ($already > 0) {
                continue;
            }
            $qty = max(1, (int)$item->get_quantity());
            for ($n = 0; $n < $qty; $n++) {
                $package_id = self::claim_available_package($product_id, $order->get_id(), (int)$item_id);
                if (!$package_id) {
                    $order->add_order_note(sprintf('Enovos Ticket Shop: no AVAILABLE ticket package could be reserved for product #%d.', $product_id));
                    Logger::log('FAIL', 'No available ticket package for order', ['order_id' => $order->get_id(), 'product_id' => $product_id]);
                    continue;
                }
                $order->add_order_note(sprintf('Enovos Ticket Shop: ticket package #%d reserved.', $package_id));
                Logger::log('OK', 'Ticket package reserved for order', ['package_id' => $package_id, 'order_id' => $order->get_id(), 'product_id' => $product_id]);
            }
            // Do not sync WooCommerce stock here – WC already decrements on order.
            // Syncing would double-reduce stock (sync to AVAILABLE, then WC −1 again).
        }
        self::sync_order_meta($order);
        AttachMe::sync_order($order);
    }

    private static function sync_order_meta(\WC_Order $order): void {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,pdf_path,status,product_id FROM " . self::table() . " WHERE order_id=%d ORDER BY id ASC",
            $order->get_id()
        ), ARRAY_A) ?: [];
        $order->update_meta_data('_enovos_ticket_package_ids', array_map('intval', array_column($rows, 'id')));
        $order->update_meta_data('_enovos_ticket_pdf_paths', array_values(array_filter(array_column($rows, 'pdf_path'))));
        $order->update_meta_data('_enovos_ticket_package_statuses', array_column($rows, 'status'));
        $order->save();
    }

    private static function claim_available_package(int $product_id, int $order_id, int $order_item_id): int {
        global $wpdb;
        $table = self::table();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE product_id = %d AND status = %s ORDER BY package_no ASC LIMIT 1", $product_id, self::STATUS_AVAILABLE));
            if (!$id) {
                return 0;
            }
            $now = current_time('mysql');
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status=%s, order_id=%d, order_item_id=%d, reserved_at=%s, updated_at=%s WHERE id=%d AND status=%s",
                self::STATUS_RESERVED, $order_id, $order_item_id, $now, $now, $id, self::STATUS_AVAILABLE
            ));
            if ($updated === 1) {
                return $id;
            }
        }
        return 0;
    }

    public static function release_order(int $order_id): void {
        global $wpdb;
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT product_id FROM " . self::table() . " WHERE order_id=%d AND status=%s",
            $order_id,
            self::STATUS_RESERVED
        )) ?: [];
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status=%s, order_id=0, order_item_id=0, reserved_at=NULL, updated_at=%s WHERE order_id=%d AND status=%s",
            self::STATUS_AVAILABLE, $now, $order_id, self::STATUS_RESERVED
        ));
        foreach (array_map('intval', $product_ids) as $product_id) {
            if ($product_id > 0) {
                self::sync_product_stock($product_id);
            }
        }
        $order = wc_get_order($order_id);
        if ($order) {
            self::sync_order_meta($order);
        }
        Logger::log('STEP', 'Reserved ticket packages released for cancelled/failed order', ['order_id' => $order_id]);
    }

    public static function invalidate_refunded_order(int $order_id): void {
        global $wpdb;
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT product_id FROM " . self::table() . " WHERE order_id=%d AND status IN (%s,%s)",
            $order_id,
            self::STATUS_DELIVERED,
            self::STATUS_RESERVED
        )) ?: [];
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status=%s, updated_at=%s WHERE order_id=%d AND status=%s",
            self::STATUS_INVALIDATED, $now, $order_id, self::STATUS_DELIVERED
        ));
        self::release_order($order_id);
        foreach (array_map('intval', $product_ids) as $product_id) {
            if ($product_id > 0) {
                self::sync_product_stock($product_id);
            }
        }
        $order = wc_get_order($order_id);
        if ($order) {
            self::sync_order_meta($order);
        }
        Logger::log('STEP', 'Delivered ticket packages invalidated for refunded order', ['order_id' => $order_id]);
    }

    /**
     * Keep WooCommerce stock aligned with AVAILABLE ticket packages.
     * Inventory is the source of truth for sellable units.
     */
    public static function sync_product_stock(int $product_id): void {
        if ($product_id <= 0) {
            return;
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        $available = self::counts_for_product($product_id)[self::STATUS_AVAILABLE] ?? 0;
        $product->set_manage_stock(true);
        $product->set_stock_quantity($available);
        $product->set_stock_status($available > 0 ? 'instock' : 'outofstock');
        $product->save();
        update_post_meta($product_id, '_enovos_ticket_packages_available', $available);
        Logger::log('STEP', 'Product stock synced from ticket inventory', [
            'product_id' => $product_id,
            'available_packages' => $available,
        ]);
    }

    public static function attachments_for_order(\WC_Order $order): array {
        global $wpdb;
        self::reserve_for_order($order);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,pdf_path,status FROM " . self::table() . " WHERE order_id=%d AND status IN (%s,%s) ORDER BY id ASC",
            $order->get_id(), self::STATUS_RESERVED, self::STATUS_DELIVERED
        ), ARRAY_A);
        $paths = [];
        foreach ($rows as $row) {
            if (!empty($row['pdf_path']) && is_readable($row['pdf_path'])) {
                $paths[] = $row['pdf_path'];
            }
        }
        return array_values(array_unique($paths));
    }

    public static function mark_delivered(int $order_id): void {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status=%s, delivered_at=COALESCE(delivered_at,%s), updated_at=%s WHERE order_id=%d AND status=%s",
            self::STATUS_DELIVERED, $now, $now, $order_id, self::STATUS_RESERVED
        ));
        $order = wc_get_order($order_id);
        if ($order) self::sync_order_meta($order);
    }

    public static function rows(int $limit = 200): array {
        global $wpdb;
        $limit = max(1, min(1000, $limit));
        return $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT {$limit}", ARRAY_A) ?: [];
    }

    public static function counts_for_product(int $product_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) AS c FROM " . self::table() . " WHERE product_id=%d GROUP BY status", $product_id), ARRAY_A);
        $out = [self::STATUS_AVAILABLE => 0, self::STATUS_RESERVED => 0, self::STATUS_DELIVERED => 0, self::STATUS_INVALIDATED => 0];
        foreach ($rows as $row) {
            $out[$row['status']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Delete a ticket package row and its PDF file.
     * RESERVED packages assigned to an open order cannot be deleted.
     */
    public static function delete_package(int $package_id) {
        global $wpdb;
        if ($package_id <= 0) {
            return new \WP_Error('invalid_package', 'Invalid ticket package ID.');
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $package_id), ARRAY_A);
        if (!$row) {
            return new \WP_Error('package_missing', 'Ticket package not found.');
        }
        if ($row['status'] === self::STATUS_RESERVED && (int) $row['order_id'] > 0) {
            return new \WP_Error(
                'package_reserved',
                sprintf('Package #%d is RESERVED for order #%d and cannot be deleted. Cancel/fail the order first, or wait until it is released.', $package_id, (int) $row['order_id'])
            );
        }

        $product_id = (int) $row['product_id'];
        $path = (string) $row['pdf_path'];
        $deleted = $wpdb->delete(self::table(), ['id' => $package_id], ['%d']);
        if (!$deleted) {
            return new \WP_Error('package_delete_failed', 'The ticket package row could not be deleted.');
        }
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
        if ($product_id > 0) {
            $counts = self::counts_for_product($product_id);
            $remaining = array_sum($counts);
            update_post_meta($product_id, '_enovos_ticket_packages', $remaining);
            self::sync_product_stock($product_id);
        }
        Logger::log('OK', 'Ticket package deleted from inventory', [
            'package_id' => $package_id,
            'product_id' => $product_id,
            'status' => $row['status'],
            'pdf_removed' => ($path !== '' && !is_file($path)),
        ]);
        return true;
    }

    /**
     * @param list<int> $package_ids
     * @return array{deleted:int,errors:list<string>}
     */
    public static function delete_packages(array $package_ids): array {
        $deleted = 0;
        $errors = [];
        foreach (array_unique(array_map('intval', $package_ids)) as $id) {
            if ($id <= 0) {
                continue;
            }
            $result = self::delete_package($id);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $deleted++;
            }
        }
        return ['deleted' => $deleted, 'errors' => $errors];
    }
}
