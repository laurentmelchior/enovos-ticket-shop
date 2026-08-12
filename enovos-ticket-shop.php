<?php
/**
 * Plugin Name: Enovos Concert Ticket Shop Importer
 * Description: Imports concert ticket PDFs into WooCommerce products using OpenAI, Gemini and a configurable Custom AI provider, with Atelier enrichment and ACF mapping.
 * Version: 0.3.7
 * Author: Enovos EU
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: enovos-ticket-shop
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ENOVOS_TICKET_SHOP_VERSION', '0.3.7');
define('ENOVOS_TICKET_SHOP_FILE', __FILE__);
define('ENOVOS_TICKET_SHOP_DIR', plugin_dir_path(__FILE__));
define('ENOVOS_TICKET_SHOP_URL', plugin_dir_url(__FILE__));

$enovos_ticket_shop_autoload = ENOVOS_TICKET_SHOP_DIR . 'vendor/autoload.php';
if (is_readable($enovos_ticket_shop_autoload)) {
    require_once $enovos_ticket_shop_autoload;
}

require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-logger.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-shop.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-pdf.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-inventory.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-attach-me.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-ai.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-importer.php';

require_once ENOVOS_TICKET_SHOP_DIR . 'plugin-update-checker/plugin-update-checker.php';

\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://wp-plugins.enovos-data.info/enovos-ticket-shop/update.json',
    __FILE__,
    'enovos-ticket-shop'
);

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
