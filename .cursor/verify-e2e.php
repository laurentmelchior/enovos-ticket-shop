<?php
/**
 * End-to-end verification for the Enovos Concert Ticket Shop Importer.
 *
 * Run with: wp eval-file .cursor/verify-e2e.php --path=$WP_DIR
 *
 * Exercises the plugin's real code paths without any AI provider:
 *  - PDF engine detection (Poppler)
 *  - Two-ticket PDF package generation (pdfseparate + pdfunite)
 *  - Ticket inventory persistence
 *  - WooCommerce order reservation -> email attachment/delivery
 *  - Cancelled-order release and refunded-order invalidation
 *
 * Prints a PASS/FAIL line per check and exits non-zero if any check fails.
 */

namespace Enovos\TicketShop;

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " -> {$detail}" : '');
    if (!$ok) {
        $GLOBALS['__e2e_failures'] = ($GLOBALS['__e2e_failures'] ?? 0) + 1;
    }
};
$GLOBALS['__e2e_failures'] = 0;

$pdf_pages = static function (string $path): int {
    if (!is_file($path)) {
        return -1;
    }
    $out = [];
    $code = 1;
    @exec('pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null', $out, $code);
    foreach ($out as $line) {
        if (preg_match('/^Pages:\s+(\d+)/', $line, $m)) {
            return (int) $m[1];
        }
    }
    return -1;
};

echo "=== Enovos Ticket Shop :: end-to-end verification ===\n\n";

/* -------------------------------------------------------------------------
 * 0. Preconditions
 * ---------------------------------------------------------------------- */
$check('WooCommerce active', class_exists('WooCommerce'));
$check('Plugin loaded', class_exists(__NAMESPACE__ . '\\Plugin'));
$check('Inventory table exists', (function (): bool {
    global $wpdb;
    $t = TicketInventory::table();
    return $wpdb->get_var("SHOW TABLES LIKE '{$t}'") === $t;
})());

/* -------------------------------------------------------------------------
 * 1. PDF engine
 * ---------------------------------------------------------------------- */
$engine = PdfPackages::engine_status();
$check('PDF engine available', !empty($engine['available']), $engine['engine'] . ': ' . $engine['message']);
$check('Poppler is the active engine', ($engine['engine'] ?? '') === 'poppler');

$source_pdf = getenv('DEMO_PDF') ?: (getenv('HOME') . '/sample-pdf/tickets.pdf');
$check('Sample source PDF readable', is_readable($source_pdf), $source_pdf . ' (' . $pdf_pages($source_pdf) . ' pages)');

/* -------------------------------------------------------------------------
 * Idempotent cleanup of any previous demo run
 * ---------------------------------------------------------------------- */
global $wpdb;
$prev = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_enovos_demo' AND meta_value='1')");
foreach ($prev as $pid) {
    wp_delete_post((int) $pid, true);
}
$wpdb->query("DELETE FROM " . TicketInventory::table() . " WHERE event_key='demo-key'");

/* -------------------------------------------------------------------------
 * 2. Create a WooCommerce ticket product
 * ---------------------------------------------------------------------- */
$product = new \WC_Product_Simple();
$product->set_name('DEMO Concert - den Atelier');
$product->set_status('publish');
$product->set_regular_price('45');
$product->set_price('45');
$product->set_sold_individually(true);
$product->set_manage_stock(true);
$product->set_stock_quantity(2);
$product->set_tax_class('vat-3');
$product->save();
$product_id = $product->get_id();
update_post_meta($product_id, '_enovos_ticket_quantity_per_product', 2);
update_post_meta($product_id, '_enovos_demo', '1');
$cat = get_term_by('slug', 'den-atelier', 'product_cat');
if ($cat) {
    wp_set_object_terms($product_id, [(int) $cat->term_id], 'product_cat');
}
$check('Product created', $product_id > 0, 'product #' . $product_id);

/* -------------------------------------------------------------------------
 * 3. Generate two-ticket PDF packages from real PDF pages (Poppler)
 * ---------------------------------------------------------------------- */
$import_id = 'DEMO-' . gmdate('YmdHis');
$event = [
    '_import_key'      => 'demo-key',
    'page_numbers'     => [1, 2, 3, 4],
    'product_quantity' => 2,
    'title'            => 'DEMO Concert - den Atelier',
    'date'             => '2026-09-01',
];
$created = TicketInventory::create_packages($product_id, $event, $source_pdf, $import_id);
$check('create_packages() succeeded', !is_wp_error($created), is_wp_error($created) ? $created->get_error_message() : ($created . ' packages'));
$check('Two packages generated', $created === 2);

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id,pdf_path,ticket_pages,status FROM " . TicketInventory::table() . " WHERE product_id=%d ORDER BY id ASC",
    $product_id
), ARRAY_A);
$check('Two AVAILABLE inventory rows', count($rows) === 2 && $rows[0]['status'] === 'AVAILABLE' && $rows[1]['status'] === 'AVAILABLE');
foreach ($rows as $i => $r) {
    $pages = $pdf_pages($r['pdf_path']);
    $check(sprintf('Package %d PDF has 2 pages (pages %s)', $i + 1, $r['ticket_pages']), $pages === 2, basename($r['pdf_path']) . " ({$pages} pages, " . (is_file($r['pdf_path']) ? filesize($r['pdf_path']) : 0) . ' bytes)');
}

$counts = TicketInventory::counts_for_product($product_id);
$check('Inventory counts: AVAILABLE=2', $counts['AVAILABLE'] === 2, json_encode($counts));

/* -------------------------------------------------------------------------
 * 4. Order reservation + delivery via WooCommerce email filter
 * ---------------------------------------------------------------------- */
$order = wc_create_order();
$order->add_product(wc_get_product($product_id), 1);
$order->set_address(['first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'buyer@example.com'], 'billing');
$order->calculate_totals();
$order->save();
$order_id = $order->get_id();
$check('WooCommerce order created', $order_id > 0, 'order #' . $order_id);

TicketInventory::reserve_for_order($order);
$counts = TicketInventory::counts_for_product($product_id);
$check('After reservation: RESERVED=1, AVAILABLE=1', $counts['RESERVED'] === 1 && $counts['AVAILABLE'] === 1, json_encode($counts));

$order = wc_get_order($order_id);
$pkg_ids = (array) $order->get_meta('_enovos_ticket_package_ids');
$check('Order meta records reserved package', count(array_filter($pkg_ids)) === 1, 'package ids: ' . implode(',', $pkg_ids));

// Exercise the real WooCommerce email attachment hook wiring.
$attachments = apply_filters('woocommerce_email_attachments', [], 'customer_processing_order', $order, null);
$check('Ticket PDF attached to processing email', count($attachments) === 1 && is_readable($attachments[0]), implode(',', array_map('basename', $attachments)));

$counts = TicketInventory::counts_for_product($product_id);
$check('After delivery: DELIVERED=1', $counts['DELIVERED'] === 1, json_encode($counts));

/* -------------------------------------------------------------------------
 * 5. Cancelled order releases the remaining reserved package
 * ---------------------------------------------------------------------- */
$order2 = wc_create_order();
$order2->add_product(wc_get_product($product_id), 1);
$order2->set_address(['first_name' => 'Second', 'last_name' => 'Buyer', 'email' => 'buyer2@example.com'], 'billing');
$order2->calculate_totals();
$order2->save();
$order2_id = $order2->get_id();
TicketInventory::reserve_for_order($order2);
$counts = TicketInventory::counts_for_product($product_id);
$check('Second order reserves last package (AVAILABLE=0)', $counts['AVAILABLE'] === 0 && $counts['RESERVED'] === 1, json_encode($counts));

TicketInventory::release_order($order2_id);
$counts = TicketInventory::counts_for_product($product_id);
$check('Cancel releases package back to AVAILABLE=1', $counts['AVAILABLE'] === 1, json_encode($counts));

/* -------------------------------------------------------------------------
 * 6. Refund invalidates the delivered package (never resold)
 * ---------------------------------------------------------------------- */
TicketInventory::invalidate_refunded_order($order_id);
$counts = TicketInventory::counts_for_product($product_id);
$check('Refund invalidates delivered package (INVALIDATED=1)', $counts['INVALIDATED'] === 1, json_encode($counts));

/* -------------------------------------------------------------------------
 * Summary
 * ---------------------------------------------------------------------- */
echo "\n=== Final inventory for product #{$product_id} ===\n";
$final = $wpdb->get_results($wpdb->prepare(
    "SELECT id,package_no,ticket_pages,status,order_id FROM " . TicketInventory::table() . " WHERE product_id=%d ORDER BY id ASC",
    $product_id
), ARRAY_A);
foreach ($final as $r) {
    printf("  package #%s  pages %-5s  %-11s  order %s\n", $r['package_no'], $r['ticket_pages'], $r['status'], $r['order_id'] ?: '-');
}

$fail = (int) ($GLOBALS['__e2e_failures'] ?? 0);
echo "\n";
if ($fail === 0) {
    echo "RESULT: ALL CHECKS PASSED\n";
} else {
    echo "RESULT: {$fail} CHECK(S) FAILED\n";
    exit(1);
}
