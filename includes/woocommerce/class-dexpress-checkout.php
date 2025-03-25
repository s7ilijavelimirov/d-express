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

        add_action('woocommerce_after_shipping_rate', array($this, 'add_dispenser_selection'), 10, 2);
        add_action('wp_footer', array($this, 'add_dispenser_modal'));

        // Učitavanje skripti i stilova
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_scripts']);

        add_action('wp_ajax_dexpress_save_chosen_dispenser', array($this, 'ajax_save_chosen_dispenser'));
        add_action('wp_ajax_nopriv_dexpress_save_chosen_dispenser', array($this, 'ajax_save_chosen_dispenser'));

        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_dispenser_to_order'), 10, 2);

        // Dodajte ove linije u init() metodu
        add_action('wp_ajax_dexpress_get_dispensers', array($this, 'ajax_get_dispensers'));
        add_action('wp_ajax_nopriv_dexpress_get_dispensers', array($this, 'ajax_get_dispensers'));
    }
    /**
     * AJAX: Dobavljanje liste paketomata za mapu
     */
    public function ajax_get_dispensers()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        $dispensers = $this->get_dispensers_list();

        wp_send_json_success(array('dispensers' => $dispensers));
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
            // Novo polje - Dodatne informacije o adresi
            'address_desc' => [
                'type'        => 'text',
                'label'       => __('Dodatne informacije o adresi', 'd-express-woo'),
                'placeholder' => __('Npr: sprat 3, stan 24, interfon 24', 'd-express-woo'),
                'required'    => false,
                'class'       => ['form-row-wide', 'dexpress-address-desc'],
                'priority'    => 56,
            ],
            'city' => [
                'type'        => 'text',
                'label'       => __('Grad', 'd-express-woo'),
                'placeholder' => __('Grad će biti popunjen automatski', 'd-express-woo'),
                'required'    => true,
                'class'       => ['form-row-wide', 'dexpress-city'],
                'priority'    => 60,
                'custom_attributes' => ['readonly' => 'readonly'],
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
        if (isset($fields['billing']['billing_phone'])) {
            $fields['billing']['billing_phone']['custom_attributes']['data-validate'] = 'phone';
            $fields['billing']['billing_phone']['custom_attributes']['pattern'] = '\\+381[1-9][0-9]{7,8}';
            $fields['billing']['billing_phone']['placeholder'] = __('npr. +381(0) 60 123 4567', 'd-express-woo');

            // Postavite podrazumevanu vrednost na +381 ako je polje prazno
            if (empty($fields['billing']['billing_phone']['default'])) {
                $fields['billing']['billing_phone']['default'] = '+381';
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
        if (isset($_POST['billing_phone'])) {
            dexpress_log("POST phone value: " . $_POST['billing_phone'], 'debug');
        }
        // Validacija telefona samo ako je D Express dostava
        $is_dexpress = false;
        foreach ($_POST['shipping_method'] as $method) {
            if (strpos($method, 'dexpress') !== false) {
                $is_dexpress = true;
                break;
            }
        }

        if ($is_dexpress) {
            $phone = isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '';

            // Čišćenje telefona od svih znakova osim brojeva
            $clean_phone = preg_replace('/[^0-9]/', '', $phone);

            // Provera formata telefona - mora biti u formatu koji API zahteva
            if (!preg_match('/^(381[1-9][0-9]{7,8}|38167[0-9]{6,8})$/', $clean_phone)) {
                wc_add_notice(__('Telefon mora biti u formatu +381 XX XXX XXXX', 'd-express-woo'), 'error');
            }
        }
        $is_dispenser_shipping = false;
        foreach (WC()->session->get('chosen_shipping_methods', array()) as $method) {
            if (strpos($method, 'dexpress_dispenser') !== false) {
                $is_dispenser_shipping = true;
                break;
            }
        }

        if ($is_dispenser_shipping && empty($_POST['dexpress_chosen_dispenser'])) {
            wc_add_notice(__('Morate izabrati paketomat za dostavu.', 'd-express-woo'), 'error');
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
            foreach (['street', 'street_id', 'number', 'address_desc', 'city', 'city_id', 'postcode'] as $key) {
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

        // Čuvanje telefona u API formatu
        if (isset($_POST['billing_phone'])) {
            $phone = sanitize_text_field($_POST['billing_phone']);

            // Log vrednosti
            dexpress_log("[CHECKOUT] Telefon iz forme: " . $phone, 'info');

            // Ako postoji, sačuvaj ga direktno sa prefiksom
            update_post_meta($order_id, '_billing_phone', $phone);

            // Sačuvaj API format za korišćenje u API
            if (strpos($phone, '+381') === 0) {
                $api_phone = substr($phone, 1); // ukloni samo + sa početka
                update_post_meta($order_id, '_billing_phone_api_format', $api_phone);
            }
        }

        dexpress_log($debug_info, 'debug');
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
        dexpress_log("Checkout scripts loaded", 'debug');

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

        // Google Maps API - koristimo API ključ ako je dostupan
        $google_maps_api_key = get_option('dexpress_google_maps_api_key', '');
        dexpress_log("Google Maps API key: {$google_maps_api_key}", 'debug');

        $google_maps_url = empty($google_maps_api_key)
            ? 'https://maps.googleapis.com/maps/api/js?v=weekly'
            : 'https://maps.googleapis.com/maps/api/js?key=' . $google_maps_api_key . '&v=weekly';

        wp_enqueue_script(
            'google-maps',
            $google_maps_url,
            array(),
            null,
            true
        );

        // Učitavanje JS-a za paketomat modal
        wp_enqueue_script(
            'dexpress-dispenser-modal',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-dispenser-modal.js',
            array('jquery'),
            DEXPRESS_WOO_VERSION,
            true
        );
    }
    /**
     * Dodaje izbor paketomata nakon opcije dostave
     */
    public function add_dispenser_selection($method, $index)
    {
        // Proveriti da li je D Express paketomat metoda
        if ($method->get_method_id() !== 'dexpress_dispenser') {
            return;
        }

        // Dobijanje dostupnih paketomata
        $dispensers = $this->get_dispensers_list();

        // Proveriti da li postoji već izabrani paketomat
        $chosen_dispenser = WC()->session->get('chosen_dispenser');

        echo '<div class="dexpress-dispenser-selection" style="margin: 10px 0 10px 30px;">';
        echo '<button type="button" class="button dexpress-select-dispenser-btn">' . __('Izaberite paketomat', 'd-express-woo') . '</button>';
        echo '<input type="hidden" id="dexpress_chosen_dispenser" name="dexpress_chosen_dispenser" value="' . esc_attr($chosen_dispenser ? $chosen_dispenser['id'] : '') . '">';

        if ($chosen_dispenser) {
            echo '<div class="dexpress-chosen-dispenser-info" style="margin-top: 10px; background: #f7f7f7; padding: 10px; border-radius: 3px;">';
            echo '<strong>' . esc_html($chosen_dispenser['name']) . '</strong><br>';
            echo esc_html($chosen_dispenser['address']) . ', ' . esc_html($chosen_dispenser['town']);
            echo '<br><a href="#" class="dexpress-change-dispenser">' . __('Promenite paketomat', 'd-express-woo') . '</a>';
            echo '</div>';
        } else {
            echo '<div class="dexpress-dispenser-warning" style="color: #e2401c; margin-top: 5px;">' . __('Morate izabrati paketomat za dostavu', 'd-express-woo') . '</div>';
        }

        echo '</div>';
    }

    /**
     * Dodaje modal za izbor paketomata
     */
    public function add_dispenser_modal()
    {
        // Proveriti da li je checkout stranica
        if (!is_checkout()) {
            return;
        }

        // HTML za modal
?>
        <div id="dexpress-dispenser-modal" style="display: none;">
            <div class="dexpress-modal-content" style="width: 90%; max-width: 1000px;">
                <div class="dexpress-modal-header">
                    <h3><?php _e('Izaberite paketomat za dostavu', 'd-express-woo'); ?></h3>
                    <span class="dexpress-modal-close">&times;</span>
                </div>
                <div class="dexpress-modal-body">
                    <!-- Filter gradova ostaje gore -->
                    <div class="dexpress-town-filter">
                        <label for="dexpress-town-filter"><?php _e('Filtrirajte po gradu:', 'd-express-woo'); ?></label>
                        <select id="dexpress-town-filter">
                            <option value=""><?php _e('Svi gradovi', 'd-express-woo'); ?></option>
                            <?php
                            global $wpdb;
                            $towns = $wpdb->get_results("
                SELECT DISTINCT t.id, t.name, t.postal_code
                FROM {$wpdb->prefix}dexpress_towns t
                JOIN {$wpdb->prefix}dexpress_dispensers d ON t.id = d.town_id
                WHERE d.deleted != 1
                ORDER BY t.name
            ");

                            foreach ($towns as $town) {
                                echo '<option value="' . esc_attr($town->id) . '">' . esc_html($town->name) . ' (' . esc_html($town->postal_code) . ')</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <!-- Glavni kontejner za mapu i listu -->
                    <div class="dexpress-dispensers-container">
                        <div id="dexpress-dispensers-map"></div>
                        <div id="dexpress-dispensers-list"></div>
                    </div>
                </div>

                <div class="dexpress-modal-footer">
                    <button type="button" class="button button-secondary dexpress-modal-close"><?php _e('Odustani', 'd-express-woo'); ?></button>
                </div>
            </div>
        </div>

        <style>
            #dexpress-dispenser-modal {
                position: fixed;
                z-index: 9999;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .dexpress-modal-content {
                background-color: #fff;
                border-radius: 5px;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            }

            .dexpress-modal-header {
                padding: 15px;
                border-bottom: 1px solid #eee;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .dexpress-modal-close {
                cursor: pointer;
                font-size: 24px;
            }

            .dexpress-modal-body {
                padding: 15px;
            }

            .dexpress-modal-footer {
                padding: 15px;
                border-top: 1px solid #eee;
                text-align: right;
            }

            .dexpress-dispenser-item {
                padding: 10px;
                border-bottom: 1px solid #eee;
                cursor: pointer;
            }

            .dexpress-dispenser-item:hover {
                background-color: #f9f9f9;
            }

            .dexpress-town-filter {
                margin-bottom: 10px;
            }
        </style>
<?php
    }

    /**
     * Dobija listu paketomata
     */
    private function get_dispensers_list()
    {
        global $wpdb;

        return $wpdb->get_results("
        SELECT d.id, d.name, d.address, d.town, d.town_id, 
               d.work_hours, d.work_days, d.coordinates, 
               d.pay_by_cash, d.pay_by_card, t.postal_code
        FROM {$wpdb->prefix}dexpress_dispensers d
        LEFT JOIN {$wpdb->prefix}dexpress_towns t ON d.town_id = t.id
        WHERE d.deleted IS NULL OR d.deleted != 1
        ORDER BY d.town, d.name
    ");
    }
    /**
     * Čuvanje izabranog paketomata u narudžbini
     */
    public function save_dispenser_to_order($order_id, $posted_data)
    {
        // Proverava da li je izabran paketomat kao shipping metoda
        $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
        $is_dispenser_shipping = false;

        foreach ($chosen_shipping_methods as $method) {
            if (strpos($method, 'dexpress_dispenser') !== false) {
                $is_dispenser_shipping = true;
                break;
            }
        }

        if (!$is_dispenser_shipping) {
            return;
        }

        // Dobijanje izabranog paketomata iz sesije
        $chosen_dispenser = WC()->session->get('chosen_dispenser');

        if ($chosen_dispenser) {
            update_post_meta($order_id, '_dexpress_dispenser_id', $chosen_dispenser['id']);
            update_post_meta($order_id, '_dexpress_dispenser_name', $chosen_dispenser['name']);
            update_post_meta($order_id, '_dexpress_dispenser_address', $chosen_dispenser['address']);
            update_post_meta($order_id, '_dexpress_dispenser_town', $chosen_dispenser['town']);

            // Oslobađanje resursa sesije
            WC()->session->__unset('chosen_dispenser');
        }
    }
    /**
     * AJAX: Čuvanje izabranog paketomata
     */
    public function ajax_save_chosen_dispenser()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        if (!isset($_POST['dispenser']) || !is_array($_POST['dispenser'])) {
            wp_send_json_error(array('message' => __('Neispravni podaci o paketomatu.', 'd-express-woo')));
        }

        $dispenser = array(
            'id' => isset($_POST['dispenser']['id']) ? intval($_POST['dispenser']['id']) : 0,
            'name' => isset($_POST['dispenser']['name']) ? sanitize_text_field($_POST['dispenser']['name']) : '',
            'address' => isset($_POST['dispenser']['address']) ? sanitize_text_field($_POST['dispenser']['address']) : '',
            'town' => isset($_POST['dispenser']['town']) ? sanitize_text_field($_POST['dispenser']['town']) : '',
            'town_id' => isset($_POST['dispenser']['town_id']) ? intval($_POST['dispenser']['town_id']) : 0,
            'postal_code' => isset($_POST['dispenser']['postal_code']) ? sanitize_text_field($_POST['dispenser']['postal_code']) : ''
        );

        // Čuvanje u WooCommerce sesiji
        WC()->session->set('chosen_dispenser', $dispenser);

        wp_send_json_success();
    }
}
