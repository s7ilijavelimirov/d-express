<?php
defined('ABSPATH') || exit;
?>

<p><?php printf(__('Poštovani %s,', 'd-express-woo'), $order->get_billing_first_name()); ?></p>

<p><?php _e('Obaveštavamo Vas da je danas pokušana isporuka Vaše pošiljke, ali nije bila uspešna.', 'd-express-woo'); ?></p>

<h2><?php _e('Informacije o pošiljci', 'd-express-woo'); ?></h2>

<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1">
    <tr>
        <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php _e('Broj pošiljke:', 'd-express-woo'); ?></th>
        <td style="text-align: left; border: 1px solid #eee; padding: 12px;"><strong><?php echo esc_html($tracking_number); ?></strong></td>
    </tr>
    <tr>
        <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php _e('Status:', 'd-express-woo'); ?></th>
        <td style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php echo esc_html($status_name); ?></td>
    </tr>
    <tr>
        <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php _e('Datum pokušaja:', 'd-express-woo'); ?></th>
        <td style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), current_time('timestamp')); ?></td>
    </tr>
</table>

<p style="margin-top: 20px;">
    <?php _e('Kurir će pokušati isporuku ponovo narednog radnog dana. Molimo Vas da budete dostupni na adresi za isporuku ili nas kontaktirajte ako želite da dogovorite drugo vreme ili način isporuke.', 'd-express-woo'); ?>
</p>

<p style="margin-top: 10px;">
    <?php _e('Možete pratiti status Vaše pošiljke na D Express sajtu koristeći sledeći link:', 'd-express-woo'); ?><br>
    <a href="https://www.dexpress.rs/rs/pracenje-posiljaka/<?php echo esc_attr($tracking_number); ?>" style="display: inline-block; margin-top: 10px; padding: 8px 15px; background-color: #0073aa; color: white; text-decoration: none; border-radius: 3px;">
        <?php _e('Prati pošiljku', 'd-express-woo'); ?>
    </a>
</p>

<p><?php _e('Za sva dodatna pitanja, stojimo Vam na raspolaganju.', 'd-express-woo'); ?></p>