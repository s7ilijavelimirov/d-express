<?php

defined('ABSPATH') || exit;

class D_Express_Checkout
{
    public function init()
    {
        // NOVA optimizovana dispenser funkcionalnost
        $this->init_dispensers();

        // Zamena standardnih polja sa D Express poljima
        add_filter('woocommerce_checkout_fields', [$this, 'modify_checkout_fields'], 1000);

        // Validacija checkout polja
        add_action('woocommerce_checkout_process', [$this, 'validate_checkout_fields']);

        // Čuvanje podataka u narudžbini
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_checkout_fields'], 999);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'force_save_number'], 1000);
        // AJAX handleri za pretragu i dobijanje podataka (ZADRŽANO)
        add_action('wp_ajax_dexpress_search_streets', [$this, 'ajax_search_streets']);
        add_action('wp_ajax_nopriv_dexpress_search_streets', [$this, 'ajax_search_streets']);

        add_action('wp_ajax_dexpress_get_town_for_street', [$this, 'ajax_get_town_for_street']);
        add_action('wp_ajax_nopriv_dexpress_get_town_for_street', [$this, 'ajax_get_town_for_street']);

        add_action('wp_ajax_dexpress_get_towns_for_street', [$this, 'ajax_get_towns_for_street']);
        add_action('wp_ajax_nopriv_dexpress_get_towns_for_street', [$this, 'ajax_get_towns_for_street']);

        add_action('wp_ajax_dexpress_search_streets_for_town', [$this, 'ajax_search_streets_for_town']);
        add_action('wp_ajax_nopriv_dexpress_search_streets_for_town', [$this, 'ajax_search_streets_for_town']);

        add_action('wp_ajax_dexpress_search_all_towns', [$this, 'ajax_search_all_towns']);
        add_action('wp_ajax_nopriv_dexpress_search_all_towns', [$this, 'ajax_search_all_towns']);

        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_scripts']);
    }
    public function force_save_number($order_id)
    {
        dexpress_log("=== FORCE SAVE NUMBER za order {$order_id} ===", 'debug');

        $address_types = ['billing', 'shipping'];
        foreach ($address_types as $type) {
            $number_field = "{$type}_number";

            if (isset($_POST[$number_field])) {
                $number_value = trim($_POST[$number_field]);
                $meta_key = "_{$number_field}";

                // Proveri šta je trenutno sačuvano
                $current_value = get_post_meta($order_id, $meta_key, true);
                dexpress_log("Trenutno sačuvano {$meta_key}: '{$current_value}'", 'debug');
                dexpress_log("Trebalo bi da bude: '{$number_value}'", 'debug');

                // Ako se razlikuje, FORSIRA čuvanje
                if ($current_value !== $number_value) {
                    dexpress_log("RAZLIKUJE SE! Forsiram čuvanje...", 'debug');

                    // Obrišii postojeći meta
                    delete_post_meta($order_id, $meta_key);

                    // Sačuvaj novi
                    update_post_meta($order_id, $meta_key, $number_value);

                    // Proveri da li je sačuvano
                    $new_value = get_post_meta($order_id, $meta_key, true);
                    dexpress_log("Nakon force save: '{$new_value}'", 'debug');

                    // TAKOĐE ažuriraj address_1 polje
                    if (!empty($_POST["{$type}_street"])) {
                        $street = sanitize_text_field($_POST["{$type}_street"]);
                        $address1 = $street . ' ' . $number_value;

                        delete_post_meta($order_id, "_{$type}_address_1");
                        update_post_meta($order_id, "_{$type}_address_1", $address1);

                        dexpress_log("Ažuriram address_1: '{$address1}'", 'debug');
                    }
                } else {
                    dexpress_log("Vrednost je OK, ne treba forsirati", 'debug');
                }
            }
        }

        dexpress_log("=== END FORCE SAVE NUMBER ===", 'debug');
    }
    public function debug_woocommerce_hooks()
    {
        // Listing svih hook-ova koji se izvršavaju
        add_action('all', function ($hook) {
            if (strpos($hook, 'checkout') !== false || strpos($hook, 'order') !== false) {
                dexpress_log("Hook: {$hook}", 'debug');
            }
        });
    }
    /**
     * NOVA: Inicijalizacija optimizovane dispenser funkcionalnosti
     */
    public function init_dispensers()
    {
        // Novi optimizovani AJAX handleri
        add_action('wp_ajax_dexpress_get_towns_with_dispensers', [$this, 'ajax_get_towns_with_dispensers']);
        add_action('wp_ajax_nopriv_dexpress_get_towns_with_dispensers', [$this, 'ajax_get_towns_with_dispensers']);

        add_action('wp_ajax_dexpress_get_all_dispensers', [$this, 'ajax_get_all_dispensers']);
        add_action('wp_ajax_nopriv_dexpress_get_all_dispensers', [$this, 'ajax_get_all_dispensers']);

        add_action('wp_ajax_dexpress_save_chosen_dispenser', [$this, 'ajax_save_chosen_dispenser']);
        add_action('wp_ajax_nopriv_dexpress_save_chosen_dispenser', [$this, 'ajax_save_chosen_dispenser']);

        // NOVI: Autocomplete za gradove
        add_action('wp_ajax_dexpress_search_towns_autocomplete', [$this, 'ajax_search_towns_autocomplete']);
        add_action('wp_ajax_nopriv_dexpress_search_towns_autocomplete', [$this, 'ajax_search_towns_autocomplete']);

        // WooCommerce integracija
        add_action('woocommerce_after_shipping_rate', [$this, 'add_dispenser_selection'], 10, 2);
        add_action('wp_footer', [$this, 'add_dispenser_modal']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_dispenser_to_order'], 10, 2);

        // Dodaj ovu liniju u init_dispensers funkciju
        add_action('wp_ajax_dexpress_search_dispensers', [$this, 'ajax_search_dispensers']);
        add_action('wp_ajax_nopriv_dexpress_search_dispensers', [$this, 'ajax_search_dispensers']);
    }

    /**
     * NOVO: AJAX za dobijanje gradova koji imaju paketomata
     */
    public function ajax_get_towns_with_dispensers()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        // Cache check - promeni verziju za refresh
        $cache_key = 'dexpress_towns_with_dispensers_v3';
        $towns = get_transient($cache_key);

        if ($towns === false) {
            global $wpdb;

            $query = "
            SELECT 
                t.id, 
                COALESCE(
                    NULLIF(TRIM(t.display_name), ''),
                    NULLIF(TRIM(t.name), ''),
                    'Nepoznat grad'
                ) as name,
                COUNT(d.id) as dispenser_count
            FROM {$wpdb->prefix}dexpress_towns t
            INNER JOIN {$wpdb->prefix}dexpress_dispensers d ON t.id = d.town_id
            WHERE (d.deleted IS NULL OR d.deleted != 1)
            GROUP BY t.id, t.name, t.display_name
            HAVING dispenser_count > 0
            ORDER BY name
        ";

            $results = $wpdb->get_results($query, ARRAY_A);

            if ($wpdb->last_error) {
                dexpress_log("Towns SQL Error: " . $wpdb->last_error, 'error');
                wp_send_json_error(['message' => 'Database error']);
                return;
            }

            $towns = array_map(function ($row) {
                $cleanName = $row['name'];

                // Ukloni duplikat u zagradama: "Beograd (Zemun) (Zemun)" → "Beograd (Zemun)"
                $cleanName = preg_replace('/\s*\(([^)]+)\)\s*\(\1\)/', ' ($1)', $cleanName);

                // Ukloni poštanski broj: " - 11080" → ""
                $cleanName = preg_replace('/\s*-\s*\d{5}/', '', $cleanName);

                // Očisti razmake
                $cleanName = trim(preg_replace('/\s+/', ' ', $cleanName));

                return [
                    'id' => intval($row['id']),
                    'name' => $cleanName,
                    'dispenser_count' => intval($row['dispenser_count'])
                ];
            }, $results);

            set_transient($cache_key, $towns, 4 * HOUR_IN_SECONDS);
        }

        wp_send_json_success(['towns' => $towns]);
    }

    /**
     * NOVO: AJAX za dobijanje svih paketomata
     */
    public function ajax_get_all_dispensers()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        $cache_key = 'dexpress_all_dispensers_v3';
        $dispensers = get_transient($cache_key);

        if ($dispensers === false) {
            global $wpdb;

            $query = "
                    SELECT 
                        d.id, 
                        d.name, 
                        d.address, 
                        d.town_id, 
                        d.work_hours,
                        d.latitude, 
                        d.longitude, 
                        d.pay_by_cash, 
                        d.pay_by_card,
                        COALESCE(
                            NULLIF(TRIM(d.town), ''),
                            NULLIF(TRIM(t.display_name), ''),
                            TRIM(t.name),
                            'Nepoznat grad'
                        ) as town,
                        COALESCE(t.postal_code, '') as postal_code
                    FROM {$wpdb->prefix}dexpress_dispensers d
                    LEFT JOIN {$wpdb->prefix}dexpress_towns t ON d.town_id = t.id
                    WHERE (d.deleted IS NULL OR d.deleted != 1)
                        AND d.latitude IS NOT NULL 
                        AND d.longitude IS NOT NULL
                        AND d.latitude != 0 
                        AND d.longitude != 0
                    ORDER BY d.town, d.name
                    LIMIT 2000
                ";

            $results = $wpdb->get_results($query, ARRAY_A);

            if ($wpdb->last_error) {
                dexpress_log("Dispensers SQL Error: " . $wpdb->last_error, 'error');
                wp_send_json_error(['message' => 'Database error']);
                return;
            }

            $dispensers = array_map(function ($row) {
                return [
                    'id' => intval($row['id']),
                    'name' => $row['name'] ?: 'Paketomat',
                    'address' => $row['address'] ?: 'Nepoznata adresa',
                    'town' => $row['town'],
                    'town_id' => intval($row['town_id']),
                    'work_hours' => $row['work_hours'] ?: '0-24',
                    'latitude' => floatval($row['latitude']),
                    'longitude' => floatval($row['longitude']),
                    'pay_by_cash' => intval($row['pay_by_cash']),
                    'pay_by_card' => intval($row['pay_by_card']),
                    'postal_code' => $row['postal_code']
                ];
            }, $results);

            set_transient($cache_key, $dispensers, 2 * HOUR_IN_SECONDS);
        }

        wp_send_json_success(['dispensers' => $dispensers]);
    }
    /**
     * NOVI: AJAX za pretragu paketomata po imenu, adresi, gradu
     */
    public function ajax_search_dispensers()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        $search_term = sanitize_text_field($_GET['term'] ?? '');

        if (strlen($search_term) < 2) {
            wp_send_json_success(['dispensers' => []]);
        }

        global $wpdb;

        // Cache ključ
        $cache_key = 'dexpress_search_dispensers_' . md5($search_term);
        $cached_results = get_transient($cache_key);

        if ($cached_results !== false) {
            wp_send_json_success(['dispensers' => $cached_results]);
        }

        $query = "
        SELECT 
            d.id, 
            d.name, 
            d.address, 
            d.town_id, 
            d.work_hours,
            d.latitude, 
            d.longitude, 
            d.pay_by_cash, 
            d.pay_by_card,
            COALESCE(
                NULLIF(TRIM(d.town), ''),
                NULLIF(TRIM(t.display_name), ''),
                TRIM(t.name),
                'Nepoznat grad'
            ) as town,
            COALESCE(t.postal_code, '') as postal_code
        FROM {$wpdb->prefix}dexpress_dispensers d
        LEFT JOIN {$wpdb->prefix}dexpress_towns t ON d.town_id = t.id
        WHERE (d.deleted IS NULL OR d.deleted != 1)
            AND d.latitude IS NOT NULL 
            AND d.longitude IS NOT NULL
            AND d.latitude != 0 
            AND d.longitude != 0
            AND (
                d.name LIKE %s 
                OR d.address LIKE %s 
                OR d.town LIKE %s
                OR t.name LIKE %s
                OR t.display_name LIKE %s
            )
        ORDER BY 
            CASE 
                WHEN d.name LIKE %s THEN 1
                WHEN d.address LIKE %s THEN 2
                WHEN d.town LIKE %s THEN 3
                ELSE 4
            END,
            d.name ASC
        LIMIT 50
    ";

        $search_pattern = '%' . $wpdb->esc_like($search_term) . '%';
        $search_start = $wpdb->esc_like($search_term) . '%';

        $results = $wpdb->get_results($wpdb->prepare(
            $query,
            $search_pattern,
            $search_pattern,
            $search_pattern,
            $search_pattern,
            $search_pattern,
            $search_start,
            $search_start,
            $search_start
        ), ARRAY_A);

        if ($wpdb->last_error) {
            dexpress_log("Dispenser Search SQL Error: " . $wpdb->last_error, 'error');
            wp_send_json_error(['message' => 'Database error']);
            return;
        }

        $dispensers = array_map(function ($row) {
            return [
                'id' => intval($row['id']),
                'name' => $row['name'] ?: 'Paketomat',
                'address' => $row['address'] ?: 'Nepoznata adresa',
                'town' => $row['town'],
                'town_id' => intval($row['town_id']),
                'work_hours' => $row['work_hours'] ?: '0-24',
                'latitude' => floatval($row['latitude']),
                'longitude' => floatval($row['longitude']),
                'pay_by_cash' => intval($row['pay_by_cash']),
                'pay_by_card' => intval($row['pay_by_card']),
                'postal_code' => $row['postal_code']
            ];
        }, $results);

        // Cache na 15 minuta
        set_transient($cache_key, $dispensers, 15 * MINUTE_IN_SECONDS);

        wp_send_json_success(['dispensers' => $dispensers]);
    }
    /**
     * NOVI: AJAX za autocomplete pretragu gradova
     */
    public function ajax_search_towns_autocomplete()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        $search_term = sanitize_text_field($_GET['term'] ?? '');

        if (strlen($search_term) < 2) {
            wp_send_json_success(['towns' => []]);
        }

        global $wpdb;

        // Cache ključ
        $cache_key = 'dexpress_autocomplete_towns_v2_' . md5($search_term);
        $cached_results = get_transient($cache_key);

        if ($cached_results !== false) {
            wp_send_json_success(['towns' => $cached_results]);
        }

        $query = "
        SELECT DISTINCT 
            t.id, 
            COALESCE(
                NULLIF(TRIM(t.display_name), ''),
                NULLIF(TRIM(t.name), ''),
                'Nepoznat grad'
            ) as name,
            COUNT(d.id) as dispenser_count
        FROM {$wpdb->prefix}dexpress_towns t
        LEFT JOIN {$wpdb->prefix}dexpress_dispensers d ON t.id = d.town_id 
            AND (d.deleted IS NULL OR d.deleted != 1)
        WHERE (
            t.name LIKE %s 
            OR t.display_name LIKE %s
        )
        GROUP BY t.id, t.name, t.display_name
        HAVING dispenser_count > 0
        ORDER BY 
            CASE 
                WHEN COALESCE(t.display_name, t.name) LIKE %s THEN 1
                ELSE 2
            END,
            dispenser_count DESC,
            name ASC
    ";

        $search_pattern = '%' . $wpdb->esc_like($search_term) . '%';
        $search_start = $wpdb->esc_like($search_term) . '%';

        $results = $wpdb->get_results($wpdb->prepare(
            $query,
            $search_pattern,
            $search_pattern,
            $search_start
        ), ARRAY_A);

        if ($wpdb->last_error) {
            dexpress_log("Autocomplete SQL Error: " . $wpdb->last_error, 'error');
            wp_send_json_error(['message' => 'Database error']);
            return;
        }

        $towns = array_map(function ($row) {
            $cleanName = $row['name'];

            // Isti regex za čišćenje kao gore
            $cleanName = preg_replace('/\s*\(([^)]+)\)\s*\(\1\)/', ' ($1)', $cleanName);
            $cleanName = preg_replace('/\s*-\s*\d{5}/', '', $cleanName);
            $cleanName = trim(preg_replace('/\s+/', ' ', $cleanName));

            return [
                'id' => intval($row['id']),
                'name' => $cleanName,
                'dispenser_count' => intval($row['dispenser_count'])
            ];
        }, $results);

        // Cache na 30 minuta
        set_transient($cache_key, $towns, 30 * MINUTE_IN_SECONDS);

        wp_send_json_success(['towns' => $towns]);
    }

    /**
     * AŽURIRANO: Čuvanje izabranog paketomata
     */
    public function ajax_save_chosen_dispenser()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        if (!isset($_POST['dispenser']) || !is_array($_POST['dispenser'])) {
            wp_send_json_error(['message' => 'Neispravni podaci o paketomatu']);
        }

        $dispenser = [
            'id' => intval($_POST['dispenser']['id'] ?? 0),
            'name' => sanitize_text_field($_POST['dispenser']['name'] ?? ''),
            'address' => sanitize_text_field($_POST['dispenser']['address'] ?? ''),
            'town' => sanitize_text_field($_POST['dispenser']['town'] ?? ''),
            'town_id' => intval($_POST['dispenser']['town_id'] ?? 0),
            'postal_code' => sanitize_text_field($_POST['dispenser']['postal_code'] ?? '')
        ];

        if (empty($dispenser['id']) || empty($dispenser['name'])) {
            wp_send_json_error(['message' => 'Nevažeći podaci o paketomatu']);
        }

        // DODAJ OVO - eksplicitno startovanje sesije ako nije
        if (!WC()->session) {
            WC()->initialize_session();
        }

        WC()->session->set('chosen_dispenser', $dispenser);

        // DODAJ OVO - forsira čuvanje sesije
        WC()->session->save_data();

        wp_send_json_success(['message' => 'Paketomat je uspešno sačuvan']);
    }

    /**
     * NOVO: Modal za izbor paketomata
     */
    public function add_dispenser_modal()
    {
        if (!is_checkout()) {
            return;
        }
?>
        <div id="dexpress-dispenser-modal">
            <div class="dexpress-modal-content">
                <div class="dexpress-modal-header">
                    <h3>Izaberite paketomat za dostavu</h3>
                    <?php $dispensers_head = DEXPRESS_WOO_PLUGIN_URL . 'assets/images/dexpress-dispenser-picture.png'; ?>
                    <img width="120" src="<?php echo $dispensers_head; ?>" alt="dexpress dispensers">
                    <button type="button" class="dexpress-modal-close">&times;</button>
                </div>

                <div class="dexpress-modal-body">
                    <!-- Filter gradova -->
                    <div class="dexpress-town-filter">
                        <label for="dexpress-town-select">Pretražite paketomata:</label>
                        <input type="text" id="dexpress-town-select" placeholder="Unesite naziv paketomata, adresu ili grad..." autocomplete="off">
                        <button type="button" class="dexpress-reset-filter">&times;</button>
                        <div id="dexpress-town-suggestions" class="dexpress-town-suggestions"></div>
                    </div>

                    <!-- Glavni kontejner -->
                    <div class="dexpress-dispensers-container">
                        <div id="dexpress-dispensers-map">
                            <div class="dexpress-map-placeholder">
                                <div class="icon"></div>
                                <p>Učitavanje mape...</p>
                            </div>
                        </div>
                        <div id="dexpress-dispensers-list">
                            <div class="no-results">
                                <div class="no-results-message">Učitavanje paketomata...</div>
                                <div class="no-results-hint">Molimo sačekajte</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dexpress-modal-footer">
                    <button type="button" class="dexpress-modal-close-btn dexpress-modal-close">Odustani</button>
                    <div class="modal-info">
                        <small>Izaberite paketomat koji vam odgovara</small>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Dodaje izbor paketomata nakon shipping rate
     */
    public function add_dispenser_selection($method, $index)
    {
        if ($method->get_method_id() !== 'dexpress_dispenser') {
            return;
        }

        $chosen_dispenser = WC()->session->get('chosen_dispenser');
        $instance_id = $method->get_instance_id();
        $settings = $this->get_dispenser_shipping_settings($instance_id);
        $is_selected = $this->is_selected_shipping_method($method->get_id());

        $this->render_dispenser_selection_html($method, $chosen_dispenser, $settings, $is_selected);
    }

    /**
     * NOVO: Dobij postavke za dispenser shipping metodu
     */
    private function get_dispenser_shipping_settings($instance_id)
    {
        $option_key = "woocommerce_dexpress_dispenser_{$instance_id}_settings";
        $settings = get_option($option_key, []);

        $defaults = [
            'description_text' => 'D Paketomati su postavljeni na benzinskim stanicama, supermarketima, šoping centrima i rade 24 časa dnevno, jednostavni su i bezbedni za upotrebu.',
            'button_text' => 'ODABERITE PAKETOMAT',
            'delivery_time_text' => '3 RADNA DANA',
            'steps_text' => "1. Odaberite paketomat/paket shop lokaciju i završite porudžbinu\n2. Kada je paket isporučen na željenu lokaciju, D Express će vam putem Viber/SMS poruke poslati kod za otvaranje paketomat ormarića\n3. Ukoliko plaćate pouzećem potrebno je da kasiru pokažete kod i platite pošiljku gotovinom ili platnom karticom\n4. Upišite kod na paketomatu\n5. Preuzmite paket iz ormarića koji će se automatski otvoriti",
            'note_text' => '* Rok za preuzimanje pošiljke sa paketomata je 2 radna dana i nakon odabira ovog tipa dostave pošiljku nije moguće preusmeriti na drugu adresu'
        ];

        return wp_parse_args($settings, $defaults);
    }

    /**
     * Proverava da li je shipping metoda izabrana
     */
    private function is_selected_shipping_method($method_id)
    {
        $chosen_methods = WC()->session->get('chosen_shipping_methods', []);
        return in_array($method_id, $chosen_methods);
    }

    /**
     * AŽURIRANO: Renderuje HTML za izbor paketomata
     */
    private function render_dispenser_selection_html($method, $chosen_dispenser, $settings, $is_selected)
    {
        $display_style = $is_selected ? 'block' : 'none';
        $cost = $method->get_cost();
        $currency_symbol = get_woocommerce_currency_symbol();
        $formatted_cost = number_format($cost, 0, ',', '.');
        $steps_html = $this->format_steps_as_html($settings['steps_text']);
    ?>
        <div class="dexpress-dispenser-wrapper" style="margin-top: 15px; padding: 15px; border: 1px solid #eee; border-radius: 4px; background-color: #f9f9f9; display: <?php echo esc_attr($display_style); ?>;">

            <div class="dexpress-dispenser-info-method">
                <p class="dexpress-dispenser-description"><?php echo wp_kses_post($settings['description_text']); ?></p>
                <p class="dexpress-dispenser-price">Cena isporuke je <?php echo esc_html($formatted_cost . ' ' . $currency_symbol); ?>.</p>

                <div class="dexpress-delivery-time" style="margin: 10px 0; text-align: right; font-weight: bold;">
                    <?php echo esc_html($settings['delivery_time_text']); ?>
                </div>
            </div>

            <?php if (!empty($steps_html)): ?>
                <div class="dexpress-steps" style="margin-top: 20px;">
                    <ol style="padding-left: 20px; margin: 0;">
                        <?php echo $steps_html; ?>
                    </ol>
                </div>
            <?php endif; ?>

            <?php if (!empty($settings['note_text'])): ?>
                <div class="dexpress-note" style="margin-top: 10px; color: #e2401c; font-size: 0.9em;">
                    <?php echo esc_html($settings['note_text']); ?>
                </div>
            <?php endif; ?>

            <div class="dexpress-dispenser-selection" style="margin: 15px 0;">
                <button type="button" class="button dexpress-select-dispenser-btn" style="width: 100%; text-align: center; padding: 10px;">
                    <?php echo esc_html($settings['button_text']); ?>
                </button>
            </div>

            <?php if ($chosen_dispenser): ?>
                <div class="dexpress-chosen-dispenser-info" style="margin-top: 10px; background: #f0f0f0; padding: 10px; border-radius: 3px;">
                    <strong><?php echo esc_html($chosen_dispenser['name']); ?></strong><br>
                    <?php echo esc_html($chosen_dispenser['address']); ?>, <?php echo esc_html($chosen_dispenser['town']); ?>
                    <br><a href="#" class="dexpress-change-dispenser">Promenite paketomat</a>
                </div>
            <?php else: ?>
                <div class="dexpress-dispenser-warning" style="color: #e2401c; margin-top: 5px; padding: 8px; background: #f8d7da; border-radius: 3px;">
                    Morate izabrati paketomat za dostavu
                </div>
            <?php endif; ?>

        </div>
<?php
    }

    /**
     * Čuva dispenser u narudžbini
     */
    public function save_dispenser_to_order($order_id, $posted_data)
    {
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

        $chosen_dispenser = WC()->session->get('chosen_dispenser');

        if ($chosen_dispenser) {
            update_post_meta($order_id, '_dexpress_dispenser_id', $chosen_dispenser['id']);
            update_post_meta($order_id, '_dexpress_dispenser_name', $chosen_dispenser['name']);
            update_post_meta($order_id, '_dexpress_dispenser_address', $chosen_dispenser['address']);
            update_post_meta($order_id, '_dexpress_dispenser_town', $chosen_dispenser['town']);

            $order = wc_get_order($order_id);
            if ($order) {
                $address = [
                    'first_name' => $order->get_shipping_first_name(),
                    'last_name'  => $order->get_shipping_last_name(),
                    'company'    => '',
                    'address_1'  => $chosen_dispenser['address'] . ' (Paketomat: ' . $chosen_dispenser['name'] . ')',
                    'address_2'  => '',
                    'city'       => $chosen_dispenser['town'],
                    'state'      => '',
                    'postcode'   => $chosen_dispenser['postal_code'] ?? '',
                    'country'    => $order->get_shipping_country()
                ];

                $order->set_address($address, 'shipping');
                $order->save();

                $order->add_order_note(
                    sprintf(
                        'Narudžbina će biti dostavljena na paketomat: %s, Adresa: %s, %s',
                        $chosen_dispenser['name'],
                        $chosen_dispenser['address'],
                        $chosen_dispenser['town']
                    )
                );
            }

            WC()->session->__unset('chosen_dispenser');
        }
    }

    /**
     * Helper funkcija za formatiranje koraka
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

    // ========== POSTOJEĆE FUNKCIONALNOSTI (ZADRŽANO) ==========

    /**
     * Modifikacija checkout polja (ZADRŽANO)
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
            'address_desc' => [
                'type'        => 'text',
                'label'       => __('Dodatne informacije o adresi', 'd-express-woo'),
                'placeholder' => __('Npr: sprat 3, stan 24, interfon 24', 'd-express-woo'),
                'required'    => false,
                'class'       => ['form-row-wide', 'dexpress-address-desc'],
                'priority'    => 56,
                'maxlength'   => 150,
                'custom_attributes' => ['pattern' => '[-a-zA-Z0-9:,._\s]+'],
            ],
            'city' => [
                'type'        => 'text',
                'label'       => __('Grad', 'd-express-woo'),
                'placeholder' => __('Naziv grada/mesta', 'd-express-woo'),
                'required'    => true,
                'class'       => ['form-row-wide', 'dexpress-city'],
                'priority'    => 60,
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

            if (empty($fields['billing']['billing_phone']['default'])) {
                $fields['billing']['billing_phone']['default'] = '+381';
            }
        }

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
     * Validacija checkout polja (ZADRŽANO)
     */
    public function validate_checkout_fields()
    {
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/class-dexpress-validator.php';
        D_Express_Validator::validate_checkout();
    }

    /**
     * AJAX: Pretraga ulica za određeni grad 
     */

    public function ajax_search_streets_for_town()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        global $wpdb;
        $search = sanitize_text_field($_GET['term'] ?? '');
        $town_id = intval($_GET['town_id'] ?? 0);

        if (!$town_id) {
            wp_send_json([]);
            return;
        }

        // ISPRAVKA: Kraći keš sa town_id u ključu
        $cache_key = "dexpress_streets_town_{$town_id}_" . md5($search);
        $cached_streets = get_transient($cache_key);

        if ($cached_streets !== false) {
            dexpress_log("Cache hit za grad {$town_id}, term: {$search}", 'debug');
            wp_send_json($cached_streets);
            return;
        }

        dexpress_log("API poziv za grad {$town_id}, term: {$search}", 'debug');

        // POBOLJŠAN SQL sa boljim sortiranjem
        $streets = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.name, s.id
         FROM {$wpdb->prefix}dexpress_streets s
         WHERE s.TId = %d 
           AND s.name LIKE %s
           AND (s.deleted IS NULL OR s.deleted = 0)
         ORDER BY 
           CASE WHEN s.name LIKE %s THEN 1 ELSE 2 END,
           LENGTH(s.name),
           s.name ASC 
         LIMIT 50",
            $town_id,
            '%' . $wpdb->esc_like($search) . '%',
            $wpdb->esc_like($search) . '%'
        ));

        if ($wpdb->last_error) {
            dexpress_log("SQL greška za grad {$town_id}: " . $wpdb->last_error, 'error');
            wp_send_json([]);
            return;
        }

        $results = array_map(function ($street) {
            return [
                'id'    => $street->id,
                'label' => $street->name,
                'value' => $street->name,
            ];
        }, $streets);

        // ISPRAVKA: Kraći keš - 15 minuta umesto sat vremena
        set_transient($cache_key, $results, 15 * MINUTE_IN_SECONDS);

        dexpress_log("Vraćam " . count($results) . " ulica za grad {$town_id}", 'debug');
        wp_send_json($results);
    }

    /**
     * Čuvanje checkout polja u bazi (ZADRŽANO)
     */
    public function save_checkout_fields($order_id)
    {
        $address_types = ['billing', 'shipping'];
        $fields_to_save = ['street', 'street_id', 'number', 'address_desc', 'city', 'city_id', 'postcode'];

        foreach ($address_types as $type) {
            $updated_values = [];

            foreach ($fields_to_save as $key) {
                $field_name = "{$type}_{$key}";
                if (isset($_POST[$field_name])) {
                    $value = $_POST[$field_name];

                    // SPECIJALNO RUKOVANJE ZA KUĆNI BROJ
                    if ($key === 'number') {
                        // NE KORISTI sanitize_text_field za broj - samo osnovnu validaciju
                        $value = trim($value);

                        // Validacija pomoću naše funkcije
                        if (!D_Express_Validator::validate_address_number($value)) {
                            dexpress_log("GREŠKA: Neispravan format kućnog broja '{$value}' za narudžbinu {$order_id}", 'error');
                            // Nastavi sa čuvanjem - možda je greška u validaciji
                        }

                        dexpress_log("Čuvam {$type}_number: '{$value}'", 'debug');
                    }
                    // SPECIJALNO RUKOVANJE ZA OPIS ADRESE
                    else if ($key === 'address_desc') {
                        $value = sanitize_text_field($value);
                        $value = preg_replace('/[^a-zžćčđšA-ZĐŠĆŽČ:,._0-9\-\s]/u', '', $value);
                        $value = preg_replace('/\s+/', ' ', $value);
                        $value = trim($value);
                        $value = preg_replace('/^\./', '', $value);

                        if (mb_strlen($value, 'UTF-8') > 150) {
                            $value = mb_substr($value, 0, 150, 'UTF-8');
                        }
                    }
                    // STANDARDNO RUKOVANJE ZA OSTALA POLJA
                    else {
                        $value = sanitize_text_field($value);
                    }

                    $updated_values["_{$field_name}"] = $value;
                }
            }

            // ISPRAVKA: Formiraj address_1 za WooCommerce format
            if (!empty($_POST["{$type}_street"]) && !empty($_POST["{$type}_number"])) {
                $street = sanitize_text_field($_POST["{$type}_street"]);
                $number = trim($_POST["{$type}_number"]); // NE SANITIZE broj!
                $updated_values["_{$type}_address_1"] = $street . ' ' . $number;

                dexpress_log("Formiram address_1: '{$street} {$number}'", 'debug');
            }

            // SAČUVAJ SVE VREDNOSTI
            foreach ($updated_values as $meta_key => $meta_value) {
                update_post_meta($order_id, $meta_key, $meta_value);
                dexpress_log("Sačuvao {$meta_key}: '{$meta_value}'", 'debug');
            }
        }

        // TELEFON - isto kao pre
        if (isset($_POST['dexpress_phone_api'])) {
            $api_phone = sanitize_text_field($_POST['dexpress_phone_api']);
            update_post_meta($order_id, '_billing_phone_api_format', $api_phone);

            $display_phone = $this->format_display_phone($api_phone);
            update_post_meta($order_id, '_billing_phone', $display_phone);
        } elseif (isset($_POST['billing_phone'])) {
            $phone = sanitize_text_field($_POST['billing_phone']);
            update_post_meta($order_id, '_billing_phone', $phone);

            if (strpos($phone, '+381') === 0) {
                $api_phone = substr($phone, 1);
                update_post_meta($order_id, '_billing_phone_api_format', $api_phone);
            }
        }
    }
    /**
     * Format display phone (ZADRŽANO)
     */
    private function format_display_phone($api_phone)
    {
        if (strlen($api_phone) < 10) {
            return '+' . $api_phone;
        }

        return '+' . substr($api_phone, 0, 3) . ' ' .
            substr($api_phone, 3, 2) . ' ' .
            substr($api_phone, 5, 3) . ' ' .
            substr($api_phone, 8);
    }
    /**
     * AJAX: Pretraga gradova sa naseljima (ZADRŽANO)
     */
    public function ajax_search_all_towns()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        global $wpdb;
        $search = sanitize_text_field($_GET['term'] ?? '');

        if (empty($search) || strlen($search) < 1) {
            wp_send_json([]);
        }

        $cache_key = 'dexpress_towns_' . md5($search);
        $cached_towns = get_transient($cache_key);

        if ($cached_towns !== false) {
            wp_send_json($cached_towns);
        }

        $towns = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT 
           t.id, 
           t.name, 
           t.display_name, 
           t.postal_code, 
           t.municipality_id,
           m.name as municipality_name
         FROM {$wpdb->prefix}dexpress_towns t
         LEFT JOIN {$wpdb->prefix}dexpress_municipalities m ON t.municipality_id = m.id
         WHERE (t.name LIKE %s OR t.display_name LIKE %s)
         ORDER BY 
           CASE 
             WHEN t.display_name LIKE %s THEN 1 
             WHEN t.name LIKE %s THEN 2 
             ELSE 3 
           END,
           LENGTH(COALESCE(t.display_name, t.name)),
           t.display_name, t.name ASC 
         LIMIT 100",
            '%' . $wpdb->esc_like($search) . '%',
            '%' . $wpdb->esc_like($search) . '%',
            $wpdb->esc_like($search) . '%',
            $wpdb->esc_like($search) . '%'
        ));

        $results = array_map(function ($town) {
            $primary_name = !empty($town->display_name) ? $town->display_name : $town->name;

            // ISPRAVKA: SAMO SIROVI PODACI - JavaScript će formatirati!
            return [
                'town_id'           => $town->id,
                'label'             => $primary_name,
                'value'             => $primary_name,
                'display_name'      => $town->display_name,
                'municipality_name' => $town->municipality_name,
                'postal_code'       => $town->postal_code,
            ];
        }, $towns);

        set_transient($cache_key, $results, HOUR_IN_SECONDS);
        wp_send_json($results);
    }

    /**
     * AJAX: Brza pretraga svih ulica (ZADRŽANO)
     */
    public function ajax_search_streets()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        global $wpdb;
        $search = sanitize_text_field($_GET['term'] ?? '');

        if (empty($search) || strlen($search) < 1) {
            wp_send_json([]);
        }

        $cache_key = 'dexpress_streets_' . md5($search);
        $cached_streets = get_transient($cache_key);

        if ($cached_streets !== false) {
            wp_send_json($cached_streets);
        }

        $streets = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.name
         FROM {$wpdb->prefix}dexpress_streets s
         WHERE s.name LIKE %s
         ORDER BY 
           CASE WHEN s.name LIKE %s THEN 1 ELSE 2 END,
           LENGTH(s.name),
           s.name ASC 
         LIMIT 100",
            '%' . $wpdb->esc_like($search) . '%',
            $wpdb->esc_like($search) . '%'
        ));

        $results = array_map(function ($street) {
            return [
                'id'    => $street->name,
                'label' => $street->name,
                'value' => $street->name,
            ];
        }, $streets);

        set_transient($cache_key, $results, 30 * MINUTE_IN_SECONDS);
        wp_send_json($results);
    }

    /**
     * AJAX: Dohvatanje grada i poštanskog broja za ulicu (ZADRŽANO)
     */
    public function ajax_get_town_for_street()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        global $wpdb;
        $street_id = intval($_GET['street_id'] ?? 0);

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

            set_transient($cache_key, $town_data, HOUR_IN_SECONDS);
            wp_send_json_success($town_data);
        } else {
            wp_send_json_error(['message' => 'Grad nije pronađen.']);
        }
    }

    /**
     * AJAX: Dobijanje gradova za ulicu (ZADRŽANO)
     */
    public function ajax_get_towns_for_street()
    {
        check_ajax_referer('dexpress-frontend-nonce', 'nonce');

        global $wpdb;
        $street_name = sanitize_text_field($_POST['street_name'] ?? '');

        if (empty($street_name)) {
            wp_send_json_error(['message' => 'Naziv ulice je obavezan.']);
        }

        $cache_key = 'dexpress_towns_for_street_' . md5($street_name);
        $cached_towns = get_transient($cache_key);

        if ($cached_towns !== false) {
            wp_send_json_success(['towns' => $cached_towns]);
        }

        $towns = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT 
           t.id, 
           t.name, 
           t.display_name, 
           t.postal_code, 
           t.municipality_id, 
           m.name as municipality_name,
           s.id as street_id
         FROM {$wpdb->prefix}dexpress_streets s
         INNER JOIN {$wpdb->prefix}dexpress_towns t ON s.TId = t.id
         LEFT JOIN {$wpdb->prefix}dexpress_municipalities m ON t.municipality_id = m.id
         WHERE s.name = %s
         ORDER BY 
           CASE 
             WHEN t.display_name IS NOT NULL THEN 1 
             ELSE 2 
           END,
           t.display_name, t.name ASC",
            $street_name
        ));

        $towns_data = array_map(function ($town) {
            return [
                'id'                => $town->id,
                'name'              => $town->name,
                'display_name'      => $town->display_name,
                'postal_code'       => $town->postal_code,
                'street_id'         => $town->street_id,
                'municipality_name' => $town->municipality_name,
            ];
        }, $towns);

        set_transient($cache_key, $towns_data, 2 * HOUR_IN_SECONDS);
        wp_send_json_success(['towns' => $towns_data]);
    }

    /**
     * Mapiranje checkout podataka u API zahtev (ZADRŽANO)
     */
    public function prepare_shipment_data($order)
    {
        $order_id = $order->get_id();
        $address_type = $order->has_shipping_address() ? 'shipping' : 'billing';

        $street_id = get_post_meta($order_id, "_{$address_type}_street_id", true);
        $street = get_post_meta($order_id, "_{$address_type}_street", true);
        $number = get_post_meta($order_id, "_{$address_type}_number", true);
        $city_id = get_post_meta($order_id, "_{$address_type}_city_id", true);

        $data = [
            'RAddress' => $street,
            'RAddressNum' => !empty($number) ? $number : '1',
            'RTownID' => !empty($city_id) ? (int)$city_id : 100001,
        ];

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
     * AŽURIRANO: Učitavanje skripti i stilova za checkout
     */
    public function enqueue_checkout_scripts()
    {
        if (!is_checkout()) {
            return;
        }

        // jQuery UI Autocomplete
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');

        // Select2
        if (!wp_script_is('select2', 'registered')) {
            wp_register_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), '4.0.13', true);
            wp_register_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', array(), '4.0.13');
        }
        wp_enqueue_script('select2');
        wp_enqueue_style('select2');

        // Osnovni checkout script
        wp_enqueue_script(
            'dexpress-checkout',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-checkout.js',
            ['jquery', 'jquery-ui-autocomplete'],
            DEXPRESS_WOO_VERSION,
            true
        );

        // Dispenser CSS
        wp_enqueue_style(
            'dexpress-dispenser',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-dispenser.css',
            array(),
            DEXPRESS_WOO_VERSION
        );

        // Osnovni checkout CSS
        wp_enqueue_style(
            'dexpress-checkout',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-checkout.css',
            array('select2'),
            DEXPRESS_WOO_VERSION
        );

        // Google Maps API - samo ako je ključ podešen
        $google_maps_api_key = get_option('dexpress_google_maps_api_key', '');
        if (!empty($google_maps_api_key)) {
            wp_enqueue_script(
                'google-maps',
                'https://maps.googleapis.com/maps/api/js?key=' . $google_maps_api_key . '&v=weekly&libraries=geometry',
                array(),
                null,
                true
            );
        }

        // Dispenser modal JavaScript
        wp_enqueue_script(
            'dexpress-dispenser-modal',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-dispenser-modal.js',
            array('jquery'),
            DEXPRESS_WOO_VERSION,
            true
        );

        // AŽURIRANA lokalizacija sa novim i18n
        wp_localize_script('dexpress-checkout', 'dexpressCheckout', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dexpress-frontend-nonce'),
            'hasGoogleMaps' => !empty($google_maps_api_key),
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
                'invalidAddressDesc' => __('Neispravan format dodatnih informacija o adresi. Dozvoljeni su samo slova, brojevi, razmaci i znakovi: , . : - _', 'd-express-woo'),
                // NOVO ZA DISPENSER:
                'allTowns' => __('Svi gradovi', 'd-express-woo'),
            )
        ));
    }
}
