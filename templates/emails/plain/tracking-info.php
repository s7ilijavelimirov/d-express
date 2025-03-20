<?php

/**
 * D Express Tracking informacije u plain text email-u
 *
 * Template za prikaz tracking informacija u plain text email-u
 *
 * @package D_Express_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

echo "= " . __('Informacije o dostavi', 'd-express-woo') . " =\n\n";

echo __('Vaša narudžbina je poslata putem D Express kurirske službe.', 'd-express-woo') . "\n\n";

echo __('Broj za praćenje:', 'd-express-woo') . ' ' . $shipment->tracking_number . "\n";

if ($shipment->status_description) {
    echo __('Status:', 'd-express-woo') . ' ' . $shipment->status_description . "\n";
}

echo __('Datum kreiranja:', 'd-express-woo') . ' ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at)) . "\n\n";

if ($shipment->is_test) {
    echo __('Ovo je test pošiljka i ne može se pratiti na zvaničnom sajtu.', 'd-express-woo') . "\n\n";
} else {
    echo __('Možete pratiti status vaše pošiljke na sledećem linku:', 'd-express-woo') . "\n";
    echo 'https://www.dexpress.rs/rs/pracenje-posiljaka/' . $shipment->tracking_number . "\n\n";
}

echo __('Očekivano vreme isporuke je 1-3 radna dana.', 'd-express-woo') . "\n";
