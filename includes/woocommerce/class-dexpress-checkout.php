<?php

defined('ABSPATH') || exit;

class D_Express_Checkout
{
    public function init()
    {
        // Zamena standardnih polja sa D Express poljima
        add_filter('woocommerce_checkout_fields', [$this, 'modify_checkout_fields'], 1000);

        // Validacija checkout polja
        add_action('woocommerce_checkout_process', [$this, 'validate_checkout_fields']);

        // Čuvanje podataka u narudžbini
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_checkout_fields']);

        // AJAX handleri za pretragu i dobijanje podataka
        add_action('wp_ajax_dexpress_search_streets', [$this, 'ajax_search_streets']);
        add_action('wp_ajax_nopriv_dexpress_search_streets', [$this, 'ajax_search_streets']);

        add_action('wp_ajax_dexpress_get_town_for_street', [$this, 'ajax_get_town_for_street']);
        add_action('wp_ajax_nopriv_dexpress_get_town_for_street', [$this, 'ajax_get_town_for_street']);

        // Učitavanje skripti i stilova
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_scripts']);
    }

    /**
     * Modifikacija checkout polja (zamena sa D Express poljima)
     */
    public function modify_checkout_fields($fields)
    {
        $custom_fields = [
            'street' => [
                'type'        => 'text',
                'label'       => __('Ulica', 'd-express-woo'),
                'placeholder' => __('Započnite unos naziva ulice', 'd-express-woo'),
                'required'    => true,
                'class'       => ['form-row-wide', 'dexpress-street'],
                'priority'    => 50,
            ],
            'street_id' => [
                'type'     => 'hidden',
                'required' => false,
                'class'    => ['dexpress-street-id'],
                'priority' => 51,
            ],
            'number' => [
                'type'        => 'text',
                'label'       => __('Kućni broj', 'd-express-woo'),
                'placeholder' => __('Npr: 15a, 23/4', 'd-express-woo'),
                'required'    => true,
                'class'       => ['form-row-wide', 'dexpress-number'],
                'priority'    => 55,
            ],
            'city' => [
                'type'        => 'text',
                'label'       => __('Grad', 'd-express-woo'),
                'placeholder' => __('Grad će biti popunjen automatski', 'd-express-woo'),
                'required'    => true,
                'class'       => ['form-row-wide', 'dexpress-city'],
                'priority'    => 60,
                'custom_attributes' => ['readonly' => 'readonly'], // Dodajemo readonly atribut
            ],
            'city_id' => [
                'type'     => 'hidden',
                'required' => false,
                'class'    => ['dexpress-city-id'],
                'priority' => 61,
            ],
            'postcode' => [
                'type'        => 'text',
                'label'       => __('Poštanski broj', 'd-express-woo'),
                'required'    => true,
                'class'       => ['form-row-wide', 'dexpress-postcode'],
                'priority'    => 65,
                'custom_attributes' => ['readonly' => 'readonly'],
            ],
        ];

        foreach (['billing', 'shipping'] as $type) {
            foreach ($custom_fields as $key => $field) {
                $fields[$type]["{$type}_{$key}"] = $field;
            }
        }

        // Ne sakrivamo standardna polja, već samo postavljamo stilove da se ne prikazuju,
        // ali primaju podatke za ispravno čuvanje
        $hide_fields = ['address_1', 'address_2'];

        foreach (['billing', 'shipping'] as $type) {
            foreach ($hide_fields as $field) {
                if (isset($fields[$type]["{$type}_{$field}"])) {
                    $fields[$type]["{$type}_{$field}"]['class'][] = 'dexpress-hidden-field';
                    $fields[$type]["{$type}_{$field}"]['required'] = false;
                }
            }
        }

        return $fields;
    }

    /**
     * Validacija checkout polja
     */
    public function validate_checkout_fields()
    {
        $address_type = isset($_POST['ship_to_different_address']) ? 'shipping' : 'billing';

        if (empty($_POST[$address_type . '_street_id'])) {
            wc_add_notice(__('Molimo izaberite validnu ulicu iz liste.', 'd-express-woo'), 'error');
        }

        if (empty($_POST[$address_type . '_city_id'])) {
            wc_add_notice(__('Molimo izaberite validan grad.', 'd-express-woo'), 'error');
        }

        if (empty($_POST[$address_type . '_postcode'])) {
            wc_add_notice(__('Poštanski broj je obavezan.', 'd-express-woo'), 'error');
        }
    }

    /**
     * Čuvanje checkout polja u bazi
     */
    public function save_checkout_fields($order_id)
    {
        $address_types = ['billing', 'shipping'];
        $debug_info = "Order ID: " . $order_id . "\n";

        foreach ($address_types as $type) {
            foreach (['street', 'street_id', 'number', 'city', 'city_id', 'postcode'] as $key) {
                $field_name = "{$type}_{$key}";
                if (isset($_POST[$field_name])) {
                    $value = sanitize_text_field($_POST[$field_name]);
                    update_post_meta($order_id, "_{$field_name}", $value);
                    $debug_info .= "{$field_name}: {$value}\n";
                } else {
                    $debug_info .= "{$field_name}: NOT SET\n";
                }
            }

            // Provera i sinhronizacija standardnih polja
            if (!empty($_POST["{$type}_street"]) && !empty($_POST["{$type}_number"])) {
                $street = sanitize_text_field($_POST["{$type}_street"]);
                $number = sanitize_text_field($_POST["{$type}_number"]);

                // Postavi address_1 kao kombinaciju ulice i broja
                update_post_meta($order_id, "_{$type}_address_1", $street . ' ' . $number);
                $debug_info .= "{$type}_address_1: {$street} {$number}\n";
            }
        }

        dexpress_log($debug_info, 'debug');
    }
    /**
     * Validacija podataka pre slanja API zahteva
     * 
     * @param array $data Podaci za API
     * @return bool|WP_Error True ako su podaci validni, WP_Error u suprotnom
     */
    private function validate_shipment_data($data)
    {
        $required_fields = ['RAddress', 'RAddressNum', 'RTownID'];

        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Polje {$field} je obavezno");
            }
        }

        // Dodatne validacije
        if (!empty($data['RTownID']) && ($data['RTownID'] < 100000 || $data['RTownID'] > 10000000)) {
            return new WP_Error('invalid_town_id', 'ID grada mora biti između 100000 i 10000000');
        }

        return true;
    }
    /**
     * AJAX: Pretraga ulica
     */
    public function ajax_search_streets()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        global $wpdb;
        $search = sanitize_text_field($_GET['term'] ?? '');

        if (empty($search) || strlen($search) < 2) {
            wp_send_json([]);
        }

        $streets = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.name, t.id as town_id, t.name as town_name, t.postal_code
             FROM {$wpdb->prefix}dexpress_streets s
             JOIN {$wpdb->prefix}dexpress_towns t ON s.TId = t.id
             WHERE s.name LIKE %s
             ORDER BY s.name, t.name ASC LIMIT 50",
            '%' . $wpdb->esc_like($search) . '%'
        ));

        $results = array_map(function ($street) {
            return [
                'id'          => $street->id,
                'label'       => "{$street->name} ({$street->town_name} - {$street->postal_code})",
                'value'       => $street->name,
                'town_id'     => $street->town_id,
                'town_name'   => $street->town_name,
                'postal_code' => $street->postal_code,
            ];
        }, $streets);

        wp_send_json($results);
    }

    /**
     * AJAX: Dohvatanje grada i poštanskog broja za ulicu
     */
    public function ajax_get_town_for_street()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        global $wpdb;
        $street_id = intval($_GET['street_id'] ?? 0);

        $town = $wpdb->get_row($wpdb->prepare(
            "SELECT t.id, t.name, t.postal_code
             FROM {$wpdb->prefix}dexpress_streets s
             JOIN {$wpdb->prefix}dexpress_towns t ON s.TId = t.id
             WHERE s.id = %d LIMIT 1",
            $street_id
        ));

        if ($town) {
            wp_send_json_success([
                'town_id'     => $town->id,
                'town_name'   => $town->name,
                'postal_code' => $town->postal_code,
            ]);
        } else {
            wp_send_json_error(['message' => 'Grad nije pronađen.']);
        }
    }

    /**
     * Mapiranje checkout podataka u API zahtev
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return array Podaci za API
     */
    public function prepare_shipment_data($order)
    {
        $order_id = $order->get_id();

        // Odredite koji tip adrese koristiti (shipping ili billing)
        $address_type = $order->has_shipping_address() ? 'shipping' : 'billing';

        // Dohvatite meta podatke iz narudžbine
        $street_id = get_post_meta($order_id, "_{$address_type}_street_id", true);
        $street = get_post_meta($order_id, "_{$address_type}_street", true);
        $number = get_post_meta($order_id, "_{$address_type}_number", true);
        $city_id = get_post_meta($order_id, "_{$address_type}_city_id", true);

        // Pripremite podatke za API zahtev
        $data = [
            // Ostali postojeći podaci
            'RAddress' => $street,
            'RAddressNum' => !empty($number) ? $number : '1', // Osigurajte da broj nikad nije prazan
            'RTownID' => !empty($city_id) ? (int)$city_id : 100001, // Osigurajte da je ID grada validan
            // Ostali postojeći podaci
        ];

        // Log za debugging
        $debug_info = "Preparing shipment data for order {$order_id}:\n";
        $debug_info .= "Street: {$street}\n";
        $debug_info .= "Number: {$number}\n";
        $debug_info .= "Street ID: {$street_id}\n";
        $debug_info .= "City ID: {$city_id}\n";
        $debug_info .= "Using address type: {$address_type}\n";
        dexpress_log($debug_info, 'debug');

        return $data;
    }

    /**
     * Učitavanje skripti i stilova za checkout
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
            ['jquery', 'jquery-ui-autocomplete'],
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
            'nonce' => wp_create_nonce('dexpress-frontend-nonce'),
            'i18n' => array(
                'selectStreet' => __('Izaberite ulicu', 'd-express-woo'),
                'enterStreet' => __('Unesite ulicu', 'd-express-woo'),
                'selectCity' => __('Izaberite grad', 'd-express-woo'),
                'firstEnterStreet' => __('Prvo unesite ulicu', 'd-express-woo'),
                'otherCity' => __('Drugo mesto (nije na listi)', 'd-express-woo'),
                'noResults' => __('Nema rezultata', 'd-express-woo'),
                'searching' => __('Pretraga...', 'd-express-woo'),
                'enterNumber' => __('Unesite kućni broj', 'd-express-woo'),
                'numberNoSpaces' => __('Kućni broj mora biti bez razmaka', 'd-express-woo'),
                'confirm' => __('Potvrdi', 'd-express-woo')
            )
        ));
    }
}
