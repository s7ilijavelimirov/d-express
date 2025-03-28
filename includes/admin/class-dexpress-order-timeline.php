<?php
/**
 * D Express Order Timeline Class
 * 
 * Klasa za prikaz timeline-a statusa pošiljke u order metabox-u
 */

defined('ABSPATH') || exit;

class D_Express_Order_Timeline {

    /**
     * Inicijalizacija
     */
    public function init() {
        // Dodavanje metabox-a za timeline
        add_action('add_meta_boxes', array($this, 'add_timeline_metabox'));
        
        // AJAX handler za osvežavanje statusa
        add_action('wp_ajax_dexpress_refresh_shipment_status', array($this, 'ajax_refresh_status'));
        
        // Registruj stilove za admin
        add_action('admin_enqueue_scripts', array($this, 'register_timeline_styles'));
    }
    
    /**
     * Dodavanje metabox-a za timeline
     */
    public function add_timeline_metabox() {
        // Za klasični način čuvanja porudžbina (post_type)
        add_meta_box(
            'dexpress_timeline_metabox',
            __('D Express Timeline', 'd-express-woo'),
            array($this, 'render_timeline'),
            'shop_order',
            'normal',
            'default'
        );

        // Za HPOS način čuvanja porudžbina (ako je omogućen)
        if (
            class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') &&
            wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
        ) {
            add_meta_box(
                'dexpress_timeline_metabox',
                __('D Express Timeline', 'd-express-woo'),
                array($this, 'render_timeline'),
                wc_get_page_screen_id('shop-order'),
                'normal',
                'default'
            );
        }
    }
    
    /**
     * Registracija stilova za timeline
     */
    public function register_timeline_styles() {
        $timeline_css = '
            .dexpress-timeline-container {
                margin: 0;
                font-family: Arial, sans-serif;
            }
            
            .dexpress-timeline-header {
                background-color: #e8f5e9;
                padding: 10px;
                border-radius: 3px;
                margin-bottom: 20px;
                font-weight: bold;
                text-align: center;
            }
            
            .dexpress-timeline {
                position: relative;
                margin: 20px 0;
                padding-left: 40px;
            }
            
            .dexpress-timeline::before {
                content: "";
                position: absolute;
                top: 0;
                bottom: 0;
                left: 18px;
                width: 2px;
                background: #ddd;
            }
            
            .dexpress-timeline-item {
                position: relative;
                margin-bottom: 30px;
            }
            
            .dexpress-timeline-dot {
                position: absolute;
                left: -40px;
                top: 0;
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: #f5f5f5;
                border: 2px solid #ddd;
                z-index: 1;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .dexpress-timeline-dot.current {
                background: #2196F3;
                border-color: #1976D2;
            }
            
            .dexpress-timeline-dot.completed {
                background: #4CAF50;
                border-color: #388E3C;
            }
            
            .dexpress-timeline-dot.pending {
                background: #FFC107;
                border-color: #FFA000;
            }
            
            .dexpress-timeline-dot.failed {
                background: #F44336;
                border-color: #D32F2F;
            }
            
            .dexpress-timeline-date {
                font-size: 12px;
                color: #777;
                margin-bottom: 5px;
            }
            
            .dexpress-timeline-content {
                background: #f5f5f5;
                padding: 15px;
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .dexpress-timeline-status {
                font-weight: 600;
                font-size: 14px;
            }
            
            .dexpress-timeline-item.completed .dexpress-timeline-content {
                background: #f1f8e9;
            }
            
            .dexpress-timeline-item.failed .dexpress-timeline-content {
                background: #ffebee;
            }
            
            .dexpress-timeline-item.current .dexpress-timeline-content {
                background: #e3f2fd;
            }
            
            .dexpress-timeline-item.pending .dexpress-timeline-content {
                background: #fff8e1;
            }
            
            .dexpress-tracking-info {
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            
            .dexpress-tracking-number {
                font-size: 14px;
                font-weight: bold;
            }
            
            .dexpress-actions {
                margin-top: 20px;
                display: flex;
                gap: 10px;
            }
            
            .dexpress-actions .button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                height: auto;
                padding: 8px 16px;
            }
            
            .dexpress-actions .dashicons {
                margin-right: 5px;
            }
            
            @keyframes dexpress-spin { 
                0% { transform: rotate(0deg); } 
                100% { transform: rotate(360deg); } 
            }
        ';
        
        wp_register_style('dexpress-timeline-css', false);
        wp_enqueue_style('dexpress-timeline-css');
        wp_add_inline_style('dexpress-timeline-css', $timeline_css);
    }
    
    /**
     * Dobavlja sve statuse iz baze i vraća mapirane po ID-u
     */
    private function get_all_statuses() {
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
     * kada nema pravih statusa u bazi
     */
    private function create_default_timeline($shipment) {
        // Definisanje koraka dostave
        $default_statuses = array(
            array(
                'status_id' => 'created',
                'status_date' => $shipment->created_at,
                'status_name' => __('Pošiljka kreirana', 'd-express-woo'),
                'status_type' => 'current'
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
                'status_type' => 'pending'
            );
            
            // Ako je pošiljka starija od 6 sati, dodaj još statusa
            if (time() - $created_time > 21600) { // 6 sati
                // Status "Pošiljka preuzeta od pošiljaoca" - 6 sati nakon kreiranja
                $default_statuses[] = array(
                    'status_id' => 'picked_up',
                    'status_date' => date('Y-m-d H:i:s', $created_time + 21600),
                    'status_name' => __('Pošiljka preuzeta od pošiljaoca', 'd-express-woo'),
                    'status_type' => 'current'
                );
            }
            
            // Ako je pošiljka starija od 24 sata (1 dan)
            if (time() - $created_time > 86400) { // 24 sata
                // Status "Pošiljka zadužena za isporuku" - 1 dan nakon kreiranja
                $default_statuses[] = array(
                    'status_id' => 'for_delivery',
                    'status_date' => date('Y-m-d H:i:s', $created_time + 86400),
                    'status_name' => __('Pošiljka zadužena za isporuku', 'd-express-woo'),
                    'status_type' => 'pending'
                );
            }
            
            // Ako je pošiljka starija od 48 sati (2 dana)
            if (time() - $created_time > 172800) { // 48 sati
                // Status "Pošiljka isporučena primaocu" - 2 dana nakon kreiranja
                $default_statuses[] = array(
                    'status_id' => '130', // Stvarni kod za isporučeno
                    'status_date' => date('Y-m-d H:i:s', $created_time + 172800),
                    'status_name' => __('Pošiljka isporučena primaocu', 'd-express-woo'),
                    'status_type' => 'completed'
                );
            }
        }
        
        return $default_statuses;
    }
    
    /**
     * Mapira statuse iz baze na odgovarajuće objekte za timeline
     */
    private function map_statuses($db_statuses, $all_status_definitions) {
        $mapped_statuses = array();
        
        foreach ($db_statuses as $status) {
            $status_name = isset($all_status_definitions[$status->status_id]) 
                ? $all_status_definitions[$status->status_id] 
                : __('Nepoznat status', 'd-express-woo');
            
            // Određivanje tipa statusa
            $status_type = 'current';
            if ($status->status_id == '130') { // Isporučeno
                $status_type = 'completed';
            } elseif ($status->status_id == '131') { // Neisporučeno
                $status_type = 'failed';
            }
            
            $mapped_statuses[] = array(
                'status_id' => $status->status_id,
                'status_date' => $status->status_date,
                'status_name' => $status_name,
                'status_type' => $status_type
            );
        }
        
        return $mapped_statuses;
    }
    
    /**
     * Render timeline-a
     */
    public function render_timeline($post_or_order) {
        // Provera da li je prosleđen WP_Post ili WC_Order
        if (is_a($post_or_order, 'WP_Post')) {
            $order = wc_get_order($post_or_order->ID);
        } else {
            $order = $post_or_order;
        }

        if (!$order) {
            echo '<p>' . __('Narudžbina nije pronađena.', 'd-express-woo') . '</p>';
            return;
        }

        // Dobavljanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order->get_id()
        ));

        if (!$shipment) {
            echo '<p>' . __('Za ovu narudžbinu još uvek ne postoji D Express pošiljka.', 'd-express-woo') . '</p>';
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
        
        // Sortiranje statusa po datumu (najnoviji prvo za prikazivanje na timeline-u)
        usort($timeline_statuses, function ($a, $b) {
            return strtotime($b['status_date']) - strtotime($a['status_date']);
        });

        // Prikazivanje timeline-a
        echo '<div class="dexpress-timeline-container">';
        
        // Prikazivanje poslednjeg statusa kao header
        if (!empty($timeline_statuses)) {
            $latest_status = $timeline_statuses[0]; // Najnoviji status
            echo '<div class="dexpress-timeline-header">' . esc_html($latest_status['status_name']) . '</div>';
        }

        // Prikazivanje tracking broja i dugmadi za akcije
        echo '<div class="dexpress-tracking-info">';
        echo '<div class="dexpress-tracking-number">';
        echo '<strong>' . __('Tracking broj:', 'd-express-woo') . '</strong> ';
        if ($shipment->is_test) {
            echo esc_html($shipment->tracking_number) . ' <span class="description">(' . __('Test', 'd-express-woo') . ')</span>';
        } else {
            echo '<a href="https://www.dexpress.rs/rs/pracenje-posiljaka/' .
                esc_attr($shipment->tracking_number) . '" target="_blank" class="dexpress-tracking-link">' .
                esc_html($shipment->tracking_number) . '</a>';
        }
        echo '</div>';
        
        // Dugmad za akcije
        $nonce = wp_create_nonce('dexpress-download-label');
        $label_url = admin_url('admin-ajax.php?action=dexpress_download_label&shipment_id=' . $shipment->id . '&nonce=' . $nonce);
        
        echo '<div class="dexpress-actions">';
        echo '<a href="' . esc_url($label_url) . '" class="button button-primary" target="_blank">';
        echo '<span class="dashicons dashicons-printer"></span> ' . __('Preuzmi nalepnicu', 'd-express-woo') . '</a>';

        // Dugme za osvežavanje statusa
        echo ' <a href="#" class="button dexpress-refresh-status" data-id="' . esc_attr($shipment->id) . '" data-nonce="' . wp_create_nonce('dexpress-refresh-status') . '">';
        echo '<span class="dashicons dashicons-update"></span> ' . __('Osveži status', 'd-express-woo') . '</a>';
        echo '</div>'; // End .dexpress-actions
        echo '</div>'; // End .dexpress-tracking-info

        // Timeline
        echo '<div class="dexpress-timeline">';

        // Prikazi statuse na timeline-u (sortirani najnoviji prvo)
        foreach ($timeline_statuses as $status) {
            $status_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($status['status_date']));
            $status_date_parts = explode(' ', $status_date);
            
            echo '<div class="dexpress-timeline-item ' . esc_attr($status['status_type']) . '">';
            
            // Dot sa ikonom
            echo '<div class="dexpress-timeline-dot ' . esc_attr($status['status_type']) . '">';
            echo '</div>';
            
            // Sadržaj timeline stavke
            echo '<div class="dexpress-timeline-content">';
            
            // Datum u formatu "DD.MM.YYYY . HH:MM"
            echo '<div class="dexpress-timeline-date">' . esc_html($status_date_parts[0]) . ' . ' . esc_html($status_date_parts[1]) . '</div>';
            
            // Naziv statusa
            echo '<div class="dexpress-timeline-status">' . esc_html($status['status_name']) . '</div>';
            
            echo '</div></div>'; // End .dexpress-timeline-content, .dexpress-timeline-item
        }

        echo '</div>'; // End .dexpress-timeline

        // JavaScript za osvežavanje statusa
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.dexpress-refresh-status').on('click', function(e) {
                    e.preventDefault();
                    var button = $(this);
                    var originalText = button.html();

                    button.html('<span class="dashicons dashicons-update" style="margin-right: 5px; animation: dexpress-spin 1s linear infinite;"></span> <?php _e('Osvežavanje...', 'd-express-woo'); ?>');
                    button.prop('disabled', true);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'dexpress_refresh_shipment_status',
                            shipment_id: button.data('id'),
                            nonce: button.data('nonce')
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert(response.data.message || '<?php _e('Došlo je do greške prilikom osvežavanja statusa.', 'd-express-woo'); ?>');
                                button.html(originalText);
                                button.prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('<?php _e('Došlo je do greške prilikom osvežavanja statusa.', 'd-express-woo'); ?>');
                            button.html(originalText);
                            button.prop('disabled', false);
                        }
                    });
                });
            });
        </script>
        <?php

        echo '</div>'; // End .dexpress-timeline-container
    }
    
    /**
     * AJAX handler za osvežavanje statusa pošiljke
     */
    public function ajax_refresh_status() {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-refresh-status')) {
            wp_send_json_error(['message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')]);
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')]);
        }

        // Provera ID-a pošiljke
        if (!isset($_POST['shipment_id']) || empty($_POST['shipment_id'])) {
            wp_send_json_error(['message' => __('ID pošiljke je obavezan.', 'd-express-woo')]);
        }

        $shipment_id = intval($_POST['shipment_id']);

        // Kreiranje instance D_Express_Shipment_Service
        $shipment_service = new D_Express_Shipment_Service();

        // Sinhronizacija statusa
        $result = $shipment_service->sync_shipment_status($shipment_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success(['message' => __('Status pošiljke je uspešno ažuriran.', 'd-express-woo')]);
        }
    }
}