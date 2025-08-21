<?php

/**
 * D Express Order Timeline Class
 * 
 * Klasa za prikaz timeline-a statusa pošiljke u order metabox-u
 */

defined('ABSPATH') || exit;

class D_Express_Order_Timeline
{
    /**
     * Inicijalizacija
     */
    public function init()
    {
        // Dodavanje metabox-a za timeline
        add_action('add_meta_boxes', array($this, 'add_timeline_metabox'));

        // Registrovanje stilova za admin
        add_action('admin_enqueue_scripts', array($this, 'register_timeline_assets'));

        // NAPOMENA: AJAX handler je sada u D_Express_Admin_Ajax klasi
    }

    /**
     * Dodavanje metabox-a za timeline
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
     * Dobija tracking identifikator za shipment
     */
    private function get_tracking_identifier($shipment)
    {
        // Prvo pokušaj package code
        global $wpdb;
        $package_code = $wpdb->get_var($wpdb->prepare(
            "SELECT package_code FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d LIMIT 1",
            $shipment->id
        ));

        if ($package_code) {
            return $package_code;
        }

        // Fallback na reference_id
        return $shipment->reference_id ?: ('REF' . $shipment->id);
    }

    /**
     * Registracija stilova i skripti za timeline
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

        wp_enqueue_style(
            'dexpress-timeline-css',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-admin.css',
            array('woocommerce_admin_styles'),
            DEXPRESS_WOO_VERSION
        );

        wp_register_script(
            'dexpress-timeline-js',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-timeline.js',
            array('jquery'),
            DEXPRESS_WOO_VERSION,
            true
        );

        wp_localize_script('dexpress-timeline-js', 'dexpressTimelineL10n', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dexpress-refresh-status'),
            'refreshing' => __('Osvežavanje...', 'd-express-woo'),
            'refreshStatus' => __('Osveži status', 'd-express-woo'),
            'errorMessage' => __('Došlo je do greške prilikom osvežavanja statusa.', 'd-express-woo'),
            'successMessage' => __('Status pošiljke je uspešno ažuriran.', 'd-express-woo')
        ));

        wp_enqueue_script('dexpress-timeline-js');
    }

    /**
     * Definisanje predefinisanih statusa za timeline
     */
    private function get_predefined_statuses()
    {
        // Za produkciju - prikaži sve moguće statuse iz baze
        $all_statuses = dexpress_get_all_status_codes();

        $timeline_statuses = [];

        // Uvek počni sa "kreirana"
        $timeline_statuses[] = [
            'id' => 'created',
            'name' => 'Pošiljka kreirana',
            'group' => 'created',
            'icon' => 'dashicons-plus-alt'
        ];

        // Dodaj samo važne statuse za timeline prikaz
        $important_statuses = ['0', '3', '4', '1']; // Osnovna putanja

        foreach ($important_statuses as $status_id) {
            if (isset($all_statuses[$status_id])) {
                $timeline_statuses[] = [
                    'id' => $status_id,
                    'name' => $all_statuses[$status_id]['name'],
                    'group' => $all_statuses[$status_id]['group'],
                    'icon' => $all_statuses[$status_id]['icon']
                ];
            }
        }

        return $timeline_statuses;
    }

    /**
     * Render timeline-a
     */
    public function render_timeline($post_or_order)
    {
        // HPOS kompatibilnost - uvek dobijamo WC_Order objekat
        if (is_a($post_or_order, 'WP_Post')) {
            $order = wc_get_order($post_or_order->ID);
        } else {
            $order = $post_or_order;
        }

        if (!$order || is_wp_error($order)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Narudžbina nije pronađena.', 'd-express-woo') . '</p></div>';
            return;
        }

        $order_id = $order->get_id();
        global $wpdb;

        // Dobij SVE pošiljke za order
        $shipments = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, sl.name as location_name 
         FROM {$wpdb->prefix}dexpress_shipments s
         LEFT JOIN {$wpdb->prefix}dexpress_sender_locations sl ON s.sender_location_id = sl.id
         WHERE s.order_id = %d 
         ORDER BY s.split_index ASC, s.created_at ASC",
            $order_id
        ));

        if (empty($shipments)) {
            echo '<div class="dexpress-panel-empty">';
            echo '<div class="dexpress-empty-message">';
            echo '<span class="dashicons dashicons-info-outline"></span>';
            echo '<p>' . esc_html__('Za ovu narudžbinu još uvek ne postoji D Express pošiljka.', 'd-express-woo') . '</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        // Prikaz svih pošiljki
        echo '<div class="dexpress-multiple-shipments-container">';

        // Header sa ukupnim informacijama
        $total_shipments = count($shipments);
        $is_multiple = $total_shipments > 1;

        echo '<div class="dexpress-shipments-header">';
        if ($is_multiple) {
            echo '<h3>' . sprintf(__('D Express Pošiljke (%d)', 'd-express-woo'), $total_shipments) . '</h3>';
            echo '<p class="description">' . __('Ova narudžbina je podeljena na više pošiljki sa različitih lokacija.', 'd-express-woo') . '</p>';
        } else {
            echo '<h3>' . __('D Express Pošiljka', 'd-express-woo') . '</h3>';
        }
        echo '</div>';

        // Prikaz svake pošiljke
        foreach ($shipments as $index => $shipment) {
            $this->render_single_shipment_timeline($shipment, $index + 1, $total_shipments);
        }

        echo '</div>';
    }

    /**
     * ISPRAVLJENA metoda za renderovanje timeline za jednu pošiljku
     */
    private function render_single_shipment_timeline($shipment, $shipment_number, $total_shipments)
    {
        global $wpdb;

        // Prvo dobij pakete
        $packages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d ORDER BY package_index ASC",
            $shipment->id
        ));

        // GLAVNA ISPRAVKA: Dobij statuse kroz package_id veze
        $db_statuses = [];
        if (!empty($packages)) {
            $package_ids = array_column($packages, 'id');
            $placeholders = implode(',', array_fill(0, count($package_ids), '%d'));

            $db_statuses = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, p.package_code FROM {$wpdb->prefix}dexpress_statuses s
                 INNER JOIN {$wpdb->prefix}dexpress_packages p ON s.package_id = p.id
                 WHERE s.package_id IN ($placeholders)
                 ORDER BY s.status_date ASC",
                ...$package_ids
            ));

            dexpress_log('[TIMELINE DEBUG] Shipment ' . $shipment->id . ' ima ' . count($packages) . ' paketa i ' . count($db_statuses) . ' statusa', 'debug');
        }

        $package_count = count($packages);
        $is_multiple = $total_shipments > 1;

        // Package info logic
        if ($package_count === 1 && $total_shipments === 1) {
            $package_info = __('1 paket', 'd-express-woo') . ' (' . $packages[0]->package_code . ')';
            $tracking_display = $this->get_tracking_identifier($shipment);
        } else if ($package_count === 1 && $total_shipments > 1) {
            $package_info = __('1 paket', 'd-express-woo');
            $tracking_display = $this->get_tracking_identifier($shipment);
        } else if ($package_count > 1) {
            $package_codes = array_map(function ($pkg) {
                return $pkg->package_code;
            }, $packages);
            $package_info = sprintf(__('%d paketa (%s)', 'd-express-woo'), $package_count, implode(', ', $package_codes));
            $tracking_display = sprintf(__('Shipment #%s', 'd-express-woo'), $shipment->id);
        } else {
            $package_info = __('Nema paketa', 'd-express-woo');
            $tracking_display = $this->get_tracking_identifier($shipment) ?: $shipment->reference_id;
        }

        // Predefined statuses
        $predefined_statuses = $this->get_predefined_statuses();

        // Status map
        $status_map = [];
        if (!empty($db_statuses)) {
            foreach ($db_statuses as $status) {
                $status_map[$status->status_id] = [
                    'date' => $status->status_date,
                    'reached' => true
                ];
            }
        }

        if (!isset($status_map['created'])) {
            $status_map['created'] = [
                'date' => $shipment->created_at,
                'reached' => true
            ];
        }

        // Current phase calculation
        $current_phase = 0;
        $current_status_code = $shipment->status_code;

        if (empty($current_status_code) && !empty($db_statuses)) {
            $last_status = end($db_statuses);
            $current_status_code = $last_status->status_id;
        }

        for ($i = 0; $i < count($predefined_statuses); $i++) {
            $status = $predefined_statuses[$i];
            if (
                isset($status_map[$status['id']]) ||
                ($status['id'] === $current_status_code) ||
                ($status['group'] === dexpress_get_status_group($current_status_code))
            ) {
                $current_phase = max($current_phase, $i);
            }
        }

        // Location info
        $location_name = '';
        if ($shipment->sender_location_id) {
            $locations_service = D_Express_Sender_Locations::get_instance();
            $location = $locations_service->get_location($shipment->sender_location_id);
            $location_name = $location ? $location->name : 'Lokacija #' . $shipment->sender_location_id;
        }

?>
        <div class="dexpress-single-shipment-timeline <?php echo $is_multiple ? 'is-multiple' : 'is-single'; ?>">

            <?php if ($is_multiple): ?>
                <div class="dexpress-shipment-header">
                    <h4>
                        <?php printf(__('Pošiljka %d od %d', 'd-express-woo'), $shipment_number, $total_shipments); ?>
                        <?php if ($location_name): ?>
                            <span class="location-badge"><?php echo esc_html($location_name); ?></span>
                        <?php endif; ?>
                    </h4>
                </div>
            <?php endif; ?>

            <!-- Informacije o pošiljci -->
            <div class="dexpress-shipment-info">
                <div class="dexpress-info-grid">
                    <div class="dexpress-info-item">
                        <span class="dexpress-info-label"><?php esc_html_e('Tracking', 'd-express-woo'); ?></span>
                        <span class="dexpress-info-value">
                            <strong><?php echo esc_html($tracking_display); ?></strong>
                        </span>
                    </div>

                    <div class="dexpress-info-item">
                        <span class="dexpress-info-label"><?php esc_html_e('Paketi', 'd-express-woo'); ?></span>
                        <span class="dexpress-info-value"><?php echo esc_html($package_info); ?></span>
                    </div>

                    <div class="dexpress-info-item">
                        <span class="dexpress-info-label"><?php esc_html_e('Status', 'd-express-woo'); ?></span>
                        <span class="dexpress-info-value"><?php echo esc_html($shipment->status_description ?: 'U obradi'); ?></span>
                    </div>

                    <div class="dexpress-info-item">
                        <span class="dexpress-info-label"><?php esc_html_e('Kreirana', 'd-express-woo'); ?></span>
                        <span class="dexpress-info-value"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($shipment->created_at))); ?></span>
                    </div>

                    <div class="dexpress-info-item">
                        <button type="button" class="button dexpress-refresh-status"
                            data-shipment-id="<?php echo esc_attr($shipment->id); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('dexpress-refresh-status')); ?>">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Osveži', 'd-express-woo'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Package Details za multiple packages -->
            <?php if ($package_count > 1): ?>
                <div class="dexpress-packages-details">
                    <h5><?php esc_html_e('Detalji paketa:', 'd-express-woo'); ?></h5>
                    <div class="dexpress-packages-grid">
                        <?php foreach ($packages as $package): ?>
                            <div class="dexpress-package-item">
                                <span class="package-code"><?php echo esc_html($package->package_code); ?></span>
                                <span class="package-mass"><?php echo esc_html($package->mass); ?>g</span>
                                <?php if ($package->package_index && $package->total_packages): ?>
                                    <span class="package-index"><?php echo esc_html($package->package_index . '/' . $package->total_packages); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Timeline -->
            <div class="dexpress-timeline-wrapper">
                <div class="dexpress-progress-track">
                    <div class="dexpress-progress-bar" style="width: <?php echo esc_attr(min(100, ($current_phase / (count($predefined_statuses) - 1)) * 100)); ?>%"></div>
                </div>

                <div class="dexpress-timeline-steps">
                    <?php foreach ($predefined_statuses as $i => $status):
                        $is_reached = $i <= $current_phase;
                        $status_class = $is_reached ? 'dexpress-step-reached' : 'dexpress-step-pending';
                        $status_date = '';

                        if (isset($status_map[$status['id']])) {
                            $status_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($status_map[$status['id']]['date']));
                        }
                    ?>
                        <div class="dexpress-timeline-step <?php echo esc_attr($status_class); ?>">
                            <div class="dexpress-step-marker">
                                <span class="dashicons <?php echo esc_attr($status['icon']); ?>"></span>
                            </div>
                            <div class="dexpress-step-content">
                                <div class="dexpress-step-title"><?php echo esc_html($status['name']); ?></div>
                                <?php if (!empty($status_date)): ?>
                                    <div class="dexpress-step-date"><?php echo esc_html($status_date); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div> <!-- End single shipment timeline -->

<?php
    }

    /**
     * ISPRAVLJENA simulacija statusa za test mode
     */
    public function simulate_test_mode_statuses($shipment_id)
    {
        global $wpdb;

        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
            $shipment_id
        ));

        if (!$shipment || !$shipment->is_test) {
            dexpress_log('[SIMULATION] Pošiljka nije test ili ne postoji. ID: ' . $shipment_id, 'debug');
            return false;
        }

        // Proveri da li postoje paketi
        $packages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d",
            $shipment->id
        ));

        if (empty($packages)) {
            dexpress_log('[SIMULATION ERROR] Shipment ' . $shipment_id . ' nema pakete!', 'error');
            return false;
        }

        dexpress_log('[SIMULATION] Shipment ' . $shipment_id . ' ima ' . count($packages) . ' paketa', 'debug');

        $created_time = strtotime($shipment->created_at);
        $current_time = current_time('timestamp');
        $elapsed_hours = ($current_time - $created_time) / 3600; // PROMENJENO: u sate

        // Forsiraj pozitivno vreme za test:
        if ($elapsed_hours < 0) {
            $elapsed_hours = abs($elapsed_hours);
            dexpress_log('[SIMULATION] Timezone greška - forsiram pozitivno vreme: ' . $elapsed_hours, 'debug');
        }

        dexpress_log('[SIMULATION] Pošiljka kreirana: ' . $shipment->created_at . ', Prošlo sati: ' . $elapsed_hours, 'debug');

        $statuses_added = false;

        // REALISTIČNA simulacija - u satima
        $simulation_statuses = [
            ['hours' => 2, 'id' => '0', 'name' => 'Čeka na preuzimanje'],      // 2 sata
            ['hours' => 6, 'id' => '3', 'name' => 'Pošiljka preuzeta'],        // 6 sati  
            ['hours' => 24, 'id' => '4', 'name' => 'Pošiljka zadužena'],       // 1 dan
            ['hours' => 48, 'id' => '1', 'name' => 'Pošiljka isporučena']      // 2 dana
        ];

        foreach ($simulation_statuses as $sim_status) {
            dexpress_log('[SIMULATION] Proveravam status: ' . $sim_status['id'] . ', potrebno sati: ' . $sim_status['hours'], 'debug');

            if ($elapsed_hours >= $sim_status['hours'] && !$this->status_exists_for_shipment($shipment->id, $sim_status['id'])) {
                dexpress_log('[SIMULATION] Dodajem status: ' . $sim_status['id'], 'debug');
                $this->add_simulated_status(
                    $shipment,
                    $sim_status['id'],
                    date('Y-m-d H:i:s', $created_time + ($sim_status['hours'] * 3600)) // PROMENJENO: * 3600
                );
                $statuses_added = true;
            } else {
                dexpress_log('[SIMULATION] Preskačem status: ' . $sim_status['id'] . ' (vreme ili već postoji)', 'debug');
            }
        }

        dexpress_log('[SIMULATION] Statusi dodani: ' . ($statuses_added ? 'Da' : 'Ne'), 'debug');
        return $statuses_added;
    }

    /**
     * NOVA metoda - provera statusa za ceo shipment
     */
    private function status_exists_for_shipment($shipment_id, $status_id)
    {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_statuses s
             INNER JOIN {$wpdb->prefix}dexpress_packages p ON s.package_id = p.id
             WHERE p.shipment_id = %d AND s.status_id = %s",
            $shipment_id,
            $status_id
        ));

        return ($count > 0);
    }

    /**
     * Metoda za manuelno pokretanje simulacije (poziva se iz AJAX)
     */
    public function manually_trigger_simulation($shipment_id)
    {
        if (!dexpress_is_test_mode()) {
            return new WP_Error('not_test_mode', 'Simulacija je dostupna samo u test režimu');
        }

        dexpress_log('[SIMULATION] Manuelno pokretanje simulacije za shipment ' . $shipment_id, 'debug');

        $result = $this->simulate_test_mode_statuses($shipment_id);

        if ($result) {
            return array('message' => 'Simulacija statusa je uspešno pokrenuta');
        } else {
            return new WP_Error('simulation_failed', 'Simulacija nije uspela - proverite log za detalje');
        }
    }

    /**
     * ISPRAVLJENA metoda za dodavanje simuliranih statusa
     */
    private function add_simulated_status($shipment, $status_id, $status_date)
    {
        global $wpdb;

        // Dobij pakete za pošiljku
        $packages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d",
            $shipment->id
        ));

        if (empty($packages)) {
            dexpress_log('[SIMULATION ERROR] Nema paketa za shipment ' . $shipment->id, 'error');
            return false;
        }

        dexpress_log('[SIMULATION] Dodajem status ' . $status_id . ' za ' . count($packages) . ' paketa', 'debug');

        // Dodaj status za svaki paket
        foreach ($packages as $package) {

            // Proveri da li status već postoji
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}dexpress_statuses 
                 WHERE package_id = %d AND status_id = %s",
                $package->id,
                $status_id
            ));

            if ($existing) {
                dexpress_log('[SIMULATION] Status ' . $status_id . ' već postoji za paket ' . $package->id, 'debug');
                continue;
            }

            // KLJUČNA ISPRAVKA: shipment_code = package_code (kako D Express šalje)
            $status_data = [
                'notification_id' => 'SIM_' . time() . '_' . rand(1000, 9999) . '_' . $package->id,
                'shipment_code' => $package->package_code, // ← OVO JE KLJUČNO
                'package_id' => $package->id,
                'reference_id' => $shipment->reference_id,
                'status_id' => $status_id,
                'status_date' => $status_date,
                'raw_data' => json_encode(['simulated' => true, 'shipment_id' => $shipment->id]),
                'is_processed' => 1,
                'created_at' => current_time('mysql')
            ];

            $result = $wpdb->insert(
                $wpdb->prefix . 'dexpress_statuses',
                $status_data,
                ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s']
            );

            if ($result) {
                dexpress_log('[SIMULATION] ✓ Status ' . $status_id . ' dodat za paket ' . $package->package_code, 'debug');

                // Ažuriraj package
                $wpdb->update(
                    $wpdb->prefix . 'dexpress_packages',
                    [
                        'current_status_id' => $status_id,
                        'current_status_name' => dexpress_get_status_name($status_id),
                        'status_updated_at' => $status_date,
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $package->id]
                );
            } else {
                dexpress_log('[SIMULATION ERROR] Neuspešno dodavanje statusa: ' . $wpdb->last_error, 'error');
            }
        }

        // Ažuriraj shipment
        $wpdb->update(
            $wpdb->prefix . 'dexpress_shipments',
            [
                'status_code' => $status_id,
                'status_description' => dexpress_get_status_name($status_id),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $shipment->id]
        );

        // Dodaj napomenu u narudžbinu
        $order = wc_get_order($shipment->order_id);
        if ($order) {
            $note = sprintf(
                'D Express status ažuriran: %s (simulirano za %d paketa)',
                dexpress_get_status_name($status_id),
                count($packages)
            );
            $order->add_order_note($note);
        }

        return true;
    }
}
