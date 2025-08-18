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

        // Generiši kod pre poziva servisne klase
        try {
            $package_code = dexpress_generate_package_code();
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Greška pri generisanju koda: ' . $e->getMessage()));
            return;
        }

        dexpress_log('[AJAX] Kreiranje single shipment - Order: ' . $order_id . ', Lokacija: ' . $sender_location_id . ', Kod: ' . $package_code, 'info');

        // Kreiranje pošiljke pomoću servisne klase
        $shipment_service = new D_Express_Shipment_Service();
        $result = $shipment_service->create_shipment($order, $sender_location_id, $package_code);

        if (is_wp_error($result)) {
            dexpress_log('[AJAX] Greška pri kreiranju: ' . $result->get_error_message(), 'error');
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            dexpress_log('[AJAX] Uspešno kreirana pošiljka: ' . print_r($result, true), 'info');

            // Formatiranje odgovora
            $response_message = sprintf(
                __('Pošiljka je uspešno kreirana. Tracking: %s, Lokacija: %s', 'd-express-woo'),
                $result['tracking_number'],
                $result['location_name']
            );

            if ($result['is_test']) {
                $response_message .= ' [TEST REŽIM]';
            }

            wp_send_json_success(array(
                'message' => $response_message,
                'shipment_id' => $result['shipment_id'],
                'tracking_number' => $result['tracking_number'],
                'package_code' => $result['shipment_code'],
                'location_name' => $result['location_name'],
                'is_test' => $result['is_test']
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
     * Kalkuliše masu za odabrane artikle
     */
    private function calculate_split_mass($order, $selected_items)
    {
        $total_mass = 0;

        foreach ($order->get_items() as $item_id => $item) {
            if (in_array($item_id, $selected_items)) {
                $product = $item->get_product();
                if ($product && $product->has_weight()) {
                    $weight_kg = floatval($product->get_weight());
                    $weight_grams = $weight_kg * 1000; // Konvertuj u grame
                    $total_mass += $weight_grams * $item->get_quantity();
                }
            }
        }

        return $total_mass > 0 ? intval($total_mass) : 500; // Min 500g
    }

    /**
     * Generiše sadržaj za odabrane artikle
     */
    private function generate_split_content($order, $selected_items)
    {
        $content_parts = array();

        foreach ($order->get_items() as $item_id => $item) {
            if (in_array($item_id, $selected_items)) {
                $product = $item->get_product();
                if ($product) {
                    $content_parts[] = $item->get_quantity() . 'x ' . $product->get_name();
                }
            }
        }

        return !empty($content_parts) ? implode(', ', $content_parts) : 'Proizvodi';
    }
    public function ajax_create_multiple_shipments()
    {
        // Nonce i permisija provera
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress_admin_nonce')) {
            wp_send_json_error(array('message' => 'Sigurnosna provera nije uspela.'));
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Nemate dozvolu za ovu akciju.'));
            return;
        }

        $order_id = intval($_POST['order_id']);
        $shipment_splits = isset($_POST['splits']) ? $_POST['splits'] : array();

        // Validacija
        if (!$order_id || empty($shipment_splits)) {
            wp_send_json_error(array('message' => 'Nedostaju obavezni podaci.'));
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Narudžbina nije pronađena.'));
            return;
        }

        // Proveri da li već postoje pošiljke
        $db = new D_Express_DB();
        $existing_shipments = $db->get_shipments_by_order_id($order_id);
        if (!empty($existing_shipments)) {
            wp_send_json_error(array('message' => 'Pošiljke za ovu narudžbinu već postoje.'));
            return;
        }

        try {
            // ✅ ISPRAVKA: Grupiši PO LOKACIJI (ne po split-u!)
            $grouped_by_location = array();
            foreach ($shipment_splits as $split_data) {
                $location_id = intval($split_data['location_id']);
                $selected_items = isset($split_data['items']) ? $split_data['items'] : array();

                if ($location_id && !empty($selected_items)) {
                    if (!isset($grouped_by_location[$location_id])) {
                        $grouped_by_location[$location_id] = array();
                    }
                    // ✅ MERGE svih item-a te lokacije u JEDAN niz
                    $grouped_by_location[$location_id] = array_merge($grouped_by_location[$location_id], $selected_items);
                }
            }

            $created_shipments = array();
            $errors = array();
            $api = new D_Express_API();

            // ✅ ISPRAVKA: SVE item-e iste lokacije stavi u JEDAN API poziv
            foreach ($grouped_by_location as $location_id => $all_items_for_location) {

                // Pripremi osnovne shipment podatke
                $shipment_data = $api->prepare_shipment_data_from_order($order, $location_id);
                if (is_wp_error($shipment_data)) {
                    $errors[] = 'Lokacija ' . $location_id . ': ' . $shipment_data->get_error_message();
                    continue;
                }

                // ✅ KLJUČNA IZMENA: Jedan paket PO ITEM-u (ne po split-u!)
                $package_list = array();
                $total_mass = 0;
                $content_parts = array();

                foreach ($all_items_for_location as $item_id) {
                    $item = $order->get_item($item_id);
                    if (!$item || !($item instanceof WC_Order_Item_Product)) {
                        continue;
                    }

                    $product = $item->get_product();
                    if (!$product) continue;

                    // ✅ ISPRAVKA: Generiši kod za OVAJ ITEM
                    try {
                        $package_code = dexpress_generate_package_code();
                    } catch (Exception $e) {
                        $errors[] = 'Greška pri generisanju koda: ' . $e->getMessage();
                        continue;
                    }

                    // ✅ ISPRAVKA: Kalkuliši masu za OVAJ ITEM (koristi postojeću logiku)
                    $custom_weight = get_post_meta($order_id, '_dexpress_item_weight_' . $item_id, true);
                    $item_mass = $custom_weight ? ($custom_weight * 1000) : 500; // Default 500g

                    // Dodaj u PackageList
                    $package_list[] = array(
                        'Code' => $package_code,
                        'Mass' => $item_mass,
                        'DimX' => null,
                        'DimY' => null,
                        'DimZ' => null,
                        'VMass' => null,
                        'ReferenceID' => null
                    );

                    $total_mass += $item_mass;
                    $content_parts[] = $item->get_quantity() . 'x ' . $product->get_name();
                }

                // Ako nema validnih paketa, preskoči
                if (empty($package_list)) {
                    $errors[] = 'Lokacija ' . $location_id . ': Nema validnih paketa za kreiranje';
                    continue;
                }

                // ✅ ISPRAVKA: Jedan API poziv sa SVIM paketima te lokacije
                $shipment_data['PackageList'] = $package_list;
                $shipment_data['Mass'] = $total_mass;
                $shipment_data['Content'] = implode(', ', $content_parts);

                // ReferenceID logika
                if (count($grouped_by_location) > 1) {
                    $shipment_data['ReferenceID'] = $order_id . '-L' . $location_id;
                } else {
                    $shipment_data['ReferenceID'] = (string)$order_id;
                }

                // COD logika
                if ($order->get_payment_method() === 'cod') {
                    $split_cod = $this->calculate_combined_split_cod($order, $all_items_for_location);
                    $shipment_data['BuyOut'] = $split_cod;
                    $shipment_data['Value'] = $split_cod;
                }

                dexpress_log(sprintf(
                    '[FIXED] Lokacija %d: %d paketa, masa %dg, ref %s',
                    $location_id,
                    count($package_list),
                    $total_mass,
                    $shipment_data['ReferenceID']
                ), 'info');

                // ✅ JEDAN API POZIV za sve pakete ove lokacije
                $response = $api->add_shipment($shipment_data);
                if (is_wp_error($response)) {
                    $errors[] = 'Lokacija ' . $location_id . ': ' . $response->get_error_message();
                    continue;
                }

                // ✅ DODAJ OVO:
                // Provera za API greške u JSON formatu
                if (is_array($response) && isset($response['Message'])) {
                    $errors[] = 'Lokacija ' . $location_id . ': ' . $response['Message'];
                    continue;
                }
                if (is_wp_error($response)) {
                    $errors[] = 'Lokacija ' . $location_id . ': ' . $response->get_error_message();
                    continue;
                }

                // Sačuvaj shipment u bazi
                $shipment_record = array(
                    'order_id' => $order->get_id(),
                    'shipment_id' => is_string($response) ? $response : json_encode($response),
                    'tracking_number' => $package_list[0]['Code'], // Prvi paket kao glavni tracking
                    'package_code' => $package_list[0]['Code'],
                    'reference_id' => $shipment_data['ReferenceID'],
                    'sender_location_id' => $location_id,
                    'split_index' => 1,
                    'total_splits' => count($grouped_by_location),
                    'parent_order_id' => $order->get_id(),
                    'status_code' => dexpress_is_test_mode() ? '0' : null,
                    'status_description' => dexpress_is_test_mode() ? 'Čeka na preuzimanje' : null,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'shipment_data' => json_encode($response),
                    'is_test' => dexpress_is_test_mode() ? 1 : 0
                );

                $shipment_id = $db->add_shipment($shipment_record);

                if ($shipment_id) {
                    // Sačuvaj SVE pakete u packages tabelu
                    foreach ($package_list as $index => $package) {
                        $package_data = array(
                            'shipment_id' => $shipment_id,
                            'package_code' => $package['Code'],
                            'package_reference_id' => $package['Code'] . '-' . ($index + 1),
                            'package_index' => $index + 1,
                            'total_packages' => count($package_list),
                            'mass' => $package['Mass'],
                            'dim_x' => isset($package['DimX']) ? $package['DimX'] : null,
                            'dim_y' => isset($package['DimY']) ? $package['DimY'] : null,
                            'dim_z' => isset($package['DimZ']) ? $package['DimZ'] : null,
                            'v_mass' => isset($package['VMass']) ? $package['VMass'] : null,
                            'created_at' => current_time('mysql')
                        );

                        $db->add_package($package_data);
                    }

                    // Dodaj note u narudžbinu
                    $sender_locations_service = new D_Express_Sender_Locations();
                    $location = $sender_locations_service->get_location($location_id);

                    $note = sprintf(
                        'D Express pošiljka kreirana (%d paketa). Location: %s, Main Tracking: %s',
                        count($package_list),
                        $location ? $location->name : 'N/A',
                        $package_list[0]['Code']
                    );

                    $order->add_order_note($note);

                    $created_shipments[] = array(
                        'shipment_id' => $shipment_id,
                        'location_id' => $location_id,
                        'packages' => count($package_list),
                        'reference_id' => $shipment_data['ReferenceID'],
                        'tracking_numbers' => array_column($package_list, 'Code'),
                        'main_tracking' => $package_list[0]['Code']
                    );
                }
            }

            // Sačuvaj meta podatke
            if (!empty($created_shipments)) {
                update_post_meta($order_id, '_dexpress_shipment_splits', $shipment_splits);
                update_post_meta($order_id, '_dexpress_multiple_shipments', count($created_shipments));
            }

            // Odgovor
            if (!empty($errors)) {
                wp_send_json_error(array(
                    'message' => 'Greške pri kreiranju pošiljki: ' . implode(', ', $errors),
                    'created_count' => count($created_shipments),
                    'shipments' => $created_shipments
                ));
            } else {
                wp_send_json_success(array(
                    'message' => sprintf('Uspešno kreirano %d pošiljki.', count($created_shipments)),
                    'shipments' => $created_shipments
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Greška: ' . $e->getMessage()));
        }
    }
    /**
     * Kalkuliše COD za kombinovane artikle
     */
    private function calculate_combined_split_cod($order, $combined_items)
    {
        if ($order->get_payment_method() !== 'cod') {
            return 0;
        }

        $split_amount = 0;
        foreach ($order->get_items() as $item_id => $item) {
            if (in_array($item_id, $combined_items)) {
                $split_amount += $item->get_total();
            }
        }

        return intval($split_amount * 100); // Konvertuj u pare
    }
    /**
     * Generiše jedinstvene package kodove za multiple shipments
     */
    private function generate_unique_package_codes($count)
    {
        $codes = array();

        // Samo JEDNOM dobij početni index i generiši sve kodove odjednom
        $prefix = get_option('dexpress_code_prefix', 'TT');
        $range_start = intval(get_option('dexpress_code_range_start', 1));
        $range_end = intval(get_option('dexpress_code_range_end', 99));

        // Dobij trenutni index
        $current_index = intval(get_option('dexpress_package_index', $range_start - 1));

        // Generiši sve kodove sekvencijalno
        for ($i = 0; $i < $count; $i++) {
            $current_index++;

            if ($current_index > $range_end) {
                throw new Exception("Opseg kodova je iscrpljen!");
            }

            $codes[] = $prefix . str_pad($current_index, 10, '0', STR_PAD_LEFT);
        }

        // JEDNOM sačuvaj finalni index
        update_option('dexpress_package_index', $current_index);

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

            // API Response Parsing prema dokumentaciji - ISTI KAO U SINGLE SHIPMENT
            if (is_string($response)) {
                if ($response === 'TEST' || $response === 'OK') {
                    // Uspešan API odgovor
                    $api_response = $response; // "TEST" ili "OK"
                    $tracking_number = $package_code; // TT0000000026

                    error_log('[SPLIT SHIPPING] API uspešno odgovorio: ' . $api_response);
                } else {
                    // Error odgovor
                    error_log('[SPLIT SHIPPING] API greška: ' . $response);
                    return new WP_Error('api_error', 'D Express API greška: ' . $response);
                }
            } else {
                // Neočekivan format odgovora
                error_log('[SPLIT SHIPPING] API neočekivan odgovor: ' . print_r($response, true));
                return new WP_Error('api_error', 'Neočekivan format odgovora od D Express API-ja');
            }

            $db = new D_Express_DB();
            $shipment_record = array(
                'order_id' => $order->get_id(),
                'shipment_id' => $api_response,        // "TEST" ili "OK" 
                'tracking_number' => $tracking_number, // "TT0000000026"
                'package_code' => $package_code,       // "TT0000000026"
                'reference_id' => $shipment_data['ReferenceID'],
                'sender_location_id' => $location_id,
                'split_index' => $split_index,
                'total_splits' => $total_splits,
                'parent_order_id' => $order->get_id(),
                'status_code' => dexpress_is_test_mode() ? '0' : null,  // ← ISPRAVKA
                'status_description' => dexpress_is_test_mode() ? 'Čeka na preuzimanje' : null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'shipment_data' => json_encode($response), // "TEST" ili "OK"
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
                'package_reference_id' => $package_code . '-' . $split_index,
                'package_index' => $split_index,
                'total_packages' => $total_splits,
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
        // VALIDACIJA: Proveri da split paket ne prelazi 34kg
        if ($total_weight > 34000) { // 34kg = 34000g
            return new WP_Error(
                'split_package_weight_limit',
                sprintf(
                    __('Paket %d/%d je previše težak (%s kg). Maksimalno je dozvoljeno 34kg po paketu. Molimo prerasporedite artikle.', 'd-express-woo'),
                    $split_index,
                    $total_splits,
                    number_format($total_weight / 1000, 1, ',', '.')
                )
            );
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
