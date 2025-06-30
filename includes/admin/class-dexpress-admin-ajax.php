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
            if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'dexpress_admin_nonce')) {
                wp_send_json_error('Sigurnosna greška');
                return;
            }

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error('Nemate dozvolu');
                return;
            }

            $location_id = intval($_GET['location_id']);
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

            wp_send_json_success(['location' => $location]);
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
}
