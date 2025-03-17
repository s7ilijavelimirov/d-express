<?php

/**
 * D Express Checkout klasa
 * 
 * Klasa za integraciju D Express polja na checkout-u
 */

defined('ABSPATH') || exit;

class D_Express_Checkout
{
    /**
     * Inicijalizacija checkout funkcionalnosti
     */
    public function init()
    {
        // Zamena standardnih polja sa D Express poljima
        add_filter('woocommerce_checkout_fields', array($this, 'modify_checkout_fields'), 1000);

        // Validacija checkout polja
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_fields'));

        // Čuvanje checkout polja
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_checkout_fields'));

        // AJAX handleri za pretragu i dobijanje podataka
        add_action('wp_ajax_dexpress_search_streets', array($this, 'ajax_search_streets'));
        add_action('wp_ajax_nopriv_dexpress_search_streets', array($this, 'ajax_search_streets'));

        add_action('wp_ajax_dexpress_get_towns_for_street', array($this, 'ajax_get_towns_for_street'));
        add_action('wp_ajax_nopriv_dexpress_get_towns_for_street', array($this, 'ajax_get_towns_for_street'));

        add_action('wp_ajax_dexpress_get_pickup_locations', array($this, 'ajax_get_pickup_locations'));
        add_action('wp_ajax_nopriv_dexpress_get_pickup_locations', array($this, 'ajax_get_pickup_locations'));

        add_action('wp_ajax_dexpress_get_all_towns', array($this, 'ajax_get_all_towns'));
        add_action('wp_ajax_nopriv_dexpress_get_all_towns', array($this, 'ajax_get_all_towns'));

        // Enqueue skripti i stilova
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_scripts'));

        // Dodavanje polja za način dostave
        add_action('woocommerce_before_checkout_shipping_form', array($this, 'add_delivery_type_field'));

        // Prikaži dodatne opcije za D Express
        add_action('woocommerce_review_order_before_payment', array($this, 'display_dexpress_options'));

        // Prikaži podatke u admin panelu
        add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_admin_data'));

        // Filter za podatke za D Express API
        add_filter('dexpress_prepare_shipment_data', array($this, 'prepare_shipment_data'), 10, 2);
    }

    /**
     * Dodavanje polja za izbor načina dostave
     */
    public function add_delivery_type_field($checkout)
    {
        // Provera da li je D Express dostava odabrana
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $has_dexpress = false;

        if (is_array($chosen_methods)) {
            foreach ($chosen_methods as $method) {
                if (strpos($method, 'dexpress') !== false) {
                    $has_dexpress = true;
                    break;
                }
            }
        }

        if (!$has_dexpress) {
            return;
        }

        echo '<div class="dexpress-delivery-type-wrapper">';
        echo '<h3>' . __('Način dostave', 'd-express-woo') . '</h3>';

        woocommerce_form_field('dexpress_delivery_type', array(
            'type'        => 'radio',
            'class'       => array('dexpress-delivery-type'),
            'required'    => true,
            'options'     => array(
                'address'   => __('Dostava na adresu', 'd-express-woo'),
                'shop'      => __('Preuzimanje u prodavnici', 'd-express-woo'),
                'dispenser' => __('Preuzimanje na paketomatu', 'd-express-woo'),
            ),
            'default'     => 'address'
        ), $checkout->get_value('dexpress_delivery_type') ?: 'address');

        // Polja za lokacije preuzimanja (prodavnica/paketomat)
        echo '<div class="dexpress-pickup-wrapper" style="display:none;">';

        woocommerce_form_field('dexpress_pickup_town', array(
            'type'        => 'select',
            'label'       => __('Grad za preuzimanje', 'd-express-woo'),
            'placeholder' => __('Izaberite grad', 'd-express-woo'),
            'required'    => false,
            'class'       => array('form-row-wide', 'dexpress-pickup-town'),
            'options'     => $this->get_towns_for_select()
        ), $checkout->get_value('dexpress_pickup_town'));

        woocommerce_form_field('dexpress_pickup_location', array(
            'type'        => 'select',
            'label'       => __('Lokacija preuzimanja', 'd-express-woo'),
            'placeholder' => __('Prvo izaberite grad', 'd-express-woo'),
            'required'    => false,
            'class'       => array('form-row-wide', 'dexpress-pickup-location'),
            'options'     => array('' => __('Prvo izaberite grad', 'd-express-woo'))
        ), $checkout->get_value('dexpress_pickup_location'));

        echo '</div>';
        echo '</div>';
    }

    /**
     * Modifikacija checkout polja
     */
    public function modify_checkout_fields($fields)
    {
        // Modifikacija shipping polja
        if (isset($fields['shipping'])) {
            // Polje za uputstvo
            $fields['shipping']['shipping_address_instructions'] = array(
                'type'        => 'text',
                'label'       => '',
                'placeholder' => '',
                'required'    => false,
                'class'       => array('dexpress-address-instructions', 'form-row-wide'),
                'priority'    => 40,
                'custom_attributes' => array('readonly' => 'readonly', 'style' => 'display:none;border:none;background:none;')
            );

            // Ulica - prvo polje prema dokumentaciji
            $fields['shipping']['shipping_street'] = array(
                'type'        => 'text',
                'label'       => __('Ulica', 'd-express-woo'),
                'placeholder' => __('Započnite unos naziva ulice', 'd-express-woo'),
                'required'    => true,
                'class'       => array('form-row-wide', 'dexpress-street'),
                'priority'    => 50
            );

            // Skriveno polje za ID ulice
            $fields['shipping']['shipping_street_id'] = array(
                'type'        => 'hidden',
                'required'    => false,
                'class'       => array('dexpress-street-id'),
                'priority'    => 51
            );

            // Kućni broj
            $fields['shipping']['shipping_number'] = array(
                'type'        => 'text',
                'label'       => __('Kućni broj', 'd-express-woo'),
                'placeholder' => __('Npr: 15a, 23/4', 'd-express-woo'),
                'required'    => true,
                'class'       => array('form-row-wide', 'dexpress-number'),
                'priority'    => 55
            );

            // Grad
            $fields['shipping']['shipping_city'] = array(
                'type'        => 'select',
                'label'       => __('Grad', 'd-express-woo'),
                'placeholder' => __('Prvo izaberite ulicu', 'd-express-woo'),
                'required'    => true,
                'class'       => array('form-row-wide', 'dexpress-city'),
                'options'     => array('' => __('Prvo izaberite ulicu', 'd-express-woo')),
                'priority'    => 60
            );

            // Skriveno polje za ID grada
            $fields['shipping']['shipping_city_id'] = array(
                'type'        => 'hidden',
                'required'    => false,
                'class'       => array('dexpress-city-id'),
                'priority'    => 61
            );

            // Poštanski broj
            $fields['shipping']['shipping_postcode'] = array(
                'type'        => 'text',
                'label'       => __('Poštanski broj', 'd-express-woo'),
                'required'    => false,
                'class'       => array('form-row-wide', 'dexpress-postcode'),
                'priority'    => 65,
                'custom_attributes' => array('readonly' => 'readonly')
            );

            // Sakrij originalna WooCommerce polja
            if (isset($fields['shipping']['shipping_address_1'])) {
                $fields['shipping']['shipping_address_1']['type'] = 'hidden';
                $fields['shipping']['shipping_address_1']['required'] = false;
                $fields['shipping']['shipping_address_1']['class'][] = 'dexpress-hidden';
            }

            if (isset($fields['shipping']['shipping_address_2'])) {
                $fields['shipping']['shipping_address_2']['type'] = 'hidden';
                $fields['shipping']['shipping_address_2']['required'] = false;
                $fields['shipping']['shipping_address_2']['class'][] = 'dexpress-hidden';
            }

            // Morati ćemo da modifikujemo polje grada koje već postoji
            if (isset($fields['shipping']['shipping_city'])) {
                $fields['shipping']['shipping_city']['type'] = 'hidden';
                $fields['shipping']['shipping_city']['required'] = false;
                $fields['shipping']['shipping_city']['class'][] = 'dexpress-hidden';
            }
        }

        // Sakrivanje billing polja koja zamenjujemo - ovo se koristi samo ako je shipping adresa različita
        if (isset($fields['billing'])) {
            if (isset($fields['billing']['billing_address_1'])) {
                $fields['billing']['billing_address_1']['class'][] = 'dexpress-billing-field';
            }

            if (isset($fields['billing']['billing_address_2'])) {
                $fields['billing']['billing_address_2']['class'][] = 'dexpress-billing-field';
            }

            if (isset($fields['billing']['billing_city'])) {
                $fields['billing']['billing_city']['class'][] = 'dexpress-billing-field';
            }

            if (isset($fields['billing']['billing_postcode'])) {
                $fields['billing']['billing_postcode']['class'][] = 'dexpress-billing-field';
            }
        }

        return $fields;
    }

    /**
     * Prikaz dodatnih opcija za D Express
     */
    public function display_dexpress_options()
    {
        // Provera da li je D Express dostava odabrana
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $has_dexpress = false;

        if (is_array($chosen_methods)) {
            foreach ($chosen_methods as $method) {
                if (strpos($method, 'dexpress') !== false) {
                    $has_dexpress = true;
                    break;
                }
            }
        }

        if (!$has_dexpress) {
            return;
        }

        echo '<div class="dexpress-options">';
        echo '<h3>' . __('D Express opcije', 'd-express-woo') . '</h3>';

        // Povratna dokumentacija
        woocommerce_form_field('dexpress_return_doc', array(
            'type'        => 'checkbox',
            'label'       => __('Potrebna povratna dokumentacija', 'd-express-woo'),
            'required'    => false,
            'class'       => array('form-row-wide')
        ), WC()->checkout->get_value('dexpress_return_doc'));

        // Napomena za dostavu
        woocommerce_form_field('dexpress_delivery_note', array(
            'type'        => 'textarea',
            'label'       => __('Napomena za dostavu', 'd-express-woo'),
            'placeholder' => __('Unesite napomenu (sprat, ulaz, stan, telefon...)', 'd-express-woo'),
            'required'    => false,
            'class'       => array('form-row-wide')
        ), WC()->checkout->get_value('dexpress_delivery_note'));

        echo '</div>';
    }

    /**
     * Dobijanje opcija gradova za select polje
     */
    private function get_towns_for_select()
    {
        global $wpdb;

        $towns = $wpdb->get_results(
            "SELECT id, name, display_name, postal_code, 
             (SELECT m.name FROM {$wpdb->prefix}dexpress_municipalities m WHERE m.id = t.municipality_id) as municipality
             FROM {$wpdb->prefix}dexpress_towns t
             ORDER BY name ASC"
        );

        $options = array('' => __('Izaberite grad', 'd-express-woo'));

        foreach ($towns as $town) {
            $town_name = $town->name;

            // Dodaj opštinu ako nije ista kao ime grada
            if (!empty($town->municipality) && $town->municipality != $town->name) {
                $town_name .= ' (' . $town->municipality . ')';
            }

            // Dodaj poštanski broj
            if (!empty($town->postal_code)) {
                $town_name .= ' - ' . $town->postal_code;
            }

            $options[$town->id] = $town_name;
        }

        return $options;
    }

    /**
     * AJAX handler za pretragu ulica
     */
    public function ajax_search_streets()
    {
        // Provera nonce-a
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        $search = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';

        if (empty($search) || strlen($search) < 2) {
            wp_send_json([]);
            return;
        }

        global $wpdb;

        // Pretraga ulica sa informacijama o gradu
        $streets = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.id, s.name, t.name as town_name, t.postal_code, m.name as municipality
            FROM {$wpdb->prefix}dexpress_streets s
            LEFT JOIN {$wpdb->prefix}dexpress_towns t ON s.TId = t.id
            LEFT JOIN {$wpdb->prefix}dexpress_municipalities m ON m.id = t.municipality_id
            WHERE s.name LIKE %s AND s.deleted = 0 
            ORDER BY s.name ASC, t.name ASC
            LIMIT 20",
            '%' . $wpdb->esc_like($search) . '%'
        ));

        $results = [];

        foreach ($streets as $street) {
            // Formatiranje: Ulica (Grad - Poštanski broj)
            $display_text = $street->name;
            $town_info = '';

            if (!empty($street->town_name)) {
                $town_info .= $street->town_name;

                if (!empty($street->municipality) && $street->municipality != $street->town_name) {
                    $town_info .= ' (' . $street->municipality . ')';
                }

                if (!empty($street->postal_code)) {
                    $town_info .= ' - ' . $street->postal_code;
                }

                if (!empty($town_info)) {
                    $display_text .= ' (' . $town_info . ')';
                }
            }

            $results[] = [
                'id' => $street->id,
                'label' => $display_text,
                'value' => $street->name,
                'town_id' => $street->TId,
                'town_name' => $street->town_name
            ];
        }

        wp_send_json($results);
    }

    /**
     * AJAX handler za dobijanje gradova za ulicu
     */
    public function ajax_get_towns_for_street()
    {
        // Provera nonce-a
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        $street_id = isset($_GET['street_id']) ? intval($_GET['street_id']) : 0;

        if (empty($street_id)) {
            wp_send_json_error(['message' => 'Missing street_id']);
            return;
        }

        global $wpdb;

        // Dobijanje naziva ulice
        $street_name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}dexpress_streets WHERE id = %d",
            $street_id
        ));

        if (!$street_name) {
            wp_send_json_error(['message' => 'Street not found']);
            return;
        }

        // Dobijanje gradova koji sadrže ulicu sa istim nazivom
        $towns = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT t.id, t.name, t.display_name, t.postal_code, m.name as municipality 
            FROM {$wpdb->prefix}dexpress_towns t
            LEFT JOIN {$wpdb->prefix}dexpress_streets s ON s.TId = t.id
            LEFT JOIN {$wpdb->prefix}dexpress_municipalities m ON m.id = t.municipality_id
            WHERE s.name = %s AND s.deleted = 0
            ORDER BY t.name ASC",
            $street_name
        ));

        $results = [];

        foreach ($towns as $town) {
            // Formatiraj prikazni tekst grada
            $town_name = $town->name;

            // Dodaj opštinu ako nije ista kao ime grada
            if (!empty($town->municipality) && $town->municipality != $town->name) {
                $town_name .= ' (' . $town->municipality . ')';
            }

            // Dodaj poštanski broj
            if (!empty($town->postal_code)) {
                $town_name .= ' - ' . $town->postal_code;
            }

            $results[] = [
                'id' => $town->id,
                'text' => $town_name,
                'postal_code' => $town->postal_code
            ];
        }

        // Dodaj opciju "Drugo mesto" na kraju
        $results[] = [
            'id' => 'other',
            'text' => __('Drugo mesto (nije na listi)', 'd-express-woo'),
            'postal_code' => ''
        ];

        wp_send_json_success(['results' => $results]);
    }

    /**
     * AJAX handler za dobijanje svih gradova
     */
    public function ajax_get_all_towns()
    {
        // Provera nonce-a
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        $search = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';

        global $wpdb;

        $query = "SELECT t.id, t.name, t.display_name, t.postal_code, m.name as municipality
                 FROM {$wpdb->prefix}dexpress_towns t
                 LEFT JOIN {$wpdb->prefix}dexpress_municipalities m ON m.id = t.municipality_id";

        if (!empty($search)) {
            $query .= $wpdb->prepare(
                " WHERE t.name LIKE %s",
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        $query .= " ORDER BY t.name ASC LIMIT 30";

        $towns = $wpdb->get_results($query);

        $results = [];

        foreach ($towns as $town) {
            $town_name = $town->name;

            if (!empty($town->display_name)) {
                $town_name .= ' - ' . $town->display_name;
            }

            if (!empty($town->municipality) && $town->municipality != $town->name) {
                $town_name .= ' (' . $town->municipality . ')';
            }

            if (!empty($town->postal_code)) {
                $town_name .= ' - ' . $town->postal_code;
            }

            $results[] = [
                'id' => $town->id,
                'text' => $town_name,
                'postal_code' => $town->postal_code
            ];
        }

        wp_send_json_success(['results' => $results]);
    }

    /**
     * AJAX handler za dobijanje lokacija za preuzimanje
     */
    public function ajax_get_pickup_locations()
    {
        // Provera nonce-a
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        $town_id = isset($_GET['town_id']) ? intval($_GET['town_id']) : 0;
        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';

        if (empty($town_id) || empty($type) || !in_array($type, ['shop', 'dispenser'])) {
            wp_send_json_error(['message' => 'Invalid parameters']);
            return;
        }

        global $wpdb;

        // Odabir tabele prema tipu lokacije
        $table = ($type == 'shop') ? 'dexpress_shops' : 'dexpress_dispensers';

        $locations = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, address, 
             COALESCE(working_hours, work_hours, '') as hours,
             work_days
             FROM {$wpdb->prefix}{$table}
             WHERE town_id = %d
             ORDER BY name ASC",
            $town_id
        ));

        $results = [];

        foreach ($locations as $location) {
            $location_name = $location->name;

            if (!empty($location->address)) {
                $location_name .= ' (' . $location->address . ')';
            }

            if (!empty($location->hours)) {
                $location_name .= ' - ' . $location->hours;
            }

            $results[] = [
                'id' => $location->id,
                'text' => $location_name
            ];
        }

        wp_send_json_success(['results' => $results]);
    }

    /**
     * Validacija checkout polja
     */
    public function validate_checkout_fields()
    {
        // Provera da li je D Express dostava odabrana
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $has_dexpress = false;

        if (is_array($chosen_methods)) {
            foreach ($chosen_methods as $method) {
                if (strpos($method, 'dexpress') !== false) {
                    $has_dexpress = true;
                    break;
                }
            }
        }

        if (!$has_dexpress) {
            return;
        }

        // Provera tipa dostave
        if (empty($_POST['dexpress_delivery_type'])) {
            wc_add_notice(__('Molimo izaberite način dostave.', 'd-express-woo'), 'error');
            return;
        }

        $delivery_type = sanitize_text_field($_POST['dexpress_delivery_type']);

        // Validacija u zavisnosti od tipa dostave
        if ($delivery_type == 'address') {
            // Dostava na adresu - provera adresnih polja
            if (empty($_POST['shipping_street'])) {
                wc_add_notice(__('Molimo unesite ulicu za dostavu.', 'd-express-woo'), 'error');
            }

            if (empty($_POST['shipping_street_id'])) {
                wc_add_notice(__('Molimo izaberite ulicu iz predloženih.', 'd-express-woo'), 'error');
            }

            if (empty($_POST['shipping_number'])) {
                wc_add_notice(__('Molimo unesite kućni broj.', 'd-express-woo'), 'error');
            } elseif (strpos($_POST['shipping_number'], ' ') !== false) {
                wc_add_notice(__('Kućni broj mora biti bez razmaka (npr. 15a, 23/4).', 'd-express-woo'), 'error');
            }

            if (empty($_POST['shipping_city']) || empty($_POST['shipping_city_id'])) {
                wc_add_notice(__('Molimo izaberite grad iz liste.', 'd-express-woo'), 'error');
            } elseif ($_POST['shipping_city_id'] === 'other') {
                wc_add_notice(__('Molimo izaberite grad iz liste svih gradova.', 'd-express-woo'), 'error');
            }
        } else {
            // Preuzimanje u prodavnici ili na paketomatu
            if (empty($_POST['dexpress_pickup_town'])) {
                wc_add_notice(__('Molimo izaberite grad za preuzimanje.', 'd-express-woo'), 'error');
            }

            if (empty($_POST['dexpress_pickup_location'])) {
                wc_add_notice(__('Molimo izaberite lokaciju za preuzimanje.', 'd-express-woo'), 'error');
            }
        }
    }

    /**
     * Čuvanje checkout polja
     */
    public function save_checkout_fields($order_id)
    {
        // Adresa za dostavu ili naplatu
        $address_type = isset($_POST['ship_to_different_address']) && $_POST['ship_to_different_address'] ? 'shipping' : 'billing';

        // Čuvanje ulice
        if (!empty($_POST[$address_type . '_street'])) {
            update_post_meta($order_id, '_' . $address_type . '_street', sanitize_text_field($_POST[$address_type . '_street']));
        }

        // Čuvanje ID-a ulice
        if (!empty($_POST[$address_type . '_street_id'])) {
            update_post_meta($order_id, '_' . $address_type . '_street_id', sanitize_text_field($_POST[$address_type . '_street_id']));
        }

        // Čuvanje kućnog broja
        if (!empty($_POST[$address_type . '_number'])) {
            update_post_meta($order_id, '_' . $address_type . '_number', sanitize_text_field($_POST[$address_type . '_number']));
        }

        // Čuvanje grada i ID-a grada
        if (!empty($_POST[$address_type . '_city'])) {
            update_post_meta($order_id, '_' . $address_type . '_city', sanitize_text_field($_POST[$address_type . '_city']));
        }

        if (!empty($_POST[$address_type . '_city_id'])) {
            update_post_meta($order_id, '_' . $address_type . '_city_id', sanitize_text_field($_POST[$address_type . '_city_id']));
        }

        // Čuvanje poštanskog broja
        if (!empty($_POST[$address_type . '_postcode'])) {
            update_post_meta($order_id, '_' . $address_type . '_postcode', sanitize_text_field($_POST[$address_type . '_postcode']));
        }

        // Čuvanje standardnih WooCommerce polja za kompatibilnost
        $street = get_post_meta($order_id, '_' . $address_type . '_street', true);
        $number = get_post_meta($order_id, '_' . $address_type . '_number', true);

        if (!empty($street) && !empty($number)) {
            update_post_meta($order_id, '_' . $address_type . '_address_1', $street . ' ' . $number);
        }

        // Dodatne D Express opcije
        if (isset($_POST['dexpress_return_doc'])) {
            update_post_meta($order_id, '_dexpress_return_doc', 'yes');
        } else {
            update_post_meta($order_id, '_dexpress_return_doc', 'no');
        }

        if (!empty($_POST['dexpress_delivery_note'])) {
            update_post_meta($order_id, '_dexpress_delivery_note', sanitize_textarea_field($_POST['dexpress_delivery_note']));
        }
    }

    /**
     * Priprema podataka za D Express API
     */
    public function prepare_shipment_data($data, $order)
    {
        // ID grada za D Express API
        $town_id = get_post_meta($order->get_id(), '_shipping_city_id', true);
        if (!empty($town_id)) {
            $data['RTownID'] = intval($town_id);
        }

        // Ulica i broj
        $street = get_post_meta($order->get_id(), '_shipping_street', true);
        if (!empty($street)) {
            $data['RAddress'] = $street;
        }

        $number = get_post_meta($order->get_id(), '_shipping_number', true);
        if (!empty($number)) {
            $data['RAddressNum'] = $number;
        }

        // Povratna dokumentacija
        $return_doc = get_post_meta($order->get_id(), '_dexpress_return_doc', true);
        $data['ReturnDoc'] = ($return_doc === 'yes') ? 1 : 0;

        // Napomena za dostavu
        $note = get_post_meta($order->get_id(), '_dexpress_delivery_note', true);
        if (!empty($note)) {
            $data['Note'] = $note;
        }

        return $data;
    }

    /**
     * Prikaz podataka u admin panelu
     */
    public function display_admin_data($order)
    {
        // Dobijanje podataka
        $street = get_post_meta($order->get_id(), '_shipping_street', true);
        $number = get_post_meta($order->get_id(), '_shipping_number', true);
        $city = get_post_meta($order->get_id(), '_shipping_city', true);
        $city_id = get_post_meta($order->get_id(), '_shipping_city_id', true);
        $return_doc = get_post_meta($order->get_id(), '_dexpress_return_doc', true);
        $delivery_note = get_post_meta($order->get_id(), '_dexpress_delivery_note', true);

        echo '<div class="dexpress-order-data">';
        echo '<h4>' . __('D Express podaci', 'd-express-woo') . '</h4>';

        if (!empty($street) || !empty($number) || !empty($city)) {
            if (!empty($street)) {
                echo '<p><strong>' . __('Ulica:', 'd-express-woo') . '</strong> ' . esc_html($street) . '</p>';
            }

            if (!empty($number)) {
                echo '<p><strong>' . __('Kućni broj:', 'd-express-woo') . '</strong> ' . esc_html($number) . '</p>';
            }

            if (!empty($city)) {
                echo '<p><strong>' . __('Grad:', 'd-express-woo') . '</strong> ' . esc_html($city) . '</p>';
            }

            if (!empty($city_id)) {
                echo '<p><strong>' . __('D Express ID grada:', 'd-express-woo') . '</strong> ' . esc_html($city_id) . '</p>';
            }
        }

        if ($return_doc === 'yes') {
            echo '<p><strong>' . __('Povratna dokumentacija:', 'd-express-woo') . '</strong> ' . __('Da', 'd-express-woo') . '</p>';
        }

        if (!empty($delivery_note)) {
            echo '<p><strong>' . __('Napomena za dostavu:', 'd-express-woo') . '</strong> ' . esc_html($delivery_note) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Učitavanje skripti za checkout
     */
    public function enqueue_checkout_scripts()
    {
        if (!is_checkout()) {
            return;
        }

        // Učitavanje jQuery UI Autocomplete
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');

        // Učitavanje Select2
        if (!wp_script_is('select2', 'registered')) {
            wp_register_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), '4.0.13', true);
            wp_register_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', array(), '4.0.13');
        }

        wp_enqueue_script('select2');
        wp_enqueue_style('select2');

        // Učitavanje naših skripti
        wp_enqueue_script(
            'dexpress-checkout',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-checkout.js',
            array('jquery', 'jquery-ui-autocomplete', 'select2'),
            DEXPRESS_WOO_VERSION,
            true
        );

        // Učitavanje naših stilova
        wp_enqueue_style(
            'dexpress-checkout',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-checkout.css',
            array('select2'),
            DEXPRESS_WOO_VERSION
        );

        // Lokalizacija skripte
        wp_localize_script('dexpress-checkout', 'dexpressCheckout', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'i18n' => array(
                'selectStreet' => __('Izaberite ulicu', 'd-express-woo'),
                'enterStreet' => __('Unesite ulicu', 'd-express-woo'),
                'selectCity' => __('Izaberite grad', 'd-express-woo'),
                'firstEnterStreet' => __('Prvo unesite ulicu', 'd-express-woo'),
                'otherCity' => __('Drugo mesto (nije na listi)', 'd-express-woo'),
                'noResults' => __('Nema rezultata', 'd-express-woo'),
                'searching' => __('Pretraga...', 'd-express-woo')
            )
        ));
    }
}
