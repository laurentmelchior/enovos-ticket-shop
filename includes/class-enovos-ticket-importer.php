<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class Importer {
    public static function build_products(array $events, array $settings): array {
        $created = [];
        $errors = [];
        $used_image_urls = [];

        foreach ($events as $event) {
            Logger::log('START', 'Processing event', ['title' => $event['title'] ?? '']);
            $price_accepted = !empty($event['price_verified']) || !empty($event['price_approved']);
            if (!$price_accepted || (float)($event['price_per_ticket'] ?? 0) <= 0) {
                $event = AI::enrich_atelier($event, $settings, array_keys($used_image_urls));
                Logger::log('STEP', 'Atelier data received', ['title' => $event['title'] ?? '', 'price' => $event['price_per_ticket'] ?? 0]);
                $price_verification = AI::verify_atelier_price($event, $settings);
                if (is_wp_error($price_verification)) {
                    Logger::log('FAIL', 'Price verification failed; product creation skipped', [
                        'title' => $event['title'] ?? '',
                        'error' => $price_verification->get_error_message(),
                    ]);
                    $errors[] = ['event' => $event, 'error' => $price_verification->get_error_message()];
                    continue;
                }
                $event = array_merge($event, $price_verification);
            } else {
                Logger::log('STEP', 'Using reviewed ticket price from import check', [
                    'title' => $event['title'] ?? '',
                    'price' => $event['price_per_ticket'] ?? 0,
                    'method' => $event['price_verification_method'] ?? '',
                ]);
            }
            if (!empty($event['image_url'])) {
                $image_key = AI::image_key((string) $event['image_url']);
                if (isset($used_image_urls[$image_key])) {
                    Logger::log('FAIL', 'Duplicate concert image rejected during product import', [
                        'title' => $event['title'] ?? '',
                        'url' => $event['image_url'],
                        'already_used_by' => $used_image_urls[$image_key],
                    ]);
                    $event['image_url'] = '';
                    $event['image_source'] = 'rejected-duplicate';
                } else {
                    $used_image_urls[$image_key] = (string) ($event['title'] ?? '');
                }
            }
            $event['price_per_ticket'] = AI::round_price_up((float) ($event['price_per_ticket'] ?? 0));
            Logger::log('OK', 'Reviewed ticket price applied to event after upward rounding', [
                'title' => $event['title'] ?? '',
                'price_per_ticket' => $event['price_per_ticket'],
                'verified_price_before_rounding' => $event['verified_price_before_rounding'] ?? null,
                'source' => $event['price_source'] ?? '',
                'method' => $event['price_verification_method'] ?? '',
            ]);

            $result = self::create_product($event, $settings);
            if (is_wp_error($result)) {
                Logger::log('FAIL', 'Event could not be imported', ['title' => $event['title'] ?? '', 'error' => $result->get_error_message()]);
                $errors[] = ['event' => $event, 'error' => $result->get_error_message()];
            } else {
                $created[] = $result;
                Logger::log('OK', 'Event created as WooCommerce product', ['title' => $result['title'] ?? '', 'product_id' => $result['id'] ?? 0]);
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    private static function create_product(array $event, array $settings) {
        Logger::log('STEP', 'Validating event data', ['title' => $event['title'] ?? '', 'date' => $event['date'] ?? '', 'tickets' => $event['ticket_count'] ?? 0]);
        if (empty($event['title']) || empty($event['date'])) {
            return new \WP_Error('invalid_event', 'Title or date is missing.');
        }
        $derived_stock = (int) floor((int) ($event['ticket_count'] ?? 0) / 2);
        $product_quantity = isset($event['product_quantity']) ? (int) $event['product_quantity'] : 0;
        if ($product_quantity <= 0) {
            if ($derived_stock <= 0) {
                return new \WP_Error('invalid_product_quantity', 'Product quantity is missing. Set a valid Product quantity in the import check.');
            }
            $product_quantity = $derived_stock;
        }
        $event['product_quantity'] = min(9999, $product_quantity);
        if ($event['product_quantity'] <= 0) {
            return new \WP_Error('invalid_product_quantity', 'Product quantity must be greater than zero.');
        }
        if (
            empty($event['price_per_ticket'])
            || $event['price_per_ticket'] <= 0
            || (empty($event['price_verified']) && empty($event['price_approved']))
        ) {
            return new \WP_Error('invalid_price', 'A positive reviewed ticket price is required. No product was created.');
        }

        Logger::log('STEP', 'Checking for an existing product');
        $existing = self::find_existing_product($event['title'], $event['date']);
        if ($existing) {
            $existing_product = wc_get_product($existing);
            if ($existing_product && (float) $existing_product->get_regular_price() <= 0 && (float) $event['price_per_ticket'] > 0) {
                $existing_product->set_regular_price(wc_format_decimal($event['price_per_ticket']));
                $existing_product->set_price(wc_format_decimal($event['price_per_ticket']));
                $existing_product->set_manage_stock(true);
                $existing_product->set_stock_quantity((int) $event['product_quantity']);
                $existing_product->set_stock_status('instock');
                $existing_product->set_sold_individually(true);
                $existing_product->save();
                update_post_meta($existing, '_enovos_price_repaired_at', current_time('mysql'));
                update_post_meta($existing, '_enovos_price_verified', !empty($event['price_verified']) ? 1 : 0);
                update_post_meta($existing, '_enovos_price_approved', !empty($event['price_approved']) ? 1 : 0);
                update_post_meta($existing, '_enovos_price_source', sanitize_text_field($event['price_source'] ?? ''));
                update_post_meta($existing, '_enovos_price_verification_method', sanitize_text_field($event['price_verification_method'] ?? ''));
                update_post_meta($existing, '_enovos_product_quantity', (int) $event['product_quantity']);
                update_post_meta($existing, '_enovos_product_quantity_source', 'manual_override');
                self::update_acf_fields($existing, $event);
                $category_result = self::assign_den_atelier_category($existing);
                if (is_wp_error($category_result)) {
                    Logger::log('FAIL', 'Product category could not be assigned', ['product_id' => $existing, 'error' => $category_result->get_error_message()]);
                } else {
                    Logger::log('OK', 'Product category assigned', ['product_id' => $existing, 'category_slug' => 'den-atelier']);
                }
                Logger::log('OK', 'Existing product ACF fields updated', ['product_id' => $existing]);
                Logger::log('OK', 'Existing zero-price product repaired with verified Atelier price', [
                    'product_id' => $existing,
                    'price_per_ticket' => (float) $event['price_per_ticket'],
                    'source' => $event['price_source'] ?? '',
                ]);
                return [
                    'id' => $existing,
                    'title' => $event['title'],
                    'stock' => (int) $event['product_quantity'],
                    'price' => (float) $event['price_per_ticket'],
                    'status' => $existing_product->get_status(),
                    'repaired' => true,
                    'manual_quantity' => true,
                    'event_key' => (string)($event['_import_key'] ?? ''),
                ];
            }
            return new \WP_Error('duplicate_product', 'Product already exists: #' . $existing);
        }

        Logger::log('STEP', 'Creating WooCommerce product', ['stock' => (int) $event['product_quantity'], 'price' => $event['price_per_ticket']]);
        $product = new \WC_Product_Simple();
        $product->set_name(sanitize_text_field($event['title']));
        $product->set_description($event['description'] ?? '');
        $product->set_status(!empty($settings['publish_products']) ? 'publish' : 'draft');
        $product->set_regular_price(wc_format_decimal($event['price_per_ticket']));
        $product->set_manage_stock(true);
        $product->set_stock_quantity((int) $event['product_quantity']);
        $product->set_stock_status('instock');
        $product->set_sold_individually(true);
        $product->set_catalog_visibility('visible');

        $product_id = $product->save();
        Logger::log('STEP', 'WooCommerce product saved', ['product_id' => $product_id]);
        if (!$product_id) {
            return new \WP_Error('product_create_failed', 'WooCommerce could not save the product.');
        }

        update_post_meta($product_id, '_enovos_ticket_count', (int)$event['ticket_count']);
        update_post_meta($product_id, '_enovos_ticket_import_source', sanitize_text_field($settings['_source_filename'] ?? ''));
        update_post_meta($product_id, '_enovos_ticket_quantity_per_product', 2);
        update_post_meta($product_id, '_enovos_product_quantity', (int) $event['product_quantity']);
        update_post_meta($product_id, '_enovos_product_quantity_source', isset($event['product_quantity']) && (int) $event['product_quantity'] !== (int) $derived_stock ? 'manual_override' : 'derived_from_ticket_count');
        update_post_meta($product_id, '_enovos_price_verified', !empty($event['price_verified']) ? 1 : 0);
        update_post_meta($product_id, '_enovos_price_approved', !empty($event['price_approved']) ? 1 : 0);
        update_post_meta($product_id, '_enovos_price_source', sanitize_text_field($event['price_source'] ?? ''));
        update_post_meta($product_id, '_enovos_price_verification_method', sanitize_text_field($event['price_verification_method'] ?? ''));
        update_post_meta($product_id, '_enovos_ai_providers', wp_json_encode($event['_ai_providers'] ?? []));
        update_post_meta($product_id, '_enovos_ai_confidence', (float)($event['_ai_confidence'] ?? 0));

        self::update_acf_fields($product_id, $event);

        Logger::log('STEP', 'Assigning product category den-atelier');
        $category_result = self::assign_den_atelier_category($product_id);
        if (is_wp_error($category_result)) {
            update_post_meta($product_id, '_enovos_category_warning', $category_result->get_error_message());
            Logger::log('FAIL', 'Product category could not be assigned', ['product_id' => $product_id, 'error' => $category_result->get_error_message()]);
        } else {
            Logger::log('OK', 'Product category assigned', ['product_id' => $product_id, 'category_slug' => 'den-atelier']);
        }

        Logger::log('STEP', 'Setting tax class VAT 3%');
        $tax_result = self::apply_tax_class($product_id);
        if (is_wp_error($tax_result)) {
            update_post_meta($product_id, '_enovos_tax_warning', $tax_result->get_error_message());
            Logger::log('FAIL', 'Tax class could not be set', ['product_id' => $product_id, 'error' => $tax_result->get_error_message()]);
        }

        if (!empty($event['image_url'])) {
            Logger::log('STEP', 'Importing artist/group product image', [
                'product_id' => $product_id,
                'image_url' => $event['image_url'],
                'image_source' => $event['image_source'] ?? '',
            ]);
            $image_result = self::set_featured_image($product_id, $event['image_url'], $event['title']);
            if (is_wp_error($image_result)) {
                update_post_meta($product_id, '_enovos_image_warning', $image_result->get_error_message());
                Logger::log('FAIL', 'Artist/group product image import failed', [
                    'product_id' => $product_id,
                    'error' => $image_result->get_error_message(),
                ]);
            } else {
                Logger::log('OK', 'Artist/group product image imported', [
                    'product_id' => $product_id,
                    'attachment_id' => (int) $image_result,
                ]);
            }
        } else {
            Logger::log('FAIL', 'No verified artist/group image available; product created without featured image', ['product_id' => $product_id]);
        }

        Logger::log('OK', 'Product created successfully', ['product_id' => $product_id, 'title' => $event['title']]);
        return [
            'id' => $product_id,
            'title' => $event['title'],
            'stock' => (int) $event['product_quantity'],
            'price' => (float)$event['price_per_ticket'],
            'status' => !empty($settings['publish_products']) ? 'publish' : 'draft',
            'event_key' => (string)($event['_import_key'] ?? ''),
        ];
    }

    private static function assign_den_atelier_category(int $product_id) {
        $slug = 'den-atelier';
        $term = get_term_by('slug', $slug, 'product_cat');
        if (!$term || is_wp_error($term)) {
            $term_result = wp_insert_term('den Atelier', 'product_cat', ['slug' => $slug]);
            if (is_wp_error($term_result)) {
                return $term_result;
            }
            $term_id = (int) $term_result['term_id'];
            Logger::log('OK', 'Created missing product category', ['term_id' => $term_id, 'name' => 'den Atelier', 'slug' => $slug]);
        } else {
            $term_id = (int) $term->term_id;
        }

        if (!$term_id) {
            return new \WP_Error('category_missing', 'The den-atelier product category could not be resolved.');
        }

        $assigned = wp_set_post_terms($product_id, [$term_id], 'product_cat', false);
        if (is_wp_error($assigned)) {
            return $assigned;
        }

        update_post_meta($product_id, '_enovos_product_category_slug', $slug);
        return $term_id;
    }

    private static function update_acf_fields(int $product_id, array $event): void {
        $date = (string) ($event['date'] ?? '');
        $atelier_url = esc_url_raw((string) ($event['atelier_url'] ?? ''));

        if (!function_exists('update_field')) {
            update_post_meta($product_id, 'date_of_concert', $date);
            update_post_meta($product_id, 'more_about_concert', $atelier_url);
            Logger::log('STEP', 'ACF not available; fallback post meta written', [
                'product_id' => $product_id,
                'date_of_concert' => $date,
                'more_about_concert' => $atelier_url,
            ]);
            return;
        }

        $date_value = '';
        if ($date !== '') {
            $timestamp = strtotime($date . ' 00:00:00');
            if ($timestamp !== false) {
                $date_value = date('Ymd', $timestamp);
            }
        }

        $date_updated = update_field('date_of_concert', $date_value, $product_id);

        $link_value = $atelier_url;
        $link_object = function_exists('get_field_object') ? get_field_object('more_about_concert', $product_id, false, false) : false;
        $link_type = is_array($link_object) ? (string) ($link_object['type'] ?? '') : '';

        // ACF Link fields require an array; URL/Text fields expect a plain URL string.
        if ($link_type === 'link' && $atelier_url !== '') {
            $link_value = [
                'url' => $atelier_url,
                'title' => 'More about this concert',
                'target' => '_blank',
            ];
        }

        $link_updated = update_field('more_about_concert', $link_value, $product_id);

        // Always keep a post-meta fallback as a diagnostic/reference value.
        update_post_meta($product_id, 'more_about_concert', $atelier_url);
        update_post_meta($product_id, 'date_of_concert', $date);

        Logger::log('OK', 'ACF fields updated', [
            'product_id' => $product_id,
            'date_field_type' => is_array($link_object) ? 'detected' : 'unknown',
            'more_about_concert_field_type' => $link_type ?: 'unknown',
            'date_of_concert_value' => $date_value,
            'more_about_concert_url' => $atelier_url,
            'date_update_result' => (bool) $date_updated,
            'link_update_result' => (bool) $link_updated,
        ]);
    }

    private static function apply_tax_class(int $product_id) {
        // WooCommerce stores tax classes by slug, not by display label.
        $class_name = 'VAT 3%';
        $tax_class = \WC_Tax::get_tax_class_by('name', $class_name);
        if (!$tax_class || empty($tax_class['slug'])) {
            return new \WP_Error('tax_class_missing', 'WooCommerce tax class "VAT 3%" was not found.');
        }

        $tax_class_slug = (string) $tax_class['slug'];
        $rates = \WC_Tax::get_rates_for_tax_class($tax_class_slug);
        if (!$rates) {
            return new \WP_Error('tax_rate_missing', 'No tax rate was found for tax class "VAT 3%".');
        }

        update_post_meta($product_id, '_enovos_required_tax_class', $class_name);
        update_post_meta($product_id, '_enovos_tax_class_slug', $tax_class_slug);

        $product = wc_get_product($product_id);
        if ($product) {
            $product->set_tax_status('taxable');
            $product->set_tax_class($tax_class_slug);
            $product->save();
        }
        return true;
    }

    private static function find_existing_product(string $title, string $date): int {
        global $wpdb;
        $title = sanitize_text_field($title);
        if ($title === '') {
            return 0;
        }
        $date_candidates = array_values(array_unique(array_filter([
            str_replace('-', '', $date),
            $date,
        ])));
        foreach ($date_candidates as $date_value) {
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm
                    ON p.ID = pm.post_id
                   AND pm.meta_key = 'date_of_concert'
                   AND pm.meta_value = %s
                 WHERE p.post_type = 'product'
                   AND p.post_status IN ('publish','draft','pending','private')
                   AND p.post_title = %s
                 ORDER BY p.ID ASC
                 LIMIT 1",
                $date_value,
                $title
            ));
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }

    private static function set_featured_image(int $product_id, string $image_url, string $title) {
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $image_url = esc_url_raw($image_url);
        if (!$image_url) {
            return new \WP_Error('image_url_invalid', 'Artist/group image URL is invalid.');
        }
        $attachment_id = media_sideload_image($image_url, $product_id, $title . ' - Artist Image', 'id');
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        if (!$attachment_id) {
            return new \WP_Error('image_import_empty', 'WordPress did not return an attachment ID for the artist/group image.');
        }
        $set = set_post_thumbnail($product_id, (int) $attachment_id);
        if (!$set) {
            return new \WP_Error('image_thumbnail_failed', 'WordPress could not set the artist/group image as the featured image.');
        }
        return (int) $attachment_id;
    }
}
