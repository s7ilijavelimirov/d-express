<?php

/**
 * Prikaz informacija o praćenju pošiljke na stranici My Account
 *
 * @package D_Express_WooCommerce
 */

defined('ABSPATH') || exit;
?>

<section class="woocommerce-dexpress-tracking">
    <h2><?php esc_html_e('Praćenje pošiljke', 'd-express-woo'); ?></h2>

    <?php if ($shipment): ?>
        <p>
            <?php esc_html_e('Vaša narudžbina je poslata putem D Express kurirske službe.', 'd-express-woo'); ?>
        </p>

        <p>
            <strong><?php esc_html_e('Broj za praćenje:', 'd-express-woo'); ?></strong>
            <?php echo esc_html($shipment->tracking_number); ?>
        </p>

        <?php if ($shipment->is_test): ?>
            <p><em><?php esc_html_e('Ovo je test pošiljka i ne može se pratiti na zvaničnom sajtu.', 'd-express-woo'); ?></em></p>
        <?php else: ?>
            <p>
                <a href="https://www.dexpress.rs/rs/pracenje-posiljaka/<?php echo esc_attr($shipment->tracking_number); ?>" target="_blank" class="button">
                    <?php esc_html_e('Prati pošiljku online', 'd-express-woo'); ?>
                </a>
            </p>
        <?php endif; ?>
    <?php else: ?>
        <p><?php esc_html_e('Nemate aktivnih pošiljki za praćenje.', 'd-express-woo'); ?></p>
    <?php endif; ?>
</section>