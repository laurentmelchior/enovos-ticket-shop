<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class EmailAdminApproval extends \WC_Email {
    public function __construct() {
        $this->id = 'enovos_admin_customer_approval';
        $this->title = __('Customer approval request', 'enovos-ticket-shop');
        $this->description = __('Sent to the configured recipients when a verified customer requires manual approval.', 'enovos-ticket-shop');
        $this->template_html = 'emails/enovos-admin-approval.php';
        $this->template_plain = 'emails/plain/enovos-admin-approval.php';
        $this->template_base = ENOVOS_TICKET_SHOP_DIR . 'templates/';
        $this->placeholders = [
            '{customer_name}' => '',
            '{customer_email}' => '',
            '{customer_domain}' => '',
            '{approval_url}' => '',
        ];
        parent::__construct();
    }

    public function get_default_subject(): string {
        return __('Customer account requires approval', 'enovos-ticket-shop');
    }

    public function get_default_heading(): string {
        return __('Review a new customer account', 'enovos-ticket-shop');
    }

    public function trigger(int $user_id, string $approval_url): void {
        $this->setup_locale();
        $user = get_userdata($user_id);
        if ($user instanceof \WP_User) {
            $this->object = $user;
            $this->recipient = CustomerApproval::admin_recipients();
            $domain = strtolower((string) substr(strrchr($user->user_email, '@') ?: '', 1));
            $this->placeholders['{customer_name}'] = $user->display_name;
            $this->placeholders['{customer_email}'] = $user->user_email;
            $this->placeholders['{customer_domain}'] = $domain;
            $this->placeholders['{approval_url}'] = $approval_url;
        }
        if ($this->is_enabled() && $this->get_recipient()) {
            $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );
        }
        $this->restore_locale();
    }

    public function get_content_html(): string {
        return wc_get_template_html($this->template_html, [
            'email_heading' => $this->get_heading(),
            'customer_name' => $this->placeholders['{customer_name}'],
            'customer_email' => $this->placeholders['{customer_email}'],
            'customer_domain' => $this->placeholders['{customer_domain}'],
            'approval_url' => $this->placeholders['{approval_url}'],
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin' => true,
            'plain_text' => false,
            'email' => $this,
        ], '', $this->template_base);
    }

    public function get_content_plain(): string {
        return wc_get_template_html($this->template_plain, [
            'email_heading' => $this->get_heading(),
            'customer_name' => $this->placeholders['{customer_name}'],
            'customer_email' => $this->placeholders['{customer_email}'],
            'customer_domain' => $this->placeholders['{customer_domain}'],
            'approval_url' => $this->placeholders['{approval_url}'],
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin' => true,
            'plain_text' => true,
            'email' => $this,
        ], '', $this->template_base);
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable this email notification', 'woocommerce'),
                'default' => 'yes',
            ],
            'subject' => [
                'title' => __('Subject', 'woocommerce'),
                'type' => 'text',
                'description' => __('Available placeholders: {site_title}, {customer_name}, {customer_email}, {customer_domain}, {approval_url}', 'enovos-ticket-shop'),
                'placeholder' => $this->get_default_subject(),
                'default' => '',
                'desc_tip' => true,
            ],
            'heading' => [
                'title' => __('Email heading', 'woocommerce'),
                'type' => 'text',
                'placeholder' => $this->get_default_heading(),
                'default' => '',
                'desc_tip' => true,
            ],
            'additional_content' => [
                'title' => __('Additional content', 'woocommerce'),
                'description' => __('Text shown below the main email content.', 'enovos-ticket-shop'),
                'css' => 'width:400px; height: 75px;',
                'type' => 'textarea',
                'default' => '',
                'desc_tip' => true,
            ],
            'email_type' => [
                'title' => __('Email type', 'woocommerce'),
                'type' => 'select',
                'description' => __('Choose which format of email to send.', 'woocommerce'),
                'default' => 'html',
                'class' => 'email_type wc-enhanced-select',
                'options' => $this->get_email_type_options(),
                'desc_tip' => true,
            ],
        ];
    }
}
