<?php

/**
 * D Express API klasa
 * 
 * Klasa za komunikaciju sa D Express API-em
 */

defined('ABSPATH') || exit;
class D_Express_API
{
    /**
     * API endpoint
     */
    const API_ENDPOINT = 'https://usersupport.dexpress.rs/ExternalApi';

    /**
     * API kredencijali
     */
    private $username;
    private $password;
    private $client_id;
    /**
     * Singleton instanca
     */
    private static $instance = null;

    /**
     * Dobijanje singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    /**
     * Test mode flag
     */
    private $test_mode;

    /**
     * Konstruktor
     */
    public function __construct()
    {
        $this->username = get_option('dexpress_api_username', '');
        $this->password = get_option('dexpress_api_password', '');
        $this->client_id = get_option('dexpress_client_id', '');
        $this->test_mode = dexpress_is_test_mode();
    }

    /**
     * Proverava da li su postavljeni API kredencijali
     */
    public function has_credentials()
    {
        return !empty($this->username) && !empty($this->password) && !empty($this->client_id);
    }

    /**
     * Osnovni API zahtev
     */
    private function api_request($endpoint, $method = 'GET', $data = null)
    {
        if (!$this->has_credentials()) {
            return new WP_Error('missing_credentials', __('Nedostaju API kredencijali', 'd-express-woo'));
        }

        set_time_limit(300); // 5 minuta
        ini_set('memory_limit', '256M');

        $url = self::API_ENDPOINT . '/' . ltrim($endpoint, '/');

        $args = array(
            'method'    => $method,
            'timeout'   => 180,
            'headers'   => array(
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            )
        );

        if ($data !== null && in_array($method, array('POST', 'PUT'))) {
            $args['body'] = json_encode($data);
        }

        // Logovanje zahteva u test modu
        if ($this->test_mode) {
            dexpress_raw_log("API Zahtev: {$url}", 'info', 'api');
            dexpress_raw_log("Metod: {$method}", 'info', 'api');

            if (!empty($data)) {
                $formatted_json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                dexpress_raw_log("Podaci za API:\n{$formatted_json}", 'info', 'api');
            } else {
                dexpress_raw_log("Podaci: nema", 'info', 'api');
            }

            dexpress_raw_log("--- SLANJE ZAHTEVA ---", 'info', 'api');
        }


        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code = $response->get_error_code();
            dexpress_log('API Greška: [' . $error_code . '] ' . $error_message, 'error');
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Logovanje odgovora u test modu
        if ($this->test_mode) {
            dexpress_raw_log("API Odgovor kod: {$response_code}", 'info', 'api');

            if (!empty($body)) {
                // Pokušaj formatirati JSON odgovor
                $decoded_body = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $formatted_response = json_encode($decoded_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    dexpress_raw_log("API Odgovor:\n{$formatted_response}", 'info', 'api');
                } else {
                    dexpress_raw_log("API Odgovor (text): {$body}", 'info', 'api');
                }
            }

            dexpress_raw_log("--- ZAVRŠEN ZAHTEV ---", 'info', 'api');
        }

        if ($response_code < 200 || $response_code >= 600) {
            $error_message = sprintf(__('API greška: %s', 'd-express-woo'), wp_remote_retrieve_response_message($response));
            dexpress_log('API Greška [' . $response_code . ']: ' . $error_message . ', Body: ' . $body, 'error');
            return new WP_Error(
                'api_error',
                $error_message,
                array('status' => $response_code, 'body' => $body)
            );
        }

        // Provera da li je odgovor JSON
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Neki API pozivi mogu vratiti običan tekst, tako da nije uvek greška
            if (strpos($body, 'ERROR') === 0) {
                dexpress_log('API je vratio grešku: ' . $body, 'error');
                return new WP_Error('api_error', $body);
            }

            return $body; // Vraćamo tekst ako nije JSON
        }

        return $data;
    }

    /**
     * Preuzimanje spiska D Express prodavnica
     */
    public function get_shops()
    {
        return $this->api_request('data/shops');
    }

    /**
     * Preuzimanje spiska D Express lokacija
     */
    public function get_locations()
    {
        return $this->api_request('data/locations');
    }

    /**
     * Preuzimanje spiska D Express automata za pakete
     */
    public function get_dispensers()
    {
        return $this->api_request('data/dispensers');
    }

    /**
     * Preuzimanje spiska D Express regionalnih centara
     */
    public function get_centres()
    {
        return $this->api_request('data/centres');
    }

    /**
     * Preuzimanje spiska statusa pošiljki
     */
    public function get_statuses()
    {
        return $this->api_request('data/statuses');
    }

    /**
     * Preuzimanje spiska opština
     */
    public function get_municipalities()
    {
        return $this->api_request('data/municipalities?date=20000101000000');
    }

    /**
     * Preuzimanje spiska gradova
     */
    public function get_towns()
    {
        return $this->api_request('data/towns?date=20000101000000');
    }

    /**
     * Preuzimanje spiska ulica
     */
    public function get_streets()
    {
        return $this->api_request('data/streets?date=20000101000000');
    }

    /**
     * Provera adrese
     * 
     * @param array $address_data Podaci adrese
     * @return array|WP_Error Odgovor API-ja ili greška
     */
    public function check_address($address_data)
    {
        return $this->api_request('data/checkaddress', 'POST', $address_data);
    }
    /**
     * Priprema podatke za proveru adrese iz WooCommerce narudžbine
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return array Podaci za proveru adrese
     */
    public function prepare_address_check_data($order)
    {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Nevažeća narudžbina', 'd-express-woo'));
        }

        // Odredite koji tip adrese koristiti
        $address_type = $order->has_shipping_address() ? 'shipping' : 'billing';

        // Dohvatite meta podatke za adresu
        $street = $order->get_meta("_{$address_type}_street", true);
        $number = $order->get_meta("_{$address_type}_number", true);
        $city_id = $order->get_meta("_{$address_type}_city_id", true);

        // Formatiranje telefonskog broja
        $phone = D_Express_Validator::format_phone($order->get_billing_phone());

        // Priprema podataka za proveru
        $check_data = array(
            'RName' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'RAddress' => !empty($street) ? $street : $order->get_shipping_address_1(),
            'RAddressNum' => !empty($number) ? $number : 'bb',
            'RTownID' => !empty($city_id) ? (int)$city_id : 100001,
            'RCName' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'RCPhone' => $phone
        );

        return $check_data;
    }
    /**
     * Provera adrese pre kreiranja pošiljke
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return bool|WP_Error True ako je adresa validna, WP_Error u suprotnom
     */
    public function validate_order_address($order)
    {
        $address_data = $this->prepare_address_check_data($order);

        if (is_wp_error($address_data)) {
            return $address_data;
        }

        dexpress_log("Checking address: " . print_r($address_data, true), 'debug');

        $response = $this->check_address($address_data);

        if (is_wp_error($response)) {
            dexpress_log("Address check failed: " . $response->get_error_message(), 'error');
            return $response;
        }

        dexpress_log("Address check response: " . print_r($response, true), 'debug');

        // Provera odgovora (struktura odgovora zavisi od implementacije API-ja)
        if (isset($response['IsValid']) && $response['IsValid'] === false) {
            return new WP_Error('invalid_address', isset($response['Message']) ? $response['Message'] : __('Adresa nije validna', 'd-express-woo'));
        }

        return true;
    }
    /**
     * Kreiranje pošiljke
     */
    public function add_shipment($shipment_data)
    {
        try {
            if (isset($shipment_data['DispenserID'])) {
                dexpress_log("[PAKETOMAT DEBUG] Kreiranje pošiljke za paketomat ID: " . $shipment_data['DispenserID'], 'info');
                dexpress_log("[PAKETOMAT DEBUG] Kompletan zahtev: " . print_r($shipment_data, true), 'info');
            }
            $payment_type = intval(get_option('dexpress_payment_type', 2));
            dexpress_log("[PAYMENT] Vrednost dexpress_payment_type iz opcija: " . $payment_type, 'info');

            // Dodavanje ClientID ako nije uključen u podatke
            if (!isset($shipment_data['CClientID'])) {
                $shipment_data['CClientID'] = $this->client_id;
            }

            // Validacija podataka pre slanja
            // Prvo proveravamo da li klasa i metoda postoje
            if (class_exists('D_Express_Validator') && method_exists('D_Express_Validator', 'validate_shipment_data')) {
                $validation = D_Express_Validator::validate_shipment_data($shipment_data);
                if ($validation !== true) {
                    dexpress_log("Validation failed: " . implode(", ", $validation), 'error');
                    return new WP_Error('validation_error', implode("<br>", $validation));
                }
            } else {
                dexpress_log("WARNING: D_Express_Validator::validate_shipment_data nije dostupna. Preskačem validaciju.", 'warning');
            }

            // Slanje zahteva i čuvanje odgovora
            $response = $this->api_request('data/addshipment', 'POST', $shipment_data);

            // Logovanje odgovora API-ja za paketomat
            if (isset($shipment_data['DispenserID']) && !is_wp_error($response)) {
                dexpress_log("[PAKETOMAT DEBUG] Odgovor API-ja: " . print_r($response, true), 'info');
            }

            return $response;
        } catch (Exception $e) {
            dexpress_log("Exception in add_shipment: " . $e->getMessage(), 'error');
            return new WP_Error('exception', $e->getMessage());
        }
    }
    /**
     * Izračunava vrednost artikala (bez dostave)
     */
    private function calculate_items_value($order)
    {
        $items_total = 0;

        foreach ($order->get_items() as $item) {
            if ($item instanceof WC_Order_Item_Product) {
                $items_total += ($item->get_total() + $item->get_total_tax());
            }
        }

        return dexpress_convert_price_to_para($items_total);
    }
    /**
     * Pregled plaćanja prema referenci
     */
    public function view_payments($payment_reference)
    {
        return $this->api_request('data/viewpayments?PaymentReference=' . urlencode($payment_reference));
    }
    /**
     * Ažurira sve šifarnike
     * 
     * @return bool True ako je uspešno, False ako nije
     */
    public function update_all_indexes()
    {
        global $wpdb;

        // Preuzimanje poslednjeg datuma ažuriranja
        $last_update = get_option('dexpress_last_index_update', '20000101000000');
        $current_time = current_time('mysql');

        // Definisanje redosleda ažuriranja i batch veličine za svaki entitet
        $entities = [
            'statuses' => ['batch_size' => 100, 'table' => 'dexpress_statuses_index'],
            'municipalities' => ['batch_size' => 100, 'table' => 'dexpress_municipalities'],
            'towns' => ['batch_size' => 200, 'table' => 'dexpress_towns'],
            'centres' => ['batch_size' => 50, 'table' => 'dexpress_centres'],
            'shops' => ['batch_size' => 100, 'table' => 'dexpress_shops'],
            'locations' => ['batch_size' => 100, 'table' => 'dexpress_locations'],
            'dispensers' => ['batch_size' => 100, 'table' => 'dexpress_dispensers'],
        ];

        try {
            // Inicijalna provera veze
            $connection_test = $this->test_connection();
            if (is_wp_error($connection_test)) {
                dexpress_log('Ažuriranje otkazano - neuspešan test konekcije: ' . $connection_test->get_error_message(), 'error');
                return false;
            }

            dexpress_log('Započeto ažuriranje šifarnika', 'info');

            // Ažuriranje statusa - ovo je mali skup podataka, ne treba batch
            $statuses = $this->get_statuses();
            if (!is_wp_error($statuses) && is_array($statuses)) {
                $this->update_statuses_index($statuses);
                dexpress_log('Uspešno ažuriran šifarnik statusa. Ukupno: ' . count($statuses), 'info');
            } else {
                dexpress_log('Greška pri ažuriranju statusa', 'error');
            }

            // Ažuriranje organizacija i lokacija
            $entities_to_update = ['shops', 'locations', 'dispensers', 'centres'];
            foreach ($entities_to_update as $entity) {
                $method_name = 'get_' . $entity;
                $update_method = 'update_' . $entity . '_index';

                if (method_exists($this, $method_name) && method_exists($this, $update_method)) {
                    $data = $this->$method_name();
                    if (!is_wp_error($data) && is_array($data)) {
                        // Batch obrada za veće skupove podataka
                        $batch_size = $entities[$entity]['batch_size'];
                        $total = count($data);

                        for ($i = 0; $i < $total; $i += $batch_size) {
                            $batch = array_slice($data, $i, $batch_size);
                            $this->$update_method($batch);

                            // Oslobađanje memorije
                            gc_collect_cycles();

                            dexpress_log(sprintf(
                                'Ažuriranje %s napredak: %d/%d',
                                $entity,
                                min($i + $batch_size, $total),
                                $total
                            ), 'debug');
                        }

                        dexpress_log("Uspešno ažuriran šifarnik za $entity. Ukupno: $total", 'info');
                    } else {
                        dexpress_log("Greška pri ažuriranju $entity", 'error');
                    }
                }
            }


            $municipalities = $this->get_municipalities();
            if (is_wp_error($municipalities)) {
                dexpress_log('Greška municipalities: ' . $municipalities->get_error_message(), 'error');
            } else {

                if (is_array($municipalities) && !empty($municipalities)) {
                    $this->update_municipalities_index($municipalities);
                } else {
                }
            }


            $towns = $this->get_towns();
            if (is_wp_error($towns)) {
                dexpress_log('Greška towns: ' . $towns->get_error_message(), 'error');
            } else {

                if (is_array($towns) && !empty($towns)) {
                    $this->update_towns_index($towns);
                    dexpress_log("Uspešno ažuriran šifarnik za towns. Ukupno: " . count($towns), 'info');
                } else {
                    dexpress_log('Towns: prazan ili nevažeći odgovor', 'warning');
                }
            }
            // Ulice se obrađuju posebno zbog veličine
            dexpress_log('Započeto ažuriranje ulica', 'info');
            $streets = $this->get_streets($last_update);
            if (!is_wp_error($streets) && is_array($streets)) {
                $this->update_streets_batch($streets);
                dexpress_log('Uspešno ažuriran šifarnik ulica. Ukupno: ' . count($streets), 'info');
            } else {
                dexpress_log('Greška pri ažuriranju ulica', 'error');
            }

            // Ažuriranje vremena poslednjeg ažuriranja
            update_option('dexpress_last_index_update', date('YmdHis'));
            dexpress_log('Ažuriranje šifarnika uspešno završeno', 'info');

            return true;
        } catch (Exception $e) {
            dexpress_log('Greška pri ažuriranju šifarnika: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    /**
     * Ažuriranje indeksa prodavnica
     */
    public function update_shops_index($shops)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_shops';

        foreach ($shops as $shop) {
            $wpdb->replace(
                $table_name,
                array(
                    'id' => $shop['ID'],
                    'name' => $shop['Name'],
                    'description' => isset($shop['Description']) ? $shop['Description'] : null,
                    'address' => isset($shop['Address']) ? $shop['Address'] : null,
                    'town' => isset($shop['Town']) ? $shop['Town'] : null,
                    'town_id' => isset($shop['TownID']) ? $shop['TownID'] : null,
                    'working_hours' => isset($shop['WorkingHours']) ? $shop['WorkingHours'] : null,
                    'work_days' => isset($shop['WorkDays']) ? $shop['WorkDays'] : null,
                    'phone' => isset($shop['Phone']) ? $shop['Phone'] : null, // ← DODAJ OVO!
                    'latitude' => isset($shop['Latitude']) ? $shop['Latitude'] : null,
                    'longitude' => isset($shop['Longitude']) ? $shop['Longitude'] : null,
                    'location_type' => isset($shop['LocationType']) ? $shop['LocationType'] : null,
                    'pay_by_cash' => isset($shop['PayByCash']) ? (int)$shop['PayByCash'] : 0,
                    'pay_by_card' => isset($shop['PayByCard']) ? (int)$shop['PayByCard'] : 0,
                    'last_updated' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s')
            );
        }
    }
    /**
     * Ažuriranje indeksa opština
     */
    public function update_municipalities_index($municipalities)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_municipalities';

        foreach ($municipalities as $municipality) {
            $wpdb->replace(
                $table_name,
                array(
                    'id' => $municipality['Id'],               // Jedinstveni ID opštine
                    'name' => $municipality['Name'],           // Naziv opštine
                    'ptt_no' => $municipality['PttNo'],        // PTT broj opštine
                    'order_num' => $municipality['O'],         // Redosled (Order)
                    'last_updated' => current_time('mysql')
                ),
                array('%d', '%s', '%d', '%d', '%s')
            );
        }
    }
    /**
     * Ažuriranje indeksa centara
     */
    public function update_centres_index($centres)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_centres';

        foreach ($centres as $centre) {
            $wpdb->replace(
                $table_name,
                array(
                    'id' => $centre['ID'],
                    'name' => $centre['Name'],
                    'prefix' => isset($centre['Prefix']) ? $centre['Prefix'] : null,
                    'address' => isset($centre['Address']) ? $centre['Address'] : null,
                    'town' => isset($centre['Town']) ? $centre['Town'] : null,
                    'town_id' => isset($centre['TownID']) ? $centre['TownID'] : null,
                    'phone' => isset($centre['Phone']) ? $centre['Phone'] : null,
                    'latitude' => isset($centre['Latitude']) ? $centre['Latitude'] : null,
                    'longitude' => isset($centre['Longitude']) ? $centre['Longitude'] : null,
                    'working_hours' => isset($centre['WorkingHours']) ? $centre['WorkingHours'] : null,
                    'work_hours' => isset($centre['WorkHours']) ? $centre['WorkHours'] : null,
                    'work_days' => isset($centre['WorkDays']) ? $centre['WorkDays'] : null,
                    'last_updated' => current_time('mysql')
                ),
                array(
                    '%d', // id
                    '%s', // name
                    '%s', // prefix
                    '%s', // address
                    '%s', // town
                    '%d', // town_id
                    '%s', // phone
                    '%s', // latitude
                    '%s', // longitude
                    '%s', // working_hours
                    '%s', // work_hours
                    '%s', // work_days
                    '%s'  // last_updated
                )
            );
        }
    }
    /**
     * Ažuriranje indeksa gradova
     */
    public function update_towns_index($towns)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_towns';

        foreach ($towns as $town) {
            $wpdb->replace(
                $table_name,
                array(
                    'id' => $town['Id'],
                    'name' => $town['Name'],
                    'display_name' => isset($town['DName']) ? $town['DName'] : null,
                    'center_id' => isset($town['CentarId']) ? $town['CentarId'] : null,
                    'municipality_id' => isset($town['MId']) ? $town['MId'] : null,
                    'order_num' => isset($town['O']) ? $town['O'] : null,
                    'postal_code' => isset($town['PttNo']) ? $town['PttNo'] : null,
                    'delivery_days' => isset($town['DeliveryDays']) ? $town['DeliveryDays'] : null,
                    'cut_off_pickup_time' => isset($town['CutOffPickupTime']) ? $town['CutOffPickupTime'] : null,
                    'last_updated' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s')
            );
        }
    }
    /**
     * Ažuriranje indeksa lokacija
     */
    public function update_locations_index($locations)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_locations';

        foreach ($locations as $location) {
            $wpdb->replace(
                $table_name,
                array(
                    'id' => $location['ID'],
                    'name' => $location['Name'],
                    'description' => isset($location['Description']) ? $location['Description'] : null,
                    'address' => isset($location['Address']) ? $location['Address'] : null,
                    'town' => isset($location['Town']) ? $location['Town'] : null,
                    'town_id' => isset($location['TownID']) ? $location['TownID'] : null,
                    'working_hours' => isset($location['WorkingHours']) ? $location['WorkingHours'] : null,
                    'work_hours' => isset($location['WorkHours']) ? $location['WorkHours'] : null,
                    'work_days' => isset($location['WorkDays']) ? $location['WorkDays'] : null,
                    'phone' => isset($location['Phone']) ? $location['Phone'] : null,
                    'latitude' => isset($location['Latitude']) ? $location['Latitude'] : null,
                    'longitude' => isset($location['Longitude']) ? $location['Longitude'] : null,
                    'location_type' => isset($location['LocationType']) ? $location['LocationType'] : null,
                    'pay_by_cash' => isset($location['PayByCash']) ? (int)$location['PayByCash'] : 0,
                    'pay_by_card' => isset($location['PayByCard']) ? (int)$location['PayByCard'] : 0,
                    'last_updated' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s')
            );
        }
    }
    /**
     * Ažuriranje ulica u batch režimu za bolju performansu
     * 
     * @param array $streets Lista ulica za ažuriranje
     * @return void
     */
    public function update_streets_batch($streets)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_streets';

        // Manja batch veličina za ulice zbog velikog broja
        $batch_size = 500;
        $total = count($streets);

        // Čistimo memoriju pre obrade
        gc_enable();

        for ($i = 0; $i < $total; $i += $batch_size) {
            $batch = array_slice($streets, $i, $batch_size);
            $values = [];
            $place_values = [];
            $all_params = [];

            foreach ($batch as $street) {
                $id = intval($street['Id']);
                $name = sanitize_text_field($street['Name']);
                $tid = intval($street['TId']);
                $deleted = isset($street['Del']) && $street['Del'] ? 1 : 0;
                $date = current_time('mysql');

                $place_values[] = "(%d, %s, %d, %d, %s)";
                array_push($all_params, $id, $name, $tid, $deleted, $date);
            }

            // Pripremi bulk upit sa ON DUPLICATE KEY UPDATE
            $query = "INSERT INTO $table_name (id, name, TId, deleted, last_updated) 
                  VALUES " . implode(', ', $place_values) . "
                  ON DUPLICATE KEY UPDATE 
                  name = VALUES(name), 
                  TId = VALUES(TId), 
                  deleted = VALUES(deleted), 
                  last_updated = VALUES(last_updated)";

            // Pripremljeni upit
            $prepared_query = $wpdb->prepare($query, $all_params);

            // Izvršavanje upita
            if (false === $wpdb->query($prepared_query)) {
                dexpress_log("Greška ažuriranja ulica: " . $wpdb->last_error, 'error');
            }

            // Oslobađamo memoriju
            unset($batch);
            unset($values);
            unset($place_values);
            unset($all_params);

            // Forsiramo oslobađanje memorije
            gc_collect_cycles();

            // Logovanje progresa
            dexpress_log(sprintf('Ažuriranje ulica napredak: %d/%d', min($i + $batch_size, $total), $total));
        }
    }

    /**
     * Ažuriranje indeksa statusa
     */
    public function update_statuses_index($statuses)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_statuses_index';

        foreach ($statuses as $status) {
            $wpdb->replace(
                $table_name,
                array(
                    'id' => $status['ID'],
                    'name' => $status['Name'],
                    'description' => isset($status['NameEn']) ? $status['NameEn'] : null,
                    'last_updated' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s')
            );
        }
    }

    /**
     * Ažuriranje indeksa automata za pakete
     */
    public function update_dispensers_index($dispensers)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_dispensers';

        foreach ($dispensers as $dispenser) {
            $wpdb->replace(
                $table_name,
                array(
                    'id' => $dispenser['ID'],
                    'name' => $dispenser['Name'],
                    'address' => isset($dispenser['Address']) ? $dispenser['Address'] : null,
                    'town' => isset($dispenser['Town']) ? $dispenser['Town'] : null,
                    'town_id' => isset($dispenser['TownID']) ? $dispenser['TownID'] : null,
                    'work_hours' => isset($dispenser['WorkHours']) ? $dispenser['WorkHours'] : null,
                    'work_days' => isset($dispenser['WorkDays']) ? $dispenser['WorkDays'] : null,
                    'latitude' => isset($dispenser['Latitude']) ? (float)$dispenser['Latitude'] : null,
                    'longitude' => isset($dispenser['Longitude']) ? (float)$dispenser['Longitude'] : null,
                    'pay_by_cash' => isset($dispenser['PayByCash']) ? (int)$dispenser['PayByCash'] : 0,
                    'pay_by_card' => isset($dispenser['PayByCard']) ? (int)$dispenser['PayByCard'] : 0,
                    'last_updated' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s')
            );
        }
    }

    /**
     * Generiše jedinstveni ReferenceID za pošiljku
     */
    public function generate_reference_id($order_id)
    {
        // Format: ORDER-{order_id}-{timestamp}
        $reference = dexpress_generate_reference($order_id);

        // Provera usklađenosti s API formatom
        // ^([\-\#\$a-zžćčđšA-ZĐŠĆŽČ_0-9,:;\+\(\)\/\.]+)( [\-\#\$a-zžćčđšA-ZĐŠĆŽČ_0-9,:;\+\(\)\/\.]+)*$

        return $reference;
    }
    /**
     * Formatira broj računa za otkupninu
     * 
     * @param string $account_number Neobrađeni broj računa
     * @return string Pravilno formatiran broj računa
     */
    public function format_bank_account($account_number)
    {
        // Ukloni sve osim brojeva
        $digits_only = preg_replace('/[^0-9]/', '', $account_number);

        dexpress_log("[BANK DEBUG] Original: {$account_number}, Digits: {$digits_only}, Length: " . strlen($digits_only), 'debug');

        // Standardni srpski format: 3-10-2 = 15 cifara
        if (strlen($digits_only) === 15) {
            $formatted = substr($digits_only, 0, 3) . '-' .
                substr($digits_only, 3, 10) . '-' .
                substr($digits_only, 13, 2);
            dexpress_log("[BANK DEBUG] Formatted (15): {$formatted}", 'debug');
            return $formatted;
        }

        // Neki bankovni računi mogu imati 16-18 cifara
        if (strlen($digits_only) >= 16 && strlen($digits_only) <= 18) {
            $formatted = substr($digits_only, 0, 3) . '-' .
                substr($digits_only, 3, strlen($digits_only) - 5) . '-' .
                substr($digits_only, -2);
            dexpress_log("[BANK DEBUG] Formatted (16-18): {$formatted}", 'debug');
            return $formatted;
        }

        dexpress_log("[BANK DEBUG] ERROR: Invalid length " . strlen($digits_only) . " for account: {$account_number}", 'error');
        return $account_number; // Vrati original ako ne možemo formatirati
    }
    /**
     * Izračunava BuyOut na osnovu metode plaćanja i besplatne dostave
     * 
     * @param WC_Order $order
     * @return int BuyOut iznos u para
     */
    private function calculate_buyout_amount($order)
    {
        // Cena proizvoda u para (bez dostave)
        $items_value_para = $this->calculate_items_value($order);

        // Ko plaća dostavu određuje BuyOut logiku
        $payment_by = intval(get_option('dexpress_payment_by', 0));

        // Cena dostave
        $shipping_cost = 0;
        foreach ($order->get_shipping_methods() as $method) {
            $shipping_cost = floatval($method->get_total());
            break;
        }

        $shipping_para = dexpress_convert_price_to_para($shipping_cost);

        switch ($payment_by) {
            case 0: // Sender (TI plaćaš transport)
                // Kupac plaća samo proizvode + dostavu (ako nije besplatna)
                if ($shipping_cost > 0) {
                    return $items_value_para + $shipping_para; // Proizvodi + dostava
                } else {
                    return $items_value_para; // Samo proizvodi (besplatna dostava)
                }

            case 1: // Pickup location plaća transport  
                // Isto kao Sender
                return $items_value_para + ($shipping_cost > 0 ? $shipping_para : 0);

            case 2: // Recipient (KUPAC plaća i proizvode i transport)
                // Kupac plaća SVE - proizvode + dostavu uvek
                return $items_value_para + $shipping_para;

            default:
                // Fallback na Sender logiku
                return $items_value_para + ($shipping_cost > 0 ? $shipping_para : 0);
        }
    }
    /**
     * Priprema podatke pošiljke iz WooCommerce narudžbine
     */
    public function prepare_shipment_data_from_order($order, $sender_location_id = null, $package_code = null)
    {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Nevažeća narudžbina', 'd-express-woo'));
        }
        if ($sender_location_id) {
            $location = D_Express_Sender_Locations::get_instance()->get_location($sender_location_id);
        } else {
            $location = D_Express_Sender_Locations::get_instance()->get_default_location();
        }

        if (!$location) {
            return new WP_Error('no_sender_location', 'Nema definisane sender lokacije');
        }
        $order_id = $order->get_id();

        // DEBUG LOG: Inicijalna vrednost iz WooCommerce
        dexpress_log("[API DEBUG] Inicijalna vrednost telefona iz WC: " . $order->get_billing_phone(), 'info');

        // Dohvatamo sačuvani API format telefona ako postoji
        $phone = get_post_meta($order_id, '_billing_phone_api_format', true);
        $payment_type = intval(get_option('dexpress_payment_type', 2));

        // Provera da li je izabran paketomat
        $dispenser_id = get_post_meta($order->get_id(), '_dexpress_dispenser_id', true);
        $is_dispenser = !empty($dispenser_id);

        // Dohvati podatke o paketomatu ako je izabran
        $dispenser = null;
        if ($is_dispenser) {
            global $wpdb;
            $dispenser = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dexpress_dispensers
            WHERE id = %d",
                intval($dispenser_id)
            ));

            if ($dispenser) {
                dexpress_log("[PAKETOMAT] Koristi se paketomat ID: {$dispenser->id}, Adresa: {$dispenser->address}, Town: {$dispenser->town}", 'info');
            } else {
                dexpress_log("[PAKETOMAT ERROR] Paketomat sa ID {$dispenser_id} nije pronađen u bazi!", 'error');
                return new WP_Error('dispenser_not_found', __('Izabrani paketomat nije pronađen. Molimo izaberite drugi paketomat.', 'd-express-woo'));
            }

            // Za paketomat uvek koristimo Faktura (2)
            $payment_type = 2;
            dexpress_log("[PAKETOMAT] Postavljanje payment_type na 2 (Faktura) za paketomat", 'info');
        }

        if (empty($phone)) {
            $phone = D_Express_Validator::format_phone($order->get_billing_phone());
            dexpress_log("[API DEBUG] Telefon formatiran standardno: {$phone}", 'info');
        } else {
            dexpress_log("[API DEBUG] Koristi se sačuvani API format telefona: {$phone}", 'info');
        }

        // Validacija telefonskog broja
        if (!D_Express_Validator::validate_phone($phone)) {
            dexpress_log("[API DEBUG] UPOZORENJE: Neispravan format telefona: {$phone}", 'info');
            // Umesto da postavimo default, vratićemo grešku
            return new WP_Error('invalid_phone', __('Neispravan format telefona. Format treba biti +381XXXXXXXXX', 'd-express-woo'));
        }
        // Odredite koji tip adrese koristiti
        $address_type = $order->has_shipping_address() ? 'shipping' : 'billing';
        $address_desc = $order->get_meta("_{$address_type}_address_desc", true);
        $delivery_note = $order->get_customer_note(); // Dobavljanje beleške kupca

        if (!empty($delivery_note)) {
            // 1. Uklanja sve nedozvoljene karaktere (samo dozvoljeni ostaju)
            $clean_note = preg_replace('/[^a-zžćčđšA-ZĐŠĆŽČ:,._0-9\-\s]/u', '', $delivery_note);

            // 2. Uklanja višestruke razmake
            $clean_note = preg_replace('/\s+/', ' ', $clean_note);

            // 3. Uklanja razmak na početku i kraju
            $clean_note = trim($clean_note);

            // 4. Osigurava da se tačka (.) ne nalazi na početku
            $clean_note = preg_replace('/^\./', '', $clean_note);

            // 5. Ograničava dužinu na 150 karaktera
            if (mb_strlen($clean_note, 'UTF-8') > 150) {
                $clean_note = mb_substr($clean_note, 0, 150, 'UTF-8');
            }

            $delivery_note = $clean_note; // Sačuvaj filtriranu vrednost
        }
        if (!empty($address_desc)) {
            $address_desc = preg_replace('/[^a-zžćčđšA-ZĐŠĆŽČ:,._0-9\-\s]/u', '', $address_desc);
            $address_desc = preg_replace('/\s+/', ' ', $address_desc);
            $address_desc = trim($address_desc);
            $address_desc = preg_replace('/^\./', '', $address_desc);
            if (mb_strlen($address_desc, 'UTF-8') > 150) {
                $address_desc = mb_substr($address_desc, 0, 150, 'UTF-8');
            }
        }
        // Dohvatite meta podatke za adresu
        $street = $order->get_meta("_{$address_type}_street", true);
        $number = $order->get_meta("_{$address_type}_number", true);
        $city_id = $order->get_meta("_{$address_type}_city_id", true);

        dexpress_log("Using address type: {$address_type}", 'debug');
        dexpress_log("Street: {$street}, Number: {$number}, City ID: {$city_id}", 'debug');

        // Postavke za otkupninu
        $buyout_amount = 0;
        $buyout_account = isset($location->bank_account) && !empty($location->bank_account) ? $location->bank_account : get_option('dexpress_buyout_account', '');

        // Prepoznavanje metode plaćanja
        $payment_method = $order->get_payment_method();
        $is_cod = ($payment_method === 'cod' || $payment_method === 'bacs' || $payment_method === 'cheque');
        dexpress_log("Payment method: {$payment_method}, Is COD: " . ($is_cod ? 'Yes' : 'No'), 'debug');

        if (!empty($buyout_account)) {
            $buyout_account = $this->format_bank_account($buyout_account);
            dexpress_log("[COD DEBUG] Formatiran račun: {$buyout_account}", 'debug');
        }

        // Izračunaj BuyOut na osnovu metode plaćanja
        if ($is_cod) {
            $buyout_amount = $this->calculate_buyout_amount($order);

            // Validacija limita otkupnine
            $max_buyout = $is_dispenser ? 20000000 : 1000000000;
            if ($buyout_amount > $max_buyout) {
                $max_rsd = $max_buyout / 100;
                return new WP_Error(
                    'buyout_limit_exceeded',
                    sprintf(
                        __('Vrednost otkupnine ne može biti veća od %s RSD.', 'd-express-woo'),
                        number_format($max_rsd, 2, ',', '.')
                    )
                );
            }

            // Validacija bankovnog računa za otkupninu
            if ($buyout_amount > 0) {
                if (empty($buyout_account)) {
                    $buyout_account = get_option('dexpress_buyout_account', '');
                }

                if (empty($buyout_account)) {
                    return new WP_Error(
                        'missing_buyout_account',
                        __('Nema definisanog bankovnog računa za otkupninu. Dodajte ga u podešavanjima.', 'd-express-woo')
                    );
                }

                $buyout_account = $this->format_bank_account($buyout_account);
                if (!D_Express_Validator::validate_bank_account($buyout_account)) {
                    return new WP_Error(
                        'invalid_buyout_account',
                        __('Neispravno formatiran bankovni račun.', 'd-express-woo')
                    );
                }
            }
        } else {
            $buyout_amount = 0;
        }

        // Validacija adrese primaoca
        if (empty($street) || !D_Express_Validator::validate_name($street)) {
            dexpress_log("WARNING: Invalid street name: {$street}", 'warning');
            return new WP_Error('invalid_street', __('Neispravan format ulice. Molimo unesite ispravnu ulicu.', 'd-express-woo'));
        }

        if (empty($number) || !D_Express_Validator::validate_address_number($number)) {
            dexpress_log("WARNING: Invalid address number: {$number}", 'warning');
            return new WP_Error('invalid_address_number', __('Neispravan format kućnog broja. Prihvatljiv format: bb, 10, 15a, 23/4', 'd-express-woo'));
        }

        if (empty($city_id) || !D_Express_Validator::validate_town_id($city_id)) {
            dexpress_log("WARNING: Invalid town ID: {$city_id}", 'warning');
            return new WP_Error('invalid_town', __('Neispravan grad. Molimo izaberite grad iz liste.', 'd-express-woo'));
        }

        $content = dexpress_generate_shipment_content($order);

        // Kreiranje reference
        $reference_id = apply_filters('dexpress_shipment_reference_id', $order->get_order_number());
        if (!D_Express_Validator::validate_reference($reference_id)) {
            dexpress_log("WARNING: Invalid reference ID: {$reference_id}", 'warning');
            $reference_id = "ORDER-" . $order->get_id(); // Pojednostavljena referenca
        }

        // Izračunaj težinu za porudžbinu
        $weight_grams = $this->calculate_order_weight($order);
        dexpress_log("[WEIGHT DEBUG] Težina iz calculate_order_weight: {$weight_grams} grama", 'debug');
        // Provera maksimalne težine (10.000 kg / 10.000.000 g)
        $max_weight = 10000000; // 10.000 kg
        if ($weight_grams > $max_weight) {
            return new WP_Error(
                'weight_limit_exceeded',
                sprintf(
                    __('Težina pošiljke ne može biti veća od %s kg.', 'd-express-woo'),
                    number_format($max_weight / 1000, 0, ',', '.')
                )
            );
        }

        // Provera vrednosti - vrednost pošiljke (Value) mora biti između 0 i 1.000.000.000 para (10.000.000 RSD)
        $value_para = dexpress_convert_price_to_para($order->get_total());
        $max_value = 1000000000; // 10.000.000 RSD

        if ($value_para > $max_value) {
            return new WP_Error(
                'value_limit_exceeded',
                sprintf(
                    __('Vrednost pošiljke ne može biti veća od %s RSD.', 'd-express-woo'),
                    number_format($max_value / 100, 2, ',', '.')
                )
            );
        }
        // Osnovni podaci o pošiljci
        $shipment_data = array(

            'CClientID' => $this->client_id,

            // Podaci o klijentu (pošiljaocu) - iz default lokacije
            'CName' => $location->contact_name,
            'CAddress' => $location->address,
            'CAddressNum' => $location->address_num,
            'CTownID' => intval($location->town_id),
            'CCName' => $location->contact_name,
            'CCPhone' => D_Express_Validator::format_phone($location->contact_phone),

            // Podaci o pošiljaocu (isto kao klijent)
            'PuClientID' => $this->client_id,
            'PuName' => $location->name,
            'PuAddress' => $location->address,
            'PuAddressNum' => $location->address_num,
            'PuTownID' => intval($location->town_id),
            'PuCName' => $location->contact_name,
            'PuCPhone' => D_Express_Validator::format_phone($location->contact_phone),

            // Podaci o primaocu - sa validiranim vrednostima
            'RName' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'RAddress' => $street,
            'RAddressNum' => $number,
            'RAddressDesc' => $address_desc,
            'RTownID' => (int)$city_id,
            'RCName' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'RCPhone' => $phone,
            'Note' => $delivery_note,

            // Tip pošiljke i plaćanje
            'DlTypeID' => intval(get_option('dexpress_shipment_type', 2)),
            'PaymentBy' => intval(get_option('dexpress_payment_by', 0)),
            'PaymentType' => $payment_type,

            // Vrednost i masa
            'Value' => $this->calculate_items_value($order),
            'Content' => dexpress_generate_shipment_content($order),
            'Mass' => $weight_grams,

            // Reference i opcije
            'ReferenceID' => $reference_id,
            'ReturnDoc' => intval(get_option('dexpress_return_doc', 0)),

            // Otkupnina - postavljeno na osnovu metode plaćanja
            'BuyOut' => $buyout_amount,
            'BuyOutFor' => intval(get_option('dexpress_buyout_for', 0)),
            'BuyOutAccount' => ($buyout_amount > 0) ? $buyout_account : '',

            // SelfDropOff - nova opcija
            'SelfDropOff' => intval(get_option('dexpress_self_drop_off', 0)),
        );
        dexpress_log("[WEIGHT DEBUG] Težina nakon formatiranja za API: {$shipment_data['Mass']} grama", 'debug');

        // VALIDACIJA: Proveri da paket ne prelazi 34kg
        if ($weight_grams > 34000) { // 34kg = 34000g
            return new WP_Error(
                'package_weight_limit',
                sprintf(
                    __('Paket ne može biti teži od 34kg. Trenutna težina: %s kg. Molimo podelite narudžbinu u više paketa.', 'd-express-woo'),
                    number_format($weight_grams / 1000, 1, ',', '.')
                )
            );
        }

        $shipment_data['PackageList'] = array(
            array(
                'Code' => $package_code,
                'Mass' => $weight_grams
            )
        );
        // Finalna validacija BuyOut vs API limiti
        if ($buyout_amount > 1000000000) {
            return new WP_Error(
                'api_buyout_limit',
                __('BuyOut ne može biti veći od 10.000.000 RSD prema API dokumentaciji.', 'd-express-woo')
            );
        }

        // Validacija Value polja
        if ($shipment_data['Value'] > 1000000000) {
            return new WP_Error(
                'api_value_limit',
                __('Value ne može biti veći od 10.000.000 RSD prema API dokumentaciji.', 'd-express-woo')
            );
        }
        // Loguj generisani kod
        dexpress_log("[PACKAGE CODE] Generisan kod: {$package_code} za order #{$order_id}", 'info');

        // Ako koristimo paketomat, postavimo adresu paketomata umesto adrese kupca
        if ($is_dispenser && $dispenser) {
            // Provere za paketomat prema dokumentaciji

            // 1. Provera broja paketa - mora biti samo jedan
            if (count($shipment_data['PackageList']) > 1) {
                return new WP_Error('paketomat_validation', __('Za dostavu u paketomat dozvoljen je samo jedan paket.', 'd-express-woo'));
            }

            // 2. Provera vrednosti otkupnine - mora biti manja od 200.000 RSD
            if ($shipment_data['BuyOut'] > 20000000) {
                return new WP_Error('paketomat_validation', __('Za dostavu u paketomat, otkupnina mora biti manja od 200.000,00 RSD.', 'd-express-woo'));
            }

            // 3. Provera telefona - mora biti mobilni (već validiramo telefon ranije)

            // 4. Provera povratnih dokumenata - ne sme biti povratka dokumenata
            if ($shipment_data['ReturnDoc'] != 0) {
                return new WP_Error('paketomat_validation', __('Za dostavu u paketomat nije dozvoljeno vraćanje dokumenata.', 'd-express-woo'));
            }

            // 5. Provera težine - mora biti manja od 20 kg
            if ($shipment_data['Mass'] > 20000) { // 20kg u gramima
                return new WP_Error('paketomat_validation', __('Za dostavu u paketomat, pošiljka mora biti lakša od 20kg.', 'd-express-woo'));
            }

            // 6. Dimenzije - moraju biti manje od 470 x 440 x 440mm
            // Napomena: Trenutno nemamo način da validiramo dimenzije
            // Implementirati kasnije kad budemo imali dimenzije proizvoda

            // Postaviti adresu i druge podatke paketomata
            $shipment_data['RAddress'] = $dispenser->address;
            $shipment_data['RAddressNum'] = 'bb'; // Paketomati obično nemaju broj
            $shipment_data['RTownID'] = $dispenser->town_id;

            // Dodaj DispenserID u podatke
            $shipment_data['DispenserID'] = intval($dispenser_id);

            // Za paketomat sa pouzećem, odgovarajuće podešavanje PaymentBy
            if ($shipment_data['BuyOut'] > 0) {
                // Ako se plaća pouzećem kod paketomata, pošiljalac plaća troškove dostave
                $shipment_data['PaymentBy'] = 0; // Sender (pošiljalac)
                dexpress_log("[PAKETOMAT] Pouzećem kod paketomata - PaymentBy postavljen na 0 (Sender)", 'info');
            }
        }

        // Dodatno logiranje za debugiranje
        dexpress_log("Final BuyOut amount: " . $shipment_data['BuyOut'], 'debug');
        dexpress_log("Final BuyOutAccount: " . $shipment_data['BuyOutAccount'], 'debug');
        dexpress_log("Final RAddressNum: " . $shipment_data['RAddressNum'], 'debug');
        dexpress_log("Final RTownID: " . $shipment_data['RTownID'], 'debug');
        dexpress_log("Final RCPhone: " . $shipment_data['RCPhone'], 'debug');

        if ($is_dispenser) {
            dexpress_log("[PAKETOMAT API] Finalni zahtev za paketomat:", 'info');
            dexpress_log("[PAKETOMAT API] DispenserID: {$shipment_data['DispenserID']}", 'info');
            dexpress_log("[PAKETOMAT API] RAddress: {$shipment_data['RAddress']}", 'info');
            dexpress_log("[PAKETOMAT API] RTownID: {$shipment_data['RTownID']}", 'info');
            dexpress_log("[PAKETOMAT API] PaymentType: {$shipment_data['PaymentType']}", 'info');
            dexpress_log("[PAKETOMAT API] PaymentBy: {$shipment_data['PaymentBy']}", 'info');
        }

        // Validacija kompletnih podataka za slanje
        $validation = D_Express_Validator::validate_shipment_data($shipment_data);
        if ($validation !== true) {
            dexpress_log("SHIPMENT DATA VALIDATION FAILED: " . implode(", ", $validation), 'error');
            // Umesto da ignorišemo, vratićemo grešku
            return new WP_Error('validation_error', __('Greška u podacima za pošiljku: ', 'd-express-woo') . implode(", ", $validation));
        }

        dexpress_log("[API DEBUG] Finalni RCPhone za API: {$phone}", 'info');

        // Omogućavanje filtiranja podataka za dodatna prilagođavanja
        return apply_filters('dexpress_prepare_shipment_data', $shipment_data, $order);
    }

    /**
     * Generiše package code koristeći centralizovanu funkciju
     */
    // private function generate_package_code()
    // {
    //     // Koristi helper funkciju umesto lokalne logike
    //     return dexpress_generate_package_code();
    // }

    /**
     * Izračunavanje težine narudžbine u gramima
     */
    public function calculate_order_weight($order)
    {
        dexpress_log("[WEIGHT DEBUG] Početak izračunavanja težine za narudžbinu #" . $order->get_id(), 'debug');
        $weight_kg = 0;

        // Dohvatanje stavki narudžbine
        $items = $order->get_items();

        foreach ($items as $item) {
            $product = $item->get_product();
            if ($product && $product->has_weight()) {
                $product_weight_kg = floatval($product->get_weight());
                $quantity = $item->get_quantity();
                $item_weight_kg = $product_weight_kg * $quantity;

                dexpress_log("[WEIGHT DEBUG] Proizvod: " . $product->get_name() . ", Težina: " . $product_weight_kg . "kg, Količina: " . $quantity . ", Ukupno: " . $item_weight_kg . "kg", 'debug');

                $weight_kg += $item_weight_kg;
            }
        }

        dexpress_log("[WEIGHT DEBUG] Ukupna težina u kg pre konverzije: " . $weight_kg, 'debug');

        // Minimalna težina 0.1kg
        $weight_kg = max(0.1, $weight_kg);

        // Konverzija u grame
        $grams = D_Express_Validator::convert_weight_to_grams($weight_kg);

        dexpress_log("[WEIGHT DEBUG] Konačna težina u gramima: " . $grams, 'debug');

        return $grams;
    }
    /**
     * Test konekcije sa API-em
     * 
     * @return bool|WP_Error True ako je konekcija uspešna, WP_Error ako nije
     */
    public function test_connection()
    {
        // Pokušavamo da dobavimo statuse kao jednostavan test
        $result = $this->get_statuses();

        // Ako je rezultat array, konekcija je uspešna
        if (is_array($result)) {
            return true;
        }

        // Inače, vraćamo error
        return $result;
    }
    /**
     * Dobijanje iznosa otkupnine ako je plaćanje pouzećem
     */
    private function get_buyout_amount($order)
    {
        // Ako je COD, postaviti iznos otkupnine
        if ($order->get_payment_method() === 'cod') {
            return dexpress_convert_price_to_para($order->get_total());
        }

        return 0;
    }
}
