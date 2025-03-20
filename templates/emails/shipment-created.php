<?php
/**
 * D Express email - obavestenje o kreiranoj pošiljci
 *
 * Template za email koji se šalje kada je pošiljka kreirana
 *
 * @package D_Express_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action('woocommerce_email_header', $email_heading, $email);
?>

<p><?php printf(__('Poštovani %s,', 'd-express-woo'), $order->get_billing_first_name()); ?></p>

<p><?php _e('Vaša porudžbina je spremna i poslata putem D Express kurirske službe.', 'd-express-woo'); ?></p>

<p><?php _e('Detalji o pošiljci:', 'd-express-woo'); ?></p>

<ul>
    <li><strong><?php _e('Broj za praćenje:', 'd-express-woo'); ?></strong> <?php echo esc_html($tracking_number); ?></li>
    <li><strong><?php _e('Referentni broj:', 'd-express-woo'); ?></strong> <?php echo esc_html($reference_id); ?></li>
    <li><strong><?php _e('Datum slanja:', 'd-express-woo'); ?></strong> <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($shipment_date))); ?></li>
</ul>

<?php if ($is_test): ?>
    <p><em><?php _e('Ovo je test pošiljka i ne može se pratiti na zvaničnom sajtu D Express kurira.', 'd-express-woo'); ?></em></p>
<?php else: ?>
    <p>
        <?php _e('Možete pratiti vašu pošiljku klikom na sledeći link:', 'd-express-woo'); ?><br>
        <a href="<?php echo esc_url('https://www.dexpress.rs/rs/pracenje-posiljaka/' . $tracking_number); ?>" target="_blank">
            <?php _e('Prati pošiljku', 'd-express-woo'); ?>
        </a>
    </p>
<?php endif; ?>

<p><?php _e('Očekivano vreme isporuke je 1-3 radna dana, u zavisnosti od vaše lokacije.', 'd-express-woo'); ?></p>

<?php if ($order->get_payment_method() === 'cod'): ?>
    <p><strong><?php _e('Plaćanje pouzećem:', 'd-express-woo'); ?></strong> <?php _e('Molimo pripremite tačan iznos za plaćanje kuriru prilikom isporuke.', 'd-express-woo'); ?></p>
<?php endif; ?>

<h2><?php _e('Detalji narudžbine', 'd-express-woo'); ?></h2>

<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; margin-bottom: 40px; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
    <thead>
        <tr>
            <th class="td" scope="col" style="text-align: left;"><?php _e('Proizvod', 'd-express-woo'); ?></th>
            <th class="td" scope="col" style="text-align: left;"><?php _e('Količina', 'd-express-woo'); ?></th>
            <th class="td" scope="col" style="text-align: left;"><?php _e('Cena', 'd-express-woo'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php
        foreach ($order->get_items() as $item_id => $item) :
            $product = $item->get_product();
        ?>
            <tr>
                <td class="td" style="text-align: left; vertical-align: middle; border: 1px solid #dddddd; word-wrap: break-word;">
                    <?php echo esc_html($item->get_name()); ?>
                </td>
                <td class="td" style="text-align: left; vertical-align: middle; border: 1px solid #dddddd;">
                    <?php echo esc_html($item->get_quantity()); ?>
                </td>
                <td class="td" style="text-align: left; vertical-align: middle; border: 1px solid #dddddd;">
                    <?php echo wp_kses_post($order->get_formatted_line_subtotal($item)); ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <?php
        $totals = $order->get_order_item_totals();

        if ($totals) {
            foreach ($totals as $total) {
                ?>
                <tr>
                    <th class="td" scope="row" colspan="2" style="text-align: right; border: 1px solid #dddddd;"><?php echo wp_kses_post($total['label']); ?></th>
                    <td class="td" style="text-align: left; border: 1px solid #dddddd;"><?php echo wp_kses_post($total['value']); ?></td>
                </tr>
                <?php
            }
        }
        ?>
    </tfoot>
</table>

<h2><?php _e('Adresa za dostavu', 'd-express-woo'); ?></h2>

<?php if ($order->get_shipping_address_1()): ?>
<address>
    <?php echo wp_kses_post($order->get_formatted_shipping_address() ); ?>
</address>
<?php else: ?>
<address>
    <?php echo wp_kses_post($order->get_formatted_billing_address() ); ?>
</address>
<?php endif; ?>

<p><?php _e('Hvala što kupujete kod nas.', 'd-express-woo'); ?></p>

<?php
/**
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action('woocommerce_email_footer', $email);