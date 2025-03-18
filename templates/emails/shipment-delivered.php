<?php
defined('ABSPATH') || exit;

/**
 * D Express Email - Pošiljka isporučena
 */
?>

<p><?php printf(__('Poštovani %s,', 'd-express-woo'), $order->get_billing_first_name()); ?></p>

<p><?php _e('Sa zadovoljstvom Vas obaveštavamo da je Vaša pošiljka uspešno isporučena.', 'd-express-woo'); ?></p>

<h2><?php _e('Informacije o pošiljci', 'd-express-woo'); ?></h2>

<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1">
    <tr>
        <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php _e('Broj pošiljke:', 'd-express-woo'); ?></th>
        <td style="text-align: left; border: 1px solid #eee; padding: 12px;"><strong><?php echo esc_html($tracking_number); ?></strong></td>
    </tr>
    <tr>
        <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php _e('Datum isporuke:', 'd-express-woo'); ?></th>
        <td style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), current_time('timestamp')); ?></td>
    </tr>
    <tr>
        <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php _e('Status:', 'd-express-woo'); ?></th>
        <td style="text-align: left; border: 1px solid #eee; padding: 12px;"><span style="color: green; font-weight: bold;"><?php echo esc_html($status_name); ?></span></td>
    </tr>
</table>

<p style="margin-top: 20px;"><?php _e('Hvala Vam na poverenju i nadamo se da ćemo sarađivati ponovo!', 'd-express-woo'); ?></p>

<p><?php _e('Za sva eventualna pitanja, molimo Vas da nas kontaktirate.', 'd-express-woo'); ?></p>