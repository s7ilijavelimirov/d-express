<?php

/**
 * D Express email - obavestenje o kreiranoj pošiljci za paketomat
 *
 * Template za email koji se šalje kada je pošiljka kreirana za paketomat
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

// Funkcija za formatiranje radnog vremena
function format_working_hours($hours)
{
    // Ako je format već human-friendly
    if (strpos($hours, "Svakim danom") !== false) {
        return $hours;
    }

    // Uklanjamo zagrade i razdvajamo po zarezima
    $clean_hours = str_replace(['(', ')'], '', $hours);
    $periods = explode(',', $clean_hours);

    // Ako su svi periodi isti, pojednostavljujemo
    $unique_periods = array_unique($periods);
    if (count($unique_periods) === 1) {
        return "Svakim danom " . trim($unique_periods[0]);
    }

    // Ako imamo 7 različitih dana
    if (count($periods) === 7) {
        return "Ponedeljak-Nedelja: " . trim($periods[0]);
    }

    return $hours; // Vraćamo originalni format ako ne možemo da ga raspoznamo
}

// Formatirano radno vreme
$formatted_hours = format_working_hours($dispenser->work_hours ?: '');

// Koordinate za Google Maps
$map_url = '';
if (!empty($dispenser->coordinates)) {
    $coordinates = json_decode($dispenser->coordinates, true);
    if (!empty($coordinates['latitude']) && !empty($coordinates['longitude'])) {
        $lat = $coordinates['latitude'];
        $lng = $coordinates['longitude'];
        $map_url = "https://www.google.com/maps/search/?api=1&query={$lat},{$lng}";
    }
}
?>

<div style="max-width: 600px; margin: 0 auto; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333;">

    <p style="font-size: 16px; margin-bottom: 20px;"><?php printf(__('Poštovani %s,', 'd-express-woo'), '<strong>' . esc_html($order->get_billing_first_name()) . '</strong>'); ?></p>

    <p style="font-size: 16px; line-height: 1.5; margin-bottom: 25px;">
        <?php _e('Vaša porudžbina je spremna i biće poslata putem D Express kurirske službe u izabrani <strong>paketomat</strong>.', 'd-express-woo'); ?>
    </p>

    <div style="background-color: #f7f7f7; border-left: 4px solid #6a1b9a; padding: 15px; margin-bottom: 25px;">
        <h2 style="color: #6a1b9a; margin-top: 0; margin-bottom: 15px; font-size: 18px;">
            <?php _e('Informacije o paketomatu', 'd-express-woo'); ?>
        </h2>

        <table cellspacing="0" cellpadding="8" style="width: 100%; border-collapse: collapse;">
            <tr>
                <th scope="row" style="text-align: left; padding: 10px 0; width: 40%; vertical-align: top; border-bottom: 1px solid #eee;">
                    <?php _e('Naziv:', 'd-express-woo'); ?>
                </th>
                <td style="text-align: left; padding: 10px 0; vertical-align: top; border-bottom: 1px solid #eee;">
                    <strong><?php echo esc_html($dispenser->name); ?></strong>
                </td>
            </tr>
            <tr>
                <th scope="row" style="text-align: left; padding: 10px 0; width: 40%; vertical-align: top; border-bottom: 1px solid #eee;">
                    <?php _e('Adresa:', 'd-express-woo'); ?>
                </th>
                <td style="text-align: left; padding: 10px 0; vertical-align: top; border-bottom: 1px solid #eee;">
                    <strong><?php echo esc_html($dispenser->address); ?>, <?php echo esc_html($dispenser->town); ?></strong>
                    <?php if (!empty($map_url)): ?>
                        <div style="margin-top: 5px;">
                            <a href="<?php echo esc_url($map_url); ?>" target="_blank" style="display: inline-block; padding: 5px 10px; background-color: #4285f4; color: white; text-decoration: none; border-radius: 3px; font-size: 13px;">
                                <img src="https://maps.google.com/mapfiles/ms/icons/red-dot.png" alt="Maps icon" width="16" height="16" style="vertical-align: middle; margin-right: 5px;">
                                <?php _e('Vidi na mapi', 'd-express-woo'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row" style="text-align: left; padding: 10px 0; width: 40%; vertical-align: top;">
                    <?php _e('Radno vreme:', 'd-express-woo'); ?>
                </th>
                <td style="text-align: left; padding: 10px 0; vertical-align: top;">
                    <strong><?php echo esc_html($formatted_hours ?: '00-24h (non-stop)'); ?></strong>
                </td>
            </tr>
        </table>
    </div>

    <div style="background-color: #f5f5f5; border-left: 4px solid #4caf50; padding: 15px; margin-bottom: 25px;">
        <h2 style="color: #2e7d32; margin-top: 0; margin-bottom: 15px; font-size: 18px;">
            <?php _e('Kako preuzeti pošiljku', 'd-express-woo'); ?>
        </h2>

        <ol style="margin-left: 5px; padding-left: 20px; line-height: 1.6;">
            <li style="margin-bottom: 10px;">
                <?php _e('Kada paket stigne u paketomat, <strong>dobićete SMS ili Viber poruku</strong> sa kodom za preuzimanje.', 'd-express-woo'); ?>
            </li>
            <li style="margin-bottom: 10px;">
                <?php _e('Na ekranu paketomata unesite dobijeni kod.', 'd-express-woo'); ?>
            </li>
            <?php if ($order->get_payment_method() === 'cod'): ?>
                <li style="margin-bottom: 10px;">
                    <?php _e('<strong>Pošto plaćate pouzećem</strong>, biće potrebno da na licu mesta platite iznos.', 'd-express-woo'); ?>
                </li>
            <?php endif; ?>
            <li style="margin-bottom: 10px;">
                <?php _e('Ormarić sa Vašim paketom će se automatski otvoriti, preuzmite paket.', 'd-express-woo'); ?>
            </li>
        </ol>

        <div style="margin-top: 15px; background-color: #fff3e0; border-left: 3px solid #ff9800; padding: 10px;">
            <p style="margin: 0;">
                <strong style="color: #e65100;"><?php _e('Važna napomena:', 'd-express-woo'); ?></strong>
                <?php _e('Rok za preuzimanje paketa je <strong>48 sati (2 dana)</strong> od prijema obaveštenja. Nakon tog perioda paket će biti vraćen pošiljaocu.', 'd-express-woo'); ?>
            </p>
        </div>
    </div>

    <div style="margin-bottom: 25px;">
        <h2 style="color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; font-size: 18px;">
            <?php _e('Detalji pošiljke', 'd-express-woo'); ?>
        </h2>

        <table cellspacing="0" cellpadding="8" style="width: 100%; border-collapse: collapse;">
            <tr>
                <th scope="row" style="text-align: left; padding: 8px 0; width: 40%; border-bottom: 1px solid #eee;">
                    <?php _e('Broj za praćenje:', 'd-express-woo'); ?>
                </th>
                <td style="text-align: left; padding: 8px 0; border-bottom: 1px solid #eee;">
                    <span style="font-family: 'Courier New', monospace; font-weight: bold; letter-spacing: 1px; background-color: #f5f5f5; padding: 3px 8px; border-radius: 3px;">
                        <?php echo esc_html($tracking_number); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th scope="row" style="text-align: left; padding: 8px 0; width: 40%; border-bottom: 1px solid #eee;">
                    <?php _e('Referentni broj:', 'd-express-woo'); ?>
                </th>
                <td style="text-align: left; padding: 8px 0; border-bottom: 1px solid #eee;">
                    <?php echo esc_html($reference_id); ?>
                </td>
            </tr>
            <tr>
                <th scope="row" style="text-align: left; padding: 8px 0; width: 40%;">
                    <?php _e('Datum slanja:', 'd-express-woo'); ?>
                </th>
                <td style="text-align: left; padding: 8px 0;">
                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($shipment_date))); ?>
                </td>
            </tr>
        </table>

        <?php if ($is_test): ?>
            <p style="background-color: #e8f5e9; padding: 10px; border-radius: 4px; font-style: italic;">
                <?php _e('Ovo je test pošiljka i ne može se pratiti na zvaničnom sajtu D Express kurira.', 'd-express-woo'); ?>
            </p>
        <?php else: ?>
            <p style="margin-top: 15px;">
                <?php _e('Možete pratiti vašu pošiljku klikom na link ispod:', 'd-express-woo'); ?>
            </p>
            <div style="text-align: center; margin: 20px 0;">
                <a href="<?php echo esc_url('https://www.dexpress.rs/rs/pracenje-posiljaka/' . $tracking_number); ?>"
                    target="_blank"
                    style="display: inline-block; background-color: #6a1b9a; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold;">
                    <?php _e('Prati pošiljku', 'd-express-woo'); ?> &#8594;
                </a>
            </div>
        <?php endif; ?>
    </div>

    <div style="margin-top: 30px; margin-bottom: 30px;">
        <h2 style="color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; font-size: 18px;">
            <?php _e('Detalji narudžbine', 'd-express-woo'); ?>
        </h2>

        <table cellspacing="0" cellpadding="6" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;" border="0">
            <thead>
                <tr>
                    <th scope="col" style="text-align: left; border-bottom: 2px solid #eee; padding: 10px; background-color: #f8f8f8;"><?php _e('Proizvod', 'd-express-woo'); ?></th>
                    <th scope="col" style="text-align: center; border-bottom: 2px solid #eee; padding: 10px; background-color: #f8f8f8;"><?php _e('Količina', 'd-express-woo'); ?></th>
                    <th scope="col" style="text-align: right; border-bottom: 2px solid #eee; padding: 10px; background-color: #f8f8f8;"><?php _e('Cena', 'd-express-woo'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($order->get_items() as $item_id => $item) :
                    $product = $item->get_product();
                ?>
                    <tr>
                        <td style="text-align: left; vertical-align: middle; border-bottom: 1px solid #eee; padding: 10px; word-wrap: break-word;">
                            <?php echo esc_html($item->get_name()); ?>
                        </td>
                        <td style="text-align: center; vertical-align: middle; border-bottom: 1px solid #eee; padding: 10px;">
                            <?php echo esc_html($item->get_quantity()); ?>
                        </td>
                        <td style="text-align: right; vertical-align: middle; border-bottom: 1px solid #eee; padding: 10px;">
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
                            <th scope="row" colspan="2" style="text-align: right; padding: 10px; border-top: 1px solid #eee; <?php echo ($total === end($totals)) ? 'font-weight: bold;' : ''; ?>">
                                <?php echo wp_kses_post($total['label']); ?>
                            </th>
                            <td style="text-align: right; padding: 10px; border-top: 1px solid #eee; <?php echo ($total === end($totals)) ? 'font-weight: bold;' : ''; ?>">
                                <?php echo wp_kses_post($total['value']); ?>
                            </td>
                        </tr>
                <?php
                    }
                }
                ?>
            </tfoot>
        </table>
    </div>

    <div style="margin-top: 30px; color: #555; font-style: italic; text-align: center; padding: 20px; border-top: 1px solid #eee;">
        <p><?php _e('Hvala što kupujete kod nas.', 'd-express-woo'); ?></p>
    </div>
</div>

<?php
/**
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action('woocommerce_email_footer', $email);
?>