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

        wp_enqueue_style(
            'dexpress-timeline-css',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-admin.css',
            array('woocommerce_admin_styles'),
            DEXPRESS_WOO_VERSION
        );

        wp_enqueue_script(
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
            echo '<div class="dexpress-timeline-empty">';
            echo '<p>' . esc_html__('Za ovu narudžbinu još uvek ne postoji D Express pošiljka.', 'd-express-woo') . '</p>';
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

        // Sortiranje statusa po datumu (najnoviji prvo za prikazivanje na timeline-u)
        usort($timeline_statuses, function ($a, $b) {
            return strtotime($b['status_date']) - strtotime($a['status_date']);
        });

        // Početak HTML-a
?>
        <div class="dexpress-timeline-container woocommerce-order-data">
            <?php
            // Prikazivanje header-a sa poslednjim statusom
            if (!empty($timeline_statuses)) {
                $latest_status = $timeline_statuses[0]; // Najnoviji status
                $status_class = 'status-' . $latest_status['status_type'];

                // Početak sekcije sa detaljima pošiljke
            ?>
                <div class="dexpress-timeline-header <?php echo esc_attr($status_class); ?>">
                    <h3><?php echo esc_html($latest_status['status_name']); ?></h3>
                </div>

                <div class="dexpress-shipment-details">
                    <div class="dexpress-tracking-info">
                        <span class="dexpress-detail-label"><?php esc_html_e('Tracking broj:', 'd-express-woo'); ?></span>
                        <?php if ($shipment->is_test) : ?>
                            <span class="dexpress-tracking-number">
                                <?php echo esc_html($shipment->tracking_number); ?>
                                <span class="woocommerce-tag is-core-test"><?php esc_html_e('Test', 'd-express-woo'); ?></span>
                            </span>
                        <?php else : ?>
                            <a href="https://www.dexpress.rs/rs/pracenje-posiljaka/<?php echo esc_attr($shipment->tracking_number); ?>"
                                target="_blank" class="dexpress-tracking-link">
                                <?php echo esc_html($shipment->tracking_number); ?>
                                <span class="dashicons dashicons-external"></span>
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="dexpress-shipment-meta">
                        <span class="dexpress-detail-label"><?php esc_html_e('Reference ID:', 'd-express-woo'); ?></span>
                        <span><?php echo esc_html($shipment->reference_id); ?></span>
                    </div>

                    <div class="dexpress-shipment-meta">
                        <span class="dexpress-detail-label"><?php esc_html_e('Datum kreiranja:', 'd-express-woo'); ?></span>
                        <span><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at))); ?></span>
                    </div>
                </div>

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

                    <button type="button" class="button dexpress-refresh-status"
                        data-id="<?php echo esc_attr($shipment->id); ?>"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('dexpress-refresh-status')); ?>">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Osveži status', 'd-express-woo'); ?>
                    </button>
                </div>
            <?php
            }
            ?>

            <div class="dexpress-timeline">
                <?php
                // Prikaz statusa na timeline-u
                if (!empty($timeline_statuses)) :
                    foreach ($timeline_statuses as $index => $status) :
                        $status_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($status['status_date']));
                        $status_class = 'status-' . esc_attr($status['status_type']);
                        $is_latest = ($index === 0);

                        // Dodatne klase za prvi i poslednji element
                        $extra_class = $is_latest ? ' is-latest' : '';
                        $extra_class .= ($index === count($timeline_statuses) - 1) ? ' is-first' : '';
                ?>

                        <div class="dexpress-timeline-item <?php echo esc_attr($status_class . $extra_class); ?>">
                            <div class="dexpress-timeline-marker">
                                <span class="dexpress-timeline-icon dashicons <?php echo esc_attr($status['icon']); ?>"></span>
                            </div>

                            <div class="dexpress-timeline-content">
                                <div class="dexpress-timeline-header">
                                    <span class="dexpress-timeline-date"><?php echo esc_html($status_date); ?></span>
                                </div>

                                <div class="dexpress-timeline-body">
                                    <span class="dexpress-timeline-status"><?php echo esc_html($status['status_name']); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php
                    endforeach;
                else :
                    ?>
                    <div class="dexpress-timeline-empty-state">
                        <p><?php esc_html_e('Još uvek nema informacija o statusu pošiljke.', 'd-express-woo'); ?></p>
                    </div>
                <?php
                endif;
                ?>
            </div>
        </div>
<?php
    }

    /**
     * AJAX handler za osvežavanje statusa pošiljke.
     *
     * @return void
     */
    public function ajax_refresh_status()
    {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-refresh-status')) {
            wp_send_json_error(array('message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')));
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')));
        }

        // Provera ID-a pošiljke
        if (!isset($_POST['shipment_id']) || empty($_POST['shipment_id'])) {
            wp_send_json_error(array('message' => __('ID pošiljke je obavezan.', 'd-express-woo')));
        }

        $shipment_id = intval($_POST['shipment_id']);

        // Kreiranje instance shipment servisa i sinhronizacija statusa
        $shipment_service = new D_Express_Shipment_Service();
        $result = $shipment_service->sync_shipment_status($shipment_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => __('Status pošiljke je uspešno ažuriran.', 'd-express-woo')));
        }
    }
}
