<?php
defined('ABSPATH') || exit;
?>

<p><?php printf(__('Poštovani %s,', 'd-express-woo'), $order->get_billing_first_name()); ?></p>

<p><?php _e('Obaveštavamo Vas da je D Express kurir preuzeo Vašu pošiljku.', 'd-express-woo'); ?></p>

<h2><?php _e('Informacije o pošiljci', 'd-express-woo'); ?></h2>

<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1">
    <tr>
        <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php _e('Broj pošiljke:', 'd-express-woo'); ?></th>
        <td style="text-align: left; border: 1px solid #eee; padding: 12px;"><strong><?php echo esc_html($tracking_number); ?></strong></td>
    </tr>
    <tr>
        <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php _e('Datum preuzimanja:', 'd-express-woo'); ?></th>
        <td style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), current_time('timestamp')); ?></td>
    </tr>
</table>

<p style="margin-top: 20px;">
    <?php _e('Možete pratiti status Vaše pošiljke na D Express sajtu koristeći sledeći link:', 'd-express-woo'); ?><br>
    <a href="https://www.dexpress.rs/rs/pracenje-posiljaka/<?php echo esc_attr($tracking_number); ?>" style="display: inline-block; margin-top: 10px; padding: 8px 15px; background-color: #0073aa; color: white; text-decoration: none; border-radius: 3px;">
        <?php _e('Prati pošiljku', 'd-express-woo'); ?>
    </a>
</p>

<p><?php _e('Očekivano vreme isporuke je 1-3 radna dana.', 'd-express-woo'); ?></p>

<p><?php _e('Za sva pitanja stojimo Vam na raspolaganju.', 'd-express-woo'); ?></p>