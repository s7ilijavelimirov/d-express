<?php
/**
 * D Express Tracking
 * 
 * Klasa za frontend prikaz praćenja pošiljki
 */

defined('ABSPATH') || exit;

class D_Express_Tracking {
    
    /**
     * Inicijalizacija
     */
    public function init() {
        // Dodavanje shortcode-a za praćenje pošiljke
        add_shortcode('dexpress_tracking', array($this, 'tracking_shortcode'));
        
        // Dodavanje prikaza praćenja na stranici narudžbine u korisničkom nalogu
        add_action('woocommerce_order_details_after_order_table', array($this, 'add_tracking_info_to_order_details'));
        
        // AJAX hendleri za praćenje
        add_action('wp_ajax_dexpress_track_shipment', array($this, 'ajax_track_shipment'));
        add_action('wp_ajax_nopriv_dexpress_track_shipment', array($this, 'ajax_track_shipment'));
        
        // Enqueue skripti i stilova
        add_action('wp_enqueue_scripts', array($this, 'enqueue_tracking_assets'));
    }
    
    /**
     * Shortcode za praćenje pošiljke
     */
    public function tracking_shortcode($atts) {
        echo 'GG';
        $atts = shortcode_atts(array(
            'order_id' => '',
            'tracking_number' => '',
        ), $atts, 'dexpress_tracking');
        
        ob_start();
        include DEXPRESS_WOO_PLUGIN_DIR . 'frontend/views/myaccount-tracking.php';
        return ob_get_clean();
    }
    
    /**
     * Dodavanje informacija o praćenju na stranici narudžbine
     */
    public function add_tracking_info_to_order_details($order) {
        $order_id = $order->get_id();
        
        // Dobijanje podataka o pošiljci
        $db = new D_Express_DB();
        $shipment = $db->get_shipment_by_order_id($order_id);
        
        if (!$shipment) {
            return;
        }
        
        // Prikaz informacija o praćenju
        echo '<h2>' . __('Praćenje pošiljke', 'd-express-woo') . '</h2>';
        echo '<p>' . __('Broj za praćenje:', 'd-express-woo') . ' <strong>' . esc_html($shipment->tracking_number) . '</strong></p>';
        
        // Ovo će biti prošireno u potpunoj verziji
    }
    
    /**
     * AJAX handler za praćenje pošiljke
     */
    public function ajax_track_shipment() {
        // Ovo će biti implementirano u potpunoj verziji
        wp_send_json_success(array(
            'message' => __('Funkcionalnost praćenja pošiljke će biti implementirana u potpunoj verziji plugin-a.', 'd-express-woo'),
        ));
    }
    
    /**
     * Registracija skripti i stilova za praćenje
     */
    public function enqueue_tracking_assets() {
        // Učitavanje stilova i skripti samo na relevantnim stranicama
        if (is_account_page() || is_checkout() || is_wc_endpoint_url('view-order') || ($post = get_post()) instanceof WP_Post && has_shortcode($post->post_content, 'dexpress_tracking')) {
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
            ));
        }
    }
}