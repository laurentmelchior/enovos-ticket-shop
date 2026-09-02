<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Audits and, when the installed Attach Me! model exposes a safe public
 * metadata setter, repairs visibility for existing Enovos ticket attachments.
 */
final class AttachMeRepair {
    private const SCAN_ACTION = 'enovos_scan_attach_me_visibility';
    private const REPAIR_ACTION = 'enovos_repair_attach_me_visibility';
    private const RESULT_TRANSIENT = 'enovos_attach_me_repair_';
    private const MAX_ORDERS = 500;

    public static function init(): void {
        add_action('admin_post_' . self::SCAN_ACTION, [self::class, 'handle_scan']);
        add_action('admin_post_' . self::REPAIR_ACTION, [self::class, 'handle_repair']);
    }

    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $result = get_transient(self::RESULT_TRANSIENT . get_current_user_id());
        if (is_array($result)) {
            delete_transient(self::RESULT_TRANSIENT . get_current_user_id());
        }

        echo '<div class="enovos-card"><div class="enovos-card__header">';
        echo '<h2>' . esc_html__('Existing Attach Me! visibility', 'enovos-ticket-shop') . '</h2>';
        echo '<p>' . esc_html__(
            'Audit Enovos ticket attachments created before version 0.7.0. No files are uploaded or duplicated.',
            'enovos-ticket-shop'
        ) . '</p></div><div class="enovos-card__body">';
        if (is_array($result)) {
            self::render_result($result);
        }
        echo '<div class="enovos-repair-actions">';
        self::render_action(self::SCAN_ACTION, __('Check existing orders', 'enovos-ticket-shop'), false);
        self::render_action(self::REPAIR_ACTION, __('Repair visibility', 'enovos-ticket-shop'), true);
        echo '</div><p class="description">' . esc_html__(
            'Repair writes only when the installed Attach Me! version exposes a supported metadata setter. Otherwise it reports that no safe write method is available.',
            'enovos-ticket-shop'
        ) . '</p></div></div>';
    }

    public static function handle_scan(): void {
        self::authorize(self::SCAN_ACTION);
        self::store_result(self::run(false));
        self::redirect();
    }

    public static function handle_repair(): void {
        self::authorize(self::REPAIR_ACTION);
        self::store_result(self::run(true));
        self::redirect();
    }

    /**
     * @return array{checked:int,matched:int,incorrect:int,repaired:int,unsupported:int,failed:int,limited:bool,method:string}
     */
    public static function run(bool $repair): array {
        global $wcam_order_model;
        $result = [
            'checked' => 0,
            'matched' => 0,
            'incorrect' => 0,
            'repaired' => 0,
            'unsupported' => 0,
            'failed' => 0,
            'limited' => false,
            'method' => '',
        ];
        if (
            !isset($wcam_order_model)
            || !is_object($wcam_order_model)
            || !method_exists($wcam_order_model, 'get_attachments_metadata')
        ) {
            $result['unsupported'] = 1;
            return $result;
        }

        $setter = $repair ? self::supported_setter($wcam_order_model) : '';
        $result['method'] = $setter;
        $order_ids = self::order_ids();
        $result['limited'] = count($order_ids) >= self::MAX_ORDERS;
        $expected = AttachMe::hidden_order_statuses(
            (string) (Plugin::settings()['delivery_order_status'] ?? 'completed')
        );

        foreach ($order_ids as $order_id) {
            $result['checked']++;
            try {
                $metadata = $wcam_order_model->get_attachments_metadata($order_id, []);
            } catch (\Throwable $exception) {
                $result['failed']++;
                continue;
            }
            if (!is_array($metadata)) {
                $result['unsupported']++;
                continue;
            }

            $changed = false;
            $changed_count = 0;
            $order_has_enovos_attachment = false;
            foreach ($metadata as $index => $attachment) {
                if (!is_array($attachment) || !self::is_enovos_attachment($attachment)) {
                    continue;
                }
                $order_has_enovos_attachment = true;
                $result['matched']++;
                $status_key = self::status_key($attachment);
                if ($status_key === null || !is_array($attachment[$status_key])) {
                    $result['unsupported']++;
                    continue;
                }
                if (self::same_statuses($attachment[$status_key], $expected)) {
                    continue;
                }
                $result['incorrect']++;
                if ($repair && $setter !== '') {
                    $metadata[$index][$status_key] = $expected;
                    $changed = true;
                    $changed_count++;
                }
            }

            if (!$order_has_enovos_attachment || !$changed) {
                continue;
            }
            try {
                $saved = $wcam_order_model->{$setter}($order_id, $metadata);
                if ($saved === false) {
                    $result['failed']++;
                } else {
                    $result['repaired'] += $changed_count;
                }
            } catch (\Throwable $exception) {
                $result['failed']++;
            }
        }
        return $result;
    }

    private static function order_ids(): array {
        if (!function_exists('wc_get_orders')) {
            return [];
        }
        $ids = wc_get_orders([
            'limit' => self::MAX_ORDERS,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [[
                'key' => '_enovos_wcam_synced_package_ids',
                'compare' => 'EXISTS',
            ]],
        ]);
        return array_values(array_filter(array_map('absint', is_array($ids) ? $ids : [])));
    }

    private static function supported_setter(object $model): string {
        foreach (['set_attachments_metadata', 'save_attachments_metadata', 'update_attachments_metadata'] as $method) {
            if (!method_exists($model, $method)) {
                continue;
            }
            try {
                $reflection = new \ReflectionMethod($model, $method);
                if ($reflection->isPublic() && $reflection->getNumberOfRequiredParameters() <= 2 && $reflection->getNumberOfParameters() >= 2) {
                    return $method;
                }
            } catch (\ReflectionException $exception) {
                continue;
            }
        }
        return '';
    }

    private static function is_enovos_attachment(array $attachment): bool {
        foreach ($attachment as $value) {
            if (is_string($value) && (
                stripos($value, 'ticket package') !== false
                || stripos($value, 'ticket-package-') !== false
            )) {
                return true;
            }
        }
        return false;
    }

    private static function status_key(array $attachment): ?string {
        $matches = [];
        $preferred = [];
        foreach ($attachment as $key => $value) {
            if (
                is_string($key)
                && stripos($key, 'status') !== false
                && is_array($value)
                && self::contains_only_statuses($value)
            ) {
                $matches[] = $key;
                if (stripos($key, 'hide') !== false) {
                    $preferred[] = $key;
                }
            }
        }
        if (count($preferred) === 1) {
            return $preferred[0];
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private static function contains_only_statuses(array $values): bool {
        if (!$values) {
            return true;
        }
        foreach ($values as $value) {
            if (!is_string($value) || !str_starts_with($value, 'wc-')) {
                return false;
            }
        }
        return true;
    }

    private static function same_statuses(array $actual, array $expected): bool {
        $actual = array_values(array_unique(array_map('strval', $actual)));
        $expected = array_values(array_unique(array_map('strval', $expected)));
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }

    private static function authorize(string $action): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to repair ticket attachments.', 'enovos-ticket-shop'));
        }
        check_admin_referer($action);
    }

    private static function store_result(array $result): void {
        set_transient(
            self::RESULT_TRANSIENT . get_current_user_id(),
            $result,
            5 * MINUTE_IN_SECONDS
        );
    }

    private static function redirect(): void {
        wp_safe_redirect(admin_url('admin.php?page=enovos-ticket-shop-settings&section=ticket-shop#enovos-attach-me-repair'));
        exit;
    }

    private static function render_action(string $action, string $label, bool $primary): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        echo '<button class="button ' . ($primary ? 'button-primary' : 'button-secondary') . '" type="submit">';
        echo esc_html($label) . '</button></form>';
    }

    private static function render_result(array $result): void {
        $class = !empty($result['failed']) || !empty($result['unsupported'])
            ? 'notice-warning'
            : 'notice-success';
        echo '<div id="enovos-attach-me-repair" class="notice ' . esc_attr($class) . ' inline"><p>';
        echo esc_html(sprintf(
            __('Checked %1$d orders; found %2$d Enovos attachments, %3$d incorrect, %4$d repaired, %5$d unsupported, %6$d failed.', 'enovos-ticket-shop'),
            (int) ($result['checked'] ?? 0),
            (int) ($result['matched'] ?? 0),
            (int) ($result['incorrect'] ?? 0),
            (int) ($result['repaired'] ?? 0),
            (int) ($result['unsupported'] ?? 0),
            (int) ($result['failed'] ?? 0)
        ));
        if (empty($result['method']) && !empty($result['incorrect'])) {
            echo ' ' . esc_html__('No supported Attach Me! metadata write method was found; nothing was changed.', 'enovos-ticket-shop');
        }
        if (!empty($result['limited'])) {
            echo ' ' . esc_html__('Only the 500 most recent matching orders were checked.', 'enovos-ticket-shop');
        }
        echo '</p></div>';
    }
}
