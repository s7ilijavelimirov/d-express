<?php
// Dodati osnovnu strukturu za prikazivanje pošiljki
defined('ABSPATH') || exit;
?>

<div class="dexpress-tracking-container">
    <h2><?php esc_html_e('Praćenje pošiljki', 'd-express-woo'); ?></h2>

    <?php if (empty($shipments)): ?>
        <div class="woocommerce-message woocommerce-message--info">
            <p><?php esc_html_e('Trenutno nemate aktivnih pošiljki za praćenje.', 'd-express-woo'); ?></p>
        </div>
    <?php else: ?>
        <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive">
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
                <?php foreach ($shipments as $shipment): ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(wc_get_account_endpoint_url('view-order') . $shipment->order_id); ?>">
                                #<?php echo esc_html($shipment->order_id); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($shipment->tracking_number); ?></td>
                        <td>
                            <span class="dexpress-status-badge <?php echo esc_attr(dexpress_get_status_css_class($shipment->status_code)); ?>">
                                <?php echo esc_html(dexpress_get_status_name($shipment->status_code)); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($shipment->created_at))); ?></td>
                        <td>
                            <a href="https://www.dexpress.rs/rs/pracenje-posiljaka/<?php echo esc_attr($shipment->tracking_number); ?>"
                                target="_blank" class="button">
                                <?php esc_html_e('Prati', 'd-express-woo'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>