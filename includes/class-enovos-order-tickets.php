<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Small Enovos status panel beside Attach Me! on WooCommerce orders.
 *
 * Attach Me! continues to own the attachment list and secure downloads.
 */
final class OrderTickets {
    private const RESEND_ACTION = 'enovos_resend_ticket_email';

    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'register_meta_box']);
        add_action('admin_notices', [self::class, 'render_notice']);
        if (Plugin::enabled('enable_ticket_resend')) {
            add_action('admin_post_' . self::RESEND_ACTION, [self::class, 'handle_resend']);
        }
    }

    public static function register_meta_box(): void {
        $screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        foreach (array_unique($screens) as $screen) {
            add_meta_box(
                'enovos-order-tickets',
                __('Enovos tickets', 'enovos-ticket-shop'),
                [self::class, 'render_meta_box'],
                $screen,
                'side',
                'high'
            );
        }
    }

    public static function render_meta_box($post_or_order): void {
        $order = self::resolve_order($post_or_order);
        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'enovos-ticket-shop') . '</p>';
            return;
        }

        $packages = TicketInventory::packages_for_order($order->get_id());
        if (!$packages) {
            echo '<p>' . esc_html__('No Enovos ticket package is assigned to this order.', 'enovos-ticket-shop') . '</p>';
            return;
        }

        echo '<div class="enovos-order-tickets">';
        foreach ($packages as $package) {
            $status = (string) $package['status'];
            $status_class = in_array($status, [TicketInventory::STATUS_DELIVERED, TicketInventory::STATUS_RESERVED], true)
                ? strtolower($status)
                : 'other';
            echo '<div class="enovos-order-ticket">';
            echo '<strong>' . esc_html((string) $package['concert_title']) . '</strong>';
            echo '<span>' . esc_html(sprintf(
                __('Package #%1$d · pages %2$s', 'enovos-ticket-shop'),
                (int) $package['package_no'],
                (string) $package['ticket_pages']
            )) . '</span>';
            echo '<span class="enovos-order-ticket__status is-' . esc_attr($status_class) . '">' . esc_html($status) . '</span>';
            echo '</div>';
        }

        if (self::can_resend($packages)) {
            $url = wp_nonce_url(
                add_query_arg(
                    [
                        'action' => self::RESEND_ACTION,
                        'order_id' => $order->get_id(),
                    ],
                    admin_url('admin-post.php')
                ),
                self::RESEND_ACTION . '_' . $order->get_id()
            );
            echo '<p><a class="button button-secondary" href="' . esc_url($url) . '">';
            echo esc_html__('Resend ticket email', 'enovos-ticket-shop');
            echo '</a></p>';
            echo '<p class="description">' . esc_html__(
                'Sends the existing Attach Me! ticket files again. No new package is reserved.',
                'enovos-ticket-shop'
            ) . '</p>';
        }
        echo '</div>';
    }

    public static function handle_resend(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to resend ticket emails.', 'enovos-ticket-shop'));
        }
        $order_id = absint($_GET['order_id'] ?? 0);
        check_admin_referer(self::RESEND_ACTION . '_' . $order_id);

        $order = wc_get_order($order_id);
        $result = 'failed';
        if ($order && Plugin::enabled('enable_ticket_resend') && AttachMe::is_active()) {
            AttachMe::sync_order($order);
            $email = self::delivery_email();
            if ($email && $order->get_meta('_enovos_wcam_synced_package_ids')) {
                DeliveryTracking::begin_resend($order_id);
                try {
                    $email->trigger($order_id, $order);
                    $result = DeliveryTracking::finish_resend() ? 'sent' : 'triggered';
                } catch (\Throwable $exception) {
                    DeliveryTracking::finish_resend();
                    Logger::log('FAIL', 'Ticket email resend failed', [
                        'order_id' => $order_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        wp_safe_redirect(add_query_arg(
            'enovos_ticket_resend',
            $result,
            $order ? $order->get_edit_order_url() : admin_url('admin.php?page=wc-orders')
        ));
        exit;
    }

    public static function render_notice(): void {
        $result = sanitize_key((string) ($_GET['enovos_ticket_resend'] ?? ''));
        if (!in_array($result, ['sent', 'triggered', 'failed'], true)) {
            return;
        }
        if ($result === 'failed') {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo esc_html__('The ticket email could not be resent. Check Attach Me! and the order log.', 'enovos-ticket-shop');
            echo '</p></div>';
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html__('The WooCommerce ticket email was triggered again with the existing Attach Me! files.', 'enovos-ticket-shop');
        echo '</p></div>';
    }

    private static function resolve_order($post_or_order): ?\WC_Order {
        if ($post_or_order instanceof \WC_Order) {
            return $post_or_order;
        }
        if ($post_or_order instanceof \WP_Post) {
            $order = wc_get_order($post_or_order->ID);
            return $order instanceof \WC_Order ? $order : null;
        }
        return null;
    }

    private static function can_resend(array $packages): bool {
        if (!Plugin::enabled('enable_ticket_resend') || !AttachMe::is_active()) {
            return false;
        }
        foreach ($packages as $package) {
            if ((string) $package['status'] !== TicketInventory::STATUS_DELIVERED) {
                return false;
            }
        }
        return true;
    }

    private static function delivery_email(): ?\WC_Email {
        if (!function_exists('WC') || !WC()->mailer()) {
            return null;
        }
        $settings = Plugin::settings();
        $expected_id = ($settings['delivery_order_status'] ?? 'completed') === 'processing'
            ? 'customer_processing_order'
            : 'customer_completed_order';
        foreach (WC()->mailer()->get_emails() as $email) {
            if ($email instanceof \WC_Email && $email->id === $expected_id) {
                return $email;
            }
        }
        return null;
    }
}
