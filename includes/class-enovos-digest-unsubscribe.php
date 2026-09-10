<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class DigestUnsubscribe {
    private const ACF_FIELD_KEY = 'field_6a450c952f711';
    public const FIELD_NAME = 'send_daily_digest';
    private const LINK_LIFETIME = 30 * DAY_IN_SECONDS;
    private const QUERY_VAR = 'enovos_digest_unsubscribe';
    private const FORM_ACTION = 'enovos_digest_unsubscribe';

    public static function init(): void {
        add_action('template_redirect', [self::class, 'render_confirmation_page']);
        add_action('admin_post_' . self::FORM_ACTION, [self::class, 'handle_unsubscribe']);
        add_action('admin_post_nopriv_' . self::FORM_ACTION, [self::class, 'handle_unsubscribe']);
    }

    public static function unsubscribe_url(\WP_User $user): string {
        $expires = time() + self::LINK_LIFETIME;
        return add_query_arg([
            self::QUERY_VAR => '1',
            'user_id' => $user->ID,
            'expires' => $expires,
            'signature' => self::signature($user, $expires),
        ], home_url('/'));
    }

    public static function render_confirmation_page(): void {
        if (sanitize_key((string) ($_GET[self::QUERY_VAR] ?? '')) !== '1') {
            return;
        }

        $status = sanitize_key((string) ($_GET['status'] ?? ''));
        $user_id = absint($_GET['user_id'] ?? 0);
        $expires = absint($_GET['expires'] ?? 0);
        $signature = sanitize_text_field(wp_unslash((string) ($_GET['signature'] ?? '')));
        $user = $status === 'success' ? null : self::validate_signature($user_id, $expires, $signature);
        $state = $status === 'success' ? 'success' : ($user instanceof \WP_User ? 'confirm' : 'invalid');

        nocache_headers();
        status_header($state === 'invalid' ? 400 : 200);
        get_header();
        wc_get_template('myaccount/enovos-digest-unsubscribe.php', [
            'state' => $state,
            'user_id' => $user instanceof \WP_User ? $user->ID : 0,
            'expires' => $expires,
            'signature' => $signature,
            'form_action' => self::FORM_ACTION,
        ], '', ENOVOS_TICKET_SHOP_DIR . 'templates/');
        get_footer();
        exit;
    }

    public static function handle_unsubscribe(): void {
        $user_id = absint($_POST['user_id'] ?? 0);
        check_admin_referer(self::FORM_ACTION . '_' . $user_id, 'enovos_digest_unsubscribe_nonce');

        $expires = absint($_POST['expires'] ?? 0);
        $signature = sanitize_text_field(wp_unslash((string) ($_POST['signature'] ?? '')));
        $user = self::validate_signature($user_id, $expires, $signature);
        if (!$user instanceof \WP_User) {
            wp_safe_redirect(self::status_url('invalid'));
            exit;
        }

        self::unsubscribe_user($user);

        wp_safe_redirect(self::status_url('success'));
        exit;
    }

    private static function unsubscribe_user(\WP_User $user): void {
        if (function_exists('update_field')) {
            update_field(self::ACF_FIELD_KEY, false, 'user_' . $user->ID);
        }
        update_user_meta($user->ID, self::FIELD_NAME, '0');
    }

    private static function validate_signature(int $user_id, int $expires, string $signature): ?\WP_User {
        if ($user_id <= 0 || $expires < time() || $signature === '') {
            return null;
        }
        $user = get_userdata($user_id);
        if (!$user instanceof \WP_User) {
            return null;
        }
        $expected = self::signature($user, $expires);
        return hash_equals($expected, $signature) ? $user : null;
    }

    private static function signature(\WP_User $user, int $expires): string {
        return hash_hmac(
            'sha256',
            $user->ID . '|' . strtolower($user->user_email) . '|' . $expires,
            wp_salt('auth')
        );
    }

    private static function status_url(string $status): string {
        return add_query_arg([
            self::QUERY_VAR => '1',
            'status' => $status,
        ], home_url('/'));
    }
}
