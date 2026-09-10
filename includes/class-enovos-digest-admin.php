<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class DigestAdmin {
    public const COLUMN = 'enovos_daily_digest';
    public const DELIVERY_COLUMN = 'enovos_delivery';
    public const DELIVERY_FIELD = 'delivery';
    public const FILTER = 'enovos_daily_digest';
    public const FILTER_SUBSCRIBED = 'subscribed';
    public const FILTER_UNSUBSCRIBED = 'unsubscribed';

    public static function init(): void {
        add_filter('manage_users_columns', [self::class, 'add_column']);
        add_filter('manage_users_custom_column', [self::class, 'render_column'], 10, 3);
        add_filter('manage_users_sortable_columns', [self::class, 'add_sortable_column']);
        add_action('restrict_manage_users', [self::class, 'render_filter']);
        add_action('pre_get_users', [self::class, 'filter_users']);
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public static function add_column(array $columns): array {
        $columns[self::COLUMN] = __('Daily Digest', 'enovos-ticket-shop');
        $columns[self::DELIVERY_COLUMN] = __('Delivery', 'enovos-ticket-shop');
        return $columns;
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public static function add_sortable_column(array $columns): array {
        $columns[self::COLUMN] = self::COLUMN;
        return $columns;
    }

    public static function render_column(string $output, string $column_name, int $user_id): string {
        if ($column_name === self::DELIVERY_COLUMN) {
            $delivery = self::delivery($user_id);
            return $delivery === '' ? '&mdash;' : nl2br(esc_html($delivery));
        }
        if ($column_name !== self::COLUMN) {
            return $output;
        }

        return self::is_subscribed($user_id)
            ? esc_html__('Subscribed', 'enovos-ticket-shop')
            : esc_html__('Not subscribed', 'enovos-ticket-shop');
    }

    public static function is_subscribed(int $user_id): bool {
        return (string) get_user_meta($user_id, DigestUnsubscribe::FIELD_NAME, true) === '1';
    }

    public static function delivery(int $user_id): string {
        $delivery = function_exists('get_field')
            ? get_field(self::DELIVERY_FIELD, 'user_' . $user_id)
            : get_user_meta($user_id, self::DELIVERY_FIELD, true);
        if (!is_scalar($delivery)) {
            $delivery = get_user_meta($user_id, self::DELIVERY_FIELD, true);
        }
        return is_scalar($delivery) ? trim((string) $delivery) : '';
    }

    public static function requested_filter(): string {
        $value = sanitize_key((string) ($_GET[self::FILTER] ?? ''));
        return in_array($value, [self::FILTER_SUBSCRIBED, self::FILTER_UNSUBSCRIBED], true) ? $value : '';
    }

    public static function render_filter(string $which): void {
        if ($which !== 'top' || !current_user_can('list_users')) {
            return;
        }

        $current = self::requested_filter();
        echo '<label class="screen-reader-text" for="' . esc_attr(self::FILTER) . '">';
        echo esc_html__('Filter by Daily Digest', 'enovos-ticket-shop');
        echo '</label>';
        echo '<select name="' . esc_attr(self::FILTER) . '" id="' . esc_attr(self::FILTER) . '">';
        echo '<option value="">' . esc_html__('All Daily Digest', 'enovos-ticket-shop') . '</option>';
        echo '<option value="' . esc_attr(self::FILTER_SUBSCRIBED) . '"' . selected($current, self::FILTER_SUBSCRIBED, false) . '>';
        echo esc_html__('Subscribed', 'enovos-ticket-shop') . '</option>';
        echo '<option value="' . esc_attr(self::FILTER_UNSUBSCRIBED) . '"' . selected($current, self::FILTER_UNSUBSCRIBED, false) . '>';
        echo esc_html__('Not subscribed', 'enovos-ticket-shop') . '</option>';
        echo '</select>';
        // The users list has no generic filter button, so the control needs its own submit.
        // The name must differ from "changeit" so users.php does not run a role change.
        submit_button(__('Filter', 'enovos-ticket-shop'), '', self::FILTER . '_action', false);
    }

    public static function filter_users(\WP_User_Query $query): void {
        if (!is_admin() || ($GLOBALS['pagenow'] ?? '') !== 'users.php' || !current_user_can('list_users')) {
            return;
        }

        $changes = self::query_modifications(
            is_array($query->get('meta_query')) ? $query->get('meta_query') : [],
            (string) $query->get('orderby'),
            (string) $query->get('order'),
            self::requested_filter()
        );
        if ($changes === []) {
            return;
        }

        $query->set('meta_query', $changes['meta_query']);
        if (isset($changes['orderby'])) {
            $query->set('orderby', $changes['orderby']);
        }
    }

    /**
     * @param array<int|string,mixed> $meta_query
     * @return array{meta_query: array<int|string,mixed>, orderby?: array<string,string>}
     */
    public static function query_modifications(array $meta_query, string $orderby, string $order, string $filter): array {
        $sorting = $orderby === self::COLUMN;
        if ($filter === '' && !$sorting) {
            return [];
        }

        if ($filter === self::FILTER_SUBSCRIBED) {
            $meta_query[] = [
                'key' => DigestUnsubscribe::FIELD_NAME,
                'value' => '1',
                'compare' => '=',
            ];
        } elseif ($filter === self::FILTER_UNSUBSCRIBED) {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => DigestUnsubscribe::FIELD_NAME,
                    'value' => '1',
                    'compare' => '!=',
                ],
                [
                    'key' => DigestUnsubscribe::FIELD_NAME,
                    'compare' => 'NOT EXISTS',
                ],
            ];
        }

        $changes = ['meta_query' => $meta_query];
        if (!$sorting) {
            return $changes;
        }

        $changes['meta_query'][] = [
            'relation' => 'OR',
            'enovos_digest_sort' => [
                'key' => DigestUnsubscribe::FIELD_NAME,
                'compare' => 'EXISTS',
            ],
            'enovos_digest_missing' => [
                'key' => DigestUnsubscribe::FIELD_NAME,
                'compare' => 'NOT EXISTS',
            ],
        ];
        $changes['orderby'] = [
            'enovos_digest_sort' => strtoupper($order) === 'ASC' ? 'ASC' : 'DESC',
            'display_name' => 'ASC',
        ];
        return $changes;
    }
}
