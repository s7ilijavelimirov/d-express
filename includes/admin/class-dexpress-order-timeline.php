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

        if (!$screen || !in_array($hook, $allowed_screens) || 
           ($screen->post_type !== 'shop_order' && $screen->id !== 'woocommerce_page_wc-orders')) {
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
            'testSimulation' => __('Test simulacija', 'd-express-woo'),
            'errorMessage' => __('Došlo je do greške.', 'd-express-woo'),
            'successMessage' => __('Status ažuriran.', 'd-express-woo')
        ));

        wp_enqueue_script('dexpress-timeline-js');
    }

    /**
     * Optimizovani timeline statusi
     */
    private function get_timeline_statuses()
    {
        return [
            [
                'id' => 'created',
                'name' => 'Pošiljka kreirana',
                'group' => 'created',
                'icon' => 'dashicons-plus-alt',
                'description' => 'Pošiljka je registrovana u sistemu'
            ],
            [
                'id' => '0',
                'name' => 'Čeka preuzimanje',
                'group' => 'pending',
                'icon' => 'dashicons-clock',
                'description' => 'Čeka da je kurirska služba preuzme'
            ],
            [
                'id' => '3',
                'name' => 'Preuzeta od pošiljaoca',
                'group' => 'transit',
                'icon' => 'dashicons-airplane',
                'description' => 'Pošiljka je preuzeta i u transportu'
            ],
            [
                'id' => '4',
                'name' => 'Na putu za isporuku',
                'group' => 'out_for_delivery',
                'icon' => 'dashicons-arrow-right-alt',
                'description' => 'Kuriral je izašao na isporuku'
            ],
            [
                'id' => '1',
                'name' => 'Isporučena',
                'group' => 'delivered',
                'icon' => 'dashicons-yes-alt',
                'description' => 'Pošiljka je uspešno isporučena'
            ]
        ];
    }

    /**
     * Render timeline-a
     */
    public function render_timeline($post_or_order)
    {
        // HPOS kompatibilnost
        if (is_a($post_or_order, 'WP_Post')) {
            $order = wc_get_order($post_or_order->ID);
        } else {
            $order = $post_or_order;
        }

        if (!$order || is_wp_error($order)) {
            echo '<div class="notice notice-error inline"><p>' . 
                 esc_html__('Narudžbina nije pronađena.', 'd-express-woo') . '</p></div>';
            return;
        }

        $order_id = $order->get_id();
        global $wpdb;

        // Dobij sve pošiljke za order
        $shipments = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, sl.name as location_name 
             FROM {$wpdb->prefix}dexpress_shipments s
             LEFT JOIN {$wpdb->prefix}dexpress_sender_locations sl ON s.sender_location_id = sl.id
             WHERE s.order_id = %d 
             ORDER BY s.split_index ASC, s.created_at ASC",
            $order_id
        ));

        if (empty($shipments)) {
            $this->render_empty_state();
            return;
        }

        $this->render_shipments_container($shipments);
    }

    /**
     * Prikaz kada nema pošiljki
     */
    private function render_empty_state()
    {
        echo '<div class="dexpress-panel-empty">';
        echo '<div class="dexpress-empty-message">';
        echo '<span class="dashicons dashicons-info-outline"></span>';
        echo '<p>' . esc_html__('Za ovu narudžbinu još uvek ne postoji D Express pošiljka.', 'd-express-woo') . '</p>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Prikaz kontejnera sa pošiljkama
     */
    private function render_shipments_container($shipments)
    {
        $total_shipments = count($shipments);
        $is_multiple = $total_shipments > 1;

        echo '<div class="dexpress-multiple-shipments-container">';
        
        // Header
        echo '<div class="dexpress-shipments-header">';
        if ($is_multiple) {
            echo '<h3>' . sprintf(__('D Express Pošiljke (%d)', 'd-express-woo'), $total_shipments) . '</h3>';
            echo '<p class="description">' . __('Ova narudžbina je podeljena na više pošiljki.', 'd-express-woo') . '</p>';
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
     * OPTIMIZOVANA metoda za renderovanje timeline jedne pošiljke
     */
    private function render_single_shipment_timeline($shipment, $shipment_number, $total_shipments)
    {
        global $wpdb;

        // Dobij pakete
        $packages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d ORDER BY package_index ASC",
            $shipment->id
        ));

        // ISPRAVLJEN SQL QUERY - samo processed statusi
        $db_statuses = [];
        if (!empty($packages)) {
            $package_ids = array_column($packages, 'id');
            $placeholders = implode(',', array_fill(0, count($package_ids), '%d'));

            $db_statuses = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT s.status_id, s.status_date, s.created_at
                 FROM {$wpdb->prefix}dexpress_statuses s
                 WHERE s.package_id IN ($placeholders) AND s.is_processed = 1
                 ORDER BY s.status_date ASC",
                ...$package_ids
            ));
        }

        // Generiši status mapu
        $status_map = ['created' => ['date' => $shipment->created_at, 'reached' => true]];
        foreach ($db_statuses as $status) {
            $status_map[$status->status_id] = [
                'date' => $status->status_date,
                'reached' => true
            ];
        }

        // Timeline statusi
        $timeline_statuses = $this->get_timeline_statuses();
        
        // ISPRAVLJEN current_phase calculation
        $current_phase = 0;
        if (!empty($db_statuses)) {
            $latest_status = end($db_statuses);
            foreach ($timeline_statuses as $i => $status) {
                if ($status['id'] === $latest_status->status_id) {
                    $current_phase = $i;
                    break;
                }
            }
        }

        $package_count = count($packages);
        $is_multiple = $total_shipments > 1;
        $tracking_display = $this->get_tracking_identifier($shipment);

        // OPTIMIZOVAN package info
        if ($package_count === 1 && !empty($packages)) {
            $package_info = __('1 paket', 'd-express-woo') . ' (' . $packages[0]->package_code . ')';
        } elseif ($package_count > 1) {
            $package_codes = array_slice(array_column($packages, 'package_code'), 0, 3);
            $more = $package_count > 3 ? '...' : '';
            $package_info = sprintf(__('%d paketa (%s%s)', 'd-express-woo'), 
                $package_count, implode(', ', $package_codes), $more);
        } else {
            $package_info = __('Nema paketa', 'd-express-woo');
        }

        // Location info
        $location_name = $shipment->location_name ?: '';

        $this->render_shipment_html($shipment, $shipment_number, $is_multiple, $location_name, 
                                    $tracking_display, $package_info, $packages, $timeline_statuses, 
                                    $status_map, $current_phase);
    }

    /**
     * HTML renderovanje pošiljke
     */
    private function render_shipment_html($shipment, $shipment_number, $is_multiple, $location_name,
                                         $tracking_display, $package_info, $packages, $timeline_statuses,
                                         $status_map, $current_phase)
    {
        ?>
        <div class="dexpress-single-shipment-timeline <?php echo $is_multiple ? 'is-multiple' : 'is-single'; ?>">

            <?php if ($is_multiple): ?>
                <div class="dexpress-shipment-header">
                    <h4>
                        <?php printf(__('Pošiljka %d od %d', 'd-express-woo'), $shipment_number, count($packages)); ?>
                        <?php if ($location_name): ?>
                            <span class="location-badge"><?php echo esc_html($location_name); ?></span>
                        <?php endif; ?>
                    </h4>
                </div>
            <?php endif; ?>

            <!-- Info grid -->
            <div class="dexpress-shipment-info">
                <div class="dexpress-info-grid">
                    <div class="dexpress-info-item">
                        <span class="dexpress-info-label"><?php esc_html_e('Tracking', 'd-express-woo'); ?></span>
                        <span class="dexpress-info-value"><strong><?php echo esc_html($tracking_display); ?></strong></span>
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

                    <div class="dexpress-info-item dexpress-action-buttons">
                        <button type="button" class="button dexpress-refresh-status"
                            data-shipment-id="<?php echo esc_attr($shipment->id); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('dexpress-refresh-status')); ?>">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Osveži', 'd-express-woo'); ?>
                        </button>
                        
                        <?php if (dexpress_is_test_mode() && $shipment->is_test): ?>
                        <button type="button" class="button button-secondary dexpress-test-simulation"
                            data-shipment-id="<?php echo esc_attr($shipment->id); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('dexpress-test-simulation')); ?>">
                            <span class="dashicons dashicons-admin-tools"></span>
                            <?php esc_html_e('Test simulacija', 'd-express-woo'); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Timeline -->
            <div class="dexpress-timeline-wrapper">
                <div class="dexpress-progress-track">
                    <div class="dexpress-progress-bar" 
                         style="width: <?php echo esc_attr(min(100, ($current_phase / (count($timeline_statuses) - 1)) * 100)); ?>%">
                    </div>
                </div>

                <div class="dexpress-timeline-steps">
                    <?php foreach ($timeline_statuses as $i => $status):
                        $is_reached = isset($status_map[$status['id']]);
                        $is_current = ($i == $current_phase);
                        $status_class = $is_reached ? 'dexpress-step-reached' : 'dexpress-step-pending';
                        if ($is_current) $status_class .= ' dexpress-step-current';
                        
                        $status_date = '';
                        if (isset($status_map[$status['id']])) {
                            $status_date = date_i18n(
                                get_option('date_format') . ' ' . get_option('time_format'), 
                                strtotime($status_map[$status['id']]['date'])
                            );
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
                                <?php if (!empty($status['description'])): ?>
                                    <div class="dexpress-step-description"><?php echo esc_html($status['description']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Package details za multiple packages -->
            <?php if (count($packages) > 1): ?>
                <div class="dexpress-packages-details">
                    <h5><?php esc_html_e('Detalji paketa:', 'd-express-woo'); ?></h5>
                    <div class="dexpress-packages-grid">
                        <?php foreach ($packages as $package): ?>
                            <div class="dexpress-package-item">
                                <span class="package-code"><?php echo esc_html($package->package_code); ?></span>
                                <span class="package-mass"><?php echo esc_html($package->mass); ?>g</span>
                                <span class="package-status"><?php echo esc_html($package->current_status_name ?: 'U obradi'); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Dobija tracking identifikator
     */
    private function get_tracking_identifier($shipment)
    {
        global $wpdb;
        $package_code = $wpdb->get_var($wpdb->prepare(
            "SELECT package_code FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d LIMIT 1",
            $shipment->id
        ));

        return $package_code ?: ($shipment->reference_id ?: ('REF' . $shipment->id));
    }

    /**
     * DIREKTNA SIMULACIJA - piše direktno u bazu (za test)
     */
    public function simulate_realistic_statuses($shipment_id)
    {
        if (!dexpress_is_test_mode()) {
            return new WP_Error('not_test_mode', 'Simulacija dostupna samo u test režimu');
        }

        global $wpdb;
        
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d AND is_test = 1",
            $shipment_id
        ));

        if (!$shipment) {
            return new WP_Error('invalid_shipment', 'Test pošiljka nije pronađena');
        }

        // Dobij pakete
        $packages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d",
            $shipment->id
        ));

        if (empty($packages)) {
            return new WP_Error('no_packages', 'Pošiljka nema pakete');
        }

        $created_time = strtotime($shipment->created_at);
        $current_time = current_time('timestamp');
        $elapsed_minutes = ($current_time - $created_time) / 60;

        // Realistični timeline - u minutima za testiranje
        $simulation_timeline = [
            ['minutes' => 2, 'status' => '0'],   // Čeka preuzimanje - 2 min
            ['minutes' => 10, 'status' => '3'],  // Preuzeta - 10 min  
            ['minutes' => 25, 'status' => '4'],  // Na putu - 25 min
            ['minutes' => 45, 'status' => '1']   // Isporučena - 45 min
        ];

        $statuses_added = 0;

        foreach ($simulation_timeline as $sim) {
            if ($elapsed_minutes >= $sim['minutes'] && 
                !$this->status_exists_for_shipment($shipment->id, $sim['status'])) {
                
                $status_date = date('Y-m-d H:i:s', $created_time + ($sim['minutes'] * 60));
                
                // Dodaj status direktno u bazu za svaki paket
                foreach ($packages as $package) {
                    $result = $wpdb->insert(
                        $wpdb->prefix . 'dexpress_statuses',
                        [
                            'notification_id' => 'SIM_' . time() . '_' . rand(1000, 9999) . '_' . $package->id,
                            'shipment_code' => $package->package_code,
                            'package_id' => $package->id,
                            'reference_id' => $shipment->reference_id,
                            'status_id' => $sim['status'],
                            'status_date' => $status_date,
                            'raw_data' => json_encode(['simulated' => true]),
                            'is_processed' => 1,
                            'created_at' => current_time('mysql')
                        ],
                        ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s']
                    );

                    if ($result) {
                        $statuses_added++;
                        
                        // Ažuriraj package
                        $wpdb->update(
                            $wpdb->prefix . 'dexpress_packages',
                            [
                                'current_status_id' => $sim['status'],
                                'current_status_name' => dexpress_get_status_name($sim['status']),
                                'status_updated_at' => $status_date,
                                'updated_at' => current_time('mysql')
                            ],
                            ['id' => $package->id]
                        );
                        
                        dexpress_log('[SIMULATION] Status ' . $sim['status'] . ' dodat za paket ' . $package->package_code, 'debug');
                    }
                }
                
                // Ažuriraj shipment
                $wpdb->update(
                    $wpdb->prefix . 'dexpress_shipments',
                    [
                        'status_code' => $sim['status'],
                        'status_description' => dexpress_get_status_name($sim['status']),
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $shipment->id]
                );
                
                // Dodaj napomenu u narudžbinu
                $order = wc_get_order($shipment->order_id);
                if ($order) {
                    $note = sprintf('D Express status ažuriran: %s (simulacija)', dexpress_get_status_name($sim['status']));
                    $order->add_order_note($note);
                }
            }
        }

        if ($statuses_added > 0) {
            return array('message' => "Dodato $statuses_added statusa");
        } else {
            return array('message' => 'Nema novih statusa za simulaciju');
        }
    }



    /**
     * Provera da li status postoji za pošiljku
     */
    private function status_exists_for_shipment($shipment_id, $status_id)
    {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_statuses s
             INNER JOIN {$wpdb->prefix}dexpress_packages p ON s.package_id = p.id
             WHERE p.shipment_id = %d AND s.status_id = %s AND s.is_processed = 1",
            $shipment_id,
            $status_id
        ));

        return ($count > 0);
    }

    /**
     * STARA SIMULACIJA - zadržana za kompatibilnost
     */
    public function simulate_test_mode_statuses($shipment_id)
    {
        // Pozovi novu webhook simulaciju
        return $this->simulate_realistic_statuses($shipment_id);
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

        $result = $this->simulate_realistic_statuses($shipment_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return array('message' => 'Simulacija webhook-a uspešno pokrenuta: ' . $result['message']);
    }
}