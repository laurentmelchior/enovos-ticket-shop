<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Records successful ticket-email delivery in the WooCommerce order notes.
 */
final class DeliveryTracking {
    private static int $resend_order_id = 0;
    private static bool $resend_succeeded = false;

    public static function init(): void {
        add_action('woocommerce_email_sent', [self::class, 'record_sent_email'], 10, 3);
    }

    public static function begin_resend(int $order_id): void {
        self::$resend_order_id = $order_id;
        self::$resend_succeeded = false;
    }

    public static function finish_resend(): bool {
        $succeeded = self::$resend_succeeded;
        self::$resend_order_id = 0;
        self::$resend_succeeded = false;
        return $succeeded;
    }

    public static function record_sent_email($sent, string $email_id, $email): void {
        if (!$sent || !is_object($email) || empty($email->object) || !($email->object instanceof \WC_Order)) {
            return;
        }

        $settings = Plugin::settings();
        $delivery_status = $settings['delivery_order_status'] ?? 'completed';
        $expected_email = $delivery_status === 'processing'
            ? 'customer_processing_order'
            : 'customer_completed_order';
        if ($email_id !== $expected_email) {
            return;
        }

        $order = $email->object;
        $is_resend = self::$resend_order_id === $order->get_id();
        if (!$is_resend && !Plugin::enabled('enable_delivery_order_note')) {
            return;
        }
        $packages = TicketInventory::packages_for_order($order->get_id());
        if (!$packages) {
            return;
        }

        $package_ids = implode(', ', array_map(
            static fn(array $package): string => '#' . (int) $package['id'],
            $packages
        ));
        if ($is_resend) {
            self::$resend_succeeded = true;
        }
        if (Plugin::enabled('enable_delivery_order_note')) {
            $message = $is_resend
                ? sprintf('Enovos WooCommerce Addons: ticket email resent successfully (packages %s).', $package_ids)
                : sprintf('Enovos WooCommerce Addons: ticket email sent successfully (packages %s).', $package_ids);
            $order->add_order_note($message);
        }
    }
}
