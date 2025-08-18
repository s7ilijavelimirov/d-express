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

        // IZMENA: Dobij SVE pošiljke za order
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

        // NOVA SEKCIJA: Prikaz svih pošiljki
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
     * Renderuje timeline za jednu pošiljku
     */
    private function render_single_shipment_timeline($shipment, $shipment_number, $total_shipments)
    {
        global $wpdb;

        // Dohvatanje statusa za ovu pošiljku
        $db_statuses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_statuses 
        WHERE (shipment_code = %s OR reference_id = %s) 
        ORDER BY status_date ASC",
            $shipment->tracking_number,
            $shipment->reference_id
        ));
        // Dobij package informacije za ovaj shipment
        $packages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d ORDER BY package_index ASC",
            $shipment->id
        ));

        $package_count = count($packages);
        $package_info = '';

        if ($package_count > 1) {
            $package_info = sprintf(__('%d paketa', 'd-express-woo'), $package_count);
        } else {
            $package_info = __('1 paket', 'd-express-woo');
        }
        // Predefinisani statusi
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

        // Dodaj status pošiljke kreiran
        if (!isset($status_map['created'])) {
            $status_map['created'] = [
                'date' => $shipment->created_at,
                'reached' => true
            ];
        }

        // Određivanje trenutne faze
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

        $tracking_number = !empty($shipment->tracking_number) ? $shipment->tracking_number : '';

        // NOVA STRUKTURA: Wrapper za jednu pošiljku
        $is_multiple = $total_shipments > 1;
?>

        <div class="dexpress-single-shipment-timeline <?php echo $is_multiple ? 'is-multiple' : 'is-single'; ?>">

            <?php if ($is_multiple): ?>
                <!-- Header za individual shipment -->
                <div class="dexpress-shipment-header">
                    <h4>
                        📦 <?php printf(__('Pošiljka %d od %d', 'd-express-woo'), $shipment_number, $total_shipments); ?>
                        <?php if (!empty($shipment->location_name)): ?>
                            <span class="location-badge"><?php echo esc_html($shipment->location_name); ?></span>
                        <?php endif; ?>
                    </h4>
                </div>
            <?php endif; ?>

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

                    <?php if ($is_multiple && !empty($shipment->reference_id)): ?>
                        <div class="dexpress-info-item">
                            <span class="dexpress-info-label"><?php esc_html_e('Reference', 'd-express-woo'); ?>:</span>
                            <span><?php echo esc_html($shipment->reference_id); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="dexpress-info-item">
                        <span class="dexpress-info-label"><?php esc_html_e('Kreirano', 'd-express-woo'); ?>:</span>
                        <span><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at))); ?></span>
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

            <!-- Minimized istorija statusa za multiple shipments -->
            <?php if (!empty($db_statuses)): ?>
                <div class="dexpress-status-history <?php echo $is_multiple ? 'collapsed' : ''; ?>">
                    <?php if ($is_multiple): ?>
                        <button class="dexpress-toggle-history" type="button">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                            <?php esc_html_e('Prikaži istoriju statusa', 'd-express-woo'); ?>
                        </button>
                    <?php else: ?>
                        <h4><?php esc_html_e('Istorija statusa', 'd-express-woo'); ?></h4>
                    <?php endif; ?>

                    <div class="dexpress-status-history-items">
                        <?php
                        // Sortiraj statuse po datumu, najnoviji prvo
                        usort($db_statuses, function ($a, $b) {
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

        </div> <!-- End single shipment timeline -->

    <?php
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
            if (
                isset($status_map[$status['id']]) ||
                ($status['id'] === $current_status_code) ||
                ($status['group'] === dexpress_get_status_group($current_status_code))
            ) {
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
                        <button type="button" class="button dexpress-refresh-status"
                            data-shipment-id="<?php echo esc_attr($shipment->id); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('dexpress-refresh-status')); ?>">
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
                        usort($db_statuses, function ($a, $b) {
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

        // ISPRAVKA: Koristi stvarne D Express status kodove
        $simulation_statuses = [
            ['hours' => 0, 'id' => '0', 'name' => 'Čeka na preuzimanje'],
            ['hours' => 2, 'id' => '3', 'name' => 'Pošiljka preuzeta od pošiljaoca'],
            ['hours' => 4, 'id' => '4', 'name' => 'Pošiljka zadužena za isporuku'],
            ['hours' => 6, 'id' => '1', 'name' => 'Pošiljka isporučena primaocu']
        ];

        foreach ($simulation_statuses as $sim_status) {
            if ($elapsed_hours >= $sim_status['hours'] && !$this->status_exists($shipment->tracking_number, $sim_status['id'])) {
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
    private function status_exists($tracking_number, $status_id)
    {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_statuses 
        WHERE shipment_code = %s AND status_id = %s",
            $tracking_number, // ISPRAVKA: koristi tracking_number umesto shipment_id
            $status_id
        ));

        return ($count > 0);
    }
    public function manually_trigger_simulation($shipment_id)
    {
        if (!dexpress_is_test_mode()) {
            return new WP_Error('not_test_mode', 'Simulacija je dostupna samo u test režimu');
        }

        $timeline = new D_Express_Order_Timeline();
        $result = $timeline->simulate_test_mode_statuses($shipment_id);

        if ($result) {
            return array('message' => 'Simulacija statusa je uspešno pokrenuta');
        } else {
            return new WP_Error('simulation_failed', 'Simulacija nije uspela');
        }
    }
    /**
     * Dodavanje simuliranog statusa u bazu
     */
    private function add_simulated_status($shipment, $status_id, $status_date)
    {
        global $wpdb;

        // Dodaj status u tabelu statusa
        $status_data = array(
            'notification_id' => 'SIM' . time() . rand(1000, 9999),
            'shipment_code' => $shipment->tracking_number, // ISPRAVKA: koristi tracking_number
            'reference_id' => $shipment->reference_id,
            'status_id' => $status_id,
            'status_date' => $status_date,
            'raw_data' => json_encode(['simulated' => true]),
            'is_processed' => 1,
            'created_at' => current_time('mysql')
        );

        $wpdb->insert($wpdb->prefix . 'dexpress_statuses', $status_data);

        // Ažuriraj glavnu tabelu pošiljki
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
                __('D Express status ažuriran: %s (simulirano)', 'd-express-woo'),
                dexpress_get_status_name($status_id)
            ));
        }

        dexpress_log('[SIMULATION] Dodat status ' . $status_id . ' za pošiljku ' . $shipment->tracking_number, 'debug');
    }
}
