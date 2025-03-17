<?php
/**
 * D Express Tracking informacije u email-u
 *
 * Template za prikaz tracking informacija u email-u
 *
 * @package D_Express_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>

<div style="margin-bottom: 40px;">
    <h2 style="margin-top: 0; color: #7f54b3; display: block; font-weight: bold; font-size: 18px;"><?php _e('Informacije o dostavi', 'd-express-woo'); ?></h2>
    <p><?php _e('Vaša narudžbina je poslata putem D Express kurirske službe.', 'd-express-woo'); ?></p>
    
    <table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee; margin-bottom: 20px;" border="1">
        <tbody>
            <tr>
                <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px; background-color: #f8f8f8;"><?php _e('Broj za praćenje:', 'd-express-woo'); ?></th>
                <td style="text-align: left; border: 1px solid #eee; padding: 12px;">
                    <strong><?php echo esc_html($shipment->tracking_number); ?></strong>
                </td>
            </tr>
            <?php if ($shipment->status_description): ?>
            <tr>
                <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px; background-color: #f8f8f8;"><?php _e('Status:', 'd-express-woo'); ?></th>
                <td style="text-align: left; border: 1px solid #eee; padding: 12px;">
                    <?php echo esc_html($shipment->status_description); ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px; background-color: #f8f8f8;"><?php _e('Datum kreiranja:', 'd-express-woo'); ?></th>
                <td style="text-align: left; border: 1px solid #eee; padding: 12px;">
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at))); ?>
                </td>
            </tr>
        </tbody>
    </table>
    
    <?php if ($shipment->is_test): ?>
        <p><em><?php _e('Ovo je test pošiljka i ne može se pratiti na zvaničnom sajtu.', 'd-express-woo'); ?></em></p>
    <?php else: ?>
        <p>
            <?php _e('Možete pratiti status vaše pošiljke klikom na link ispod:', 'd-express-woo'); ?><br>
            <a href="https://www.dexpress.rs/TrackingParcel?trackingNumber=<?php echo esc_attr($shipment->tracking_number); ?>" style="display: inline-block; font-weight: normal; padding: 8px 15px; margin: 10px 0; text-decoration: none; background-color: #7f54b3; color: #ffffff; border-radius: 3px;"><?php _e('Prati pošiljku online', 'd-express-woo'); ?></a>
        </p>
    <?php endif; ?>
    
    <p><?php _e('Očekivano vreme isporuke je 1-3 radna dana.', 'd-express-woo'); ?></p>
</div>