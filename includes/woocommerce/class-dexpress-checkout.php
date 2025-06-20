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

        // Dodaj ovo u init metodu klase D_Express_Checkout
        add_action('wp_ajax_dexpress_get_towns_list', array($this, 'ajax_get_towns_list'));
        add_action('wp_ajax_nopriv_dexpress_get_towns_list', array($this, 'ajax_get_towns_list'));

        add_action('wp_ajax_dexpress_filter_dispensers', array($this, 'ajax_filter_dispensers'));
        add_action('wp_ajax_nopriv_dexpress_filter_dispensers', array($this, 'ajax_filter_dispensers'));
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
                        <input type="text" id="dexpress-town-filter" placeholder="Započnite unos naziva grada..." autocomplete="off">
                        <div id="dexpress-town-suggestions" class="dexpress-town-suggestions"></div>
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
    <?php
    }
    /**
     * AJAX: Dobavljanje liste gradova sa paketomatima
     */
    public function ajax_get_towns_list()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        // Pokušaj dobiti iz cache-a
        $towns = get_transient('dexpress_dispenser_towns');

        if ($towns === false) {
            global $wpdb;

            // Dohvati samo gradove koji imaju paketomate
            $towns = $wpdb->get_results("
            SELECT DISTINCT t.id, t.name, t.postal_code 
            FROM {$wpdb->prefix}dexpress_towns t
            JOIN {$wpdb->prefix}dexpress_dispensers d ON t.id = d.town_id
            WHERE d.deleted != 1
            ORDER BY t.name
        ");

            // Sačuvaj u cache na 24 sata
            set_transient('dexpress_dispenser_towns', $towns, 24 * HOUR_IN_SECONDS);
        }

        wp_send_json_success(array('towns' => $towns));
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
                'maxlength'   => 150, // Dodajemo maksimalnu dužinu
                'custom_attributes' => ['pattern' => '[-a-zA-Z0-9:,._\s]+'], // Dodajemo pattern za validaciju na frontendu
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
        // Koristimo centralni validator
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/class-dexpress-validator.php';
        D_Express_Validator::validate_checkout();
    }

    /**
     * Čuvanje checkout polja u bazi
     */
    public function save_checkout_fields($order_id)
    {
        $address_types = ['billing', 'shipping'];
        $fields_to_save = ['street', 'street_id', 'number', 'address_desc', 'city', 'city_id', 'postcode'];

        foreach ($address_types as $type) {
            $updated_values = [];

            // Prikupljamo vrednosti za batch ažuriranje
            foreach ($fields_to_save as $key) {
                $field_name = "{$type}_{$key}";
                if (isset($_POST[$field_name])) {
                    $value = sanitize_text_field($_POST[$field_name]);

                    // Posebna sanitizacija za address_desc
                    if ($key === 'address_desc') {
                        // Uklanja sve nedozvoljene karaktere
                        $value = preg_replace('/[^a-zžćčđšA-ZĐŠĆŽČ:,._0-9\-\s]/u', '', $value);
                        $value = preg_replace('/\s+/', ' ', $value); // Uklanja višestruke razmake
                        $value = trim($value); // Uklanja razmak na početku i kraju
                        $value = preg_replace('/^\./', '', $value); // Osigurava da tačka nije na početku

                        // Ograničava dužinu na 150 karaktera
                        if (mb_strlen($value, 'UTF-8') > 150) {
                            $value = mb_substr($value, 0, 150, 'UTF-8');
                        }
                    }

                    $updated_values["_{$field_name}"] = $value;
                }
            }

            // Provera i sinhronizacija standardnih polja
            if (!empty($_POST["{$type}_street"]) && !empty($_POST["{$type}_number"])) {
                $street = sanitize_text_field($_POST["{$type}_street"]);
                $number = sanitize_text_field($_POST["{$type}_number"]);

                // Postavi address_1 kao kombinaciju ulice i broja
                $updated_values["_{$type}_address_1"] = $street . ' ' . $number;
            }

            // Batch ažuriranje meta podataka
            foreach ($updated_values as $meta_key => $meta_value) {
                update_post_meta($order_id, $meta_key, $meta_value);
            }
        }

        // Čuvanje telefona u API formatu
        if (isset($_POST['billing_phone'])) {
            $phone = sanitize_text_field($_POST['billing_phone']);
            update_post_meta($order_id, '_billing_phone', $phone);

            // Sačuvaj API format za korišćenje u API
            if (strpos($phone, '+381') === 0) {
                $api_phone = substr($phone, 1); // ukloni samo + sa početka
                update_post_meta($order_id, '_billing_phone_api_format', $api_phone);
            }
        }
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
    // U class-dexpress-checkout.php
    public function ajax_get_town_for_street()
    {
        // Dodati keš
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        global $wpdb;
        $street_id = intval($_GET['street_id'] ?? 0);

        // Provera keša
        $cache_key = 'dexpress_town_for_street_' . $street_id;
        $cached_town = get_transient($cache_key);

        if ($cached_town !== false) {
            wp_send_json_success($cached_town);
            return;
        }

        $town = $wpdb->get_row($wpdb->prepare(
            "SELECT t.id, t.name, t.postal_code
         FROM {$wpdb->prefix}dexpress_streets s
         JOIN {$wpdb->prefix}dexpress_towns t ON s.TId = t.id
         WHERE s.id = %d LIMIT 1",
            $street_id
        ));

        if ($town) {
            $town_data = [
                'town_id'     => $town->id,
                'town_name'   => $town->name,
                'postal_code' => $town->postal_code,
            ];

            // Keš na 1 sat
            set_transient($cache_key, $town_data, HOUR_IN_SECONDS);
            wp_send_json_success($town_data);
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
                'confirm' => __('Potvrdi', 'd-express-woo'),
                // Dodato za validaciju address_desc
                'invalidAddressDesc' => __('Neispravan format dodatnih informacija o adresi. Dozvoljeni su samo slova, brojevi, razmaci i znakovi: , . : - _', 'd-express-woo')
            )
        ));

        // Google Maps API - koristimo API ključ ako je dostupan
        $google_maps_api_key = get_option('dexpress_google_maps_api_key', '');

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

        // Proveriti da li postoji već izabrani paketomat
        $chosen_dispenser = WC()->session->get('chosen_dispenser');

        // Dohvatanje instance_id za trenutnu metodu dostave
        $instance_id = $method->get_instance_id();

        // Dobijanje postavki iz WooCommerce opcija koristeći instance_id
        $settings = $this->get_shipping_method_settings($instance_id);

        // Provera da li je ova shipping metoda trenutno izabrana
        $is_selected = $this->is_selected_shipping_method($method->get_id());

        // Generisanje HTML-a
        $this->render_dispenser_selection_html($method, $chosen_dispenser, $settings, $is_selected);
    }
    /**
     * Dohvata podešavanja shipping metode iz baze
     */
    private function get_shipping_method_settings($instance_id)
    {
        $option_key = "woocommerce_dexpress_dispenser_{$instance_id}_settings";
        $settings = get_option($option_key, array());

        // Podrazumevane vrednosti
        $defaults = array(
            'description_text' => 'D Paketomati su postavljeni na benzinskim stanicama, supermarketima, šoping centrima i rade 24 časa dnevno, jednostavni su i bezbedni za upotrebu. Svoju porudžbinu možete preuzeti i u Paket shopu koji Vam najviše odgovara – radno vreme lokacije je prikazano prilikom odabira!',
            'steps_text' => "1. Odaberite paketomat/paket shop lokaciju i završite porudžbinu\n2. Kada je paket isporučen na željenu lokaciju, D Express će vam putem Viber/SMS poruke poslati kod za otvaranje paketomat ormarića\n3. Ukoliko plaćate pouzećem potrebno je da kasiru pokažete kod i platite pošiljku gotovinom ili platnom karticom\n4. Upišite kod na paketomatu\n5. Preuzmite paket iz ormarića koji će se automatski otvoriti",
            'note_text' => '* Rok za preuzimanje pošiljke sa paketomata je 2 radna dana i nakon odabira ovog tipa dostave pošiljku nije moguće preusmeriti na drugu adresu',
            'delivery_time_text' => '3 RADNA DANA',
            'button_text' => 'ODABERITE PAKETOMAT'
        );

        // Spajanje postavki sa default vrednostima
        return wp_parse_args($settings, $defaults);
    }
    /**
     * Proverava da li je metoda dostave trenutno izabrana
     */
    private function is_selected_shipping_method($method_id)
    {
        $chosen_methods = WC()->session->get('chosen_shipping_methods', array());
        return in_array($method_id, $chosen_methods);
    }
    /**
     * Generisanje HTML-a za prikaz izbora paketomata
     */
    private function render_dispenser_selection_html($method, $chosen_dispenser, $settings, $is_selected)
    {
        // Priprema koraka kao HTML listu
        $steps_html = $this->format_steps_as_html($settings['steps_text']);

        // Dohvati simbol valute
        $currency_symbol = get_woocommerce_currency_symbol();

        // Formiraj tekst cene sa simbolom valute
        $cost = $method->get_cost();
        $formatted_cost = number_format($cost, 0, ',', '.');
        $price_text = sprintf(__('Cena isporuke je %s %s.', 'd-express-woo'), $formatted_cost, $currency_symbol);

        // Klase za prikaz/sakrivanje panela
        $display_style = $is_selected ? 'block' : 'none';
    ?>
        <div class="dexpress-dispenser-wrapper" style="margin-top: 15px; padding: 15px; border: 1px solid #eee; border-radius: 4px; background-color: #f9f9f9; display: <?php echo esc_attr($display_style); ?>;">
            <!-- Naslov i opis -->
            <div class="dexpress-dispenser-info-method">
                <p class="dexpress-dispenser-description"><?php echo wp_kses_post($settings['description_text']); ?></p>
                <p class="dexpress-dispenser-price"><?php echo esc_html($price_text); ?></p>

                <!-- Slika paketomata -->
                <div class="dexpress-dispenser-image" style="margin: 15px 0; text-align: center;">
                    <img src="<?php echo plugin_dir_url(__FILE__) . '../../assets/images/paketomat-picture.jpg'; ?>"
                        alt="<?php esc_attr_e('Dimenzije paketomata', 'd-express-woo'); ?>"
                        style="max-width: 100%; height: auto;">
                    <p class="dexpress-image-caption" style="font-size: 0.9em; text-align: center; margin-top: 5px;">
                        <?php esc_html_e('Dimenzije paketomata (S, M, L veličine)', 'd-express-woo'); ?>
                    </p>
                </div>

                <!-- Vreme isporuke -->
                <div class="dexpress-delivery-time" style="margin: 10px 0; text-align: right; font-weight: bold;">
                    <?php echo esc_html($settings['delivery_time_text']); ?>
                </div>
            </div>
            <!-- Koraci za preuzimanje -->
            <?php if (!empty($steps_html)): ?>
                <div class="dexpress-steps" style="margin-top: 20px;">
                    <ol style="padding-left: 20px; margin: 0;">
                        <?php echo $steps_html; ?>
                    </ol>
                </div>
            <?php endif; ?>

            <!-- Napomena -->
            <?php if (!empty($settings['note_text'])): ?>
                <div class="dexpress-note" style="margin-top: 10px; color: #e2401c; font-size: 0.9em;">
                    <?php echo esc_html($settings['note_text']); ?>
                </div>
            <?php endif; ?>

            <!-- Dugme za izbor -->
            <div class="dexpress-dispenser-selection" style="margin: 15px 0;">
                <button type="button" class="button dexpress-select-dispenser-btn" style="width: 100%; text-align: center; padding: 10px;">
                    <?php echo esc_html($settings['button_text']); ?>
                </button>
                <input type="hidden" id="dexpress_chosen_dispenser" name="dexpress_chosen_dispenser" value="<?php echo esc_attr($chosen_dispenser ? $chosen_dispenser['id'] : ''); ?>">
            </div>

            <!-- Prikaz izabranog paketomata -->
            <?php if ($chosen_dispenser): ?>
                <div class="dexpress-chosen-dispenser-info" style="margin-top: 10px; background: #f0f0f0; padding: 10px; border-radius: 3px;">
                    <strong><?php echo esc_html($chosen_dispenser['name']); ?></strong><br>
                    <?php echo esc_html($chosen_dispenser['address']); ?>, <?php echo esc_html($chosen_dispenser['town']); ?>
                    <br><a href="#" class="dexpress-change-dispenser"><?php _e('Promenite paketomat', 'd-express-woo'); ?></a>
                </div>
            <?php else: ?>
                <div class="dexpress-dispenser-warning" style="color: #e2401c; margin-top: 5px; padding: 8px; background: #f8d7da; border-radius: 3px;">
                    <?php _e('Morate izabrati paketomat za dostavu', 'd-express-woo'); ?>
                </div>
            <?php endif; ?>
        </div>
<?php
    }
    /**
     * Dobavlja listu paketomata sa keširanjem
     * 
     * @param int $town_id ID grada za filtriranje (opciono)
     * @return array Lista paketomata
     */
    private function get_cached_dispensers($town_id = null)
    {
        // Ključ za keširanje - uključujem town_id ako je prosleđen
        $cache_key = 'dexpress_dispensers' . ($town_id ? '_town_' . $town_id : '_all');

        // Pokušaj dobiti iz transient cache-a
        $dispensers = get_transient($cache_key);

        // Ako nema u cache-u, učitaj iz baze
        if ($dispensers === false) {
            global $wpdb;

            $query = "
            SELECT d.id, d.name, d.address, d.town, d.town_id, 
                   d.work_hours, d.work_days, d.coordinates, 
                   d.pay_by_cash, d.pay_by_card, t.postal_code
            FROM {$wpdb->prefix}dexpress_dispensers d
            LEFT JOIN {$wpdb->prefix}dexpress_towns t ON d.town_id = t.id
            WHERE d.deleted IS NULL OR d.deleted != 1
        ";

            // Dodaj filter po gradu ako je prosleđen
            if ($town_id) {
                $query .= $wpdb->prepare(" AND d.town_id = %d", $town_id);
            }

            $query .= " ORDER BY d.town, d.name";

            $dispensers = $wpdb->get_results($query);

            // Sačuvaj u cache na 12 sati (može se izmeniti po potrebi)
            set_transient($cache_key, $dispensers, 12 * HOUR_IN_SECONDS);

            // Loguj operaciju
            dexpress_structured_log('checkout', 'Učitani paketomati iz baze', 'debug', [
                'count' => count($dispensers),
                'town_id' => $town_id
            ]);
        } else {
            // Loguj cache hit
            dexpress_structured_log('checkout', 'Učitani paketomati iz cache-a', 'debug', [
                'count' => count($dispensers),
                'town_id' => $town_id
            ]);
        }

        return $dispensers;
    }
    /**
     * Formatira korake kao HTML listu
     */
    private function format_steps_as_html($steps_text)
    {
        if (empty($steps_text)) {
            return '';
        }

        $steps_html = '';
        $steps = explode("\n", $steps_text);

        foreach ($steps as $step) {
            $step = trim($step);
            if (!empty($step)) {
                $steps_html .= '<li>' . esc_html($step) . '</li>';
            }
        }

        return $steps_html;
    }
    /**
     * Dobija listu paketomata
     */
    private function get_dispensers_list()
    {
        return $this->get_cached_dispensers();
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
            // Sačuvaj ID paketomata i ostale podatke kao meta
            update_post_meta($order_id, '_dexpress_dispenser_id', $chosen_dispenser['id']);
            update_post_meta($order_id, '_dexpress_dispenser_name', $chosen_dispenser['name']);
            update_post_meta($order_id, '_dexpress_dispenser_address', $chosen_dispenser['address']);
            update_post_meta($order_id, '_dexpress_dispenser_town', $chosen_dispenser['town']);

            // KLJUČNI DEO: Ažuriranje shipping adrese na narudžbini
            $order = wc_get_order($order_id);
            if ($order) {
                // Formatiranje adrese paketomata
                $address = array(
                    'first_name' => $order->get_shipping_first_name(),
                    'last_name'  => $order->get_shipping_last_name(),
                    'company'    => '',
                    'address_1'  => $chosen_dispenser['address'] . ' (Paketomat: ' . $chosen_dispenser['name'] . ')',
                    'address_2'  => '',
                    'city'       => $chosen_dispenser['town'],
                    'state'      => '',
                    'postcode'   => $chosen_dispenser['postal_code'] ?? '',
                    'country'    => $order->get_shipping_country()
                );

                // Postavljanje shipping adrese na narudžbini
                $order->set_address($address, 'shipping');
                $order->save();

                // Dodaj napomenu u narudžbinu
                $order->add_order_note(
                    sprintf(
                        __('Narudžbina će biti dostavljena na paketomat: %s, Adresa: %s, %s', 'd-express-woo'),
                        $chosen_dispenser['name'],
                        $chosen_dispenser['address'],
                        $chosen_dispenser['town']
                    )
                );
            }

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
    /**
     * AJAX: Filtriranje paketomata po gradu
     */
    public function ajax_filter_dispensers()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        $town_id = isset($_POST['town_id']) ? intval($_POST['town_id']) : null;

        // Dobavljanje filtriranih paketomata
        $dispensers = $this->get_cached_dispensers($town_id);

        // Vraćanje rezultata
        wp_send_json_success(['dispensers' => $dispensers]);
    }
}
