<?php
/**
 * Plugin Name: Enovos WooCommerce Addons
 * Description: Adds ticket importing, protected ticket delivery, and customer approval workflows to WooCommerce.
 * Version: 0.7.2
 * Author: Enovos Digital Marketing / Bromance INC
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: enovos-ticket-shop
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ENOVOS_TICKET_SHOP_VERSION', '0.7.2');
define('ENOVOS_TICKET_SHOP_FILE', __FILE__);
define('ENOVOS_TICKET_SHOP_DIR', plugin_dir_path(__FILE__));
define('ENOVOS_TICKET_SHOP_URL', plugin_dir_url(__FILE__));

$enovos_ticket_shop_autoload = ENOVOS_TICKET_SHOP_DIR . 'vendor/autoload.php';
if (is_readable($enovos_ticket_shop_autoload)) {
    require_once $enovos_ticket_shop_autoload;
}

require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-logger.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-customer-approval.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-customer-approval-admin.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-email-template-editor.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-shop.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-pdf.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-pdf-text.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-inventory.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-attach-me.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-attach-me-repair.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-woocommerce-compatibility.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-delivery-tracking.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-order-tickets.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-system-check.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-ai.php';
require_once ENOVOS_TICKET_SHOP_DIR . 'includes/class-enovos-ticket-importer.php';

\Enovos\TicketShop\WooCommerceCompatibility::init();

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
