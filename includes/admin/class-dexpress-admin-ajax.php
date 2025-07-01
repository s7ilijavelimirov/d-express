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

        add_action('wp_ajax_dexpress_delete_shipment', array($this, 'ajax_delete_shipment'));
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
    public function ajax_create_multiple_shipments()
    {
        // Nonce i permisija provera
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress_admin_nonce')) {
            wp_send_json_error(array('message' => 'Sigurnosna provera nije uspela.'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Nemate dozvolu za ovu akciju.'));
        }

        $order_id = intval($_POST['order_id']);
        $shipment_splits = isset($_POST['splits']) ? $_POST['splits'] : array();

        // Validacija
        if (!$order_id) {
            wp_send_json_error(array('message' => 'ID narudžbine je obavezan.'));
        }

        if (empty($shipment_splits) || !is_array($shipment_splits)) {
            wp_send_json_error(array('message' => 'Morate definisati podelu pošiljki.'));
        }

        // Dobijanje narudžbine
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Narudžbina nije pronađena.'));
        }

        // Proverava da li već postoje pošiljke za ovu narudžbinu
        $db = new D_Express_DB();
        $existing_shipments = $db->get_shipments_by_order_id($order_id);

        if (!empty($existing_shipments)) {
            wp_send_json_error(array('message' => 'Pošiljke za ovu narudžbinu već postoje.'));
        }

        $created_shipments = array();
        $errors = array();
        $split_index = 1;
        $total_splits = count($shipment_splits);

        // Generiši jedinstvene package kodove za sve splits odjednom
        $all_package_codes = $this->generate_unique_package_codes($total_splits);

        foreach ($shipment_splits as $split_data) {
            $location_id = intval($split_data['location_id']);
            $selected_items = isset($split_data['items']) ? $split_data['items'] : array();

            if (!$location_id) {
                $errors[] = sprintf('Lokacija nije definisana za pošiljku %d', $split_index);
                continue;
            }

            if (empty($selected_items)) {
                $errors[] = sprintf('Nema izabranih artikala za pošiljku %d', $split_index);
                continue;
            }

            // Kreiraj pošiljku sa unapred generisanim package kodom
            $package_code = $all_package_codes[$split_index - 1];
            $result = $this->create_optimized_split_shipment(
                $order,
                $location_id,
                $selected_items,
                $split_index,
                $total_splits,
                $package_code
            );

            if (is_wp_error($result)) {
                $errors[] = sprintf('Pošiljka %d: %s', $split_index, $result->get_error_message());
            } else {
                $created_shipments[] = $result;
            }

            $split_index++;
        }

        // Sačuvaj split informacije u order meta
        if (!empty($created_shipments)) {
            update_post_meta($order_id, '_dexpress_shipment_splits', $shipment_splits);
            update_post_meta($order_id, '_dexpress_multiple_shipments', count($created_shipments));
        }

        if (!empty($errors)) {
            wp_send_json_error(array(
                'message' => 'Greške pri kreiranju pošiljki:' . "\n" . implode("\n", $errors),
                'created_count' => count($created_shipments),
                'shipments' => $created_shipments
            ));
        } else {
            wp_send_json_success(array(
                'message' => sprintf('Uspešno kreirano %d pošiljki.', count($created_shipments)),
                'shipments' => $created_shipments
            ));
        }
    }

    /**
     * Generiše jedinstvene package kodove za multiple shipments
     */
    private function generate_unique_package_codes($count)
    {
        $codes = array();

        // KORISTI ISTU FUNKCIJU kao pojedinačne pošiljke
        for ($i = 0; $i < $count; $i++) {
            $codes[] = dexpress_generate_package_code();
        }

        error_log('Generated package codes: ' . implode(', ', $codes));
        return $codes;
    }

    /**
     * Kreiranje optimizovane split pošiljke
     */
    private function create_optimized_split_shipment($order, $location_id, $selected_items, $split_index, $total_splits, $package_code)
    {
        try {
            $api = new D_Express_API();

            // Pripremi osnovne podatke
            $shipment_data = $api->prepare_shipment_data_from_order($order, $location_id);

            if (is_wp_error($shipment_data)) {
                return $shipment_data;
            }

            // Modifikuj podatke za split
            $shipment_data = $this->modify_shipment_data_for_split_optimized(
                $shipment_data,
                $order,
                $selected_items,
                $split_index,
                $total_splits,
                $package_code
            );

            if (is_wp_error($shipment_data)) {
                return $shipment_data;
            }

            // Loguj podatke pre slanja
            error_log(sprintf(
                '[DEXPRESS SPLIT %d/%d] Order #%d, Package: %s, Location: %d',
                $split_index,
                $total_splits,
                $order->get_id(),
                $package_code,
                $location_id
            ));

            // Pošalji API zahtev
            $response = $api->add_shipment($shipment_data);

            if (is_wp_error($response)) {
                return $response;
            }

            // Sačuvaj u bazu
            $tracking_number = !empty($response['TrackingNumber']) ? $response['TrackingNumber'] : $package_code;
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
                return new WP_Error('db_error', 'Greška pri upisu pošiljke u bazu.');
            }

            // Sačuvaj package
            $package_data = array(
                'shipment_id' => $insert_id,
                'package_code' => $package_code,
                'mass' => $shipment_data['Mass'],
                'created_at' => current_time('mysql')
            );
            $db->add_package($package_data);

            // Dodaj note u narudžbinu
            $sender_locations_service = new D_Express_Sender_Locations();
            $location = $sender_locations_service->get_location($location_id);

            $note = sprintf(
                'D Express pošiljka %d/%d kreirana. Tracking: %s, Lokacija: %s',
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
                'total_splits' => $total_splits,
                'location_name' => $location ? $location->name : 'N/A',
                'package_code' => $package_code
            );
        } catch (Exception $e) {
            error_log(sprintf(
                '[DEXPRESS SPLIT %d/%d EXCEPTION] %s',
                $split_index,
                $total_splits,
                $e->getMessage()
            ));
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * Modifikuje podatke pošiljke za split - OPTIMIZOVANO
     */
    private function modify_shipment_data_for_split_optimized($shipment_data, $order, $selected_items, $split_index, $total_splits, $package_code)
    {
        // Izračunaj težinu za izabrane artikle
        $total_weight = 0;
        $split_value = 0;
        $content_items = array();

        foreach ($selected_items as $item_id) {
            $item = $order->get_item($item_id);
            if (!$item) {
                continue;
            }

            $product = $item->get_product();
            if ($product) {
                // Težina
                if ($product->has_weight()) {
                    $weight_kg = floatval($product->get_weight());
                    $quantity = $item->get_quantity();
                    $total_weight += ($weight_kg * $quantity * 1000); // u gramima
                }

                // Vrednost
                $split_value += ($item->get_total() + $item->get_total_tax());

                // Sadržaj
                $content_items[] = $item->get_quantity() . 'x ' . $product->get_name();
            }
        }

        // Ako nema težine, koristi podrazumevanu (100g po artiklu)
        if ($total_weight <= 0) {
            $total_weight = count($selected_items) * 100; // 100g po artiklu
        }

        // Minimum težina 100g
        if ($total_weight < 100) {
            $total_weight = 100;
        }

        // Modifikuj podatke
        $shipment_data['Mass'] = round($total_weight);
        $shipment_data['Value'] = round($split_value * 100); // u parima

        // Jedinstveni reference ID
        $shipment_data['ReferenceID'] = $order->get_id() . '-' . $split_index;

        // Sadržaj
        $shipment_data['Content'] = !empty($content_items) ?
            implode(', ', $content_items) :
            'Deo narudžbine ' . $split_index;

        // Package lista sa unapred generisanim kodom
        $shipment_data['PackageList'] = array(
            array(
                'Code' => $package_code,
                'Mass' => round($total_weight)
            )
        );

        return $shipment_data;
    }
    /**
     * AJAX: Brisanje pošiljke
     */
    public function ajax_delete_shipment()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress_admin_nonce')) {
            wp_send_json_error(array('message' => 'Sigurnosna provera nije uspela.'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Nemate dozvolu za ovu akciju.'));
        }

        $shipment_id = intval($_POST['shipment_id']);
        $order_id = intval($_POST['order_id']);

        if (!$shipment_id || !$order_id) {
            wp_send_json_error(array('message' => 'Neispravni podaci.'));
        }

        $db = new D_Express_DB();

        // Dobij podatke o pošiljci pre brisanja
        $shipment = $db->get_shipment($shipment_id);
        if (!$shipment) {
            wp_send_json_error(array('message' => 'Pošiljka nije pronađena.'));
        }

        // Obriši pošiljku i povezane pakete
        if (method_exists($db, 'delete_shipment')) {
            $result = $db->delete_shipment($shipment_id);
        } else {
            // Fallback
            global $wpdb;
            $wpdb->delete($wpdb->prefix . 'dexpress_packages', array('shipment_id' => $shipment_id));
            $result = $wpdb->delete($wpdb->prefix . 'dexpress_shipments', array('id' => $shipment_id));
        }

        if ($result) {
            // Dodaj napomenu u narudžbinu
            $order = wc_get_order($order_id);
            if ($order) {
                $order->add_order_note(sprintf(
                    'D Express pošiljka obrisana. Tracking: %s',
                    $shipment->tracking_number
                ));
            }

            wp_send_json_success(array('message' => 'Pošiljka je uspešno obrisana.'));
        } else {
            wp_send_json_error(array('message' => 'Greška pri brisanju pošiljke.'));
        }
    }
}
