<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only preflight checks for the ticket-import configuration.
 */
final class SystemCheck {
    public static function render(): void {
        $checks = self::checks();
        echo '<div class="enovos-card"><div class="enovos-card__header">';
        echo '<h2>' . esc_html__('System check', 'enovos-ticket-shop') . '</h2>';
        echo '<p>' . esc_html__('Review these requirements before importing a ticket PDF.', 'enovos-ticket-shop') . '</p>';
        echo '</div><div class="enovos-card__body"><ul class="enovos-system-check">';
        foreach ($checks as $check) {
            echo '<li>';
            echo '<span class="enovos-check-status ' . ($check['ok'] ? 'is-ok' : 'is-error') . '">';
            echo esc_html($check['ok'] ? __('OK', 'enovos-ticket-shop') : __('Action required', 'enovos-ticket-shop'));
            echo '</span>';
            echo '<div><strong>' . esc_html($check['label']) . '</strong>';
            echo '<span>' . esc_html($check['message']) . '</span></div>';
            echo '</li>';
        }
        echo '</ul></div></div>';
    }

    private static function checks(): array {
        $settings = Plugin::settings();
        $engine = PdfPackages::engine_status();
        $uploads = wp_upload_dir();
        $private_dir = empty($uploads['basedir'])
            ? ''
            : trailingslashit((string) $uploads['basedir']) . 'woocommerce_uploads/enovos-ticket-shop';
        $writable_path = is_dir($private_dir) ? $private_dir : (string) ($uploads['basedir'] ?? '');
        $tax_classes = class_exists(\WC_Tax::class) ? \WC_Tax::get_tax_classes() : [];
        $category = taxonomy_exists('product_cat') ? get_term_by('slug', 'den-atelier', 'product_cat') : false;

        return [
            [
                'ok' => !empty($engine['available']),
                'label' => __('PDF engine', 'enovos-ticket-shop'),
                'message' => (string) ($engine['message'] ?? __('No PDF engine detected.', 'enovos-ticket-shop')),
            ],
            [
                'ok' => in_array('VAT 3%', $tax_classes, true),
                'label' => __('WooCommerce tax class', 'enovos-ticket-shop'),
                'message' => in_array('VAT 3%', $tax_classes, true)
                    ? __('Tax class “VAT 3%” exists.', 'enovos-ticket-shop')
                    : __('Create a WooCommerce tax class named exactly “VAT 3%”.', 'enovos-ticket-shop'),
            ],
            [
                'ok' => $category instanceof \WP_Term,
                'label' => __('Product category', 'enovos-ticket-shop'),
                'message' => $category instanceof \WP_Term
                    ? __('Product category “den-atelier” exists.', 'enovos-ticket-shop')
                    : __('Create the product category with slug “den-atelier”.', 'enovos-ticket-shop'),
            ],
            [
                'ok' => self::ai_is_configured($settings),
                'label' => __('AI provider', 'enovos-ticket-shop'),
                'message' => self::ai_is_configured($settings)
                    ? sprintf(__('%s credentials are configured.', 'enovos-ticket-shop'), ucfirst((string) $settings['ai_provider']))
                    : sprintf(__('Configure the required %s credentials.', 'enovos-ticket-shop'), ucfirst((string) $settings['ai_provider'])),
            ],
            [
                'ok' => empty($uploads['error']) && $writable_path !== '' && is_writable($writable_path),
                'label' => __('Protected uploads', 'enovos-ticket-shop'),
                'message' => empty($uploads['error']) && $writable_path !== '' && is_writable($writable_path)
                    ? __('The protected ticket storage location is writable.', 'enovos-ticket-shop')
                    : __('The WordPress uploads directory is not writable.', 'enovos-ticket-shop'),
            ],
            [
                'ok' => !empty($settings['enable_attach_me']) && AttachMe::is_active(),
                'label' => __('Attach Me!', 'enovos-ticket-shop'),
                'message' => !empty($settings['enable_attach_me']) && AttachMe::is_active()
                    ? __('Attach Me! is active and enabled for ticket delivery.', 'enovos-ticket-shop')
                    : __('Activate Attach Me! and enable it in the delivery settings.', 'enovos-ticket-shop'),
            ],
        ];
    }

    private static function ai_is_configured(array $settings): bool {
        return match ($settings['ai_provider'] ?? 'openai') {
            'gemini' => trim((string) ($settings['gemini_api_key'] ?? '')) !== '',
            'custom' => trim((string) ($settings['custom_ai_endpoint'] ?? '')) !== ''
                && (
                    ($settings['custom_ai_auth_type'] ?? 'bearer') === 'none'
                    || trim((string) ($settings['custom_ai_token'] ?? '')) !== ''
                ),
            default => trim((string) ($settings['openai_api_key'] ?? '')) !== '',
        };
    }
}
