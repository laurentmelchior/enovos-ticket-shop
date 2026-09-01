<?php
defined('ABSPATH') || exit;
?>
<form method="post" class="woocommerce-ResetPassword lost_reset_password" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <p><?php esc_html_e('Enter your email address and we will send a new verification link if your account is still waiting for confirmation.', 'enovos-ticket-shop'); ?></p>

    <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
        <label for="enovos_resend_email"><?php esc_html_e('Email address', 'enovos-ticket-shop'); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e('Required', 'woocommerce'); ?></span></label>
        <input class="woocommerce-Input woocommerce-Input--text input-text" type="email" name="email" id="enovos_resend_email" autocomplete="email" required aria-required="true">
    </p>

    <div class="clear"></div>

    <p class="woocommerce-form-row form-row">
        <input type="hidden" name="action" value="enovos_resend_verification">
        <button type="submit" class="woocommerce-Button button" value="<?php esc_attr_e('Send a new verification link', 'enovos-ticket-shop'); ?>"><?php esc_html_e('Send a new verification link', 'enovos-ticket-shop'); ?></button>
    </p>

    <?php wp_nonce_field('enovos_resend_verification', 'enovos_resend_nonce'); ?>
</form>
