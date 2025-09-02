<?php
/**
 * D Express Tracking Template za MyAccount
 * Prikaz korisničkih pošiljki sa pravilnim dohvatanjem iz baze
 */

defined('ABSPATH') || exit;


if (get_option('dexpress_enable_myaccount_tracking', 'yes') !== 'yes') {
    return;
}
// Dobijamo podatke o pošiljkama trenutnog korisnika
global $wpdb;
$user_id = get_current_user_id();

if (!$user_id) {
    return;
}

// Prvo dohvatimo sve narudžbine ovog korisnika
$user_orders = wc_get_orders([
    'customer_id' => $user_id,
    'limit' => -1,
    'status' => 'any'
]);

if (empty($user_orders)) {
    $shipments = [];
} else {
    $order_ids = array_map(function($order) { 
        return $order->get_id(); 
    }, $user_orders);
    
    $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));

    // SQL upit koji dohvata sve pakete za korisnikove narudžbine
    $shipments = $wpdb->get_results($wpdb->prepare(
        "SELECT s.id as shipment_id,
                s.order_id,
                s.reference_id,
                s.status_code,
                s.created_at,
                s.is_test,
                p.package_code as tracking_number,
                p.package_index,
                p.total_packages,
                COUNT(p2.id) as total_packages_count
         FROM {$wpdb->prefix}dexpress_shipments s
         LEFT JOIN {$wpdb->prefix}dexpress_packages p ON s.id = p.shipment_id
         LEFT JOIN {$wpdb->prefix}dexpress_packages p2 ON s.id = p2.shipment_id
         WHERE s.order_id IN ($placeholders)
         GROUP BY p.id
         ORDER BY s.created_at DESC, p.package_index ASC",
        ...$order_ids
    ));
}
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
                    <th><?php esc_html_e('Paket', 'd-express-woo'); ?></th>
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
                        <td>
                            <?php if ($shipment->tracking_number): ?>
                                <strong><?php echo esc_html($shipment->tracking_number); ?></strong>
                                <?php if ($shipment->is_test): ?>
                                    <small class="dexpress-test-badge">(TEST)</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php echo esc_html($shipment->reference_id ?: 'U pripremi'); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="dexpress-package-info">
                                <?php echo esc_html($shipment->package_index); ?>/<?php echo esc_html($shipment->total_packages_count); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($shipment->status_code): ?>
                                <span class="dexpress-status-badge <?php echo esc_attr(dexpress_get_status_css_class($shipment->status_code)); ?>">
                                    <?php echo esc_html(dexpress_get_status_name($shipment->status_code)); ?>
                                </span>
                            <?php else: ?>
                                <span class="dexpress-status-badge dexpress-status-pending">U obradi</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($shipment->created_at))); ?>
                        </td>
                        <td>
                            <?php if ($shipment->tracking_number): ?>
                                <a href="https://www.dexpress.rs/rs/pracenje-posiljaka/<?php echo esc_attr($shipment->tracking_number); ?>"
                                   target="_blank" class="button">
                                    <?php esc_html_e('Prati', 'd-express-woo'); ?>
                                </a>
                            <?php else: ?>
                                <span class="button button-disabled">
                                    <?php esc_html_e('U pripremi', 'd-express-woo'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
