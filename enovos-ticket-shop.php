<?php
/**
 * Plugin Name: Enovos Concert Ticket Shop Importer
 * Description: Imports concert ticket PDFs into WooCommerce products using OpenAI, Gemini and a configurable Custom AI provider, with Atelier enrichment and ACF mapping.
 * Version: 0.3.3
 * Author: Enovos EU
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: enovos-ticket-shop
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ENOVOS_TICKET_SHOP_VERSION', '0.3.3');
define('ENOVOS_TICKET_SHOP_FILE', __FILE__);
define('ENOVOS_TICKET_SHOP_DIR', plugin_dir_path(__FILE__));
define('ENOVOS_TICKET_SHOP_URL', plugin_dir_url(__FILE__));

require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-logger.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-shop.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-pdf.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-inventory.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-attach-me.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-ai.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-importer.php';

add_action('plugins_loaded', static function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    \Enovos\TicketShop\Plugin::instance();
});

register_activation_hook(__FILE__, static function () {
    add_option('enovos_ticket_shop_settings', \Enovos\TicketShop\Plugin::defaults());
    \Enovos\TicketShop\TicketInventory::install();
});
