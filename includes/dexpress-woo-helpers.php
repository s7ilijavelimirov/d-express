<?php

/**
 * D Express WooCommerce Helper funkcije
 */

defined('ABSPATH') || exit;

/**
 * Logovanje poruka u fajl
 *
 * @param mixed $message Poruka ili podaci za logovanje
 * @param string $type Tip loga (info, error, debug)
 */
function dexpress_log($message, $type = 'info')
{
    if (get_option('dexpress_enable_logging', 'no') !== 'yes') {
        return;
    }

    $log_dir = DEXPRESS_WOO_PLUGIN_DIR . 'logs/';

    // Kreiranje direktorijuma za logove ako ne postoji
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }

    // Kreiranje .htaccess fajla za sigurnost ako ne postoji
    if (!file_exists($log_dir . '.htaccess')) {
        file_put_contents($log_dir . '.htaccess', 'deny from all');
    }

    // Format ime fajla: dexpress-{tip}-{datum}.log
    $log_file = $log_dir . 'dexpress-' . $type . '-' . date('Y-m-d') . '.log';

    // Priprema poruke
    if (is_array($message) || is_object($message)) {
        $message = print_r($message, true);
    }

    // Dodavanje timestamp-a
    $log_message = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";

    // Pisanje u fajl
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

/**
 * Formatiranje statusa pošiljke
 *
 * @param string $status_code Kod statusa
 * @return string Formatiran status
 */
function dexpress_format_status($status_code)
{
    global $wpdb;

    $status = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dexpress_statuses_index WHERE id = %s",
        $status_code
    ));

    return $status ? $status->name : __('Nepoznat status', 'd-express-woo');
}

/**
 * Vraća opcije za dropdown gradova
 *
 * @return array Opcije za dropdown
 */
function dexpress_get_towns_options()
{
    global $wpdb;

    $towns = $wpdb->get_results(
        "SELECT id, name, display_name, postal_code FROM {$wpdb->prefix}dexpress_towns ORDER BY name ASC"
    );

    $options = array();

    foreach ($towns as $town) {
        $postal_info = $town->postal_code ? " ({$town->postal_code})" : '';
        $display_name = !empty($town->display_name) ? " - {$town->display_name}" : '';
        $options[$town->id] = $town->name . $display_name . $postal_info;
    }

    return $options;
}

/**
 * Vraća ulice za grad
 *
 * @param int $town_id ID grada
 * @return array Lista ulica
 */
function dexpress_get_streets_for_town($town_id)
{
    global $wpdb;

    $streets = $wpdb->get_results($wpdb->prepare(
        "SELECT id, name FROM {$wpdb->prefix}dexpress_streets WHERE town_id = %d ORDER BY name ASC",
        $town_id
    ));

    $options = array();

    foreach ($streets as $street) {
        $options[$street->id] = $street->name;
    }

    return $options;
}
/**
 * Vraća tekst za status pošiljke
 *
 * @param string $status_code Kod statusa
 * @return string Tekst statusa
 */
function dexpress_get_status_name($status_code)
{
    global $wpdb;

    $status = $wpdb->get_var($wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}dexpress_statuses_index WHERE id = %s",
        $status_code
    ));

    return $status ?: __('Nepoznat status', 'd-express-woo');
}
/**
 * Vraća tekst za PaymentBy opciju
 *
 * @param string $code Kod opcije
 * @return string Tekst opcije
 */
function dexpress_get_payment_by_text($code)
{
    $options = dexpress_get_payment_by_options();
    return isset($options[$code]) ? $options[$code] : __('Pošiljalac', 'd-express-woo');
}

/**
 * Vraća tekst za PaymentType opciju
 *
 * @param string $code Kod opcije
 * @return string Tekst opcije
 */
function dexpress_get_payment_type_text($code)
{
    $options = dexpress_get_payment_type_options();
    return isset($options[$code]) ? $options[$code] : __('Faktura', 'd-express-woo');
}

/**
 * Vraća tekst za ReturnDoc opciju
 *
 * @param string $code Kod opcije
 * @return string Tekst opcije
 */
function dexpress_get_return_doc_text($code)
{
    $options = dexpress_get_return_doc_options();
    return isset($options[$code]) ? $options[$code] : __('Bez povraćaja', 'd-express-woo');
}
/**
 * Vraća informacije o gradu prema ID-u
 *
 * @param int $town_id ID grada
 * @return object|null Podaci o gradu
 */
function dexpress_get_town_by_id($town_id)
{
    global $wpdb;

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dexpress_towns WHERE id = %d",
        $town_id
    ));
}

/**
 * Generisanje reference za pošiljku
 *
 * @param int $order_id ID narudžbine
 * @return string Jedinstvena referenca
 */
function dexpress_generate_reference($order_id)
{
    return 'ORDER-' . $order_id . '-' . time();
}

/**
 * Generisanje koda paketa
 *
 * @return string Kod paketa
 */
function dexpress_generate_package_code()
{
    $prefix = get_option('dexpress_code_prefix', 'TT');
    $range_start = intval(get_option('dexpress_code_range_start', 1));
    $range_end = intval(get_option('dexpress_code_range_end', 99));

    // Trenutni indeks
    $current_index = intval(get_option('dexpress_current_code_index', $range_start));

    // Povećanje indeksa
    $current_index++;

    // Vraćanje na početak ako smo došli do kraja opsega
    if ($current_index > $range_end) {
        $current_index = $range_start;
    }

    // Čuvanje novog indeksa
    update_option('dexpress_current_code_index', $current_index);

    // Formatiranje koda paketa
    return $prefix . str_pad($current_index, 10, '0', STR_PAD_LEFT);
}

/**
 * Vraća dostupne statuse narudžbina za automatsko kreiranje pošiljke
 *
 * @return array Status opcije
 */
function dexpress_get_order_status_options()
{
    return array(
        'processing' => __('Obrađuje se', 'd-express-woo'),
        'completed'  => __('Završeno', 'd-express-woo'),
        'custom'     => __('Korisnički status', 'd-express-woo'),
    );
}

/**
 * Vraća dostupne tipove pošiljki
 *
 * @return array Tipovi pošiljki
 */
function dexpress_get_shipment_types()
{
    return array(
        1 => __('Hitna isporuka (za danas)', 'd-express-woo'),
        2 => __('Redovna isporuka', 'd-express-woo')
    );
}

/**
 * Vraća opcije za plaćanje dostave
 *
 * @return array Opcije plaćanja
 */
function dexpress_get_payment_by_options()
{
    return array(
        0 => __('Pošiljalac', 'd-express-woo'),
        1 => __('Primalac', 'd-express-woo'),
        2 => __('Treća strana', 'd-express-woo'),
    );
}

/**
 * Vraća tipove plaćanja
 *
 * @return array Tipovi plaćanja
 */
function dexpress_get_payment_type_options()
{
    return array(
        0 => __('Gotovina', 'd-express-woo'),
        1 => __('Kartica', 'd-express-woo'),
        2 => __('Faktura', 'd-express-woo'),
    );
}

/**
 * Vraća opcije za povraćaj dokumenata
 *
 * @return array Opcije za povraćaj
 */
function dexpress_get_return_doc_options()
{
    return array(
        0 => __('Bez povraćaja', 'd-express-woo'),
        1 => __('Obavezan povraćaj', 'd-express-woo'),
        2 => __('Povraćaj ako je potrebno', 'd-express-woo'),
    );
}

/**
 * Provera da li je test režim aktivan
 *
 * @return bool True ako je test režim aktivan
 */
function dexpress_is_test_mode()
{
    return get_option('dexpress_test_mode', 'yes') === 'yes';
}

/**
 * Provera da li automatsko kreiranje pošiljke aktivno
 *
 * @return bool True ako je automatsko kreiranje aktivno
 */
function dexpress_is_auto_create_enabled()
{
    return get_option('dexpress_auto_create_shipment', 'no') === 'yes';
}

/**
 * Vraća status za automatsko kreiranje pošiljke
 *
 * @return string Status za automatsko kreiranje
 */
function dexpress_get_auto_create_status()
{
    return get_option('dexpress_auto_create_on_status', 'processing');
}

/**
 * Konvertuje masu iz kg u g
 *
 * @param float $weight Težina u kg
 * @return int Težina u g
 */
function dexpress_convert_weight_to_grams($weight)
{
    return intval($weight * 1000);
}

/**
 * Konvertuje cenu iz valute prodavnice u para (100 para = 1 RSD)
 *
 * @param float $price Cena u valuti prodavnice
 * @return int Cena u para
 */
function dexpress_convert_price_to_para($price)
{
    // Konverzija u RSD ako je potrebno
    $price_rsd = $price; // Ovde bi trebalo implementirati konverziju ako prodavnica ne koristi RSD

    // Konverzija u para (1 RSD = 100 para)
    return intval($price_rsd * 100);
}
