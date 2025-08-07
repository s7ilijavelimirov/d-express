<?php

/**
 * D Express Shipment Service
 * 
 * Servisna klasa za upravljanje D Express pošiljkama
 */

defined('ABSPATH') || exit;

class D_Express_Shipment_Service
{

    /**
     * @var D_Express_API
     */
    private $api;

    /**
     * @var D_Express_DB
     */
    private $db;

    /**
     * Konstruktor
     */
    public function __construct()
    {
        $this->api = new D_Express_API();
        $this->db = new D_Express_DB();
        add_action('dexpress_after_shipment_created', array($this, 'send_tracking_email'), 10, 2);
    }
    /**
     * Kreiranje D Express pošiljke
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @param int $sender_location_id ID lokacije pošaljioce (opciono)
     * @return int|WP_Error ID pošiljke ili WP_Error
     */
    /**
     * Kreiranje D Express pošiljke - FINALNA VERZIJA
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @param int $sender_location_id ID lokacije pošaljioce (opciono)
     * @param string $package_code Unapred generisan package kod
     * @return array|WP_Error Array sa podacima o kreiranoj pošiljci ili WP_Error
     */
    public function create_shipment($order, $sender_location_id = null, $package_code = null)
    {
        try {
            $order_id = $order->get_id();

            // Početni log 
            dexpress_log('[SHIPPING] Započinjem kreiranje pošiljke za narudžbinu #' . $order_id, 'info');

            // Određivanje lokacije koja će se koristiti
            if (empty($sender_location_id)) {
                // Pokušaj dobijanje glavne lokacije
                $sender_locations_service = D_Express_Sender_Locations::get_instance();
                $sender_location = $sender_locations_service->get_default_location();

                if (!$sender_location) {
                    return new WP_Error('no_default_location', __('Nema definisane glavne lokacije pošiljaoca.', 'd-express-woo'));
                }

                $sender_location_id = $sender_location->id;
                dexpress_log('[SHIPPING] Koristi se glavna lokacija ID: ' . $sender_location_id . ' (' . $sender_location->name . ')', 'info');
            } else {
                // Validacija da prosleđena lokacija postoji
                $sender_locations_service = D_Express_Sender_Locations::get_instance();
                $sender_location = $sender_locations_service->get_location($sender_location_id);

                if (!$sender_location) {
                    return new WP_Error('invalid_location', __('Lokacija pošiljaoca nije pronađena.', 'd-express-woo'));
                }

                dexpress_log('[SHIPPING] Koristi se prosleđena lokacija ID: ' . $sender_location_id . ' (' . $sender_location->name . ')', 'info');
            }

            // Generiši package kod ako nije prosleđen
            if (empty($package_code)) {
                try {
                    $package_code = dexpress_generate_package_code();
                    dexpress_log('[SHIPPING] Generisan novi package kod: ' . $package_code, 'info');
                } catch (Exception $e) {
                    return new WP_Error('package_code_error', 'Greška pri generisanju package koda: ' . $e->getMessage());
                }
            } else {
                dexpress_log('[SHIPPING] Koristi prosleđeni package kod: ' . $package_code, 'info');
            }

            // Provera da li pošiljka već postoji
            $existing_shipment = $this->db->get_shipment_by_order_id($order_id);
            if ($existing_shipment) {
                return new WP_Error('shipment_exists', __('Pošiljka za ovu narudžbinu već postoji.', 'd-express-woo'));
            }

            // Validacija COD i bankovnog računa
            $payment_method = $order->get_payment_method();
            if ($payment_method === 'cod') {
                $buyout_account = !empty($sender_location->bank_account)
                    ? $sender_location->bank_account
                    : get_option('dexpress_buyout_account', '');

                if (empty($buyout_account) && get_option('dexpress_require_buyout_account', 'no') === 'yes') {
                    return new WP_Error(
                        'missing_buyout_account',
                        sprintf(
                            __('Lokacija "%s" nema bankovni račun za otkupninu, a COD je izabrano. Dodajte bankovni račun lokaciji ili u globalna podešavanja.', 'd-express-woo'),
                            $sender_location->name
                        )
                    );
                }
            }

            // Priprema podataka za API sa unapred generisanim package kodom
            dexpress_log('[SHIPPING] Priprema podataka za API...', 'debug');
            $shipment_data = $this->api->prepare_shipment_data_from_order($order, $sender_location_id, $package_code);

            if (is_wp_error($shipment_data)) {
                dexpress_log('[SHIPPING] Greška pri pripremi podataka: ' . $shipment_data->get_error_message(), 'error');
                return $shipment_data;
            }

            dexpress_log('[SHIPPING] Podaci pripremljeni, pozivam D Express API...', 'info');

            // Slanje zahteva ka D Express API
            $response = $this->api->add_shipment($shipment_data);

            if (is_wp_error($response)) {
                dexpress_log('[SHIPPING] API greška: ' . $response->get_error_message(), 'error');
                return $response;
            }

            // API Response Parsing prema dokumentaciji
            if (is_string($response)) {
                if ($response === 'TEST' || $response === 'OK') {
                    // Uspešan API odgovor
                    $api_response = $response; // "TEST" ili "OK"
                    $tracking_number = $package_code; // TT0000000026

                    dexpress_log('[SHIPPING] API uspešno odgovorio: ' . $api_response, 'info');
                } else {
                    // Error odgovor
                    dexpress_log('[SHIPPING] API greška: ' . $response, 'error');
                    return new WP_Error('api_error', 'D Express API greška: ' . $response);
                }
            } else {
                // Neočekivan format odgovora
                dexpress_log('[SHIPPING] API neočekivan odgovor: ' . print_r($response, true), 'error');
                return new WP_Error('api_error', 'Neočekivan format odgovora od D Express API-ja');
            }

            // Priprema zapisa za bazu
            $shipment_record = array(
                'order_id' => $order_id,
                'shipment_id' => $api_response,        // "TEST" ili "OK"
                'tracking_number' => $tracking_number, // "TT0000000026"
                'package_code' => $package_code,       // "TT0000000026"
                'reference_id' => $shipment_data['ReferenceID'],
                'sender_location_id' => $sender_location_id,
                'split_index' => null,
                'total_splits' => null,
                'parent_order_id' => null,
                'status_code' => dexpress_is_test_mode() ? '0' : null,
                'status_description' => dexpress_is_test_mode() ? 'Čeka na preuzimanje' : null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'shipment_data' => json_encode($response),
                'is_test' => dexpress_is_test_mode() ? 1 : 0
            );

            dexpress_log('[SHIPPING] Čuvam pošiljku u bazu podataka...', 'debug');

            // Čuvanje u bazu
            $insert_id = $this->db->add_shipment($shipment_record);

            if (!$insert_id) {
                dexpress_log('[SHIPPING] Greška pri čuvanju u bazu', 'error');
                return new WP_Error('db_error', __('Greška pri čuvanju pošiljke u bazu podataka.', 'd-express-woo'));
            }

            dexpress_log('[SHIPPING] Pošiljka sačuvana u bazu sa ID: ' . $insert_id, 'info');

            // Čuvanje paketa
            if (isset($shipment_data['PackageList']) && is_array($shipment_data['PackageList'])) {
                $package_index = 1;
                $total_packages = count($shipment_data['PackageList']);

                foreach ($shipment_data['PackageList'] as $package) {
                    $mass = isset($package['Mass']) ? $package['Mass'] : $shipment_data['Mass'];

                    $package_data = array(
                        'shipment_id' => $insert_id,
                        'package_code' => $package['Code'],
                        'package_reference_id' => $shipment_data['ReferenceID'] . '-P1',
                        'package_index' => $package_index,
                        'total_packages' => $total_packages,
                        'mass' => $mass,
                        'created_at' => current_time('mysql')
                    );

                    $package_id = $this->db->add_package($package_data);
                    dexpress_log('[SHIPPING] Paket sačuvan sa ID: ' . $package_id, 'debug');
                    $package_index++;
                }
            }
            // Dodavanje note u narudžbinu
            $note = sprintf(
                'D Express pošiljka kreirana. Tracking: %s, Lokacija: %s%s',
                $tracking_number,
                $sender_location->name,
                dexpress_is_test_mode() ? ' [TEST]' : ''
            );
            $order->add_order_note($note);

            // Hook za dodatne akcije nakon kreiranja pošiljke
            do_action('dexpress_after_shipment_created', $insert_id, $order);

            // Povratne informacije
            $result = array(
                'shipment_id' => $insert_id,
                'shipment_code' => $package_code,
                'tracking_number' => $tracking_number,
                'used_location_id' => $sender_location_id,
                'location_name' => $sender_location->name,
                'is_test' => dexpress_is_test_mode(),
                'api_response' => $api_response
            );

            dexpress_log('[SHIPPING] Pošiljka uspešno kreirana! ID: ' . $insert_id . ', Tracking: ' . $tracking_number . ', API odgovor: ' . $api_response, 'info');

            return $result;
        } catch (Exception $e) {
            dexpress_log('[SHIPPING] Exception pri kreiranju pošiljke: ' . $e->getMessage(), 'error');
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * Šalje email obaveštenje o promeni statusa pošiljke
     * 
     * @param object $shipment Podaci o pošiljci
     * @param WC_Order $order WooCommerce narudžbina
     * @param string $status_type Tip statusa (delivered, failed, itd.)
     * @return void
     */
    private function send_status_email($shipment, $order, $status_type)
    {
        $mailer = WC()->mailer();

        // Dobavljanje emaila kupca
        $recipient = $order->get_billing_email();

        // Naslov emaila zavisi od tipa statusa
        switch ($status_type) {
            case 'delivered':
                $subject = sprintf(__('Vaša porudžbina #%s je isporučena', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-delivered.php';
                break;
            case 'failed':
                $subject = sprintf(__('Problem sa isporukom porudžbine #%s', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-failed.php';
                break;
            default:
                $subject = sprintf(__('Ažuriranje statusa pošiljke za porudžbinu #%s', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-status-change.php';
        }

        $email_heading = $subject;

        // Pripremamo podatke za šablon
        $tracking_number = $shipment->tracking_number;
        $status_name = dexpress_get_status_name($shipment->status_code);

        // Kreiramo email objekat
        $email = new WC_Email();

        // Učitavanje sadržaja emaila iz šablona
        ob_start();
        include DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/' . $template;
        $email_content = ob_get_clean();

        // Slanje emaila
        $headers = "Content-Type: text/html\r\n";
        $mailer->send($recipient, $subject, $email_content, $headers);

        dexpress_log('[EMAIL] Poslat email o promeni statusa na: ' . $recipient, 'debug');
    }
    /**
     * Sinhronizacija statusa pošiljke koristeći lokalne podatke o statusima
     * 
     * @param int|string $shipment_id ID pošiljke ili tracking broj
     * @return bool|WP_Error True ako je sinhronizacija uspela, WP_Error ako nije
     */
    public function sync_shipment_status($shipment_id)
    {
        try {
            global $wpdb;

            // Preuzimanje informacija o pošiljci iz baze
            $shipment = null;

            // Dozvoli da shipment_id bude ili ID iz naše baze ili tracking broj
            if (is_numeric($shipment_id)) {
                $shipment = $this->db->get_shipment($shipment_id);
            } else {
                $shipment = $this->db->get_shipment_by_tracking($shipment_id);
            }

            if (!$shipment) {
                return new WP_Error('shipment_not_found', __('Pošiljka nije pronađena u sistemu.', 'd-express-woo'));
            }

            // Provera last-modified vremena za prečeste provere statusa
            $last_update = get_post_meta($shipment->order_id, '_dexpress_last_status_check', true);
            $current_time = time();

            // Ako je poslednja provera bila u poslednjih 15 minuta, preskačemo
            if (!empty($last_update) && (($current_time - intval($last_update)) < 900)) {
                return true; // Preskačemo proveru
            }

            // Ako je test pošiljka, postavićemo samo simluirani status
            if ($shipment->is_test) {
                // Simuliramo da je pošiljka isporučena nakon određenog vremena
                $created_time = strtotime($shipment->created_at);

                // Ako je prošlo više od 2 dana, označavamo kao isporučeno
                if (($current_time - $created_time) > (2 * 24 * 60 * 60) && $shipment->status_code != '1') {
                    $this->db->update_shipment_status($shipment->shipment_id, '1', __('Pošiljka isporučena (simulirano)', 'd-express-woo'));

                    // Dobavi narudžbinu i dodaj napomenu
                    $order = wc_get_order($shipment->order_id);
                    if ($order) {
                        $order->add_order_note(sprintf(
                            __('D Express status ažuriran: %s (simulirano za test pošiljku)', 'd-express-woo'),
                            dexpress_get_status_name('1')
                        ));

                        // Pošalji email o isporuci
                        $this->send_status_email($shipment, $order, 'delivered');
                    }
                }

                // Ažuriramo vreme poslednje provere
                update_post_meta($shipment->order_id, '_dexpress_last_status_check', $current_time);
                return true;
            }

            // Dohvatanje poslednjeg statusa iz baze - koristimo keš
            $cache_key = 'dexpress_status_' . $shipment->shipment_id;
            $latest_status = get_transient($cache_key);

            if ($latest_status === false) {
                // Ako nema u kešu, dohvatamo iz baze
                $latest_status = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}dexpress_statuses 
                WHERE (shipment_code = %s OR reference_id = %s) 
                ORDER BY status_date DESC LIMIT 1",
                    $shipment->shipment_id,
                    $shipment->reference_id
                ));

                // Čuvamo u kešu na 15 minuta
                if ($latest_status) {
                    set_transient($cache_key, $latest_status, 15 * MINUTE_IN_SECONDS);
                }
            }

            // Ako nema statusa u tabeli statusa, vraćamo true (nema promene)
            if (!$latest_status) {
                // Ažuriramo vreme poslednje provere
                update_post_meta($shipment->order_id, '_dexpress_last_status_check', $current_time);
                return true;
            }

            // Ažuriranje statusa u tabeli pošiljki
            if ($latest_status->status_id != $shipment->status_code) {
                // Dohvati opis statusa
                $status_description = $wpdb->get_var($wpdb->prepare(
                    "SELECT name FROM {$wpdb->prefix}dexpress_statuses_index WHERE id = %s",
                    $latest_status->status_id
                ));

                // Ažuriranje statusa u bazi
                $updated = $this->db->update_shipment_status(
                    $shipment->shipment_id,
                    $latest_status->status_id,
                    $status_description
                );

                if (!$updated) {
                    return new WP_Error('update_failed', __('Greška pri ažuriranju statusa pošiljke.', 'd-express-woo'));
                }

                // Invalidiramo keš za ovu pošiljku
                $this->db->clear_shipment_cache($shipment->order_id);
                delete_transient($cache_key);

                // Dobaviti narudžbinu i dodati napomenu o promeni statusa
                $order = wc_get_order($shipment->order_id);
                if ($order) {
                    $order->add_order_note(
                        sprintf(
                            __('D Express status ažuriran: %s', 'd-express-woo'),
                            $status_description
                        )
                    );

                    // Dohvatamo sve statuse i njihove grupe
                    $all_statuses = dexpress_get_all_status_codes();
                    $status_group = isset($all_statuses[$latest_status->status_id]) ? $all_statuses[$latest_status->status_id]['group'] : 'transit';

                    // Slanje email notifikacije kupcima za određene grupe statusa
                    if ($status_group === 'delivered') {
                        $this->send_status_email($shipment, $order, 'delivered');
                    } elseif ($status_group === 'failed') {
                        $this->send_status_email($shipment, $order, 'failed');
                    }

                    // Izvršiti hook za custom akcije nakon promene statusa
                    do_action('dexpress_shipment_status_updated', $shipment, $latest_status->status_id, $status_description, $order);
                }
            }

            // Ažuriramo vreme poslednje provere
            update_post_meta($shipment->order_id, '_dexpress_last_status_check', $current_time);

            return true;
        } catch (Exception $e) {
            dexpress_log('[STATUS] Exception pri sinhronizaciji statusa: ' . $e->getMessage(), 'error');
            return new WP_Error('exception', $e->getMessage());
        }
    }

    public function send_tracking_email($shipment_id, $order)
    {
        // Provera da li je već poslat email
        $email_sent = get_post_meta($order->get_id(), '_dexpress_tracking_email_sent', true);
        if ($email_sent) {
            return;
        }

        $mailer = WC()->mailer();
        $shipment = $this->db->get_shipment($shipment_id);

        if (!$shipment) {
            return;
        }

        // Dobavljanje email-a kupca
        $recipient = $order->get_billing_email();

        // Provera da li je dostava preko paketomata
        $dispenser_id = get_post_meta($order->get_id(), '_dexpress_dispenser_id', true);
        $is_dispenser = !empty($dispenser_id);
        $dispenser = null;

        if ($is_dispenser) {
            // Dohvati podatke o paketomatu
            global $wpdb;
            $dispenser = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dexpress_dispensers WHERE id = %d",
                intval($dispenser_id)
            ));
        }

        // Naslov email-a
        $subject = sprintf(__('Praćenje pošiljke za narudžbinu #%s', 'd-express-woo'), $order->get_order_number());
        $email_heading = $subject;

        // Popuni podatke za šablon
        $tracking_number = $shipment->tracking_number;
        $reference_id = $shipment->reference_id;
        $shipment_date = $shipment->created_at;
        $is_test = $shipment->is_test;

        // Kreiramo email objekat
        $email = new WC_Email();

        // Učitavanje sadržaja email-a iz šablona
        // Koristimo različite šablone zavisno od toga da li je paketomat ili ne
        if ($is_dispenser && $dispenser) {
            ob_start();
            include DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/shipment-created-dispenser.php';
            $email_content = ob_get_clean();
        } else {
            ob_start();
            include DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/shipment-created.php';
            $email_content = ob_get_clean();
        }

        // Slanje email-a
        $headers = "Content-Type: text/html\r\n";
        $mailer->send($recipient, $subject, $email_content, $headers);

        // Označava da je email poslat
        update_post_meta($order->get_id(), '_dexpress_tracking_email_sent', 'yes');
    }
}
