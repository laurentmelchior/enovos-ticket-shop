<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class CustomerApproval {
    public const STATUS_META = '_enovos_approval_status';
    public const STATUS_PENDING_EMAIL = 'pending_email';
    public const STATUS_PENDING_ADMIN = 'pending_admin';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    private const TOKEN_HASH_META = '_enovos_verify_token_hash';
    private const TOKEN_EXPIRES_META = '_enovos_verify_expires';
    private const DOMAIN_META = '_enovos_email_domain';
    private const WHITELISTED_META = '_enovos_whitelisted';
    private const VERIFIED_AT_META = '_enovos_email_verified_at';
    private const APPROVED_AT_META = '_enovos_approved_at';
    private const REJECTED_AT_META = '_enovos_rejected_at';
    private const TOKEN_LIFETIME = 48 * HOUR_IN_SECONDS;
    private const RESEND_LIMIT = 3;
    private const RESEND_WINDOW = 15 * MINUTE_IN_SECONDS;

    public static function init(): void {
        add_action('woocommerce_created_customer', [self::class, 'created_customer'], 10, 1);
        add_filter('woocommerce_registration_auth_new_customer', [self::class, 'prevent_automatic_login'], 10, 2);
        add_filter('woocommerce_registration_redirect', [self::class, 'registration_redirect']);
        add_filter('wp_authenticate_user', [self::class, 'check_login'], 10, 2);
        add_action('template_redirect', [self::class, 'handle_frontend_request']);
        add_filter('the_content', [self::class, 'prepend_registration_confirmation'], 20);
        add_action('woocommerce_after_checkout_validation', [self::class, 'validate_checkout'], 10, 2);
        add_filter('rest_pre_dispatch', [self::class, 'validate_store_api_checkout'], 10, 3);
        add_action('woocommerce_login_form_end', [self::class, 'render_resend_form']);
        add_filter('wc_get_template', [self::class, 'resend_page_template'], 10, 5);
        add_action('admin_post_nopriv_enovos_resend_verification', [self::class, 'handle_resend']);
        add_action('admin_post_enovos_resend_verification', [self::class, 'handle_resend']);
    }

    public static function enabled(): bool {
        return Plugin::enabled('enable_customer_approval');
    }

    public static function created_customer(int $customer_id): void {
        if (!self::enabled()) {
            return;
        }
        $user = get_userdata($customer_id);
        if (!$user instanceof \WP_User) {
            return;
        }
        $domain = self::email_domain((string) $user->user_email);
        update_user_meta($customer_id, self::STATUS_META, self::STATUS_PENDING_EMAIL);
        update_user_meta($customer_id, self::DOMAIN_META, $domain);
        update_user_meta($customer_id, self::WHITELISTED_META, self::domain_is_whitelisted($domain) ? '1' : '0');
        self::send_verification($customer_id);
        Logger::log('STEP', 'Customer email verification requested', [
            'user_id' => $customer_id,
            'domain' => $domain,
            'status' => self::STATUS_PENDING_EMAIL,
        ]);
    }

    public static function prevent_automatic_login(bool $authenticate, int $customer_id): bool {
        if (!self::enabled()) {
            return $authenticate;
        }
        return get_user_meta($customer_id, self::STATUS_META, true) === self::STATUS_APPROVED;
    }

    public static function registration_redirect($redirect): string {
        if (!self::enabled()) {
            return is_string($redirect) ? $redirect : self::my_account_url();
        }
        return add_query_arg('enovos_registration', 'pending', self::my_account_url());
    }

    /**
     * @param \WP_User|\WP_Error $user
     * @return \WP_User|\WP_Error
     */
    public static function check_login($user, string $password) {
        unset($password);
        if (!$user instanceof \WP_User || !self::enabled()) {
            return $user;
        }
        $status = (string) get_user_meta($user->ID, self::STATUS_META, true);
        if ($status === self::STATUS_PENDING_EMAIL) {
            return new \WP_Error(
                'enovos_email_unverified',
                __('Please confirm your email address before signing in. You can request a new link below.', 'enovos-ticket-shop')
            );
        }
        if ($status === self::STATUS_PENDING_ADMIN) {
            return new \WP_Error(
                'enovos_admin_approval_pending',
                __('Your email address is confirmed. Your account is waiting for administrator approval.', 'enovos-ticket-shop')
            );
        }
        if ($status === self::STATUS_REJECTED) {
            return new \WP_Error(
                'enovos_customer_rejected',
                __('Your customer account was not approved. Please contact the shop if you think this is a mistake.', 'enovos-ticket-shop')
            );
        }
        return $user;
    }

    public static function handle_frontend_request(): void {
        if (!self::enabled()) {
            return;
        }
        if (isset($_GET['enovos_verify_email'])) {
            self::handle_verification_link();
        }
        $is_get_request = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET';
        if ($is_get_request && isset($_GET['enovos_resend']) && sanitize_key((string) $_GET['enovos_resend']) === 'submitted') {
            wc_add_notice(
                __('If an account is waiting for email confirmation, a new verification link has been sent.', 'enovos-ticket-shop'),
                'success'
            );
        }
        if ($is_get_request && isset($_GET['enovos_verified'])) {
            $verification_result = sanitize_key((string) $_GET['enovos_verified']);
            if ($verification_result === 'approved') {
                wc_add_notice(
                    __('Your email address is confirmed and your account is now active. You can sign in.', 'enovos-ticket-shop'),
                    'success'
                );
            } elseif ($verification_result === 'pending_admin') {
                wc_add_notice(
                    __('Your email address is confirmed. An administrator will review your account.', 'enovos-ticket-shop'),
                    'success'
                );
            } elseif ($verification_result === 'invalid') {
                wc_add_notice(
                    __('This verification link is invalid or has expired. Please request a new link.', 'enovos-ticket-shop'),
                    'error'
                );
            }
        }
        if (is_user_logged_in() && self::is_blocked(get_current_user_id())) {
            $message = self::blocked_message(get_current_user_id());
            wp_logout();
            wc_add_notice($message, 'error');
            wp_safe_redirect(self::my_account_url());
            exit;
        }
    }

    public static function prepend_registration_confirmation(string $content): string {
        static $rendered = false;
        if (
            $rendered
            || !self::enabled()
            || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET'
            || sanitize_key((string) ($_GET['enovos_registration'] ?? '')) !== 'pending'
            || !function_exists('is_account_page')
            || !is_account_page()
            || !is_main_query()
        ) {
            return $content;
        }

        $rendered = true;
        $message = esc_html__(
            'Your account was created. Please check your inbox and confirm your email address before signing in.',
            'enovos-ticket-shop'
        );
        return '<div class="woocommerce-message" role="alert">' . $message . '</div>' . $content;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function validate_checkout(array $data, \WP_Error $errors): void {
        if (!self::enabled()) {
            return;
        }
        if (is_user_logged_in() && self::is_blocked(get_current_user_id())) {
            $errors->add(
                'enovos_customer_pending',
                self::blocked_message(get_current_user_id())
            );
        }
        if (!is_user_logged_in() && (!empty($data['createaccount']) || self::checkout_registration_required())) {
            $errors->add(
                'enovos_register_before_checkout',
                __('Please register and confirm your email address before creating an account during checkout.', 'enovos-ticket-shop')
            );
        }
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public static function validate_store_api_checkout($result, \WP_REST_Server $server, \WP_REST_Request $request) {
        unset($server);
        if (!self::enabled() || $result !== null || $request->get_method() !== 'POST') {
            return $result;
        }
        if (!preg_match('#^/wc/store(?:/v\d+)?/checkout/?$#', $request->get_route())) {
            return $result;
        }
        if (is_user_logged_in() && self::is_blocked(get_current_user_id())) {
            return new \WP_Error(
                'enovos_customer_pending',
                self::blocked_message(get_current_user_id()),
                ['status' => 403]
            );
        }
        if (!is_user_logged_in() && ($request->get_param('create_account') || self::checkout_registration_required())) {
            return new \WP_Error(
                'enovos_register_before_checkout',
                __('Please register and confirm your email address before creating an account during checkout.', 'enovos-ticket-shop'),
                ['status' => 403]
            );
        }
        return $result;
    }

    public static function render_resend_form(): void {
        if (!self::enabled()) {
            return;
        }
        echo '<p class="woocommerce-LostPassword lost_password">';
        echo '<a href="' . esc_url(self::resend_page_url()) . '">' . esc_html__('Did not receive the verification email?', 'enovos-ticket-shop') . '</a>';
        echo '</p>';
    }

    /**
     * @param mixed $args
     */
    public static function resend_page_template(string $template, string $template_name, $args, string $template_path, string $default_path): string {
        unset($args, $template_path, $default_path);
        if (
            $template_name !== 'myaccount/form-login.php'
            || is_user_logged_in()
            || !self::enabled()
            || sanitize_key((string) ($_GET['enovos_verification_resend'] ?? '')) !== '1'
        ) {
            return $template;
        }
        return ENOVOS_TICKET_SHOP_DIR . 'templates/myaccount/enovos-verification-resend.php';
    }

    public static function handle_resend(): void {
        check_admin_referer('enovos_resend_verification', 'enovos_resend_nonce');
        $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
        $rate_key = self::resend_rate_key($email);
        $attempts = (int) get_transient($rate_key);
        if ($email !== '' && $attempts < self::RESEND_LIMIT) {
            set_transient($rate_key, $attempts + 1, self::RESEND_WINDOW);
            $user = get_user_by('email', $email);
            if ($user instanceof \WP_User && get_user_meta($user->ID, self::STATUS_META, true) === self::STATUS_PENDING_EMAIL) {
                self::send_verification($user->ID);
                Logger::log('STEP', 'Customer email verification resent', [
                    'user_id' => $user->ID,
                    'domain' => self::email_domain($email),
                    'status' => self::STATUS_PENDING_EMAIL,
                ]);
            }
        }
        wp_safe_redirect(add_query_arg('enovos_resend', 'submitted', self::my_account_url()));
        exit;
    }

    public static function resend_for_user(int $user_id): bool {
        if (get_user_meta($user_id, self::STATUS_META, true) !== self::STATUS_PENDING_EMAIL) {
            return false;
        }
        self::send_verification($user_id);
        return true;
    }

    public static function approve(int $user_id): bool {
        if (get_user_meta($user_id, self::STATUS_META, true) !== self::STATUS_PENDING_ADMIN) {
            return false;
        }
        update_user_meta($user_id, self::STATUS_META, self::STATUS_APPROVED);
        update_user_meta($user_id, self::APPROVED_AT_META, current_time('mysql', true));
        self::trigger_email(EmailCustomerApproved::class, $user_id);
        Logger::log('STEP', 'Customer account approved', [
            'user_id' => $user_id,
            'domain' => (string) get_user_meta($user_id, self::DOMAIN_META, true),
            'status' => self::STATUS_APPROVED,
        ]);
        return true;
    }

    public static function reject(int $user_id): bool {
        if (get_user_meta($user_id, self::STATUS_META, true) !== self::STATUS_PENDING_ADMIN) {
            return false;
        }
        update_user_meta($user_id, self::STATUS_META, self::STATUS_REJECTED);
        update_user_meta($user_id, self::REJECTED_AT_META, current_time('mysql', true));
        self::trigger_email(EmailCustomerRejected::class, $user_id);
        Logger::log('STEP', 'Customer account rejected', [
            'user_id' => $user_id,
            'domain' => (string) get_user_meta($user_id, self::DOMAIN_META, true),
            'status' => self::STATUS_REJECTED,
        ]);
        return true;
    }

    public static function admin_recipients(): string {
        $settings = Plugin::settings();
        $recipients = self::sanitize_recipient_list((string) ($settings['approval_admin_recipients'] ?? ''));
        return $recipients !== '' ? $recipients : sanitize_email((string) get_option('admin_email'));
    }

    public static function sanitize_domains(string $raw): string {
        $domains = [];
        foreach (preg_split('/[\r\n,]+/', strtolower($raw)) ?: [] as $domain) {
            $domain = ltrim(trim($domain), '@.');
            if ($domain !== '' && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
                $domains[$domain] = true;
            }
        }
        return implode("\n", array_keys($domains));
    }

    public static function sanitize_recipient_list(string $raw): string {
        $recipients = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $recipient) {
            $recipient = sanitize_email($recipient);
            if ($recipient !== '' && is_email($recipient)) {
                $recipients[$recipient] = true;
            }
        }
        return implode(', ', array_keys($recipients));
    }

    public static function verification_url(int $user_id, string $token): string {
        return add_query_arg([
            'enovos_verify_email' => '1',
            'user_id' => $user_id,
            'token' => rawurlencode($token),
        ], self::my_account_url());
    }

    private static function handle_verification_link(): void {
        $user_id = absint($_GET['user_id'] ?? 0);
        $token = sanitize_text_field(wp_unslash((string) ($_GET['token'] ?? '')));
        $status = (string) get_user_meta($user_id, self::STATUS_META, true);
        $expected_hash = (string) get_user_meta($user_id, self::TOKEN_HASH_META, true);
        $expires = (int) get_user_meta($user_id, self::TOKEN_EXPIRES_META, true);
        $valid = $user_id > 0
            && $token !== ''
            && $status === self::STATUS_PENDING_EMAIL
            && $expires >= time()
            && $expected_hash !== ''
            && hash_equals($expected_hash, hash('sha256', $token));

        if (!$valid) {
            wp_safe_redirect(add_query_arg('enovos_verified', 'invalid', self::my_account_url()));
            exit;
        }

        delete_user_meta($user_id, self::TOKEN_HASH_META);
        delete_user_meta($user_id, self::TOKEN_EXPIRES_META);
        update_user_meta($user_id, self::VERIFIED_AT_META, current_time('mysql', true));
        $domain = (string) get_user_meta($user_id, self::DOMAIN_META, true);
        $whitelisted = self::domain_is_whitelisted($domain);
        update_user_meta($user_id, self::WHITELISTED_META, $whitelisted ? '1' : '0');

        if ($whitelisted) {
            update_user_meta($user_id, self::STATUS_META, self::STATUS_APPROVED);
            update_user_meta($user_id, self::APPROVED_AT_META, current_time('mysql', true));
            $new_status = self::STATUS_APPROVED;
        } else {
            update_user_meta($user_id, self::STATUS_META, self::STATUS_PENDING_ADMIN);
            self::trigger_email(
                EmailAdminApproval::class,
                $user_id,
                CustomerApprovalAdmin::decision_page_url($user_id, 'approve'),
                CustomerApprovalAdmin::decision_page_url($user_id, 'reject')
            );
            $new_status = self::STATUS_PENDING_ADMIN;
        }

        Logger::log('STEP', 'Customer email verified', [
            'user_id' => $user_id,
            'domain' => $domain,
            'status' => $new_status,
        ]);
        wp_safe_redirect(add_query_arg('enovos_verified', $whitelisted ? 'approved' : 'pending_admin', self::my_account_url()));
        exit;
    }

    private static function send_verification(int $user_id): void {
        $token = bin2hex(random_bytes(32));
        update_user_meta($user_id, self::TOKEN_HASH_META, hash('sha256', $token));
        update_user_meta($user_id, self::TOKEN_EXPIRES_META, time() + self::TOKEN_LIFETIME);
        self::trigger_email(EmailCustomerVerify::class, $user_id, self::verification_url($user_id, $token));
    }

    private static function trigger_email(string $class_name, ...$arguments): void {
        if (!function_exists('WC') || !WC()->mailer()) {
            return;
        }
        foreach (WC()->mailer()->get_emails() as $email) {
            if ($email instanceof $class_name) {
                $email->trigger(...$arguments);
                return;
            }
        }
    }

    private static function email_domain(string $email): string {
        $position = strrpos($email, '@');
        return $position === false ? '' : strtolower(substr($email, $position + 1));
    }

    private static function domain_is_whitelisted(string $domain): bool {
        if ($domain === '') {
            return false;
        }
        $settings = Plugin::settings();
        $whitelist = self::sanitize_domains((string) ($settings['approval_domain_whitelist'] ?? ''));
        return in_array(strtolower($domain), preg_split('/\R/', $whitelist) ?: [], true);
    }

    private static function is_blocked(int $user_id): bool {
        return in_array(
            (string) get_user_meta($user_id, self::STATUS_META, true),
            [self::STATUS_PENDING_EMAIL, self::STATUS_PENDING_ADMIN, self::STATUS_REJECTED],
            true
        );
    }

    private static function blocked_message(int $user_id): string {
        return get_user_meta($user_id, self::STATUS_META, true) === self::STATUS_REJECTED
            ? __('Your customer account was not approved. Please contact the shop if you think this is a mistake.', 'enovos-ticket-shop')
            : __('Your customer account must be approved before you can sign in or place an order.', 'enovos-ticket-shop');
    }

    private static function checkout_registration_required(): bool {
        return function_exists('WC') && WC()->checkout() && WC()->checkout()->is_registration_required();
    }

    private static function my_account_url(): string {
        $url = wc_get_page_permalink('myaccount');
        return is_string($url) && $url !== '' ? $url : home_url('/');
    }

    private static function resend_page_url(): string {
        return add_query_arg('enovos_verification_resend', '1', self::my_account_url());
    }

    private static function resend_rate_key(string $email): string {
        $ip = sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
        return 'enovos_verify_resend_' . hash('sha256', strtolower($email) . '|' . $ip);
    }
}
