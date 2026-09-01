<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class CustomerApprovalAdmin {
    private const PAGE_SLUG = 'enovos-pending-customers';

    public static function init(): void {
        add_action('admin_post_enovos_approve_customer', [self::class, 'handle_approve']);
        add_action('admin_post_enovos_admin_resend_verification', [self::class, 'handle_resend']);
    }

    public static function approval_page_url(int $user_id): string {
        return add_query_arg([
            'page' => self::PAGE_SLUG,
            'action' => 'confirm_approve',
            'user_id' => $user_id,
        ], admin_url('admin.php'));
    }

    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to manage customer approvals.', 'enovos-ticket-shop'));
        }
        $action = sanitize_key((string) ($_GET['action'] ?? ''));
        if ($action === 'confirm_approve') {
            self::render_confirmation();
            return;
        }
        self::render_list();
    }

    public static function handle_approve(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to approve customers.', 'enovos-ticket-shop'));
        }
        $user_id = absint($_POST['user_id'] ?? 0);
        check_admin_referer('enovos_approve_customer_' . $user_id);
        $approved = CustomerApproval::approve($user_id);
        wp_safe_redirect(add_query_arg(
            'enovos_approval_notice',
            $approved ? 'approved' : 'invalid',
            admin_url('admin.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }

    public static function handle_resend(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to resend verification emails.', 'enovos-ticket-shop'));
        }
        $user_id = absint($_POST['user_id'] ?? 0);
        check_admin_referer('enovos_admin_resend_verification_' . $user_id);
        $resent = CustomerApproval::resend_for_user($user_id);
        if ($resent) {
            Logger::log('STEP', 'Administrator resent customer verification', [
                'user_id' => $user_id,
                'status' => CustomerApproval::STATUS_PENDING_EMAIL,
            ]);
        }
        wp_safe_redirect(add_query_arg(
            'enovos_approval_notice',
            $resent ? 'resent' : 'invalid',
            admin_url('admin.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }

    private static function render_confirmation(): void {
        $user_id = absint($_GET['user_id'] ?? 0);
        if (!$user_id) {
            wp_die(esc_html__('The approval link is invalid.', 'enovos-ticket-shop'));
        }
        $user = get_userdata($user_id);
        $status = (string) get_user_meta($user_id, CustomerApproval::STATUS_META, true);
        if (!$user instanceof \WP_User || $status !== CustomerApproval::STATUS_PENDING_ADMIN) {
            echo '<div class="wrap enovos-admin"><h1>' . esc_html__('Customer approval', 'enovos-ticket-shop') . '</h1>';
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('This customer is no longer waiting for approval.', 'enovos-ticket-shop') . '</p></div>';
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">' . esc_html__('Back to pending customers', 'enovos-ticket-shop') . '</a></p></div>';
            return;
        }

        echo '<div class="wrap enovos-admin"><h1>' . esc_html__('Approve customer', 'enovos-ticket-shop') . '</h1>';
        echo '<div class="enovos-card"><div class="enovos-card__header"><h2>' . esc_html__('Confirm account approval', 'enovos-ticket-shop') . '</h2>';
        echo '<p>' . esc_html__('Review the customer details before granting access to the shop.', 'enovos-ticket-shop') . '</p></div>';
        echo '<div class="enovos-card__body"><table class="form-table" role="presentation">';
        echo '<tr><th>' . esc_html__('Customer', 'enovos-ticket-shop') . '</th><td>' . esc_html($user->display_name) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Email', 'enovos-ticket-shop') . '</th><td>' . esc_html($user->user_email) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Domain', 'enovos-ticket-shop') . '</th><td>' . esc_html((string) get_user_meta($user_id, '_enovos_email_domain', true)) . '</td></tr>';
        echo '</table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="enovos_approve_customer">';
        echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $user_id) . '">';
        wp_nonce_field('enovos_approve_customer_' . $user_id);
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Approve customer', 'enovos-ticket-shop') . '</button> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">' . esc_html__('Cancel', 'enovos-ticket-shop') . '</a></p>';
        echo '</form></div></div></div>';
    }

    private static function render_list(): void {
        $page = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 50;
        $query = new \WP_User_Query([
            'number' => $per_page,
            'paged' => $page,
            'orderby' => 'registered',
            'order' => 'ASC',
            'count_total' => true,
            'meta_query' => [
                [
                    'key' => CustomerApproval::STATUS_META,
                    'value' => [CustomerApproval::STATUS_PENDING_EMAIL, CustomerApproval::STATUS_PENDING_ADMIN],
                    'compare' => 'IN',
                ],
            ],
        ]);

        echo '<div class="wrap enovos-admin enovos-admin--wide"><h1>' . esc_html__('Pending customers', 'enovos-ticket-shop') . '</h1>';
        echo '<p class="enovos-admin-lead">' . esc_html__('Customers waiting for email verification or manual approval.', 'enovos-ticket-shop') . '</p>';
        self::render_notice();
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Customer', 'enovos-ticket-shop') . '</th>';
        echo '<th>' . esc_html__('Email', 'enovos-ticket-shop') . '</th>';
        echo '<th>' . esc_html__('Domain', 'enovos-ticket-shop') . '</th>';
        echo '<th>' . esc_html__('Status', 'enovos-ticket-shop') . '</th>';
        echo '<th>' . esc_html__('Registered', 'enovos-ticket-shop') . '</th>';
        echo '<th>' . esc_html__('Actions', 'enovos-ticket-shop') . '</th>';
        echo '</tr></thead><tbody>';

        $users = $query->get_results();
        if (!$users) {
            echo '<tr><td colspan="6">' . esc_html__('No customers are waiting for approval.', 'enovos-ticket-shop') . '</td></tr>';
        }
        foreach ($users as $user) {
            if (!$user instanceof \WP_User) {
                continue;
            }
            $status = (string) get_user_meta($user->ID, CustomerApproval::STATUS_META, true);
            $domain = (string) get_user_meta($user->ID, '_enovos_email_domain', true);
            echo '<tr>';
            echo '<td>' . esc_html($user->display_name) . '</td>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td>' . esc_html($domain) . '</td>';
            echo '<td>' . esc_html($status === CustomerApproval::STATUS_PENDING_EMAIL ? __('Waiting for email', 'enovos-ticket-shop') : __('Waiting for admin', 'enovos-ticket-shop')) . '</td>';
            echo '<td>' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $user->user_registered)) . '</td>';
            echo '<td>';
            if ($status === CustomerApproval::STATUS_PENDING_ADMIN) {
                echo '<a class="button button-primary" href="' . esc_url(self::approval_page_url($user->ID)) . '">' . esc_html__('Review and approve', 'enovos-ticket-shop') . '</a>';
            } else {
                echo '<form class="enovos-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="enovos_admin_resend_verification">';
                echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $user->ID) . '">';
                wp_nonce_field('enovos_admin_resend_verification_' . $user->ID);
                echo '<button type="submit" class="button">' . esc_html__('Resend verification', 'enovos-ticket-shop') . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        self::render_pagination($page, $per_page, (int) $query->get_total());
        echo '</div>';
    }

    private static function render_notice(): void {
        $notice = sanitize_key((string) ($_GET['enovos_approval_notice'] ?? ''));
        $messages = [
            'approved' => [__('Customer approved. The customer notification was sent.', 'enovos-ticket-shop'), 'success'],
            'resent' => [__('A new verification email was sent.', 'enovos-ticket-shop'), 'success'],
            'invalid' => [__('The requested action could not be completed because the customer status changed.', 'enovos-ticket-shop'), 'warning'],
        ];
        if (!isset($messages[$notice])) {
            return;
        }
        [$message, $type] = $messages[$notice];
        echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($message) . '</p></div>';
    }

    private static function render_pagination(int $page, int $per_page, int $total): void {
        $pages = (int) ceil($total / $per_page);
        if ($pages <= 1) {
            return;
        }
        $links = paginate_links([
            'base' => add_query_arg('paged', '%#%', admin_url('admin.php?page=' . self::PAGE_SLUG)),
            'format' => '',
            'current' => $page,
            'total' => $pages,
            'type' => 'array',
        ]);
        if (!$links) {
            return;
        }
        echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(implode(' ', $links)) . '</div></div>';
    }
}
