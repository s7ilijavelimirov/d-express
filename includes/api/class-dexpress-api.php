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
            'timeout'   => 120,
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
            dexpress_log('API Zahtev: ' . $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            dexpress_log('API Greška: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Logovanje odgovora u test modu
        if ($this->test_mode) {
            if (is_wp_error($response)) {
                dexpress_log('API Greška: ' . $response->get_error_message(), 'error');
                return $response;
            }
        }

        if ($response_code < 200 || $response_code >= 600) {
            return new WP_Error(
                'api_error',
                sprintf(__('API greška: %s', 'd-express-woo'), wp_remote_retrieve_response_message($response)),
                array('status' => $response_code, 'body' => $body)
            );
        }

        // Provera da li je odgovor JSON
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Neki API pozivi mogu vratiti običan tekst, tako da nije uvek greška
            if (strpos($body, 'ERROR') === 0) {
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
     */
    public function check_address($address_data)
    {
        return $this->api_request('data/checkaddress', 'POST', $address_data);
    }

    /**
     * Kreiranje pošiljke
     */
    public function add_shipment($shipment_data)
    {
        // Dodavanje ClientID ako nije uključen u podatke
        if (!isset($shipment_data['CClientID'])) {
            $shipment_data['CClientID'] = $this->client_id;
        }

        return $this->api_request('data/addshipment', 'POST', $shipment_data);
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
     */
    public function update_all_indexes()
    {
        global $wpdb;
        $db = new D_Express_DB();

        // Preuzimanje poslednjeg datuma ažuriranja
        $last_update = get_option('dexpress_last_index_update', '20000101000000');

        try {
            // Ažuriranje prodavnica
            $shops = $this->get_shops();
            if (!is_wp_error($shops) && is_array($shops)) {
                $this->update_shops_index($shops);
            }
            // Ažuriranje locations
            $locations = $this->get_locations();
            if (!is_wp_error($locations) && is_array($locations)) {
                $this->update_locations_index($locations);
            }
            // Ažuriranje opština
            $municipalities = $this->get_municipalities($last_update);
            if (!is_wp_error($municipalities) && is_array($municipalities)) {
                $this->update_municipalities_index($municipalities);
            }

            // Ažuriranje gradova
            $towns = $this->get_towns($last_update);
            if (!is_wp_error($towns) && is_array($towns)) {
                $this->update_towns_index($towns);
            }

            dexpress_log('Updating streets...');
            // Ažuriranje ulica
            $streets = $this->get_streets($last_update);
            if (!is_wp_error($streets) && is_array($streets)) {
                dexpress_log('Got ' . count($streets) . ' streets, starting update...');
                // Proverite strukturu prve ulice
                dexpress_log('First street data: ' . print_r($streets[0], true));
                $this->update_streets_index($streets);
            } else {
                dexpress_log('Error fetching streets: ' . print_r($streets, true));
            }

            // Ažuriranje statusa
            $statuses = $this->get_statuses();
            if (!is_wp_error($statuses) && is_array($statuses)) {
                $this->update_statuses_index($statuses);
            }

            // Ažuriranje automata za pakete
            $dispensers = $this->get_dispensers();
            if (!is_wp_error($dispensers) && is_array($dispensers)) {
                $this->update_dispensers_index($dispensers);
            }
            $centres = $this->get_centres();
            if (!is_wp_error($centres) && is_array($centres)) {
                $this->update_centres_index($centres);
            }
            // Ažuriranje vremena poslednjeg ažuriranja
            update_option('dexpress_last_index_update', date('YmdHis'));
            dexpress_log('Index update completed successfully');
            return true;
        } catch (Exception $e) {
            dexpress_log('Greška pri ažuriranju šifarnika: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    /**
     * Ažuriranje indeksa prodavnica
     */
    private function update_shops_index($shops)
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
                    'phone' => isset($shop['Phone']) ? $shop['Phone'] : null,
                    'latitude' => isset($shop['Latitude']) ? $shop['Latitude'] : null,
                    'longitude' => isset($shop['Longitude']) ? $shop['Longitude'] : null,
                    'location_type' => isset($shop['LocationType']) ? $shop['LocationType'] : null,
                    'pay_by_cash' => isset($shop['PayByCash']) ? ($shop['PayByCash'] ? 1 : 0) : 0,
                    'pay_by_card' => isset($shop['PayByCard']) ? ($shop['PayByCard'] ? 1 : 0) : 0,
                    'last_updated' => current_time('mysql')
                ),
                array(
                    '%d', // id
                    '%s', // name
                    '%s', // description
                    '%s', // address
                    '%s', // town
                    '%d', // town_id
                    '%s', // working_hours
                    '%s', // work_days
                    '%s', // phone
                    '%s', // latitude
                    '%s', // longitude
                    '%s', // location_type
                    '%d', // pay_by_cash
                    '%d', // pay_by_card
                    '%s'  // last_updated
                )
            );
        }
    }
    /**
     * Ažuriranje indeksa opština
     */
    private function update_municipalities_index($municipalities)
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
    private function update_centres_index($centres)
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
    private function update_towns_index($towns)
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
    private function update_locations_index($locations)
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
                    'pay_by_cash' => isset($location['PayByCash']) ? ($location['PayByCash'] ? 1 : 0) : 0,
                    'pay_by_card' => isset($location['PayByCard']) ? ($location['PayByCard'] ? 1 : 0) : 0,
                    'last_updated' => current_time('mysql')
                ),
                array(
                    '%d', // id
                    '%s', // name
                    '%s', // description
                    '%s', // address
                    '%s', // town
                    '%d', // town_id
                    '%s', // working_hours
                    '%s', // work_hours
                    '%s', // work_days
                    '%s', // phone
                    '%s', // latitude
                    '%s', // longitude
                    '%s', // location_type
                    '%d', // pay_by_cash
                    '%d', // pay_by_card
                    '%s'  // last_updated
                )
            );
        }
    }
    /**
     * Ažuriranje indeksa ulica
     */
    private function update_streets_index($streets)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_streets';

        // Provera da li tabela postoji
        if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
            dexpress_log("Table $table_name does not exist.");
            return;
        }

        // Batch processing - ubacujemo po 1000 redova odjednom
        $batch_size = 1000;
        $total = count($streets);

        for ($i = 0; $i < $total; $i += $batch_size) {
            $batch = array_slice($streets, $i, $batch_size);
            $values = array();
            $placeholders = array();

            foreach ($batch as $street) {
                $values[] = $street['Id'];
                $values[] = $street['Name'];
                $values[] = $street['TId'];
                $values[] = $street['Del'] ? 1 : 0; // Pretvaramo boolean u 0 ili 1
                $values[] = current_time('mysql');

                $placeholders[] = "(%d, %s, %d, %d, %s)";
            }

            // Koristimo ON DUPLICATE KEY UPDATE za ažuriranje postojećih redova
            $query = $wpdb->prepare(
                "INSERT INTO $table_name (id, name, TId, deleted, last_updated) 
             VALUES " . implode(', ', $placeholders) . "
             ON DUPLICATE KEY UPDATE 
             name = VALUES(name), 
             TId = VALUES(TId), 
             deleted = VALUES(deleted), 
             last_updated = VALUES(last_updated)",
                $values
            );

            if (false === $wpdb->query($query)) {
                dexpress_log("Error updating streets: " . $wpdb->last_error);
            }

            // Logovanje progresa
            dexpress_log(sprintf('Streets update progress: %d/%d', min($i + $batch_size, $total), $total));
        }
    }

    /**
     * Ažuriranje indeksa statusa
     */
    private function update_statuses_index($statuses)
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
    private function update_dispensers_index($dispensers)
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
                    'coordinates' => json_encode(array(
                        'latitude' => isset($dispenser['Latitude']) ? $dispenser['Latitude'] : null,
                        'longitude' => isset($dispenser['Longitude']) ? $dispenser['Longitude'] : null
                    )),
                    'pay_by_cash' => isset($dispenser['PayByCash']) ? (int) $dispenser['PayByCash'] : 0,
                    'pay_by_card' => isset($dispenser['PayByCard']) ? (int) $dispenser['PayByCard'] : 0,
                    'last_updated' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s')
            );
        }
    }

    /**
     * Generiše jedinstveni ReferenceID za pošiljku
     */
    public function generate_reference_id($order_id)
    {
        // Format: ORDER-{order_id}-{timestamp}
        return dexpress_generate_reference($order_id);
    }

    /**
     * Generiše jedinstveni kod paketa
     */
    public function generate_package_code()
    {
        return dexpress_generate_package_code();
    }

    /**
     * Priprema podatke pošiljke iz WooCommerce narudžbine
     */
    public function prepare_shipment_data_from_order($order)
    {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Nevažeća narudžbina', 'd-express-woo'));
        }

        // Osnovni podaci o pošiljci
        $shipment_data = array(
            'CClientID' => $this->client_id,

            // Podaci o klijentu (pošiljaocu)
            'CName' => get_option('dexpress_sender_name', ''),
            'CAddress' => get_option('dexpress_sender_address', ''),
            'CAddressNum' => get_option('dexpress_sender_address_num', ''),
            'CTownID' => intval(get_option('dexpress_sender_town_id', 0)),
            'CCName' => get_option('dexpress_sender_contact_name', ''),
            'CCPhone' => get_option('dexpress_sender_contact_phone', ''),

            // Podaci o pošiljaocu (isto kao klijent)
            'PuClientID' => $this->client_id,
            'PuName' => get_option('dexpress_sender_name', ''),
            'PuAddress' => get_option('dexpress_sender_address', ''),
            'PuAddressNum' => get_option('dexpress_sender_address_num', ''),
            'PuTownID' => intval(get_option('dexpress_sender_town_id', 0)),
            'PuCName' => get_option('dexpress_sender_contact_name', ''),
            'PuCPhone' => get_option('dexpress_sender_contact_phone', ''),

            // Podaci o primaocu
            'RName' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'RAddress' => $order->get_meta('_shipping_street', true),
            'RAddressNum' => $order->get_meta('_shipping_street_num', true),
            'RTownID' => intval($order->get_meta('_shipping_town_id', true)),
            'RCName' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'RCPhone' => $this->format_phone_number($order->get_billing_phone()),

            // Tip pošiljke i plaćanje
            'DlTypeID' => intval(get_option('dexpress_shipment_type', 2)),
            'PaymentBy' => intval(get_option('dexpress_payment_by', 0)),
            'PaymentType' => intval(get_option('dexpress_payment_type', 2)),

            // Vrednost i masa
            'Value' => dexpress_convert_price_to_para($order->get_total()),
            'Content' => get_option('dexpress_default_content', __('Roba iz web prodavnice', 'd-express-woo')),
            'Mass' => $this->calculate_order_weight($order),

            // Reference i opcije
            'ReferenceID' => $this->generate_reference_id($order->get_id()),
            'ReturnDoc' => intval(get_option('dexpress_return_doc', 0)),

            // Otkupnina ako je potrebno
            'BuyOut' => $this->get_buyout_amount($order),
            'BuyOutFor' => intval(get_option('dexpress_buyout_for', 0)),
            'BuyOutAccount' => get_option('dexpress_buyout_account', ''),
        );

        // Dodavanje paketa
        $shipment_data['PackageList'] = $this->prepare_packages_for_order($order);
        // Omogućavanje filtiranja podataka za dodatna prilagođavanja
        return apply_filters('dexpress_prepare_shipment_data', $shipment_data, $order);
    }

    /**
     * Priprema listu paketa za narudžbinu
     */
    private function prepare_packages_for_order($order)
    {
        // Za jednostavne slučajeve, samo jedan paket
        $packages = array(
            array(
                'Code' => $this->generate_package_code(),
                'Mass' => $this->calculate_order_weight($order)
            )
        );

        return $packages;
    }

    /**
     * Izračunavanje ukupne težine narudžbine u gramima
     */
    private function calculate_order_weight($order)
    {
        $weight = 0;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->has_weight()) {
                $weight += floatval($product->get_weight()) * $item->get_quantity();
            }
        }

        // Minimalna težina 100g
        $weight = max(0.1, $weight);

        // Konverzija u grame
        return dexpress_convert_weight_to_grams($weight);
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

    /**
     * Formatiranje broja telefona za D Express format
     */
    private function format_phone_number($phone)
    {
        // Uklanjanje svega osim brojeva
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Dodavanje prefiksa 381 ako nedostaje
        if (substr($phone, 0, 3) !== '381') {
            // Ako počinje sa 0, zameniti 0 sa 381
            if (substr($phone, 0, 1) === '0') {
                $phone = '381' . substr($phone, 1);
            } else {
                // Inače samo dodati 381 na početak
                $phone = '381' . $phone;
            }
        }

        return $phone;
    }
}
