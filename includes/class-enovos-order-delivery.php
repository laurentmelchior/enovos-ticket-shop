<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Copies the customer ACF delivery location onto every WooCommerce order
 * and shows it on the order screen, customer order details and default emails.
 */
final class OrderDelivery {
    public const META_KEY = '_enovos_delivery';

    public static function init(): void {
        add_action('woocommerce_checkout_order_created', [self::class, 'capture_order']);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'capture_order']);
        add_action('woocommerce_process_shop_order_meta', [self::class, 'capture_order_id'], 40);
        add_action('woocommerce_admin_order_data_after_shipping_address', [self::class, 'render_admin']);
        add_action('woocommerce_order_details_after_customer_details', [self::class, 'render_customer']);
        add_action('woocommerce_email_customer_details', [self::class, 'render_email'], 20, 4);
    }

    /**
     * @param mixed $order
     */
    public static function capture_order($order): void {
        if ($order instanceof \WC_Order) {
            self::store($order);
        }
    }

    public static function capture_order_id(int $order_id): void {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if ($order instanceof \WC_Order) {
            self::store($order);
        }
    }

    public static function for_order(\WC_Order $order): string {
        $stored = self::normalize($order->get_meta(self::META_KEY, true));
        if ($stored !== '') {
            return $stored;
        }
        $user = $order->get_user();
        if (!$user instanceof \WP_User) {
            return '';
        }
        return self::normalize(DigestAdmin::delivery($user->ID));
    }

    /**
     * @param mixed $order
     */
    public static function render_admin($order): void {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $delivery = self::for_order($order);
        echo '<div class="enovos-order-delivery">';
        echo '<p><strong>' . esc_html__('Delivery', 'enovos-ticket-shop') . '</strong></p>';
        echo '<p>' . ($delivery === '' ? '&mdash;' : nl2br(esc_html($delivery))) . '</p>';
        echo '</div>';
    }

    /**
     * @param mixed $order
     */
    public static function render_customer($order): void {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $delivery = self::for_order($order);
        if ($delivery === '') {
            return;
        }
        echo '<section class="woocommerce-customer-details enovos-order-delivery-customer">';
        echo '<h2 class="woocommerce-column__title">' . esc_html__('Delivery', 'enovos-ticket-shop') . '</h2>';
        echo '<address>' . nl2br(esc_html($delivery)) . '</address>';
        echo '</section>';
    }

    /**
     * @param mixed $order
     * @param mixed $sent_to_admin
     * @param mixed $plain_text
     * @param mixed $email
     */
    public static function render_email($order, $sent_to_admin = false, $plain_text = false, $email = null): void {
        unset($sent_to_admin, $email);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $delivery = self::for_order($order);
        if ($delivery === '') {
            return;
        }
        if ($plain_text) {
            echo "\n" . __('Delivery', 'enovos-ticket-shop') . ': ' . $delivery . "\n";
            return;
        }
        echo '<div class="enovos-order-delivery-email" style="margin:16px 0;">';
        echo '<h2>' . esc_html__('Delivery', 'enovos-ticket-shop') . '</h2>';
        echo '<p>' . nl2br(esc_html($delivery)) . '</p>';
        echo '</div>';
    }

    private static function store(\WC_Order $order): void {
        $delivery = self::for_order($order);
        if ($delivery === '') {
            return;
        }
        if (self::normalize($order->get_meta(self::META_KEY, true)) === $delivery) {
            return;
        }
        $order->update_meta_data(self::META_KEY, $delivery);
        $order->save();
    }

    /**
     * @param mixed $value
     */
    private static function normalize(mixed $value): string {
        if (is_array($value)) {
            $label = $value['label'] ?? $value['value'] ?? '';
            $value = is_scalar($label) ? $label : '';
        }
        if (!is_scalar($value)) {
            return '';
        }
        $delivery = trim((string) $value);
        $placeholder = strtolower((string) preg_replace('/\s+/u', ' ', $delivery));
        if ($delivery === '' || in_array($placeholder, ['none', '-- select --', '--select--'], true)) {
            return '';
        }
        return $delivery;
    }
}
