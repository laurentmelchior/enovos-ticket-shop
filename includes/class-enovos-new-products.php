<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class NewProducts {
    /**
     * @var array<string,list<\WC_Product>>
     */
    private static array $cache = [];

    /**
     * @return list<\WC_Product>
     */
    public static function get_products(int $hours, int $limit): array {
        $hours = max(1, min(720, $hours));
        $limit = max(1, min(50, $limit));
        $cache_key = $hours . ':' . $limit;

        if (!isset(self::$cache[$cache_key])) {
            $products = wc_get_products([
                'status' => 'publish',
                'visibility' => 'catalog',
                'date_created' => '>' . (time() - ($hours * HOUR_IN_SECONDS)),
                'orderby' => 'date',
                'order' => 'DESC',
                'limit' => $limit,
            ]);
            self::$cache[$cache_key] = array_values(array_filter(
                is_array($products) ? $products : [],
                static fn($product): bool => $product instanceof \WC_Product
            ));
        }

        return self::$cache[$cache_key];
    }
}
