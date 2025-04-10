<?php
defined('ABSPATH') || exit;
?>

<p><?php printf(__('Poštovani %s,', 'd-express-woo'), $order->get_billing_first_name()); ?></p>

<p><?php _e('Obaveštavamo Vas da je Vaša pošiljka danas izašla na dostavu i biće isporučena u toku dana.', 'd-express-woo'); ?></p>

<h2><?php _e('Informacije o pošiljci', 'd-express-woo'); ?></h2>

<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1">
    <tr>
        <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php _e('Broj pošiljke:', 'd-express-woo'); ?></th>
        <td style="text-align: left; border: 1px solid #eee; padding: 12px;"><strong><?php echo esc_html($tracking_number); ?></strong></td>
    </tr>
    <tr>
        <th scope="row" style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php _e('Datum isporuke:', 'd-express-woo'); ?></th>
        <td style="text-align: left; border: 1px solid #eee; padding: 12px;"><?php echo date_i18n(get_option('date_format'), current_time('timestamp')); ?></td>
    </tr>
</table>

<p style="margin-top: 20px;">
    <?php _e('Molimo Vas da osigurate da neko bude prisutan na adresi za prijem pošiljke. Kurir će Vas kontaktirati pre isporuke.', 'd-express-woo'); ?>
</p>

<p><?php _e('Za sva pitanja ili promene vezane za isporuku, kontaktirajte nas odmah.', 'd-express-woo'); ?></p>