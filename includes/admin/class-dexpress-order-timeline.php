<?php

/**
 * D Express Order Timeline Class
 * 
 * Klasa za prikaz timeline-a statusa pošiljke u order metabox-u
 * 
 */

defined('ABSPATH') || exit;

/**
 * D_Express_Order_Timeline Class.
 */
class D_Express_Order_Timeline
{

    /**
     * Inicijalizacija.
     *
     * @return void
     */
    public function init()
    {
        // Dodavanje metabox-a za timeline
        add_action('add_meta_boxes', array($this, 'add_timeline_metabox'));

        // AJAX handler za osvežavanje statusa
        add_action('wp_ajax_dexpress_refresh_shipment_status', array($this, 'ajax_refresh_status'));

        // Registrovanje stilova za admin
        add_action('admin_enqueue_scripts', array($this, 'register_timeline_assets'));
    }

    /**
     * Dodavanje metabox-a za timeline.
     *
     * @return void
     */
    public function add_timeline_metabox()
    {
        // Za klasični način čuvanja porudžbina (post_type)
        add_meta_box(
            'dexpress_timeline_metabox',
            __('D Express Status Timeline', 'd-express-woo'),
            array($this, 'render_timeline'),
            'shop_order',
            'normal',
            'high'
        );

        // Za HPOS način čuvanja porudžbina (ako je omogućen)
        if (
            class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') &&
            wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
        ) {
            add_meta_box(
                'dexpress_timeline_metabox',
                __('D Express Status Timeline', 'd-express-woo'),
                array($this, 'render_timeline'),
                wc_get_page_screen_id('shop-order'),
                'normal',
                'high'
            );
        }
    }

    /**
     * Registracija stilova i skripti za timeline.
     *
     * @param string $hook Hook suffix.
     * @return void
     */
    public function register_timeline_assets($hook)
    {
        // Učitaj stilove samo na stranicama narudžbina
        $allowed_screens = array(
            'post.php',
            'post-new.php',
            'woocommerce_page_wc-orders',
            'edit-shop_order'
        );

        $screen = get_current_screen();

        if (!$screen || !in_array($hook, $allowed_screens) || ($screen->post_type !== 'shop_order' && $screen->id !== 'woocommerce_page_wc-orders')) {
            return;
        }

        // Dodajemo komentare za debugging
        error_log('D Express Timeline assets loading for screen: ' . $screen->id . ', hook: ' . $hook);

        wp_enqueue_style(
            'dexpress-timeline-css',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-admin.css',
            array('woocommerce_admin_styles'),
            DEXPRESS_WOO_VERSION . '.' . time() // Dodajemo timestamp za eliminisanje keširanje
        );

        // Registrujemo i dodajemo naš JavaScript fajl
        wp_register_script(
            'dexpress-timeline-js',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-timeline.js',
            array('jquery'),
            DEXPRESS_WOO_VERSION . '.' . time(), // Dodajemo timestamp za eliminisanje keširanje
            true
        );

        // Lokalizujemo skriptu sa potrebnim podatcima
        wp_localize_script('dexpress-timeline-js', 'dexpressTimelineL10n', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dexpress-refresh-status'),
            'refreshing' => __('Osvežavanje...', 'd-express-woo'),
            'refreshStatus' => __('Osveži status', 'd-express-woo'),
            'errorMessage' => __('Došlo je do greške prilikom osvežavanja statusa.', 'd-express-woo'),
            'successMessage' => __('Status pošiljke je uspešno ažuriran.', 'd-express-woo')
        ));

        // Učitavamo skriptu
        wp_enqueue_script('dexpress-timeline-js');

        // Dodajemo inline stilove
        wp_add_inline_style('dexpress-timeline-css', '
            .dashicons.rotating {
                animation: dashicons-spin 1s infinite linear;
            }
            @keyframes dashicons-spin {
                0% {
                    transform: rotate(0deg);
                }
                100% {
                    transform: rotate(360deg);
                }
            }
        ');
    }

    /**
     * Dobavlja sve statuse iz baze i vraća mapirane po ID-u.
     *
     * @return array Mapirani statusi.
     */
    private function get_all_statuses()
    {
        global $wpdb;

        $statuses = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}dexpress_statuses_index ORDER BY id");

        $status_map = array();
        foreach ($statuses as $status) {
            $status_map[$status->id] = $status->name;
        }

        return $status_map;
    }

    /**
     * Kreira pseudo timeline da simulira statuse isporuke
     * kada nema pravih statusa u bazi.
     *
     * @param object $shipment Podaci o pošiljci.
     * @return array Timeline statusi.
     */
    private function create_default_timeline($shipment)
    {
        // Definisanje koraka dostave
        $default_statuses = array(
            array(
                'status_id' => 'created',
                'status_date' => $shipment->created_at,
                'status_name' => __('Pošiljka kreirana', 'd-express-woo'),
                'status_type' => 'current',
                'icon' => 'dashicons-plus-alt'
            )
        );

        // Ako je test pošiljka, dodajemo još nekoliko simuliranih statusa
        if ($shipment->is_test) {
            // Dodavanje osnovnih statusa sa različitim vremenima
            $created_time = strtotime($shipment->created_at);

            // Status "Čeka na preuzimanje" - 2 sata nakon kreiranja
            $default_statuses[] = array(
                'status_id' => 'waiting',
                'status_date' => date('Y-m-d H:i:s', $created_time + 7200),
                'status_name' => __('Čeka na preuzimanje', 'd-express-woo'),
                'status_type' => 'pending',
                'icon' => 'dashicons-clock'
            );

            // Ako je pošiljka starija od 6 sati, dodaj još statusa
            if (time() - $created_time > 21600) { // 6 sati
                // Status "Pošiljka preuzeta od pošiljaoca" - 6 sati nakon kreiranja
                $default_statuses[] = array(
                    'status_id' => 'picked_up',
                    'status_date' => date('Y-m-d H:i:s', $created_time + 21600),
                    'status_name' => __('Pošiljka preuzeta od pošiljaoca', 'd-express-woo'),
                    'status_type' => 'current',
                    'icon' => 'dashicons-cart'
                );
            }

            // Ako je pošiljka starija od 24 sata (1 dan)
            if (time() - $created_time > 86400) { // 24 sata
                // Status "Pošiljka zadužena za isporuku" - 1 dan nakon kreiranja
                $default_statuses[] = array(
                    'status_id' => 'for_delivery',
                    'status_date' => date('Y-m-d H:i:s', $created_time + 86400),
                    'status_name' => __('Pošiljka zadužena za isporuku', 'd-express-woo'),
                    'status_type' => 'pending',
                    'icon' => 'dashicons-arrow-right-alt'
                );
            }

            // Ako je pošiljka starija od 48 sati (2 dana)
            if (time() - $created_time > 172800) { // 48 sati
                // Status "Pošiljka isporučena primaocu" - 2 dana nakon kreiranja
                $default_statuses[] = array(
                    'status_id' => '130', // Stvarni kod za isporučeno
                    'status_date' => date('Y-m-d H:i:s', $created_time + 172800),
                    'status_name' => __('Pošiljka isporučena primaocu', 'd-express-woo'),
                    'status_type' => 'completed',
                    'icon' => 'dashicons-yes-alt'
                );
            }
        }

        return $default_statuses;
    }

    /**
     * Mapira statuse iz baze na odgovarajuće objekte za timeline.
     *
     * @param array $db_statuses Status podaci iz baze.
     * @param array $all_status_definitions Definicije svih statusa.
     * @return array Mapirani statusi.
     */
    private function map_statuses($db_statuses, $all_status_definitions)
    {
        $mapped_statuses = array();
        $all_status_codes = dexpress_get_all_status_codes();

        foreach ($db_statuses as $status) {
            $status_name = isset($all_status_definitions[$status->status_id])
                ? $all_status_definitions[$status->status_id]
                : __('Nepoznat status', 'd-express-woo');

            // Određivanje tipa statusa i ikone
            $status_type = 'current';
            $icon = 'dashicons-marker';

            // Dohvatanje grupe statusa ako postoji u mapiranju
            if (isset($all_status_codes[$status->status_id])) {
                $status_group = $all_status_codes[$status->status_id]['group'];

                // Mapiranje grupa na tipove statusa
                if ($status_group === 'delivered') {
                    $status_type = 'completed';
                    $icon = 'dashicons-yes-alt';
                } elseif (in_array($status_group, ['failed', 'returned', 'returning', 'problem'])) {
                    $status_type = 'failed';
                    $icon = 'dashicons-dismiss';
                } elseif (in_array($status_group, ['transit', 'out_for_delivery'])) {
                    $status_type = 'current';
                    $icon = 'dashicons-airplane';
                } elseif ($status_group === 'pending' || $status_group === 'pending_pickup') {
                    $status_type = 'pending';
                    $icon = 'dashicons-clock';
                } elseif ($status_group === 'delayed') {
                    $status_type = 'delayed';
                    $icon = 'dashicons-backup';
                } elseif ($status_group === 'cancelled') {
                    $status_type = 'cancelled';
                    $icon = 'dashicons-no';
                }
            }

            $mapped_statuses[] = array(
                'status_id' => $status->status_id,
                'status_date' => $status->status_date,
                'status_name' => $status_name,
                'status_type' => $status_type,
                'icon' => $icon
            );
        }

        return $mapped_statuses;
    }

    /**
     * Render timeline-a.
     *
     * @param WP_Post|WC_Order $post_or_order Post ili Order objekat.
     * @return void
     */
    public function render_timeline($post_or_order)
    {
        // Provera da li je prosleđen WP_Post ili WC_Order
        if (is_a($post_or_order, 'WP_Post')) {
            $order = wc_get_order($post_or_order->ID);
        } else {
            $order = $post_or_order;
        }

        if (!$order) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Narudžbina nije pronađena.', 'd-express-woo') . '</p></div>';
            return;
        }

        // Dobavljanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order->get_id()
        ));

        if (!$shipment) {
            echo '<div class="dexpress-panel-empty">';
            echo '<div class="dexpress-empty-message">';
            echo '<span class="dashicons dashicons-info-outline"></span>';
            echo '<p>' . esc_html__('Za ovu narudžbinu još uvek ne postoji D Express pošiljka.', 'd-express-woo') . '</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        // Dobavljanje statusa pošiljke
        $db_statuses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_statuses 
        WHERE (shipment_code = %s OR reference_id = %s) 
        ORDER BY status_date DESC",
            $shipment->shipment_id,
            $shipment->reference_id
        ));

        // Ako nema statusa, pokušaj dobaviti iz shipment tabele
        if (empty($db_statuses) && !empty($shipment->status_code)) {
            $db_statuses = [
                (object)[
                    'status_id' => $shipment->status_code,
                    'status_date' => $shipment->updated_at
                ]
            ];
        }

        // Dobavi sve definicije statusa iz baze
        $all_status_definitions = $this->get_all_statuses();

        // Ako nemamo statuse, kreiramo podrazumevani timeline
        if (empty($db_statuses)) {
            $timeline_statuses = $this->create_default_timeline($shipment);
        } else {
            // Mapiraj stvarne statuse
            $timeline_statuses = $this->map_statuses($db_statuses, $all_status_definitions);
        }

        // Sortiranje statusa po datumu (najnoviji prvo)
        usort($timeline_statuses, function ($a, $b) {
            return strtotime($b['status_date']) - strtotime($a['status_date']);
        });

        // Najnoviji status za prikaz u zaglavlju
        $latest_status = !empty($timeline_statuses) ? $timeline_statuses[0] : null;

        // Početak HTML-a
?>
        <div class="dexpress-shipment-panel woocommerce-order-data">
            <?php if ($latest_status) :
                $status_class = 'status-' . $latest_status['status_type'];
                $status_badge_class = 'dexpress-status-badge dexpress-status-' . $latest_status['status_type'];
            ?>
                <!-- Zaglavlje sa trenutnim statusom -->
                <div class="dexpress-panel-header <?php echo esc_attr($status_class); ?>">
                    <h3>
                        <span class="<?php echo esc_attr($status_badge_class); ?>"><?php echo esc_html($latest_status['status_name']); ?></span>
                        <?php if ($shipment->is_test) : ?>
                            <span class="dexpress-test-badge"><?php esc_html_e('Test', 'd-express-woo'); ?></span>
                        <?php endif; ?>
                    </h3>
                </div>

                <!-- Informacije o pošiljci -->
                <div class="dexpress-panel-content">
                    <div class="dexpress-shipment-info-grid">
                        <div class="dexpress-info-item">
                            <span class="dexpress-label"><?php esc_html_e('Tracking broj', 'd-express-woo'); ?></span>
                            <div class="dexpress-value">
                                <?php if (!$shipment->is_test) : ?>
                                    <a href="https://www.dexpress.rs/rs/pracenje-posiljaka/<?php echo esc_attr($shipment->tracking_number); ?>"
                                        target="_blank" class="dexpress-tracking-link">
                                        <?php echo esc_html($shipment->tracking_number); ?>
                                        <span class="dashicons dashicons-external"></span>
                                    </a>
                                <?php else : ?>
                                    <span class="dexpress-tracking-number"><?php echo esc_html($shipment->tracking_number); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="dexpress-info-item">
                            <span class="dexpress-label"><?php esc_html_e('Reference ID', 'd-express-woo'); ?></span>
                            <div class="dexpress-value"><?php echo esc_html($shipment->reference_id); ?></div>
                        </div>

                        <div class="dexpress-info-item">
                            <span class="dexpress-label"><?php esc_html_e('Datum kreiranja', 'd-express-woo'); ?></span>
                            <div class="dexpress-value">
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at))); ?>
                            </div>
                        </div>

                        <div class="dexpress-info-item dexpress-actions-item">
                            <div class="dexpress-actions">
                                <?php
                                // Kreiraj URL za štampanje nalepnice
                                $nonce = wp_create_nonce('dexpress-download-label');
                                $label_url = admin_url('admin-ajax.php?action=dexpress_download_label&shipment_id=' . $shipment->id . '&nonce=' . $nonce);
                                ?>
                                <a href="<?php echo esc_url($label_url); ?>" class="button button-secondary" target="_blank">
                                    <span class="dashicons dashicons-printer"></span>
                                    <?php esc_html_e('Preuzmi nalepnicu', 'd-express-woo'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Horizontalni timeline -->
                <div class="dexpress-timeline-wrapper">
                    <?php if (!empty($timeline_statuses)) :
                        // Određivanje najvišeg tipa statusa za progress liniju
                        $highest_status_type = 'pending';
                        $progress_percentage = 0;

                        foreach ($timeline_statuses as $status) {
                            if ($status['status_type'] === 'completed' || $status['status_type'] === 'delivered') {
                                $highest_status_type = 'completed';
                                $progress_percentage = 100;
                                break;
                            } elseif ($status['status_type'] === 'current' || $status['status_type'] === 'transit') {
                                $highest_status_type = 'transit';
                                $progress_percentage = 65;
                            } elseif ($status['status_type'] === 'failed' || $status['status_type'] === 'returned') {
                                if ($highest_status_type !== 'transit') {
                                    $highest_status_type = 'failed';
                                    $progress_percentage = 50;
                                }
                            }
                        }

                        // Okretanje redosleda za horizontalni timeline (najstariji prvo)
                        $sorted_timeline_statuses = array_reverse($timeline_statuses);
                    ?>
                        <div class="dexpress-timeline">
                            <div class="dexpress-timeline-progress-bar">
                                <div class="dexpress-timeline-track">
                                    <div class="dexpress-timeline-progress dexpress-progress-<?php echo esc_attr($highest_status_type); ?>"
                                        style="width: <?php echo esc_attr($progress_percentage); ?>%"></div>
                                </div>
                            </div>

                            <div class="dexpress-timeline-items">
                                <?php foreach ($sorted_timeline_statuses as $index => $status) :
                                    // Formatiranje datuma
                                    $date_obj = new DateTime($status['status_date']);
                                    $formatted_date = $date_obj->format('j. F Y.');
                                    $formatted_time = $date_obj->format('H:i');

                                    $status_class = 'dexpress-timeline-step status-' . esc_attr($status['status_type']);

                                    // Dodaj klasu ako je status već dostignut
                                    $completed_class = '';
                                    if (
                                        $index < count($sorted_timeline_statuses) - 1 ||
                                        in_array($status['status_type'], ['completed', 'delivered'])
                                    ) {
                                        $completed_class = ' step-completed';
                                    }
                                ?>
                                    <div class="<?php echo esc_attr($status_class . $completed_class); ?>"
                                        style="--step-index: <?php echo esc_attr($index); ?>; --total-steps: <?php echo esc_attr(count($sorted_timeline_statuses)); ?>">
                                        <div class="dexpress-step-marker">
                                            <span class="dexpress-step-icon dashicons <?php echo esc_attr($status['icon']); ?>"></span>
                                        </div>
                                        <div class="dexpress-step-content">
                                            <div class="dexpress-step-date">
                                                <span class="dexpress-date"><?php echo esc_html($formatted_date); ?></span>
                                                <span class="dexpress-time"><?php echo esc_html($formatted_time); ?></span>
                                            </div>
                                            <div class="dexpress-step-title"><?php echo esc_html($status['status_name']); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="dexpress-timeline-empty">
                            <div class="dexpress-empty-message">
                                <span class="dashicons dashicons-info-outline"></span>
                                <p><?php esc_html_e('Još uvek nema informacija o statusu pošiljke.', 'd-express-woo'); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
<?php
    }
    /**
     * Simulacija statusa za test mode
     * 
     * @param int $shipment_id ID pošiljke
     * @return bool True ako je simulacija uspešna
     */
    public function simulate_test_mode_statuses($shipment_id)
    {
        global $wpdb;

        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
            $shipment_id
        ));

        if (!$shipment || !$shipment->is_test) {
            return false;
        }

        $created_time = strtotime($shipment->created_at);
        $current_time = time();
        $elapsed_hours = ($current_time - $created_time) / 3600;
        $statuses_added = false;

        // Simuliramo progresivne statuse na osnovu proteklog vremena
        if ($elapsed_hours > 2 && !$this->status_exists($shipment->shipment_id, '0')) {
            // Čeka na preuzimanje - 2h nakon kreiranja
            $this->add_simulated_status($shipment, '0', date('Y-m-d H:i:s', $created_time + 7200));
            $statuses_added = true;
        }

        if ($elapsed_hours > 8 && !$this->status_exists($shipment->shipment_id, '3')) {
            // Pošiljka preuzeta - 8h nakon kreiranja
            $this->add_simulated_status($shipment, '3', date('Y-m-d H:i:s', $created_time + 28800));
            $statuses_added = true;
        }

        if ($elapsed_hours > 24 && !$this->status_exists($shipment->shipment_id, '4')) {
            // Zadužena za isporuku - 24h nakon kreiranja
            $this->add_simulated_status($shipment, '4', date('Y-m-d H:i:s', $created_time + 86400));
            $statuses_added = true;
        }

        if ($elapsed_hours > 48 && !$this->status_exists($shipment->shipment_id, '1')) {
            // Isporučena - 48h nakon kreiranja
            $this->add_simulated_status($shipment, '1', date('Y-m-d H:i:s', $created_time + 172800));
            $statuses_added = true;
        }

        return $statuses_added;
    }
    /**
     * Provera da li postoji određeni status
     * 
     * @param string $shipment_code Kod pošiljke
     * @param string $status_id ID statusa
     * @return bool True ako status postoji
     */
    private function status_exists($shipment_code, $status_id)
    {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_statuses 
        WHERE shipment_code = %s AND status_id = %s",
            $shipment_code,
            $status_id
        ));

        return ($count > 0);
    }

    /**
     * Dodavanje simuliranog statusa u bazu
     * 
     * @param object $shipment Podaci o pošiljci
     * @param string $status_id ID statusa
     * @param string $status_date Datum statusa
     */
    private function add_simulated_status($shipment, $status_id, $status_date)
    {
        global $wpdb;

        $status_data = array(
            'notification_id' => 'SIM' . time() . rand(1000, 9999),
            'shipment_code' => $shipment->shipment_id,
            'reference_id' => $shipment->reference_id,
            'status_id' => $status_id,
            'status_date' => $status_date,
            'raw_data' => json_encode(array(
                'simulated' => true,
                'shipment_id' => $shipment->shipment_id,
                'status_id' => $status_id
            )),
            'is_processed' => 1,
            'created_at' => current_time('mysql')
        );

        $wpdb->insert($wpdb->prefix . 'dexpress_statuses', $status_data);
        dexpress_log('Simulirani status dodat: ' . $status_id . ' za pošiljku ' . $shipment->shipment_id, 'debug');

        // Ako je to najnoviji status (po statusDate), ažuriraj i pošiljku
        $wpdb->update(
            $wpdb->prefix . 'dexpress_shipments',
            array(
                'status_code' => $status_id,
                'status_description' => dexpress_get_status_name($status_id),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $shipment->id)
        );

        dexpress_log('Pošiljka ažurirana sa statusom: ' . $status_id, 'debug');
    }
    /**
     * AJAX handler za osvežavanje statusa pošiljke.
     */
    public function ajax_refresh_status()
    {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-refresh-status')) {
            wp_send_json_error(array('message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')));
            return;
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')));
            return;
        }

        // Provera ID-a pošiljke
        if (!isset($_POST['shipment_id']) || empty($_POST['shipment_id'])) {
            wp_send_json_error(array('message' => __('ID pošiljke je obavezan.', 'd-express-woo')));
            return;
        }

        $shipment_id = intval($_POST['shipment_id']);
        dexpress_log('Pokrenuto osvežavanje statusa za pošiljku ID: ' . $shipment_id, 'debug');

        try {
            global $wpdb;
            $shipment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
                $shipment_id
            ));

            if (!$shipment) {
                wp_send_json_error(array('message' => __('Pošiljka nije pronađena.', 'd-express-woo')));
                return;
            }

            $result = false;

            // U test režimu, simuliraj statuse
            if ($shipment->is_test) {
                dexpress_log('Pokrenuta simulacija statusa za test pošiljku ID: ' . $shipment_id, 'debug');
                $result = $this->simulate_test_mode_statuses($shipment_id);

                if ($result) {
                    dexpress_log('Simulacija statusa uspešna za pošiljku ID: ' . $shipment_id, 'debug');
                    wp_send_json_success(array('message' => __('Status pošiljke je uspešno ažuriran (test režim).', 'd-express-woo')));
                } else {
                    dexpress_log('Nema novih simuliranih statusa za pošiljku ID: ' . $shipment_id, 'debug');
                    wp_send_json_success(array('message' => __('Pošiljka je već u najnovijem statusu (test režim).', 'd-express-woo')));
                }
                return;
            }

            // U produkcijskom režimu, pokušaj sinhronizaciju
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/services/class-dexpress-shipment-service.php';
            $shipment_service = new D_Express_Shipment_Service();
            $result = $shipment_service->sync_shipment_status($shipment_id);

            if (is_wp_error($result)) {
                dexpress_log('Greška pri sinhronizaciji statusa: ' . $result->get_error_message(), 'error');
                wp_send_json_error(array('message' => $result->get_error_message()));
            } else {
                dexpress_log('Uspešna sinhronizacija statusa za pošiljku ID: ' . $shipment_id, 'debug');
                wp_send_json_success(array('message' => __('Status pošiljke je uspešno ažuriran.', 'd-express-woo')));
            }
        } catch (Exception $e) {
            dexpress_log('Izuzetak pri ažuriranju statusa: ' . $e->getMessage(), 'error');
            wp_send_json_error(array(
                'message' => __('Došlo je do greške prilikom ažuriranja statusa.', 'd-express-woo'),
                'error' => $e->getMessage()
            ));
        }
    }
}
