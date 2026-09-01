<?php
namespace Enovos\TicketShop;

if (!defined('ABSPATH')) {
    exit;
}

final class EmailCustomerRejected extends \WC_Email {
    public function __construct() {
        $this->id = 'enovos_customer_rejected';
        $this->customer_email = true;
        $this->title = __('Customer account rejected', 'enovos-ticket-shop');
        $this->description = __('Sent to a customer after an administrator rejects the account.', 'enovos-ticket-shop');
        $this->template_html = 'emails/enovos-customer-rejected.php';
        $this->template_plain = 'emails/plain/enovos-customer-rejected.php';
        $this->template_base = ENOVOS_TICKET_SHOP_DIR . 'templates/';
        $this->placeholders = [
            '{customer_name}' => '',
            '{customer_email}' => '',
            '{shop_url}' => '',
        ];
        parent::__construct();
    }

    public function get_default_subject(): string {
        return __('Your customer account was not approved', 'enovos-ticket-shop');
    }

    public function get_default_heading(): string {
        return __('Account review completed', 'enovos-ticket-shop');
    }

    public function trigger(int $user_id): void {
        $this->setup_locale();
        $user = get_userdata($user_id);
        if ($user instanceof \WP_User) {
            $this->object = $user;
            $this->recipient = $user->user_email;
            $this->placeholders['{customer_name}'] = $user->display_name;
            $this->placeholders['{customer_email}'] = $user->user_email;
            $shop_url = wc_get_page_permalink('shop');
            $this->placeholders['{shop_url}'] = is_string($shop_url) && $shop_url !== '' ? $shop_url : home_url('/');
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
            'shop_url' => $this->placeholders['{shop_url}'],
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin' => false,
            'plain_text' => false,
            'email' => $this,
        ], '', $this->template_base);
    }

    public function get_content_plain(): string {
        return wc_get_template_html($this->template_plain, [
            'email_heading' => $this->get_heading(),
            'customer_name' => $this->placeholders['{customer_name}'],
            'shop_url' => $this->placeholders['{shop_url}'],
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin' => false,
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
                'description' => __('Available placeholders: {site_title}, {customer_name}, {customer_email}, {shop_url}', 'enovos-ticket-shop'),
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
