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

        $this->register_hooks();
        $this->register_ajax_handlers();
        add_action('dexpress_after_shipment_created', array($this, 'send_tracking_email'), 10, 2);
    }
    public function register_hooks()
    {
        add_action('woocommerce_order_status_changed', array($this, 'maybe_create_shipment_on_status_change'), 10, 4);
    }
    /**
     * Kreiranje D Express pošiljke
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return int|WP_Error ID pošiljke ili WP_Error
     */
    public function create_shipment($order)
    {
        try {
            // Početni log 
            dexpress_log('[SHIPPING] Započinjem kreiranje pošiljke za narudžbinu #' . $order->get_id(), 'debug');

            // Provera da li pošiljka već postoji
            $existing = $this->db->get_shipment_by_order_id($order->get_id());

            if ($existing) {
                dexpress_log('[SHIPPING] Pošiljka već postoji za narudžbinu #' . $order->get_id(), 'debug');
                return new WP_Error('shipment_exists', __('Pošiljka već postoji za ovu narudžbinu.', 'd-express-woo'));
            }

            // Provera da li su postavljeni API kredencijali
            if (!$this->api->has_credentials()) {
                dexpress_log('[SHIPPING] Nedostaju API kredencijali za narudžbinu #' . $order->get_id(), 'error');
                return new WP_Error('missing_credentials', __('Nedostaju API kredencijali. Molimo podesite API kredencijale u podešavanjima.', 'd-express-woo'));
            }

            // Validacija adrese ako je opcija uključena
            if (get_option('dexpress_validate_address', 'yes') === 'yes') {
                dexpress_log('[SHIPPING] Proveravam adresu za narudžbinu #' . $order->get_id(), 'debug');
                $address_check = $this->api->validate_order_address($order);

                if (is_wp_error($address_check)) {
                    dexpress_log('[SHIPPING] Greška pri proveri adrese: ' . $address_check->get_error_message(), 'error');
                    return $address_check;
                }

                dexpress_log('[SHIPPING] Adresa validirana uspešno', 'debug');
            }

            // Dobijanje podataka za pošiljku
            dexpress_log('[SHIPPING] Priprema podataka za narudžbinu #' . $order->get_id(), 'debug');
            $shipment_data = $this->api->prepare_shipment_data_from_order($order);

            if (is_wp_error($shipment_data)) {
                dexpress_log('[SHIPPING] Greška pri pripremi podataka: ' . $shipment_data->get_error_message(), 'error');
                return $shipment_data;
            }

            // Logovanje u test modu
            if (dexpress_is_test_mode()) {
                dexpress_log('Kreiranje pošiljke. Podaci: ' . print_r($shipment_data, true));
            }

            // Kreiranje pošiljke preko API-ja
            dexpress_log('[SHIPPING] Šaljem zahtev ka D-Express API-ju', 'debug');
            $response = $this->api->add_shipment($shipment_data);

            if (is_wp_error($response)) {
                dexpress_log('[SHIPPING] Greška pri kreiranju pošiljke: ' . $response->get_error_message(), 'error');
                return $response;
            }

            dexpress_log('[SHIPPING] API odgovor primljen uspešno', 'debug');

            // Kreiranje tracking broja
            $tracking_number = isset($response['TrackingNumber']) ? $response['TrackingNumber'] : $shipment_data['PackageList'][0]['Code'];
            $shipment_id = isset($response['ShipmentID']) ? $response['ShipmentID'] : $tracking_number;

            dexpress_log('[SHIPPING] Tracking broj: ' . $tracking_number . ', Shipment ID: ' . $shipment_id, 'debug');

            // Dodavanje napomene u narudžbinu
            $note = sprintf(
                __('D Express pošiljka je kreirana. Tracking broj: %s, Reference ID: %s', 'd-express-woo'),
                $tracking_number,
                $shipment_data['ReferenceID']
            );

            // Dobavi postojeće komentare
            $order_notes = wc_get_order_notes(['order_id' => $order->get_id()]);
            $note_exists = false;

            // Proveri da li napomena već postoji
            foreach ($order_notes as $order_note) {
                if (strpos($order_note->content, $tracking_number) !== false) {
                    $note_exists = true;
                    break;
                }
            }

            // Dodaj napomenu samo ako ne postoji
            if (!$note_exists) {
                $order->add_order_note($note);
            }

            // Čuvanje podataka o pošiljci u bazi
            dexpress_log('[SHIPPING] Čuvanje pošiljke u bazu podataka', 'debug');
            $shipment = array(
                'order_id' => $order->get_id(),
                'shipment_id' => $shipment_id,
                'tracking_number' => $tracking_number,
                'reference_id' => $shipment_data['ReferenceID'],
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'shipment_data' => json_encode($response),
                'is_test' => dexpress_is_test_mode() ? 1 : 0
            );

            $insert_id = $this->db->add_shipment($shipment);

            if (!$insert_id) {
                dexpress_log('[SHIPPING] Greška pri upisu pošiljke u bazu', 'error');
                return new WP_Error('db_error', __('Greška pri upisu pošiljke u bazu.', 'd-express-woo'));
            }

            // Čuvanje paketa
            if (isset($shipment_data['PackageList']) && is_array($shipment_data['PackageList'])) {
                foreach ($shipment_data['PackageList'] as $package) {
                    $mass = isset($package['Mass']) ? $package['Mass'] : $shipment_data['Mass'];

                    $package_data = array(
                        'shipment_id' => $insert_id,
                        'package_code' => $package['Code'],
                        'mass' => $mass,
                        'created_at' => current_time('mysql')
                    );

                    $this->db->add_package($package_data);
                }
            }

            // Uspešno kreiranje
            dexpress_log('[SHIPPING] Pošiljka uspešno kreirana sa ID: ' . $insert_id, 'debug');

            // Hook za dodatne akcije nakon kreiranja pošiljke
            do_action('dexpress_after_shipment_created', $insert_id, $order);
            return $insert_id;
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

            // Korak 1: Preuzimanje informacija o pošiljci iz baze
            dexpress_log('[STATUS] Započinjem sinhronizaciju statusa za pošiljku ID: ' . $shipment_id, 'debug');

            // Dozvoli da shipment_id bude ili ID iz naše baze ili tracking broj
            if (is_numeric($shipment_id)) {
                $shipment = $this->db->get_shipment($shipment_id);
            } else {
                $shipment = $this->db->get_shipment_by_tracking($shipment_id);
            }

            if (!$shipment) {
                dexpress_log('[STATUS] Pošiljka nije pronađena: ' . $shipment_id, 'error');
                return new WP_Error('shipment_not_found', __('Pošiljka nije pronađena u sistemu.', 'd-express-woo'));
            }

            // Ako je test pošiljka, postavićemo samo simluirani status
            if ($shipment->is_test) {
                dexpress_log('[STATUS] Test pošiljka, postavljam simulirani status', 'debug');
                // Simuliramo da je pošiljka isporučena nakon određenog vremena
                $created_time = strtotime($shipment->created_at);
                $current_time = time();

                // Ako je prošlo više od 2 dana, označavamo kao isporučeno
                if (($current_time - $created_time) > (2 * 24 * 60 * 60)) {
                    $this->db->update_shipment_status($shipment->shipment_id, '130', __('Pošiljka isporučena (simulirano)', 'd-express-woo'));
                    return true;
                }
                return true;
            }

            // Korak 2: Dohvatanje poslednjeg statusa iz baze
            dexpress_log('[STATUS] Dohvatam poslednji status za pošiljku: ' . $shipment->shipment_id, 'debug');

            $latest_status = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}dexpress_statuses 
                WHERE (shipment_code = %s OR reference_id = %s) 
                ORDER BY status_date DESC LIMIT 1",
                    $shipment->shipment_id,
                    $shipment->reference_id
                )
            );

            // Ako nema statusa u tabeli statusa, vraćamo true (nema promene)
            if (!$latest_status) {
                dexpress_log('[STATUS] Nema novih statusa za sinhronizaciju', 'debug');
                return true;
            }

            // Korak 3: Ažuriranje statusa u tabeli pošiljki
            if ($latest_status->status_id != $shipment->status_code) {
                dexpress_log('[STATUS] Novi status pronađen: ' . $latest_status->status_id, 'debug');

                // Dohvati opis statusa
                $status_description = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT name FROM {$wpdb->prefix}dexpress_statuses_index WHERE id = %s",
                        $latest_status->status_id
                    )
                );

                // Ažuriranje statusa u bazi
                $updated = $this->db->update_shipment_status(
                    $shipment->shipment_id,
                    $latest_status->status_id,
                    $status_description
                );

                if (!$updated) {
                    dexpress_log('[STATUS] Greška pri ažuriranju statusa u bazi', 'error');
                    return new WP_Error('update_failed', __('Greška pri ažuriranju statusa pošiljke.', 'd-express-woo'));
                }

                // Korak 4: Dobaviti narudžbinu i dodati napomenu o promeni statusa
                $order = wc_get_order($shipment->order_id);
                if ($order) {
                    $order->add_order_note(
                        sprintf(
                            __('D Express status ažuriran: %s', 'd-express-woo'),
                            $status_description
                        )
                    );

                    // Korak 5: Slanje email notifikacije kupcima za određene statuse
                    if ($latest_status->status_id == '130') { // Isporučeno
                        $this->send_status_email($shipment, $order, 'delivered');
                    } elseif ($latest_status->status_id == '131') { // Neuspešna isporuka
                        $this->send_status_email($shipment, $order, 'failed');
                    }

                    // Korak 6: Izvršiti hook za custom akcije nakon promene statusa
                    do_action('dexpress_shipment_status_updated', $shipment, $latest_status->status_id, $status_description, $order);
                }

                dexpress_log('[STATUS] Status uspešno ažuriran', 'debug');
                return true;
            } else {
                dexpress_log('[STATUS] Status je već ažuriran', 'debug');
                return true;
            }
        } catch (Exception $e) {
            dexpress_log('[STATUS] Exception pri sinhronizaciji statusa: ' . $e->getMessage(), 'error');
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * Obrada funkcije pri promeni statusa narudžbine
     * 
     * @param int $order_id ID narudžbine
     * @param string $from_status Prethodni status
     * @param string $to_status Novi status
     * @param WC_Order $order WooCommerce narudžbina
     * @return void
     */
    public function maybe_create_shipment_on_status_change($order_id, $from_status, $to_status, $order)
    {
        // Provera da li je automatsko kreiranje pošiljke omogućeno
        if (get_option('dexpress_auto_create_shipment', 'no') !== 'yes') {
            return;
        }

        // Provera da li je novi status onaj koji je postavljen za automatsko kreiranje pošiljke
        $auto_create_status = get_option('dexpress_auto_create_on_status', 'processing');
        if ($to_status !== $auto_create_status) {
            return;
        }

        // Provera da li pošiljka već postoji
        $existing = $this->db->get_shipment_by_order_id($order_id);
        if ($existing) {
            return;
        }

        // Provera da li je izabrana D Express dostava
        $has_dexpress_shipping = false;
        foreach ($order->get_shipping_methods() as $shipping_method) {
            if (strpos($shipping_method->get_method_id(), 'dexpress') !== false) {
                $has_dexpress_shipping = true;
                break;
            }
        }

        if (!$has_dexpress_shipping) {
            return;
        }

        // Kreiranje pošiljke
        $this->create_shipment($order);
    }
    /**
     * Registruje AJAX handler za kreiranje pošiljke
     */
    public function register_ajax_handlers()
    {
        add_action('wp_ajax_dexpress_create_shipment', array($this, 'ajax_create_shipment'));
    }
    // Dodaj u klasu D_Express_Shipment_Service
    public function send_tracking_email($shipment_id, $order)
    {
        $mailer = WC()->mailer();
        $shipment = $this->db->get_shipment($shipment_id);

        if (!$shipment) {
            return;
        }

        // Dobavljanje emaila kupca
        $recipient = $order->get_billing_email();

        // Naslov emaila
        $subject = sprintf(__('Praćenje pošiljke za narudžbinu #%s', 'd-express-woo'), $order->get_order_number());
        $email_heading = $subject; // Dodajemo email_heading varijablu

        // Popuni podatke za šablon
        $tracking_number = $shipment->tracking_number;
        $reference_id = $shipment->reference_id;
        $shipment_date = $shipment->created_at;
        $is_test = $shipment->is_test;

        // Kreiramo email objekat
        $email = new WC_Email(); // Dodajemo email objekat

        // Učitavanje sadržaja emaila iz šablona
        ob_start();
        include DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/shipment-created.php';
        $email_content = ob_get_clean();

        // Slanje emaila
        $headers = "Content-Type: text/html\r\n";
        $mailer->send($recipient, $subject, $email_content, $headers);
    }
    /**
     * AJAX handler za kreiranje pošiljke
     */
    public function ajax_create_shipment()
    {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-admin-nonce')) {
            wp_send_json_error(array(
                'message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')
            ));
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')
            ));
        }

        // Provera ID-a narudžbine
        if (!isset($_POST['order_id']) || empty($_POST['order_id'])) {
            wp_send_json_error(array(
                'message' => __('ID narudžbine je obavezan.', 'd-express-woo')
            ));
        }

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(array(
                'message' => __('Narudžbina nije pronađena.', 'd-express-woo')
            ));
        }

        // Kreiranje pošiljke
        $result = $this->create_shipment($order);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        } else {
            wp_send_json_success(array(
                'message' => __('Pošiljka je uspešno kreirana.', 'd-express-woo'),
                'shipment_id' => $result
            ));
        }
    }
}
