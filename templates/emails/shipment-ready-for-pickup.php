<?php
defined('ABSPATH') || exit;
?>

<p><?php printf(__('Poštovani %s,', 'd-express-woo'), $order->get_billing_first_name()); ?></p>

<p><?php _e('Obaveštavamo Vas da je Vaša pošiljka spremna za preuzimanje.', 'd-express-woo'); ?></p>

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
</table>

<?php if(!empty($dispenser)): ?>
<h3><?php _e('Lokacija za preuzimanje', 'd-express-woo'); ?></h3>
<p>
    <strong><?php echo esc_html($dispenser->name); ?></strong><br>
    <?php echo esc_html($dispenser->address); ?>, <?php echo esc_html($dispenser->town); ?><br>
    <?php if(!empty($dispenser->work_hours)): ?>
    <?php _e('Radno vreme:', 'd-express-woo'); ?> <?php echo esc_html($dispenser->work_hours); ?>
    <?php endif; ?>
</p>
<?php endif; ?>

<p style="margin-top: 20px;">
    <?php _e('Rok za preuzimanje paketa je 48 sati (2 dana) od prijema ovog obaveštenja.', 'd-express-woo'); ?>
</p>

<p><?php _e('Za sva pitanja stojimo Vam na raspolaganju.', 'd-express-woo'); ?></p>