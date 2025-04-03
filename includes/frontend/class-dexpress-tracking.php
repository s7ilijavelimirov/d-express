<?php

/**
 * D Express Tracking
 * 
 * Klasa za frontend prikaz praćenja pošiljki
 */

defined('ABSPATH') || exit;

class D_Express_Tracking
{

    /**
     * Inicijalizacija
     */
    public function init()
    {
        // Dodavanje shortcode-a za praćenje pošiljke
        add_shortcode('dexpress_tracking', array($this, 'tracking_shortcode'));

        // Dodavanje prikaza praćenja na stranici narudžbine u korisničkom nalogu
        add_action('woocommerce_order_details_after_order_table', array($this, 'add_tracking_info_to_order_details'));

        // AJAX hendleri za praćenje
        add_action('wp_ajax_dexpress_track_shipment', array($this, 'ajax_track_shipment'));
        add_action('wp_ajax_nopriv_dexpress_track_shipment', array($this, 'ajax_track_shipment'));

        // Enqueue skripti i stilova
        add_action('wp_enqueue_scripts', array($this, 'enqueue_tracking_assets'));

        // Dodavanje stranice praćenja u My Account
        add_filter('woocommerce_account_menu_items', array($this, 'add_tracking_menu_item'));
        add_action('init', array($this, 'add_tracking_endpoint'));
        add_action('woocommerce_account_dexpress-tracking_endpoint', array($this, 'tracking_page_content'));
    }

    /**
     * Dodavanje endpointa za tracking u My Account
     */
    public function add_tracking_endpoint()
    {
        add_rewrite_endpoint('dexpress-tracking', EP_ROOT | EP_PAGES);

        // Provjeri ako su pravila već osvježena
        $rules_option = get_option('rewrite_rules');
        if (!$rules_option || !array_key_exists('(.?.+?)/dexpress-tracking(/(.*))?/?$', $rules_option)) {
            flush_rewrite_rules();
        }
    }
    public function add_tracking_menu_item($items)
    {
        // Proverite da li je opcija uključena
        if (get_option('dexpress_enable_myaccount_tracking', 'yes') !== 'yes') {
            return $items;
        }

        // Dodavanje nakon narudžbina
        $new_items = array();

        foreach ($items as $key => $value) {
            $new_items[$key] = $value;

            if ($key === 'orders') {
                $new_items['dexpress-tracking'] = __('Praćenje pošiljke', 'd-express-woo');
            }
        }

        return $new_items;
    }
    /**
     * Sadržaj stranice za praćenje
     */
    public function tracking_page_content()
    {
        // Dobavljanje trenutnog korisnika
        $user_id = get_current_user_id();

        // Dobavljanje poslednjih pošiljki za korisnika
        global $wpdb;
        $customer_orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'limit' => -1  // Dobavi sve narudžbine
        ));

        $order_ids = array();
        if (!empty($customer_orders)) {
            $order_ids = array_map(function ($order) {
                return $order->get_id();
            }, $customer_orders);
        }

        $shipments = array();
        if (!empty($order_ids)) {
            $shipments = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}dexpress_shipments 
                WHERE order_id IN (" . implode(',', array_map('intval', $order_ids)) . ")
                ORDER BY created_at DESC"
            );
        }

        // Uzimamo prvu kao aktivni shipment ako postoji
        $shipment = !empty($shipments) ? $shipments[0] : null;

        // Uključi samo jedan šablon za prikaz
        include DEXPRESS_WOO_PLUGIN_DIR . 'templates/myaccount/tracking.php';
    }

    /**
     * Prikaz rezultata praćenja
     */
    private function display_tracking_results($tracking_number)
    {
        // Dobijanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE tracking_number = %s",
            $tracking_number
        ));

        if (!$shipment) {
            echo '<div class="woocommerce-message woocommerce-message--error">';
            echo __('Pošiljka sa tim brojem nije pronađena. Proverite broj i pokušajte ponovo.', 'd-express-woo');
            echo '</div>';
            return;
        }

        // Dobijanje statusa
        $statuses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_statuses 
        WHERE (shipment_code = %s OR reference_id = %s) 
        ORDER BY status_date DESC",
            $shipment->shipment_id,
            $shipment->reference_id
        ));

        // Prikaz detalja pošiljke
        echo '<div class="dexpress-tracking-info">';
        echo '<h3>' . __('Informacije o pošiljci', 'd-express-woo') . '</h3>';
        echo '<table class="dexpress-tracking-details">';
        echo '<tr><th>' . __('Tracking broj:', 'd-express-woo') . '</th><td>' . esc_html($shipment->tracking_number) . '</td></tr>';
        echo '<tr><th>' . __('Referenca:', 'd-express-woo') . '</th><td>' . esc_html($shipment->reference_id) . '</td></tr>';

        // Koristi helper funkciju za dohvatanje imena statusa
        $status_name = dexpress_get_status_name($shipment->status_code);
        $status_class = dexpress_get_status_css_class($shipment->status_code);

        echo '<tr><th>' . __('Status:', 'd-express-woo') . '</th><td>';
        echo '<span class="dexpress-status-badge ' . esc_attr($status_class) . '">' . esc_html($status_name) . '</span>';
        echo '</td></tr>';

        echo '<tr><th>' . __('Kreirana:', 'd-express-woo') . '</th><td>' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at))) . '</td></tr>';
        echo '</table>';

        // Prikaz statusa ako ih ima
        if (!empty($statuses)) {
            echo '<h3>' . __('Istorija statusa', 'd-express-woo') . '</h3>';
            echo '<table class="dexpress-tracking-statuses">';
            echo '<tr><th>' . __('Datum', 'd-express-woo') . '</th><th>' . __('Status', 'd-express-woo') . '</th></tr>';

            foreach ($statuses as $status) {
                echo '<tr>';
                echo '<td>' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($status->status_date))) . '</td>';
                echo '<td>' . esc_html(dexpress_get_status_name($status->status_id)) . '</td>';
                echo '</tr>';
            }

            echo '</table>';
        } else {
            echo '<p>' . __('Još uvek nema informacija o statusu ove pošiljke.', 'd-express-woo') . '</p>';
        }

        // Dugme za direktan link na D Express sajt
        if (!$shipment->is_test) {
            echo '<p>';
            echo '<a href="https://www.dexpress.rs/rs/pracenje-posiljaka/' . esc_attr($shipment->tracking_number) . '" class="button" target="_blank">';
            echo __('Prati na D Express sajtu', 'd-express-woo');
            echo '</a>';
            echo '</p>';
        }

        echo '</div>';
    }

    /**
     * Prikaz korisnikovih pošiljki
     */
    private function display_user_shipments()
    {
        // Samo za prijavljene korisnike
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();

        // Dobijanje narudžbina korisnika
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'limit' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        if (empty($orders)) {
            return;
        }

        // Izvuci order IDs
        $order_ids = array_map(function ($order) {
            return $order->get_id();
        }, $orders);

        // Dobijanje pošiljki za ove narudžbine
        global $wpdb;
        $shipments = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments 
            WHERE order_id IN (" . implode(',', array_map('intval', $order_ids)) . ")
            ORDER BY created_at DESC"
        );

        if (empty($shipments)) {
            return;
        }

        echo '<h3>' . __('Vaše nedavne pošiljke', 'd-express-woo') . '</h3>';
        echo '<table class="dexpress-user-shipments">';
        echo '<tr>';
        echo '<th>' . __('Narudžbina', 'd-express-woo') . '</th>';
        echo '<th>' . __('Tracking broj', 'd-express-woo') . '</th>';
        echo '<th>' . __('Status', 'd-express-woo') . '</th>';
        echo '<th>' . __('Datum', 'd-express-woo') . '</th>';
        echo '<th>' . __('Akcije', 'd-express-woo') . '</th>';
        echo '</tr>';

        foreach ($shipments as $shipment) {
            $order = wc_get_order($shipment->order_id);

            echo '<tr>';
            echo '<td><a href="' . esc_url($order->get_view_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a></td>';
            echo '<td>' . esc_html($shipment->tracking_number) . '</td>';
            echo '<td>' . ($shipment->status_description ? esc_html($shipment->status_description) : __('U obradi', 'd-express-woo')) . '</td>';
            echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($shipment->created_at))) . '</td>';
            echo '<td>';
            // Direktan link ka zvaničnom sajtu za praćenje
            echo '<a href="https://www.dexpress.rs/rs/pracenje-posiljaka/' . esc_attr($shipment->tracking_number) . '" target="_blank" class="button button-small">' . __('Prati', 'd-express-woo') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    /**
     * Shortcode za praćenje pošiljke
     */
    public function tracking_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'order_id' => '',
            'tracking_number' => '',
        ), $atts, 'dexpress_tracking');

        ob_start();
        include DEXPRESS_WOO_PLUGIN_DIR . 'templates/frontend/tracking-widget.php';
        return ob_get_clean();
    }

    /**
     * Dodavanje informacija o praćenju na stranici narudžbine
     */
    public function add_tracking_info_to_order_details($order)
    {
        $order_id = $order->get_id();

        // Dobijanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));

        if (!$shipment) {
            return;
        }

        // Prikaz informacija o praćenju
        include DEXPRESS_WOO_PLUGIN_DIR . 'templates/myaccount/tracking.php';
    }

    /**
     * AJAX handler za praćenje pošiljke
     */
    public function ajax_track_shipment()
    {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-frontend-nonce')) {
            wp_send_json_error(array(
                'message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')
            ));
        }

        // Provera tracking broja
        if (!isset($_POST['tracking_number']) || empty($_POST['tracking_number'])) {
            wp_send_json_error(array(
                'message' => __('Broj za praćenje je obavezan.', 'd-express-woo')
            ));
        }

        $tracking_number = sanitize_text_field($_POST['tracking_number']);

        // Dobijanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE tracking_number = %s",
            $tracking_number
        ));

        if (!$shipment) {
            wp_send_json_error(array(
                'message' => __('Pošiljka sa tim brojem nije pronađena.', 'd-express-woo')
            ));
        }

        // Dobijanje statusa
        $statuses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_statuses 
            WHERE (shipment_code = %s OR reference_id = %s) 
            ORDER BY status_date DESC",
            $shipment->shipment_id,
            $shipment->reference_id
        ));

        // Priprema odgovora
        $response = array(
            'tracking_number' => $shipment->tracking_number,
            'reference_id' => $shipment->reference_id,
            'status' => $shipment->status_description ?: __('U obradi', 'd-express-woo'),
            'created_at' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at)),
            'statuses' => array()
        );

        // Dodavanje statusa
        if (!empty($statuses)) {
            foreach ($statuses as $status) {
                $response['statuses'][] = array(
                    'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($status->status_date)),
                    'status' => dexpress_get_status_name($status->status_id)
                );
            }
        }

        wp_send_json_success($response);
    }

    /**
     * Registracija skripti i stilova za praćenje
     */
    public function enqueue_tracking_assets()
    {
        // Učitavanje stilova i skripti samo na relevantnim stranicama
        if (is_account_page() || is_checkout() || is_wc_endpoint_url('view-order') || is_wc_endpoint_url('dexpress-tracking') || ($post = get_post()) instanceof WP_Post && has_shortcode($post->post_content, 'dexpress_tracking')) {
            wp_enqueue_style(
                'dexpress-frontend-css',
                DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-frontend.css',
                array(),
                DEXPRESS_WOO_VERSION
            );

            wp_enqueue_script(
                'dexpress-frontend-js',
                DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-frontend.js',
                array('jquery'),
                DEXPRESS_WOO_VERSION,
                true
            );

            wp_localize_script('dexpress-frontend-js', 'dexpressFrontend', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('dexpress-frontend-nonce'),
                'i18n' => array(
                    'loading' => __('Učitavanje...', 'd-express-woo'),
                    'error' => __('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'),
                    'notFound' => __('Pošiljka nije pronađena.', 'd-express-woo')
                )
            ));
        }
    }
}
