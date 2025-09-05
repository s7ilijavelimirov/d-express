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

        add_action('wp_ajax_dexpress_generate_split_content', array($this, 'ajax_generate_split_content'));

        add_action('wp_ajax_dexpress_refresh_shipment_status', array($this, 'ajax_refresh_shipment_status'));

        add_action('wp_ajax_dexpress_mark_printed', array($this, 'ajax_mark_printed'));

        add_action('wp_ajax_dexpress_save_custom_weights', array($this, 'ajax_save_custom_weights'));

        add_action('wp_ajax_dexpress_generate_single_content', array($this, 'ajax_generate_single_content'));

        add_action('wp_ajax_dexpress_get_fresh_nonce', array($this, 'ajax_get_fresh_nonce'));
    }

    public function ajax_generate_single_content()
    {
        check_ajax_referer('dexpress_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Nemate dozvolu');
        }

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error('Narudžbina nije pronađena');
        }

        $content = function_exists('dexpress_generate_shipment_content')
            ? dexpress_generate_shipment_content($order)
            : 'Razni proizvodi';

        wp_send_json_success(['content' => $content]);
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
                'address_description' => sanitize_text_field($_POST['address_description'] ?? ''),
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
                'address_description' => sanitize_text_field($_POST['address_description'] ?? ''),
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
        // Provere nonce i dozvola...
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress_admin_nonce')) {
            wp_send_json_error(array('message' => 'Sigurnosna provera nije uspela.'));
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Nemate dozvolu za ovu akciju.'));
            return;
        }

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        $sender_location_id = intval($_POST['sender_location_id']);
        $custom_content = isset($_POST['content']) ? sanitize_text_field($_POST['content']) : '';
        if (!$order) {
            wp_send_json_error(array('message' => 'Narudžbina nije pronađena.'));
            return;
        }

        try {
            // KORISTI SHIPMENT SERVICE umesto direktno API
            $shipment_service = new D_Express_Shipment_Service();
            $result = $shipment_service->create_shipment($order, $sender_location_id, null, $custom_content);

            if (is_wp_error($result)) {
                // Dodaj specifičnu poruku za težinu
                $error_code = $result->get_error_code();
                $error_message = $result->get_error_message();

                if ($error_code === 'package_weight_limit') {
                    wp_send_json_error([
                        'message' => "TEŽINA PROBLEM: " . $error_message,
                        'error_type' => 'weight_limit'
                    ]);
                } else {
                    wp_send_json_error(['message' => $error_message]);
                }
                return;
            }

            wp_send_json_success(array(
                'message' => 'Pošiljka uspešno kreirana',
                'shipment_id' => $result['shipment_id'],
                'tracking_number' => $result['tracking_number']
            ));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Greška: ' . $e->getMessage()));
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

        $download_url = admin_url('admin-ajax.php') . '?' . http_build_query(array(
            'action' => 'dexpress_download_label',
            'shipment_id' => $shipment_id,
            'nonce' => wp_create_nonce('dexpress_admin_nonce')  // ← KORISTITI ISTI NONCE
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
            // Grupiši split-ove po lokaciji
            $grouped_by_location = array();
            foreach ($shipment_splits as $split_data) {
                $location_id = intval($split_data['location_id']);
                $selected_items = isset($split_data['items']) ? $split_data['items'] : array();

                // Proveri da li ima validnih količina
                $has_items = false;
                if (is_array($selected_items)) {
                    foreach ($selected_items as $item_id => $quantity) {
                        if (intval($quantity) > 0) {
                            $has_items = true;
                            break;
                        }
                    }
                }

                if ($location_id && $has_items) {
                    if (!isset($grouped_by_location[$location_id])) {
                        $grouped_by_location[$location_id] = array();
                    }
                    $grouped_by_location[$location_id][] = $split_data;
                }
            }

            $created_shipments = array();
            $errors = array();
            $api = new D_Express_API();
            $split_index = 1;

            // Kreiraj pošiljke po lokacijama
            foreach ($grouped_by_location as $location_id => $splits_for_location) {
                $total_locations = count($grouped_by_location);
                $shipment_data = $api->prepare_shipment_data_from_order($order, $location_id);
                $shipment_data['RClientID'] = '';

                $sender_location = D_Express_Sender_Locations::get_instance()->get_location($location_id);
                if ($sender_location && !empty($sender_location->address_description)) {
                    $shipment_data['PuAddressDesc'] = $sender_location->address_description;
                }

                if (is_wp_error($shipment_data)) {
                    $errors[] = 'Lokacija ' . $location_id . ': ' . $shipment_data->get_error_message();
                    $split_index++;
                    continue;
                }

                $package_list = array();
                $total_mass = 0;
                $content_parts = array();

                // Kreiraj jedan paket po split-u
                foreach ($splits_for_location as $split_data) {
                    $split_items = isset($split_data['items']) ? $split_data['items'] : array();
                    if (empty($split_items)) continue;

                    // Generiši kod za paket
                    try {
                        $package_code = dexpress_generate_package_code();
                    } catch (Exception $e) {
                        $errors[] = 'Greška pri generisanju koda: ' . $e->getMessage();
                        continue;
                    }

                    // Kalkuliši masu split-a koristeći quantities
                    $split_mass = 0;
                    foreach ($split_items as $item_id => $quantity) {
                        $qty = intval($quantity);
                        if ($qty <= 0) continue;

                        $item = $order->get_item($item_id);
                        if (!$item || !($item instanceof WC_Order_Item_Product)) continue;

                        $product = $item->get_product();
                        if (!$product) continue;

                        $custom_weight = get_post_meta($order_id, '_dexpress_item_weight_' . $item_id, true);

                        if ($custom_weight && $custom_weight > 0) {
                            $split_mass += ($custom_weight * $qty * 1000); // qty umesto $item->get_quantity()
                        } else if ($product->has_weight()) {
                            $split_mass += ($product->get_weight() * $qty * 1000);
                        } else {
                            $split_mass += 100 * $qty; // 100g per quantity
                        }
                    }

                    // Minimum masa
                    if ($split_mass < 100) {
                        $split_mass = 100;
                    }
                    $custom_split_weight = isset($split_data['final_weight']) ? floatval($split_data['final_weight']) : 0;
                    if ($custom_split_weight > 0) {
                        $split_mass = intval($custom_split_weight * 1000); // Konvertuj kg u grame
                        dexpress_log("[CUSTOM WEIGHT] Koristi custom weight: {$custom_split_weight}kg za paket", 'info');
                    }
                    // Generiši sadržaj split-a
                    $custom_split_content = isset($split_data['custom_content']) ? sanitize_text_field($split_data['custom_content']) : null;
                    if (!empty($custom_split_content)) {
                        $split_content = $custom_split_content;
                    } else {
                        // Kreiraj item ID array za content generation
                        $item_ids_for_content = array();
                        foreach ($split_items as $item_id => $quantity) {
                            if (intval($quantity) > 0) {
                                $item_ids_for_content[] = $item_id;
                            }
                        }

                        $metabox_instance = new D_Express_Order_Metabox();
                        $content_type = get_option('dexpress_content_type', 'category');
                        $split_content = function_exists('dexpress_generate_shipment_content')
                            ? dexpress_generate_shipment_content($order, $item_ids_for_content)
                            : 'Razni proizvodi';
                    }

                    // Dodaj paket u listu
                    $package_list[] = array(
                        'Code' => $package_code,
                        'ReferenceID' => $shipment_data['ReferenceID'] . '_PKG_' . (count($package_list) + 1),
                        'Mass' => $split_mass,
                        'Content' => $split_content,
                        'DimX' => null,
                        'DimY' => null,
                        'DimZ' => null,
                        'VMass' => null,
                    );

                    if (!in_array($split_content, $content_parts)) {
                        $content_parts[] = $split_content;
                    }
                    $total_mass += $split_mass;
                }

                // Proveri da li postoje validni paketi
                if (empty($package_list)) {
                    $errors[] = 'Lokacija ' . $location_id . ': Nema validnih paketa za kreiranje';
                    $split_index++;
                    continue;
                }

                // Postavka shipment podataka
                $shipment_data['PackageList'] = $package_list;
                $shipment_data['Mass'] = $total_mass;

                // Kombinuj i sanitizuj content
                $combined_content = implode(', ', array_unique($content_parts));
                $safe_content = preg_replace('/[^a-zA-Zžćčđš\s,\-\(\)\/\.0-9]/u', '', $combined_content);
                $safe_content = preg_replace('/\s+/', ' ', trim($safe_content));

                if (strlen($safe_content) > 50) {
                    $safe_content = substr($safe_content, 0, 47) . '...';
                }

                $shipment_data['Content'] = $safe_content;

                // ReferenceID logika
                if (count($grouped_by_location) > 1) {
                    $shipment_data['ReferenceID'] = $order_id . '-L' . $location_id;
                } else {
                    $shipment_data['ReferenceID'] = (string)$order_id;
                }

                // Kalkuliši sve artikle za ovu lokaciju (za Value i COD)
                $all_items_for_location = array();
                foreach ($splits_for_location as $split_data) {
                    foreach ($split_data['items'] as $item_id => $quantity) {
                        if (intval($quantity) > 0) {
                            if (!isset($all_items_for_location[$item_id])) {
                                $all_items_for_location[$item_id] = 0;
                            }
                            $all_items_for_location[$item_id] += intval($quantity);
                        }
                    }
                }

                // COD i Value logika
                if ($order->get_payment_method() === 'cod') {
                    $split_cod = $this->calculate_quantity_based_split_cod($order, $all_items_for_location, $split_index, count($grouped_by_location));
                    $shipment_data['BuyOut'] = $split_cod;

                    // Izračunaj Value na osnovu artikala u ovoj lokaciji
                    $location_items_value = 0;
                    foreach ($all_items_for_location as $item_id => $quantity) {
                        $item = $order->get_item($item_id);
                        if ($item instanceof WC_Order_Item_Product) {
                            $item_price = ($item->get_total() + $item->get_total_tax()) / $item->get_quantity();
                            $location_items_value += ($item_price * $quantity);
                        }
                    }
                    $shipment_data['Value'] = intval($location_items_value * 100); // u parama
                } else {
                    // Non-COD orders
                    $location_items_value = 0;
                    foreach ($all_items_for_location as $item_id => $quantity) {
                        $item = $order->get_item($item_id);
                        if ($item instanceof WC_Order_Item_Product) {
                            $item_price = ($item->get_total() + $item->get_total_tax()) / $item->get_quantity();
                            $location_items_value += ($item_price * $quantity);
                        }
                    }
                    $shipment_data['Value'] = intval($location_items_value * 100); // u parama
                    $shipment_data['BuyOut'] = 0; // Non-COD nema BuyOut
                }

                dexpress_log(sprintf(
                    '[SHIPMENT] Lokacija %d: %d paketa, masa %dg, ref %s, COD: %d',
                    $location_id,
                    count($package_list),
                    $total_mass,
                    $shipment_data['ReferenceID'],
                    $shipment_data['BuyOut']
                ), 'info');

                $payment_service = new D_Express_Payments_Service();
                $validation = $payment_service->validate_payment_logic($order, $shipment_data);
                if (is_wp_error($validation)) {
                    $errors[] = 'Lokacija ' . $location_id . ': Payment validation greška - ' . $validation->get_error_message();
                    continue;
                }

                dexpress_log("[VALIDATION] Payment logic validated for location {$location_id}", 'debug');

                $response = $api->add_shipment($shipment_data);
                if (is_wp_error($response)) {
                    $errors[] = 'Lokacija ' . $location_id . ': ' . $response->get_error_message();
                    $split_index++;
                    continue;
                }

                if (is_array($response) && isset($response['Message'])) {
                    $errors[] = 'Lokacija ' . $location_id . ': ' . $response['Message'];
                    $split_index++;
                    continue;
                }

                // Sačuvaj shipment u bazi
                $shipment_record = array(
                    'order_id' => $order->get_id(),
                    'reference_id' => $shipment_data['ReferenceID'],
                    'sender_location_id' => $location_id,
                    'split_index' => $split_index,
                    'total_splits' => $total_locations,
                    'value_in_para' => $shipment_data['Value'],
                    'buyout_in_para' => $shipment_data['BuyOut'],
                    'payment_by' => $shipment_data['PaymentBy'],
                    'payment_type' => $shipment_data['PaymentType'],
                    'shipment_type' => $shipment_data['DlTypeID'],
                    'return_doc' => $shipment_data['ReturnDoc'],
                    'content' => $shipment_data['Content'],
                    'total_mass' => $shipment_data['Mass'],
                    'note' => $shipment_data['Note'],
                    'api_response' => is_string($response) ? $response : json_encode($response),
                    'is_test' => dexpress_is_test_mode() ? 1 : 0
                );

                $shipment_id = $db->add_shipment($shipment_record);

                if ($shipment_id) {
                    // Sačuvaj pakete
                    $total_packages = count($package_list);
                    $package_index = 1;

                    foreach ($package_list as $package) {
                        $package_data = array(
                            'shipment_id' => $shipment_id,
                            'package_code' => $package['Code'],
                            'package_reference_id' => isset($package['ReferenceID']) ? $package['ReferenceID'] : null,
                            'package_index' => $package_index,
                            'total_packages' => $total_packages,
                            'mass' => $package['Mass'],
                            'content' => isset($package['Content']) ? $package['Content'] : null,
                            'dim_x' => isset($package['DimX']) ? $package['DimX'] : null,
                            'dim_y' => isset($package['DimY']) ? $package['DimY'] : null,
                            'dim_z' => isset($package['DimZ']) ? $package['DimZ'] : null,
                            'v_mass' => isset($package['VMass']) ? $package['VMass'] : null
                        );

                        $db->add_package($package_data);
                        $package_index++;
                    }

                    // Dodaj note u narudžbinu
                    $sender_locations_service = new D_Express_Sender_Locations();
                    $location = $sender_locations_service->get_location($location_id);

                    $note = sprintf(
                        'D Express pošiljka kreirana (%d paketa). Location: %s',
                        count($package_list),
                        $location ? $location->name : 'N/A',
                        $package_list[0]['Code']
                    );

                    $order->add_order_note($note);
                    if ($split_index === 1) {
                        do_action('dexpress_after_shipment_created', $shipment_id, $order);
                    }

                    $created_shipments[] = array(
                        'shipment_id' => $shipment_id,
                        'location_id' => $location_id,
                        'packages' => count($package_list),
                        'reference_id' => $shipment_data['ReferenceID'],
                        'tracking_numbers' => array_column($package_list, 'Code'),
                        'main_tracking' => $package_list[0]['Code']
                    );
                }

                $split_index++;
            }

            // Sačuvaj meta podatke
            if (!empty($created_shipments)) {
                update_post_meta($order_id, '_dexpress_shipment_splits', $shipment_splits);
                update_post_meta($order_id, '_dexpress_multiple_shipments', count($created_shipments));
            }

            // Pošalji odgovor
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

    public function ajax_generate_split_content()
    {
        check_ajax_referer('dexpress_admin_nonce', 'nonce');

        $order_id = intval($_POST['order_id']);
        $selected_items = $_POST['selected_items'];

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Narudžbina nije pronađena']);
            return;
        }

        $content_type = get_option('dexpress_content_type', 'category');
        $content = function_exists('dexpress_generate_shipment_content')
            ? dexpress_generate_shipment_content($order, $selected_items)
            : 'Razni proizvodi';

        wp_send_json_success(['content' => $content]);
    }
    /**
     * Kalkuliše COD za kombinovane artikle - NOVA LOGIKA
     */
    private function calculate_combined_split_cod($order, $combined_items, $split_index = 1, $total_splits = 1)
    {
        if ($order->get_payment_method() !== 'cod') {
            return 0;
        }

        // Izračunaj vrednost artikala u ovom split-u
        $split_value = 0;
        foreach ($combined_items as $item_id) {
            $item = $order->get_item($item_id);
            if ($item instanceof WC_Order_Item_Product) {
                $split_value += ($item->get_total() + $item->get_total_tax());
            }
        }

        // SAMO prvi split dobija dostavu i dodatne troškove
        if ($split_index === 1) {
            $split_value += $order->get_shipping_total();
            $split_value += $order->get_shipping_tax();

            // Dodaj i ostale fees ako postoje
            foreach ($order->get_fees() as $fee) {
                $split_value += $fee->get_total();
                $split_value += $fee->get_total_tax();
            }
        }

        $split_cod = intval($split_value * 100); // u parama

        dexpress_log(sprintf(
            '[SPLIT COD] Split %d/%d: Artikli=%.2f, Dostava=%s, Total COD=%d para',
            $split_index,
            $total_splits,
            $split_value - ($split_index === 1 ? $order->get_shipping_total() + $order->get_shipping_tax() : 0),
            $split_index === 1 ? ($order->get_shipping_total() + $order->get_shipping_tax()) . ' RSD' : 'N/A',
            $split_cod
        ), 'debug');

        return $split_cod;
    }
    /**
     * Kalkuliše COD za quantity-based artikle
     */
    private function calculate_quantity_based_split_cod($order, $quantity_items, $split_index = 1, $total_splits = 1)
    {
        if ($order->get_payment_method() !== 'cod') {
            return 0;
        }

        // Izračunaj vrednost artikala u ovom split-u na osnovu quantities
        $split_value = 0;
        foreach ($quantity_items as $item_id => $quantity) {
            $item = $order->get_item($item_id);
            if ($item instanceof WC_Order_Item_Product) {
                $item_price = ($item->get_total() + $item->get_total_tax()) / $item->get_quantity();
                $split_value += ($item_price * $quantity);
            }
        }

        // SAMO prvi split dobija dostavu
        if ($split_index === 1) {
            $split_value += $order->get_shipping_total();
            $split_value += $order->get_shipping_tax();

            // Dodaj i ostale fees ako postoje
            foreach ($order->get_fees() as $fee) {
                $split_value += $fee->get_total();
                $split_value += $fee->get_total_tax();
            }
        }

        return intval($split_value * 100); // u parama
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
                    $shipment->reference_id
                ));
            }

            wp_send_json_success(array('message' => 'Pošiljka je uspešno obrisana.'));
        } else {
            wp_send_json_error(array('message' => 'Greška pri brisanju pošiljke.'));
        }
    }
    /**
     * AJAX: Osvežavanje statusa pošiljke
     */
    public function ajax_refresh_shipment_status()
    {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-refresh-status')) {
            wp_send_json_error(['message' => 'Sigurnosna provera nije uspela.']);
            return;
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nemate dozvolu za ovu akciju.']);
            return;
        }

        $shipment_id = intval($_POST['shipment_id']);
        if (!$shipment_id) {
            wp_send_json_error(['message' => 'ID pošiljke je obavezan.']);
            return;
        }

        try {
            // Pozovi timeline klasu
            $timeline = new D_Express_Order_Timeline();
            $result = $timeline->manually_trigger_simulation($shipment_id);

            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            } else {
                wp_send_json_success($result);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Greška: ' . $e->getMessage()]);
        }
    }
    /**
     * POPRAVKA: AJAX akcija za označavanje kao štampano
     * Dodaj ovu izmenu u ajax_mark_printed metodu u class-dexpress-admin-ajax.php
     */
    public function ajax_mark_printed()
    {
        check_ajax_referer('dexpress_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Nemate dozvolu za ovu akciju');
            return;
        }

        global $wpdb;

        // Podrška za oba: shipment_id i order_id
        if (isset($_POST['order_id'])) {
            // Nova logika za order-based view
            $order_id = intval($_POST['order_id']);

            if (!$order_id) {
                wp_send_json_error('Nevaljan order ID');
                return;
            }

            // Označi order kao štampan
            update_post_meta($order_id, '_dexpress_label_printed', 'yes');
            update_post_meta($order_id, '_dexpress_label_printed_date', current_time('mysql'));

            wp_send_json_success('Narudžbina je označena kao štampana');
        } elseif (isset($_POST['shipment_id'])) {
            // Postojeća logika za shipment-based view
            $shipment_id = intval($_POST['shipment_id']);

            if (!$shipment_id) {
                wp_send_json_error('Nevaljan shipment ID');
                return;
            }

            $shipment = $wpdb->get_row($wpdb->prepare(
                "SELECT order_id FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
                $shipment_id
            ));

            if ($shipment) {
                update_post_meta($shipment->order_id, '_dexpress_label_printed', 'yes');
                update_post_meta($shipment->order_id, '_dexpress_label_printed_date', current_time('mysql'));
                wp_send_json_success('Pošiljka je označena kao štampana');
            } else {
                wp_send_json_error('Pošiljka nije pronađena');
            }
        } else {
            wp_send_json_error('Nedostaje shipment_id ili order_id parametar');
        }
    }
    /**
     * AJAX: Čuvanje custom težina
     */
    public function ajax_save_custom_weights()
    {
        check_ajax_referer('dexpress_meta_box', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Nemate dozvolu');
        }

        $order_id = intval($_POST['order_id']);
        $weights = $_POST['weights'];

        if (!$order_id || !is_array($weights)) {
            wp_send_json_error('Nevalidni podaci');
        }

        $total_weight = 0;
        $order = wc_get_order($order_id);
        $updated_count = 0; // DODAJ OVO

        foreach ($weights as $item_id => $weight) {
            $weight = floatval($weight);
            $item = $order->get_item($item_id);

            if ($weight > 0 && $item) {
                update_post_meta($order_id, '_dexpress_item_weight_' . $item_id, $weight);
                $total_weight += ($weight * $item->get_quantity());
                $updated_count++; // DODAJ OVO
            } else {
                delete_post_meta($order_id, '_dexpress_item_weight_' . $item_id);
            }
        }

        wp_send_json_success([
            'total_weight' => number_format($total_weight, 2) . ' kg',
            'updated_count' => $updated_count, // DODAJ OVO
            'message' => "Ažurirano {$updated_count} proizvoda" // IZMENI OVO
        ]);
    }
    public function ajax_get_fresh_nonce()
    {
        check_ajax_referer('dexpress_admin_nonce', 'nonce');
        wp_send_json_success(['nonce' => wp_create_nonce('dexpress-bulk-print')]);
    }
}
