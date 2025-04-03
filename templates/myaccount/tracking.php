<?php

/**
 * Prikaz informacija o praćenju pošiljke na stranici My Account
 *
 * @package D_Express_WooCommerce
 */
defined('ABSPATH') || exit;
?>

<div class="dexpress-tracking-container">
    <h2><?php esc_html_e('Praćenje pošiljki', 'd-express-woo'); ?></h2>

    <?php if (empty($shipments)): ?>
        <div class="woocommerce-message woocommerce-message--info">
            <p><?php esc_html_e('Nemate aktivnih pošiljki za praćenje.', 'd-express-woo'); ?></p>
        </div>
    <?php else: ?>
        <?php if ($shipment): ?>
            <div class="dexpress-tracking-active">
                <div class="dexpress-tracking-header">
                    <h3><?php esc_html_e('Aktivna pošiljka', 'd-express-woo'); ?></h3>
                </div>

                <div class="dexpress-tracking-details">
                    <table>
                        <tr>
                            <th><?php esc_html_e('Narudžbina:', 'd-express-woo'); ?></th>
                            <td>
                                <?php
                                $order = wc_get_order($shipment->order_id);
                                if ($order):
                                ?>
                                    <a href="<?php echo esc_url($order->get_view_order_url()); ?>">
                                        #<?php echo esc_html($order->get_order_number()); ?>
                                    </a>
                                <?php else: ?>
                                    #<?php echo esc_html($shipment->order_id); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Broj za praćenje:', 'd-express-woo'); ?></th>
                            <td><?php echo esc_html($shipment->tracking_number); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Status:', 'd-express-woo'); ?></th>
                            <td>
                                <span class="dexpress-status-badge <?php echo esc_attr(dexpress_get_status_css_class($shipment->status_code)); ?>">
                                    <?php echo esc_html(dexpress_get_status_name($shipment->status_code)); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Datum kreiranja:', 'd-express-woo'); ?></th>
                            <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at))); ?></td>
                        </tr>
                    </table>

                    <?php if (!$shipment->is_test): ?>
                        <div class="dexpress-tracking-actions">
                            <a href="https://www.dexpress.rs/rs/pracenje-posiljaka/<?php echo esc_attr($shipment->tracking_number); ?>"
                                target="_blank" class="button">
                                <?php esc_html_e('Prati na D Express sajtu', 'd-express-woo'); ?>
                            </a>
                        </div>
                    <?php else: ?>
                        <p class="dexpress-test-note"><em><?php esc_html_e('Ovo je test pošiljka i ne može se pratiti na zvaničnom sajtu.', 'd-express-woo'); ?></em></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Lista svih pošiljki korisnika -->
        <div class="dexpress-tracking-list">
            <h3><?php esc_html_e('Sve pošiljke', 'd-express-woo'); ?></h3>

            <table class="woocommerce-orders-table shop_table shop_table_responsive">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Narudžbina', 'd-express-woo'); ?></th>
                        <th><?php esc_html_e('Tracking broj', 'd-express-woo'); ?></th>
                        <th><?php esc_html_e('Status', 'd-express-woo'); ?></th>
                        <th><?php esc_html_e('Datum', 'd-express-woo'); ?></th>
                        <th><?php esc_html_e('Akcije', 'd-express-woo'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shipments as $shipment_item):
                        $order = wc_get_order($shipment_item->order_id);
                    ?>
                        <tr>
                            <td data-title="<?php esc_attr_e('Narudžbina', 'd-express-woo'); ?>">
                                <?php if ($order): ?>
                                    <a href="<?php echo esc_url($order->get_view_order_url()); ?>">
                                        #<?php echo esc_html($order->get_order_number()); ?>
                                    </a>
                                <?php else: ?>
                                    #<?php echo esc_html($shipment_item->order_id); ?>
                                <?php endif; ?>
                            </td>
                            <td data-title="<?php esc_attr_e('Tracking broj', 'd-express-woo'); ?>">
                                <?php echo esc_html($shipment_item->tracking_number); ?>
                                <?php if ($shipment_item->is_test): ?>
                                    <span class="dexpress-test-badge"><?php esc_html_e('Test', 'd-express-woo'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-title="<?php esc_attr_e('Status', 'd-express-woo'); ?>">
                                <span class="dexpress-status-badge <?php echo esc_attr(dexpress_get_status_css_class($shipment_item->status_code)); ?>">
                                    <?php echo esc_html(dexpress_get_status_name($shipment_item->status_code)); ?>
                                </span>
                            </td>
                            <td data-title="<?php esc_attr_e('Datum', 'd-express-woo'); ?>">
                                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($shipment_item->created_at))); ?>
                            </td>
                            <td data-title="<?php esc_attr_e('Akcije', 'd-express-woo'); ?>">
                                <?php if (!$shipment_item->is_test): ?>
                                    <a href="https://www.dexpress.rs/rs/pracenje-posiljaka/<?php echo esc_attr($shipment_item->tracking_number); ?>"
                                        target="_blank" class="button button-small">
                                        <?php esc_html_e('Prati', 'd-express-woo'); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="dexpress-test-only"><?php esc_html_e('Test', 'd-express-woo'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>