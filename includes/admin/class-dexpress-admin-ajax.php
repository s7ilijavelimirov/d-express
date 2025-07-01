<?php

/**
 * D Express Admin AJAX Handler
 * 
 * Sve AJAX funkcionalnosti izdvojene iz glavne admin klase
 */

defined('ABSPATH') || exit;

class D_Express_Admin_Ajax
{
    /**
     * Konstruktor - registruje sve AJAX hooks
     */
    public function __construct()
    {
        $this->register_ajax_hooks();
    }

    /**
     * Registracija svih AJAX hooks-a
     */
    private function register_ajax_hooks()
    {
        // AJAX za lokacije
        add_action('wp_ajax_dexpress_create_location', array($this, 'ajax_create_location'));
        add_action('wp_ajax_dexpress_update_location', array($this, 'ajax_update_location'));
        add_action('wp_ajax_dexpress_get_location', array($this, 'ajax_get_location'));
        add_action('wp_ajax_dexpress_set_default_location', array($this, 'ajax_set_default_location'));
        add_action('wp_ajax_dexpress_delete_location', array($this, 'ajax_delete_location'));

        // AJAX za order meta
        add_action('wp_ajax_dexpress_save_order_meta', array($this, 'ajax_save_order_meta'));

        // AJAX za settings
        add_action('wp_ajax_dexpress_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_dexpress_test_api', array($this, 'ajax_test_api'));

        // NOVA REGISTRACIJA za multiple shipments
        add_action('wp_ajax_dexpress_create_multiple_shipments', array($this, 'ajax_create_multiple_shipments'));

        // AJAX za pošiljke
        add_action('wp_ajax_dexpress_create_shipment', array($this, 'ajax_create_shipment'));

        // AJAX za nalepnice
        add_action('wp_ajax_dexpress_get_label', array($this, 'ajax_get_label'));
    }

    /**
     * AJAX: Kreiranje lokacije
     */
    public function ajax_create_location()
    {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress_admin_nonce')) {
                wp_send_json_error('Sigurnosna greška');
                return;
            }

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error('Nemate dozvolu');
                return;
            }

            $locations_service = D_Express_Sender_Locations::get_instance();

            $data = [
                'name' => sanitize_text_field($_POST['name']),
                'address' => sanitize_text_field($_POST['address']),
                'address_num' => sanitize_text_field($_POST['address_num']),
                'town_id' => intval($_POST['town_id']),
                'contact_name' => sanitize_text_field($_POST['contact_name']),
                'contact_phone' => sanitize_text_field($_POST['contact_phone']),
                'bank_account' => sanitize_text_field($_POST['bank_account'] ?? ''),
                'is_default' => !empty($_POST['is_default']) ? 1 : 0
            ];

            $result = $locations_service->create_location($data);

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            } else {
                wp_send_json_success([
                    'message' => 'Lokacija je uspešno kreirana',
                    'location_id' => $result
                ]);
            }
        } catch (Exception $e) {
            error_log('DExpress Create Location Error: ' . $e->getMessage());
            wp_send_json_error('Server greška: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Ažuriranje lokacije
     */
    public function ajax_update_location()
    {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress_admin_nonce')) {
                wp_send_json_error('Sigurnosna greška');
                return;
            }

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error('Nemate dozvolu');
                return;
            }

            $location_id = intval($_POST['location_id']);
            if (!$location_id) {
                wp_send_json_error('Nevaljan ID lokacije');
                return;
            }

            $locations_service = D_Express_Sender_Locations::get_instance();

            $data = [
                'name' => sanitize_text_field($_POST['name']),
                'address' => sanitize_text_field($_POST['address']),
                'address_num' => sanitize_text_field($_POST['address_num']),
                'town_id' => intval($_POST['town_id']),
                'contact_name' => sanitize_text_field($_POST['contact_name']),
                'contact_phone' => sanitize_text_field($_POST['contact_phone']),
                'bank_account' => sanitize_text_field($_POST['bank_account'] ?? ''),
                'is_default' => !empty($_POST['is_default']) ? 1 : 0
            ];

            $result = $locations_service->update_location($location_id, $data);

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            } else {
                wp_send_json_success(['message' => 'Lokacija je uspešno ažurirana']);
            }
        } catch (Exception $e) {
            error_log('DExpress Update Location Error: ' . $e->getMessage());
            wp_send_json_error('Server greška: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Dobijanje lokacije
     */
    public function ajax_get_location()
    {
        try {
            // ✅ ISPRAVKA: Koristi $_POST jer JavaScript šalje POST
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress_admin_nonce')) {
                wp_send_json_error('Sigurnosna greška');
                return;
            }

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error('Nemate dozvolu');
                return;
            }

            // ✅ ISPRAVKA: Koristi $_POST
            $location_id = intval($_POST['location_id']);
            if (!$location_id) {
                wp_send_json_error('Nevaljan ID lokacije');
                return;
            }

            $locations_service = D_Express_Sender_Locations::get_instance();
            $location = $locations_service->get_location($location_id);

            if (!$location) {
                wp_send_json_error('Lokacija nije pronađena');
                return;
            }

            // ✅ ISPRAVKA: Vraćaj direktno pod 'data' ključem
            wp_send_json_success($location);

            // ALI JOŠ BOLJE: Konvertuj objekat u array da JavaScript može pristupiti svojstvima
            wp_send_json_success((array) $location);
        } catch (Exception $e) {
            error_log('DExpress Get Location Error: ' . $e->getMessage());
            wp_send_json_error('Server greška: ' . $e->getMessage());
        }
    }
    /**
     * AJAX: Postavljanje default lokacije
     */
    public function ajax_set_default_location()
    {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress_admin_nonce')) {
                wp_send_json_error('Sigurnosna greška');
                return;
            }

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error('Nemate dozvolu');
                return;
            }

            $location_id = intval($_POST['location_id']);
            if (!$location_id) {
                wp_send_json_error('Nevaljan ID lokacije');
                return;
            }

            $locations_service = D_Express_Sender_Locations::get_instance();
            $result = $locations_service->set_default_location($location_id);

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            } else {
                wp_send_json_success(['message' => 'Glavna lokacija je uspešno postavljena']);
            }
        } catch (Exception $e) {
            error_log('DExpress Set Default Location Error: ' . $e->getMessage());
            wp_send_json_error('Server greška: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Brisanje lokacije
     */
    public function ajax_delete_location()
    {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress_admin_nonce')) {
                wp_send_json_error('Sigurnosna greška');
                return;
            }

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error('Nemate dozvolu');
                return;
            }

            $location_id = intval($_POST['location_id']);
            if (!$location_id) {
                wp_send_json_error('Nevaljan ID lokacije');
                return;
            }

            $locations_service = D_Express_Sender_Locations::get_instance();

            // Dobij ime lokacije pre brisanja
            $location = $locations_service->get_location($location_id);
            $location_name = $location ? $location->name : 'Nepoznata lokacija';

            $result = $locations_service->delete_location($location_id);

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            } else {
                wp_send_json_success(array(
                    'message' => sprintf(__('Lokacija "%s" je uspešno obrisana.', 'd-express-woo'), $location_name)
                ));
            }
        } catch (Exception $e) {
            error_log('DExpress Delete Location Exception: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'Sistemska greška: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX: Čuvanje order meta podataka
     */
    public function ajax_save_order_meta()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress_admin_nonce')) {
            wp_send_json_error(array('message' => 'Sigurnosna provera nije uspela.'));
        }

        $order_id = intval($_POST['order_id']);
        $field_name = sanitize_text_field($_POST['field_name']);
        $field_value = sanitize_text_field($_POST['field_value']);

        if ($order_id && $field_name) {
            update_post_meta($order_id, '_' . $field_name, $field_value);
            wp_send_json_success(array('message' => 'Podaci sačuvani'));
        } else {
            wp_send_json_error(array('message' => 'Neispravni podaci'));
        }
    }

    /**
     * AJAX: Kreiranje pošiljke
     */
    public function ajax_create_shipment()
    {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'dexpress_admin_nonce')) {
            wp_send_json_error(array('message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')));
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')));
        }

        $order_id = intval($_POST['order_id']);
        $sender_location_id = intval($_POST['sender_location_id']);

        if (!$order_id) {
            wp_send_json_error(array('message' => __('ID narudžbine je obavezan.', 'd-express-woo')));
        }

        if (!$sender_location_id) {
            wp_send_json_error(array('message' => __('Morate izabrati lokaciju pošaljioce.', 'd-express-woo')));
        }

        // Dobijanje narudžbine
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Narudžbina nije pronađena.', 'd-express-woo')));
        }

        // Kreiranje pošiljke pomoću servisne klase
        $shipment_service = new D_Express_Shipment_Service();
        $result = $shipment_service->create_shipment($order, $sender_location_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array(
                'message' => __('Pošiljka je uspešno kreirana.', 'd-express-woo'),
                'shipment_id' => $result
            ));
        }
    }

    /**
     * AJAX: Generisanje label-a
     */
    public function ajax_get_label()
    {
        // Provera nonce-a
        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'dexpress_admin_nonce')) {
            wp_send_json_error(array('message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')));
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')));
        }

        $shipment_id = isset($_REQUEST['shipment_id']) ? intval($_REQUEST['shipment_id']) : 0;

        if (!$shipment_id) {
            wp_send_json_error(array('message' => __('ID pošiljke je obavezan.', 'd-express-woo')));
        }

        // Dobijanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
            $shipment_id
        ));

        if (!$shipment) {
            wp_send_json_error(array('message' => __('Pošiljka nije pronađena.', 'd-express-woo')));
        }

        // Kreiranje URL-a za download
        $download_url = admin_url('admin-ajax.php') . '?' . http_build_query(array(
            'action' => 'dexpress_download_label',
            'shipment_id' => $shipment_id,
            'nonce' => wp_create_nonce('dexpress-download-label')  // ← ISPRAVKA!
        ));

        wp_send_json_success(array(
            'message' => __('Nalepnica uspešno generisana.', 'd-express-woo'),
            'url' => $download_url
        ));
    }

    /**
     * AJAX: Čuvanje settings-a
     */
    public function ajax_save_settings()
    {
        // Implementirati kada dođemo do settings refaktora
        wp_send_json_error('Not implemented yet');
    }

    /**
     * AJAX: Test API
     */
    public function ajax_test_api()
    {
        // Implementirati kada dođemo do API refaktora
        wp_send_json_error('Not implemented yet');
    }
    /**
     * AJAX: Kreiranje više pošiljki iz jedne narudžbine
     */
    public function ajax_create_multiple_shipments()
    {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'dexpress_admin_nonce')) {
            wp_send_json_error(array('message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')));
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')));
        }

        $order_id = intval($_POST['order_id']);
        $splits = isset($_POST['splits']) ? $_POST['splits'] : array();

        if (!$order_id) {
            wp_send_json_error(array('message' => __('ID narudžbine je obavezan.', 'd-express-woo')));
        }

        if (empty($splits) || !is_array($splits)) {
            wp_send_json_error(array('message' => __('Morate definisati podele pošiljki.', 'd-express-woo')));
        }

        // Dobijanje narudžbine
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Narudžbina nije pronađena.', 'd-express-woo')));
        }

        // Provera da li već postoje pošiljke
        global $wpdb;
        $existing_shipments = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));

        if ($existing_shipments > 0) {
            wp_send_json_error(array('message' => __('Za ovu narudžbinu već postoje pošiljke.', 'd-express-woo')));
        }

        $shipment_service = new D_Express_Shipment_Service();
        $created_shipments = array();
        $errors = array();

        // Validacija da su svi artikli dodeljeni
        $order_items = $order->get_items();
        $assigned_items = array();

        foreach ($splits as $split) {
            if (isset($split['items']) && is_array($split['items'])) {
                foreach ($split['items'] as $item_id) {
                    $assigned_items[] = intval($item_id);
                }
            }
        }

        // Proveri da li su svi artikli dodeljeni
        foreach ($order_items as $item_id => $item) {
            if (!in_array($item_id, $assigned_items)) {
                wp_send_json_error(array(
                    'message' => sprintf(__('Artikl "%s" nije dodeljen nijednoj pošiljci.', 'd-express-woo'), $item->get_name())
                ));
            }
        }

        // Kreiranje pošiljki
        $split_index = 1;
        foreach ($splits as $split) {
            if (empty($split['items']) || !is_array($split['items'])) {
                continue;
            }

            $location_id = intval($split['location_id']);
            $selected_items = array_map('intval', $split['items']);

            try {
                // Kreiraj modifikovanu narudžbinu za ovu pošiljku
                $split_result = $this->create_split_shipment($order, $location_id, $selected_items, $split_index, count($splits));

                if (is_wp_error($split_result)) {
                    $errors[] = sprintf(__('Pošiljka %d: %s', 'd-express-woo'), $split_index, $split_result->get_error_message());
                } else {
                    $created_shipments[] = $split_result;
                }
            } catch (Exception $e) {
                $errors[] = sprintf(__('Pošiljka %d: %s', 'd-express-woo'), $split_index, $e->getMessage());
            }

            $split_index++;
        }

        // Sačuvaj informacije o podeli
        if (!empty($created_shipments)) {
            $split_info = array();
            foreach ($splits as $index => $split) {
                $split_info[] = array(
                    'location_id' => $split['location_id'],
                    'items' => $split['items'],
                    'shipment_index' => $index + 1
                );
            }
            update_post_meta($order_id, '_dexpress_shipment_splits', $split_info);
        }

        if (!empty($errors)) {
            wp_send_json_error(array(
                'message' => __('Greške pri kreiranju pošiljki:', 'd-express-woo') . "\n" . implode("\n", $errors),
                'created_count' => count($created_shipments)
            ));
        } else {
            wp_send_json_success(array(
                'message' => sprintf(__('Uspešno kreirano %d pošiljki.', 'd-express-woo'), count($created_shipments)),
                'shipments' => $created_shipments
            ));
        }
    }
    /**
     * Kreiranje pojedinačne split pošiljke
     */
    private function create_split_shipment($order, $location_id, $selected_items, $split_index, $total_splits)
    {
        $shipment_service = new D_Express_Shipment_Service();

        // Kreiraj custom API instancu za ovu split pošiljku
        $api = new D_Express_API();

        // Pripremi podatke za pošiljku samo sa izabranim artiklima
        $shipment_data = $api->prepare_shipment_data_from_order($order, $location_id);

        if (is_wp_error($shipment_data)) {
            return $shipment_data;
        }

        // Modifikuj podatke za split pošiljku
        $shipment_data = $this->modify_shipment_data_for_split($shipment_data, $order, $selected_items, $split_index, $total_splits);

        if (is_wp_error($shipment_data)) {
            return $shipment_data;
        }

        // Kreiraj pošiljku preko API-ja
        $response = $api->add_shipment($shipment_data);

        if (is_wp_error($response)) {
            return $response;
        }

        // Sačuvaj u bazu
        $tracking_number = !empty($response['TrackingNumber']) ? $response['TrackingNumber'] : $shipment_data['PackageList'][0]['Code'];
        $shipment_id = !empty($response['ShipmentID']) ? $response['ShipmentID'] : $tracking_number;

        $db = new D_Express_DB();
        $shipment_record = array(
            'order_id' => $order->get_id(),
            'shipment_id' => $shipment_id,
            'tracking_number' => $tracking_number,
            'reference_id' => $shipment_data['ReferenceID'],
            'sender_location_id' => $location_id,
            'split_index' => $split_index,
            'total_splits' => $total_splits,
            'parent_order_id' => $order->get_id(),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'shipment_data' => json_encode($response),
            'is_test' => dexpress_is_test_mode() ? 1 : 0
        );

        $insert_id = $db->add_shipment($shipment_record);

        if (!$insert_id) {
            return new WP_Error('db_error', __('Greška pri upisu pošiljke u bazu.', 'd-express-woo'));
        }

        // Sačuvaj pakete
        if (isset($shipment_data['PackageList']) && is_array($shipment_data['PackageList'])) {
            foreach ($shipment_data['PackageList'] as $package) {
                $package_data = array(
                    'shipment_id' => $insert_id,
                    'package_code' => $package['Code'],
                    'mass' => $package['Mass'],
                    'created_at' => current_time('mysql')
                );
                $db->add_package($package_data);
            }
        }

        // Dodaj napomenu u narudžbinu
        $sender_locations_service = new D_Express_Sender_Locations();
        $location = $sender_locations_service->get_location($location_id);

        $note = sprintf(
            __('D Express pošiljka %d/%d kreirana. Tracking: %s, Lokacija: %s', 'd-express-woo'),
            $split_index,
            $total_splits,
            $tracking_number,
            $location ? $location->name : 'N/A'
        );

        $order->add_order_note($note);

        return array(
            'shipment_id' => $insert_id,
            'tracking_number' => $tracking_number,
            'split_index' => $split_index,
            'location_name' => $location ? $location->name : 'N/A'
        );
    }
    /**
     * Modifikuje podatke pošiljke za split
     */
    private function modify_shipment_data_for_split($shipment_data, $order, $selected_items, $split_index, $total_splits)
    {
        // Izračunaj težinu za izabrane artikle
        $total_weight = 0;
        $split_value = 0;

        foreach ($selected_items as $item_id) {
            $item = $order->get_item($item_id);
            if (!$item) {
                continue;
            }

            $product = $item->get_product();
            if ($product && $product->has_weight()) {
                $weight_kg = floatval($product->get_weight());
                $quantity = $item->get_quantity();
                $total_weight += ($weight_kg * $quantity * 1000); // u gramima
            }

            // Dodaj vrednost artikla
            $split_value += ($item->get_total() + $item->get_total_tax());
        }

        // Ako nema težine, koristi podrazumevanu
        if ($total_weight <= 0) {
            $total_weight = floatval(get_option('dexpress_default_weight', 1)) * 1000;
        }

        // Modifikuj podatke
        $shipment_data['Mass'] = round($total_weight);

        // Modifikuj reference ID da bude jedinstven
        $shipment_data['ReferenceID'] = $shipment_data['ReferenceID'] . '-' . $split_index;

        // Modifikuj PackageList - kreiraj novi paket za ovaj split
        $package_code = dexpress_generate_package_code();
        $shipment_data['PackageList'] = array(
            array(
                'Code' => $package_code,
                'Mass' => round($total_weight)
            )
        );

        // Ako je pouzećem, podeli vrednost otkupnine
        if ($shipment_data['BuyOut'] > 0) {
            // Proporcionalno podeli otkupninu
            $original_total = $order->get_total();
            if ($original_total > 0) {
                $shipment_data['BuyOut'] = round(($split_value / $original_total) * $shipment_data['BuyOut'] * 100); // u parama
            }
        }

        return $shipment_data;
    }
}
