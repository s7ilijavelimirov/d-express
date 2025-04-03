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
        
        // AJAX handler za osvežavanje statusa
        add_action('wp_ajax_dexpress_refresh_shipment_status', array($this, 'ajax_refresh_status'));
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
        return [
            ['id' => 'created', 'name' => __('Pošiljka kreirana', 'd-express-woo'), 'group' => 'created', 'icon' => 'dashicons-plus-alt'],
            ['id' => '0', 'name' => __('Čeka na preuzimanje', 'd-express-woo'), 'group' => 'pending', 'icon' => 'dashicons-clock'],
            ['id' => '3', 'name' => __('Preuzeta od pošiljaoca', 'd-express-woo'), 'group' => 'transit', 'icon' => 'dashicons-car'],
            ['id' => '4', 'name' => __('Zadužena za isporuku', 'd-express-woo'), 'group' => 'out_for_delivery', 'icon' => 'dashicons-arrow-right-alt'],
            ['id' => '1', 'name' => __('Isporučena primaocu', 'd-express-woo'), 'group' => 'delivered', 'icon' => 'dashicons-yes-alt'],
        ];
    }

    /**
     * Render timeline-a
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

        // Dohvatanje stvarnih statusa iz baze
        $db_statuses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_statuses 
            WHERE (shipment_code = %s OR reference_id = %s) 
            ORDER BY status_date ASC",
            $shipment->shipment_id,
            $shipment->reference_id
        ));

        // Kreiraj timeline sa fiksnim statusima i označavanje trenutnog
        $this->render_improved_timeline($shipment, $db_statuses);
    }

    /**
     * Renderuje poboljšani timeline sa fiksnim statusima i trenutnim progresom
     */
    private function render_improved_timeline($shipment, $db_statuses)
    {
        // Predefinisani statusi koje uvek prikazujemo
        $predefined_statuses = $this->get_predefined_statuses();
        
        // Kreiranje mape trenutnih statusa
        $status_map = [];
        if (!empty($db_statuses)) {
            foreach ($db_statuses as $status) {
                $status_map[$status->status_id] = [
                    'date' => $status->status_date,
                    'reached' => true
                ];
            }
        }

        // Dodaj status pošiljke kreiran ako postoji
        if (!isset($status_map['created'])) {
            $status_map['created'] = [
                'date' => $shipment->created_at,
                'reached' => true
            ];
        }

        // Dobijanje trenutne faze na osnovu poslednjeg statusa
        $current_phase = 0;
        $current_status_code = $shipment->status_code;
        
        if (empty($current_status_code) && !empty($db_statuses)) {
            $last_status = end($db_statuses);
            $current_status_code = $last_status->status_id;
        }
        
        // Procesiranje predefinisanih statusa i određivanje trenutne faze
        for ($i = 0; $i < count($predefined_statuses); $i++) {
            $status = $predefined_statuses[$i];
            
            // Ako je status postignut ili postoji u mapi statusa
            if (isset($status_map[$status['id']]) || 
                ($status['id'] === $current_status_code) ||
                ($status['group'] === dexpress_get_status_group($current_status_code))) {
                $current_phase = max($current_phase, $i);
            }
        }

        // Prikazivanje detalja o pošiljci
        $tracking_number = !empty($shipment->tracking_number) ? $shipment->tracking_number : '';
        ?>
        
        <div class="dexpress-timeline-container">
            <!-- Informacije o pošiljci -->
            <div class="dexpress-shipment-info">
                <div class="dexpress-info-grid">
                    <div class="dexpress-info-item">
                        <span class="dexpress-info-label"><?php esc_html_e('Tracking broj', 'd-express-woo'); ?>:</span>
                        <?php if (!$shipment->is_test): ?>
                            <a href="https://www.dexpress.rs/rs/pracenje-posiljaka/<?php echo esc_attr($tracking_number); ?>" 
                               target="_blank" class="dexpress-tracking-link">
                                <?php echo esc_html($tracking_number); ?>
                                <span class="dashicons dashicons-external"></span>
                            </a>
                        <?php else: ?>
                            <span><?php echo esc_html($tracking_number); ?> <span class="dexpress-test-badge"><?php esc_html_e('Test', 'd-express-woo'); ?></span></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="dexpress-info-item">
                        <span class="dexpress-info-label"><?php esc_html_e('Status', 'd-express-woo'); ?>:</span>
                        <span class="dexpress-status-badge dexpress-status-<?php echo esc_attr(dexpress_get_status_group($current_status_code)); ?>">
                            <?php echo esc_html(dexpress_get_status_name($current_status_code)); ?>
                        </span>
                    </div>
                    
                    <div class="dexpress-info-item">
                        <span class="dexpress-info-label"><?php esc_html_e('Kreirano', 'd-express-woo'); ?>:</span>
                        <span><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at))); ?></span>
                    </div>
                    
                    <div class="dexpress-info-item">
                        <button type="button" class="button dexpress-refresh-status" data-shipment-id="<?php echo esc_attr($shipment->id); ?>">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Osveži status', 'd-express-woo'); ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Timeline -->
            <div class="dexpress-timeline-wrapper">
                <!-- Progres linija -->
                <div class="dexpress-progress-track">
                    <div class="dexpress-progress-bar" style="width: <?php echo esc_attr(min(100, ($current_phase / (count($predefined_statuses) - 1)) * 100)); ?>%"></div>
                </div>
                
                <!-- Statusi -->
                <div class="dexpress-timeline-steps">
                    <?php foreach ($predefined_statuses as $i => $status): 
                        $is_reached = $i <= $current_phase;
                        $status_class = $is_reached ? 'dexpress-step-reached' : 'dexpress-step-pending';
                        $status_date = '';
                        
                        // Ako status postoji u mapi, prikažemo datum
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
            
            <!-- Istorija svih statusa -->
            <?php if (!empty($db_statuses)): ?>
                <div class="dexpress-status-history">
                    <h4><?php esc_html_e('Istorija statusa', 'd-express-woo'); ?></h4>
                    <div class="dexpress-status-history-items">
                        <?php 
                        // Sortiraj statuse po datumu, najnoviji prvo
                        usort($db_statuses, function($a, $b) {
                            return strtotime($b->status_date) - strtotime($a->status_date);
                        });
                        
                        foreach ($db_statuses as $status): 
                            $status_name = dexpress_get_status_name($status->status_id);
                            $status_group = dexpress_get_status_group($status->status_id);
                            $status_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($status->status_date));
                        ?>
                            <div class="dexpress-status-history-item">
                                <div class="dexpress-status-history-date"><?php echo esc_html($status_date); ?></div>
                                <div class="dexpress-status-history-info">
                                    <span class="dexpress-status-badge dexpress-status-<?php echo esc_attr($status_group); ?>">
                                        <?php echo esc_html($status_name); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX handler za osvežavanje statusa pošiljke
     */
    public function ajax_refresh_status()
    {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-refresh-status')) {
            wp_send_json_error(['message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')]);
            return;
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')]);
            return;
        }

        // Provera ID-a pošiljke
        if (!isset($_POST['shipment_id']) || empty($_POST['shipment_id'])) {
            wp_send_json_error(['message' => __('ID pošiljke je obavezan.', 'd-express-woo')]);
            return;
        }

        $shipment_id = intval($_POST['shipment_id']);

        try {
            global $wpdb;
            $shipment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
                $shipment_id
            ));

            if (!$shipment) {
                wp_send_json_error(['message' => __('Pošiljka nije pronađena.', 'd-express-woo')]);
                return;
            }

            // U test režimu, simuliraj statuse
            if ($shipment->is_test) {
                $result = $this->simulate_test_mode_statuses($shipment_id);
                
                if ($result) {
                    wp_send_json_success(['message' => __('Status pošiljke je uspešno ažuriran (test režim).', 'd-express-woo')]);
                } else {
                    wp_send_json_success(['message' => __('Pošiljka je već u najnovijem statusu (test režim).', 'd-express-woo')]);
                }
                return;
            }

            // U produkcijskom režimu, pokušaj sinhronizaciju
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/services/class-dexpress-shipment-service.php';
            $shipment_service = new D_Express_Shipment_Service();
            $result = $shipment_service->sync_shipment_status($shipment_id);

            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            } else {
                wp_send_json_success(['message' => __('Status pošiljke je uspešno ažuriran.', 'd-express-woo')]);
            }
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => __('Došlo je do greške prilikom ažuriranja statusa.', 'd-express-woo'),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Simulacija statusa za test mode
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

        // Simulacija statusa prema proteklom vremenu
        $simulation_statuses = [
            ['hours' => 2, 'id' => '0', 'name' => 'Čeka na preuzimanje', 'group' => 'pending'],
            ['hours' => 8, 'id' => '3', 'name' => 'Pošiljka preuzeta od pošiljaoca', 'group' => 'transit'],
            ['hours' => 10, 'id' => '4', 'name' => 'Pošiljka zadužena za isporuku', 'group' => 'out_for_delivery'],
            ['hours' => 15, 'id' => '1', 'name' => 'Pošiljka isporučena primaocu', 'group' => 'delivered']
        ];

        foreach ($simulation_statuses as $sim_status) {
            if ($elapsed_hours > $sim_status['hours'] && !$this->status_exists($shipment->shipment_id, $sim_status['id'])) {
                $this->add_simulated_status(
                    $shipment,
                    $sim_status['id'],
                    date('Y-m-d H:i:s', $created_time + ($sim_status['hours'] * 3600))
                );
                $statuses_added = true;
            }
        }

        return $statuses_added;
    }

    /**
     * Provera da li postoji određeni status
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
            'raw_data' => json_encode(['simulated' => true]),
            'is_processed' => 1,
            'created_at' => current_time('mysql')
        );

        $wpdb->insert($wpdb->prefix . 'dexpress_statuses', $status_data);

        // Ažuriranje statusa pošiljke
        $wpdb->update(
            $wpdb->prefix . 'dexpress_shipments',
            array(
                'status_code' => $status_id,
                'status_description' => dexpress_get_status_name($status_id),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $shipment->id)
        );
        
        // Dodaj napomenu u narudžbinu
        $order = wc_get_order($shipment->order_id);
        if ($order) {
            $order->add_order_note(sprintf(
                __('D Express status ažuriran: %s (simulirano za test pošiljku)', 'd-express-woo'),
                dexpress_get_status_name($status_id)
            ));
        }
    }
}