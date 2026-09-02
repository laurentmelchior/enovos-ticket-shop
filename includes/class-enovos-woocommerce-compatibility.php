<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce compatibility hooks that must remain active for order safety.
 *
 * This module has no feature toggle: disabling Store API reservation could
 * create paid orders without a ticket package.
 */
final class WooCommerceCompatibility {
    public static function init(): void {
        add_action('before_woocommerce_init', [self::class, 'declare_hpos_compatibility']);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'reserve_store_api_order']);
    }

    public static function declare_hpos_compatibility(): void {
        if (!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            return;
        }
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            ENOVOS_TICKET_SHOP_FILE,
            true
        );
    }

    public static function reserve_store_api_order($order): void {
        if ($order instanceof \WC_Order) {
            TicketInventory::reserve_for_order($order);
        }
    }
}
