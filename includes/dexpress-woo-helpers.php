<?php

/**
 * D Express WooCommerce Helper funkcije
 */

defined('ABSPATH') || exit;


/**
 * Vraća mapu svih mogućih D Express statusa sa njihovim grupama
 *
 * @return array Mapa statusa
 */
function dexpress_get_all_status_codes()
{
    static $statuses = null;

    // Koristi keširanje da izbegneš ponavljanje upita
    if ($statuses === null) {
        global $wpdb;
        $results = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}dexpress_statuses_index ORDER BY id", ARRAY_A);

        $statuses = array();
        if (!empty($results)) {
            foreach ($results as $row) {
                // Određivanje grupe statusa na osnovu ID-a
                $group = 'transit'; // Default grupa
                $icon = 'dashicons-airplane'; // Default ikona
                $css_class = 'dexpress-status-transit'; // Default CSS klasa

                // Mapiranje statusa na grupe
                if (in_array($row['id'], ['1', '831', '843'])) {
                    $group = 'delivered';
                    $icon = 'dashicons-yes-alt';
                    $css_class = 'dexpress-status-delivered';
                } elseif (in_array($row['id'], ['-13', '-12', '-11', '5'])) {
                    $group = 'failed';
                    $icon = 'dashicons-no-alt';
                    $css_class = 'dexpress-status-failed';
                } elseif (in_array($row['id'], ['20'])) {
                    $group = 'returned';
                    $icon = 'dashicons-undo';
                    $css_class = 'dexpress-status-returned';
                } elseif (in_array($row['id'], ['21', '23'])) {
                    $group = 'returning';
                    $icon = 'dashicons-undo';
                    $css_class = 'dexpress-status-returning';
                } elseif (in_array($row['id'], ['8', '9', '10', '11', '19', '25', '108', '109', '110', '111', '119', '125', '822', '841'])) {
                    $group = 'problem';
                    $icon = 'dashicons-warning';
                    $css_class = 'dexpress-status-problem';
                } elseif (in_array($row['id'], ['6', '7', '12', '17', '106', '107', '112'])) {
                    $group = 'delayed';
                    $icon = 'dashicons-clock';
                    $css_class = 'dexpress-status-delayed';
                } elseif (in_array($row['id'], ['18', '118', '830', '842'])) {
                    $group = 'pending_pickup';
                    $icon = 'dashicons-location';
                    $css_class = 'dexpress-status-pending-pickup';
                } elseif (in_array($row['id'], ['3', '4', '30', '820', '840'])) {
                    $group = 'transit';
                    $icon = 'dashicons-airplane';
                    $css_class = 'dexpress-status-transit';
                } elseif (in_array($row['id'], ['0', '105', '123'])) {
                    $group = 'pending';
                    $icon = 'dashicons-clock';
                    $css_class = 'dexpress-status-pending';
                } elseif (in_array($row['id'], ['-1', '-2'])) {
                    $group = 'cancelled';
                    $icon = 'dashicons-dismiss';
                    $css_class = 'dexpress-status-cancelled';
                }

                $statuses[$row['id']] = [
                    'name' => $row['name'],
                    'group' => $group,
                    'icon' => $icon,
                    'css_class' => $css_class
                ];
            }
        } else {
            // Fallback na hardkodirane vrednosti ako iz nekog razloga nema podataka u bazi
            // Samo koristimo ID-jeve koje si dobio iz dokumentacije
            $statuses = [
                '-13' => ['name' => 'Nepovratno izgubljena', 'group' => 'failed', 'icon' => 'dashicons-no-alt', 'css_class' => 'dexpress-status-failed'],
                '-12' => ['name' => 'Totalno oštećena', 'group' => 'failed', 'icon' => 'dashicons-no-alt', 'css_class' => 'dexpress-status-failed'],
                '-11' => ['name' => 'Zaplenjena od strane inspekcije', 'group' => 'failed', 'icon' => 'dashicons-no-alt', 'css_class' => 'dexpress-status-failed'],
                '-2' => ['name' => 'Obrisana pošiljka', 'group' => 'cancelled', 'icon' => 'dashicons-dismiss', 'css_class' => 'dexpress-status-cancelled'],
                '-1' => ['name' => 'Storno isporuke', 'group' => 'cancelled', 'icon' => 'dashicons-dismiss', 'css_class' => 'dexpress-status-cancelled'],
                '0' => ['name' => 'Čeka na preuzimanje', 'group' => 'pending', 'icon' => 'dashicons-clock', 'css_class' => 'dexpress-status-pending'],
                '1' => ['name' => 'Pošiljka je isporučena primaocu', 'group' => 'delivered', 'icon' => 'dashicons-yes-alt', 'css_class' => 'dexpress-status-delivered'],
                '3' => ['name' => 'Pošiljka je preuzeta od pošiljaoca', 'group' => 'transit', 'icon' => 'dashicons-airplane', 'css_class' => 'dexpress-status-transit'],
                '4' => ['name' => 'Pošiljka zadužena za isporuku', 'group' => 'out_for_delivery', 'icon' => 'dashicons-arrow-right-alt', 'css_class' => 'dexpress-status-out-for-delivery'],
                '5' => ['name' => 'Pošiljka je odbijena od strane primaoca', 'group' => 'failed', 'icon' => 'dashicons-no-alt', 'css_class' => 'dexpress-status-failed'],
                '6' => ['name' => 'Pokušana isporuka, nema nikoga na adresi', 'group' => 'delayed', 'icon' => 'dashicons-clock', 'css_class' => 'dexpress-status-delayed'],
                '7' => ['name' => 'Pokušana isporuka, primalac je na godišnjem odmoru', 'group' => 'delayed', 'icon' => 'dashicons-clock', 'css_class' => 'dexpress-status-delayed'],
                '8' => ['name' => 'Pokušana isporuka, netačna je adresa primaoca', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '9' => ['name' => 'Pokušana isporuka, primalac nema novac', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '10' => ['name' => 'Sadržaj pošiljke nije odgovarajući', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '11' => ['name' => 'Pošiljka je oštećena-reklamacioni postupak', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '12' => ['name' => 'Isporuka odložena u dogovoru sa primaocem', 'group' => 'delayed', 'icon' => 'dashicons-clock', 'css_class' => 'dexpress-status-delayed'],
                '17' => ['name' => 'Isporuka samo određenim danima', 'group' => 'delayed', 'icon' => 'dashicons-clock', 'css_class' => 'dexpress-status-delayed'],
                '18' => ['name' => 'Primalac će doći po paket u magacin', 'group' => 'pending_pickup', 'icon' => 'dashicons-location', 'css_class' => 'dexpress-status-pending-pickup'],
                '19' => ['name' => 'Telefon primaoca netačan', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '20' => ['name' => 'Pošiljka je vraćena pošiljaocu', 'group' => 'returned', 'icon' => 'dashicons-undo', 'css_class' => 'dexpress-status-returned'],
                '21' => ['name' => 'Pošiljka se vraća pošiljaocu', 'group' => 'returning', 'icon' => 'dashicons-undo', 'css_class' => 'dexpress-status-returning'],
                '22' => ['name' => 'Ukinut povrat pošiljke', 'group' => 'transit', 'icon' => 'dashicons-airplane', 'css_class' => 'dexpress-status-transit'],
                '23' => ['name' => 'Zahtevan povrat po nalogu pošiljaoca', 'group' => 'returning', 'icon' => 'dashicons-undo', 'css_class' => 'dexpress-status-returning'],
                '25' => ['name' => 'Primalac se ne javlja na telefonski poziv', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '30' => ['name' => 'Međunarodna pošiljka - u tranzitu', 'group' => 'transit', 'icon' => 'dashicons-airplane', 'css_class' => 'dexpress-status-transit'],
                '105' => ['name' => 'Čeka na preuzimanje', 'group' => 'pending', 'icon' => 'dashicons-clock', 'css_class' => 'dexpress-status-pending'],
                '106' => ['name' => 'Odložena isporuka', 'group' => 'delayed', 'icon' => 'dashicons-clock', 'css_class' => 'dexpress-status-delayed'],
                '107' => ['name' => 'Odložena isporuka - godišnji odmor', 'group' => 'delayed', 'icon' => 'dashicons-clock', 'css_class' => 'dexpress-status-delayed'],
                '108' => ['name' => 'Netačna adresa', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '109' => ['name' => 'Nema novca za otkupninu', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '110' => ['name' => 'Neodgovarajući sadržaj', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '111' => ['name' => 'Oštećena pošiljka', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '112' => ['name' => 'Isporuka odložena - dogovor', 'group' => 'delayed', 'icon' => 'dashicons-clock', 'css_class' => 'dexpress-status-delayed'],
                '118' => ['name' => 'Paket za preuzimanje u magacinu', 'group' => 'pending_pickup', 'icon' => 'dashicons-location', 'css_class' => 'dexpress-status-pending-pickup'],
                '119' => ['name' => 'Netačan telefon', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '123' => ['name' => 'Isporuka u toku', 'group' => 'pending', 'icon' => 'dashicons-clock', 'css_class' => 'dexpress-status-pending'],
                '125' => ['name' => 'Primalac se ne javlja', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '820' => ['name' => 'Preusmerena na paketomat', 'group' => 'transit', 'icon' => 'dashicons-airplane', 'css_class' => 'dexpress-status-transit'],
                '822' => ['name' => 'Pošiljka ne može biti ispručena putem paketomata', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '830' => ['name' => 'Paket ostavljen u paketomatu', 'group' => 'pending_pickup', 'icon' => 'dashicons-location', 'css_class' => 'dexpress-status-pending-pickup'],
                '831' => ['name' => 'Paket izvađen iz paketomata', 'group' => 'delivered', 'icon' => 'dashicons-yes-alt', 'css_class' => 'dexpress-status-delivered'],
                '840' => ['name' => 'Preusmerena na paket šop', 'group' => 'transit', 'icon' => 'dashicons-airplane', 'css_class' => 'dexpress-status-transit'],
                '841' => ['name' => 'Pošiljka ne može biti ispručena putem paket šopa', 'group' => 'problem', 'icon' => 'dashicons-warning', 'css_class' => 'dexpress-status-problem'],
                '842' => ['name' => 'Pošiljka ostavljena u paket šopu', 'group' => 'pending_pickup', 'icon' => 'dashicons-location', 'css_class' => 'dexpress-status-pending-pickup'],
                '843' => ['name' => 'Pošiljka iznešena iz paket šopa', 'group' => 'delivered', 'icon' => 'dashicons-yes-alt', 'css_class' => 'dexpress-status-delivered'],
            ];
        }
    }

    return $statuses;
}
/**
 * Unapređena funkcija za strukturirano logovanje
 *
 * @param string $context Kontekst loga (npr. 'api', 'shipping', 'db')
 * @param string $message Poruka koja se loguje
 * @param string $level Nivo logovanja ('debug', 'info', 'warning', 'error')
 * @param array $data Dodatni podaci za logovanje (opciono)
 */
function dexpress_structured_log($context, $message, $level = 'info', $data = [])
{
    // Proveravamo da li je logovanje omogućeno
    static $logging_enabled = null;
    if ($logging_enabled === null) {
        $logging_enabled = (get_option('dexpress_enable_logging', 'no') === 'yes');
    }

    if (!$logging_enabled) {
        return;
    }

    // Proveravamo nivo logovanja
    $min_level = get_option('dexpress_log_level', 'debug');
    $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    if (!isset($levels[$level]) || !isset($levels[$min_level])) {
        $level = 'info';
        $min_level = 'debug';
    }

    if ($levels[$level] < $levels[$min_level]) {
        return; // Preskačemo logovanje za nivoe ispod konfigurisanog minimuma
    }

    // Formiranje strukturirane poruke
    $log_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'context' => $context,
        'level' => $level,
        'message' => $message
    ];

    // Dodavanje dodatnih podataka ako postoje
    if (!empty($data)) {
        $log_data['data'] = $data;
    }

    // Kreiranje log fajla sa kontekstom u nazivu (organizacija po kontekstu)
    $log_dir = DEXPRESS_WOO_PLUGIN_DIR . 'logs/';
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }

    // Različiti fajlovi za različite nivoe logovanja
    $log_file = $log_dir . "dexpress-{$context}-{$level}-" . date('Y-m-d') . '.log';

    // Zapisivanje u log fajl
    file_put_contents(
        $log_file,
        json_encode($log_data, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND
    );
}
/**
 * Logovanje poruka u fajl - kompatibilnost sa starim kodom
 *
 * @param mixed $message Poruka ili podaci za logovanje
 * @param string $type Tip loga (info, error, debug)
 */
function dexpress_log($message, $type = 'info')
{
    // Određivanje konteksta na osnovu backtrace-a ili defaultni kontekst
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = isset($backtrace[1]['class']) ? $backtrace[1]['class'] : '';
    $context = 'general';

    // Pokušaj otkrivanja konteksta iz naziva klase
    if (strpos($caller, 'D_Express_API') !== false) {
        $context = 'api';
    } elseif (strpos($caller, 'D_Express_Shipment') !== false) {
        $context = 'shipping';
    } elseif (strpos($caller, 'D_Express_DB') !== false) {
        $context = 'db';
    } elseif (strpos($caller, 'D_Express_Checkout') !== false) {
        $context = 'checkout';
    }

    // Priprema poruke
    if (is_array($message) || is_object($message)) {
        $message = print_r($message, true);
    }

    // Pozivanje nove struktuirane funkcije
    dexpress_structured_log($context, $message, $type);
}
/**
 * Poboljšano logovanje grešaka sa kontekstom
 *
 * @param string $message Poruka greške
 * @param array $context Kontekst greške (opciono)
 * @return void
 */
function dexpress_log_error($message, $context = array())
{
    if (get_option('dexpress_enable_logging', 'no') !== 'yes') {
        return;
    }

    $log_entry = array(
        'time' => current_time('mysql'),
        'message' => $message,
        'context' => $context
    );

    $log_dir = DEXPRESS_WOO_PLUGIN_DIR . 'logs/';

    // Kreiranje direktorijuma za logove ako ne postoji
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }

    $log_file = $log_dir . 'errors-' . date('Y-m-d') . '.log';
    file_put_contents(
        $log_file,
        json_encode($log_entry, JSON_PRETTY_PRINT) . "\n",
        FILE_APPEND
    );
}
/**
 * Vraća opcije za dropdown gradova
 *
 * @return array Opcije za dropdown
 */
function dexpress_get_towns_options()
{
    global $wpdb;

    // Proveri da li tabela postoji
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}dexpress_towns'");
    if (!$table_exists) {
        return array(); // Vrati prazan niz ako tabela ne postoji
    }

    $results = $wpdb->get_results(
        "SELECT id, name, display_name, postal_code FROM {$wpdb->prefix}dexpress_towns ORDER BY name ASC"
    );

    $options = array();
    if ($results) {
        foreach ($results as $town) {
            $display_name = !empty($town->display_name) ? $town->display_name : $town->name;
            $options[$town->id] = $display_name;
        }
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
    if (empty($status_code)) {
        return __('U obradi', 'd-express-woo');
    }

    // Prvo proveriti da li postoji u mapi svih statusa
    $all_statuses = dexpress_get_all_status_codes();
    if (isset($all_statuses[$status_code])) {
        return $all_statuses[$status_code]['name'];
    }

    return __('Nepoznat status', 'd-express-woo');
}
/**
 * Vraća CSS klasu za status pošiljke
 *
 * @param string $status_code Kod statusa
 * @return string CSS klasa
 */
function dexpress_get_status_css_class($status_code)
{
    if (empty($status_code)) {
        return 'dexpress-status-pending';
    }

    $all_statuses = dexpress_get_all_status_codes();
    if (isset($all_statuses[$status_code])) {
        return $all_statuses[$status_code]['css_class'];
    }

    return 'dexpress-status-transit';
}

/**
 * Vraća grupu za status pošiljke
 *
 * @param string $status_code Kod statusa
 * @return string Grupa statusa
 */
function dexpress_get_status_group($status_code)
{
    if (empty($status_code)) {
        return 'pending';
    }

    $all_statuses = dexpress_get_all_status_codes();
    if (isset($all_statuses[$status_code])) {
        return $all_statuses[$status_code]['group'];
    }

    return 'transit';
}

/**
 * Vraća ikonu za status pošiljke
 *
 * @param string $status_code Kod statusa
 * @return string Dashicons klasa
 */
function dexpress_get_status_icon($status_code)
{
    if (empty($status_code)) {
        return 'dashicons-clock';
    }

    $all_statuses = dexpress_get_all_status_codes();
    if (isset($all_statuses[$status_code])) {
        return $all_statuses[$status_code]['icon'];
    }

    return 'dashicons-airplane';
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
    $current_index = intval(get_option('dexpress_current_code_index', $range_start));

    // Povećaj index
    $current_index++;

    // UKLONI RESETOVANJE! Umesto toga, baci grešku
    if ($current_index > $range_end) {
        throw new Exception("Opseg kodova je iscrpljen! Trenutni opseg: {$range_start}-{$range_end}. Molimo proširite opseg u admin panelu.");
    }

    // Sačuvaj novi index
    update_option('dexpress_current_code_index', $current_index);

    // Formatiraj kod
    $formatted_code = $prefix . sprintf('%010d', $current_index);

    // Log generisan kod
    dexpress_log("Generisan kod paketa: {$formatted_code} (index: {$current_index})", 'debug');

    return $formatted_code;
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
        1 => __('Gotovina', 'd-express-woo'),
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
/**
 * Generiše sadržaj pošiljke na osnovu proizvoda u narudžbini
 * 
 * @param WC_Order $order WooCommerce narudžbina
 * @return string Formatiran sadržaj za nalepnicu
 */
function dexpress_generate_shipment_content($order)
{
    $content_setting = get_option('dexpress_content_type', 'category');
    $custom_content = get_option('dexpress_default_content', '');

    // Ako je podešeno na custom, vrati custom sadržaj
    if ($content_setting === 'custom') {
        return !empty($custom_content) ? $custom_content : 'Proizvod';
    }

    $items = $order->get_items();
    $categories = array();
    $total_items = 0;

    foreach ($items as $item) {
        if (!($item instanceof WC_Order_Item_Product)) {
            continue;
        }

        $product = $item->get_product();
        if (!$product) {
            continue;
        }

        $quantity = $item->get_quantity();
        $total_items += $quantity;

        switch ($content_setting) {
            case 'name':
                // KRATKI NAZIVI - samo prvi proizvod + ukupno
                static $first_item = null;

                if ($first_item === null) {
                    $name = $product->get_name();

                    // Skrati naziv ako je predugačak
                    if (strlen($name) > 20) {
                        $name = substr($name, 0, 17) . '...';
                    }

                    // Očisti nedozvoljene karaktere
                    $name = preg_replace('/[^\p{L}\p{N}\s\-,\(\)\/\.]/u', '', $name);
                    $name = preg_replace('/\s+/', ' ', trim($name));

                    if (empty($name)) {
                        $name = 'Proizvod';
                    }

                    $first_item = $name;
                }
                break;

            case 'category':
            case 'auto':
            default:
                // KATEGORIJE - ovo je najbolje za D Express
                $product_categories = wp_get_post_terms($product->get_id(), 'product_cat');
                if (!empty($product_categories) && !is_wp_error($product_categories)) {
                    $category_name = $product_categories[0]->name;
                    // Očisti kategoriju
                    $category_name = preg_replace('/[^\p{L}\p{N}\s\-,\(\)\/\.]/u', '', $category_name);
                    $category_name = preg_replace('/\s+/', ' ', trim($category_name));

                    if (!empty($category_name) && !in_array($category_name, $categories)) {
                        $categories[] = $category_name;
                    }
                } else {
                    // FALLBACK ako nema kategoriju
                    if (!in_array('Proizvod', $categories)) {
                        $categories[] = 'Proizvod';
                    }
                }
                break;
        }
    }

    // Generiši finalni sadržaj na osnovu opcije
    switch ($content_setting) {
        case 'name':
            // Za nazive - vrati prvi proizvod + ukupno
            if ($total_items > 1 && isset($first_item)) {
                $content = "{$total_items}x {$first_item}";
            } elseif (isset($first_item)) {
                $content = $first_item;
            } else {
                $content = $total_items > 1 ? "{$total_items}x Proizvod" : "Proizvod";
            }
            break;

        case 'category':
        case 'auto':
        default:
            // Za kategorije - generiši na osnovu kategorija
            if (!empty($categories)) {
                // Ukloni duplikate
                $categories = array_unique($categories);

                if (count($categories) === 1) {
                    // Jedna kategorija
                    $content = $total_items > 1 ?
                        "{$total_items}x {$categories[0]}" :
                        $categories[0];
                } else {
                    // Više kategorija - spoji ih zarezom
                    $content = $total_items > 1 ?
                        "{$total_items}x " . implode(', ', $categories) :
                        implode(', ', $categories);
                }
            } else {
                // Fallback
                $content = $total_items > 1 ? "{$total_items}x Proizvod" : "Proizvod";
            }
            break;
    }

    // Ograniči na 50 karaktera (D Express limit)
    if (strlen($content) > 50) {
        $content = substr($content, 0, 47) . '...';
    }

    // Finalna validacija
    if (empty($content) || !D_Express_Validator::validate_content($content)) {
        $content = 'Proizvod';
    }

    return $content;
}
