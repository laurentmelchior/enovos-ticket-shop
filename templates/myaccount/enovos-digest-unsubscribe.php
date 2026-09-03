<?php
defined('ABSPATH') || exit;
?>
<main id="primary" class="site-main">
    <div class="woocommerce">
        <?php if ($state === 'success') : ?>
            <div class="woocommerce-message" role="status">
                <?php esc_html_e('You have been unsubscribed from the daily new products digest.', 'enovos-ticket-shop'); ?>
            </div>
        <?php elseif ($state === 'invalid') : ?>
            <div class="woocommerce-error" role="alert">
                <?php esc_html_e('This unsubscribe link is invalid or has expired.', 'enovos-ticket-shop'); ?>
            </div>
        <?php else : ?>
            <form method="post" class="woocommerce-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <h1><?php esc_html_e('Unsubscribe from daily new products', 'enovos-ticket-shop'); ?></h1>
                <p><?php esc_html_e('Would you like to stop receiving the daily new products digest?', 'enovos-ticket-shop'); ?></p>

                <input type="hidden" name="action" value="<?php echo esc_attr($form_action); ?>">
                <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user_id); ?>">
                <input type="hidden" name="expires" value="<?php echo esc_attr((string) $expires); ?>">
                <input type="hidden" name="signature" value="<?php echo esc_attr($signature); ?>">
                <?php wp_nonce_field($form_action . '_' . $user_id, 'enovos_digest_unsubscribe_nonce'); ?>

                <p>
                    <button type="submit" class="woocommerce-Button button">
                        <?php esc_html_e('Unsubscribe', 'enovos-ticket-shop'); ?>
                    </button>
                </p>
            </form>
        <?php endif; ?>
    </div>
</main>
