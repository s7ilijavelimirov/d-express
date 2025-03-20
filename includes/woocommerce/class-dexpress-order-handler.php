<?php

/**
 * D Express Order Handler klasa
 * 
 * Klasa za rukovanje WooCommerce narudžbinama
 */

defined('ABSPATH') || exit;

class D_Express_Order_Handler
{
    /**
     * Inicijalizacija order handlera
     */
    // Promenite ovo:
    public function init()
    {
        // Kreiranje instance servisne klase
        $shipment_service = new D_Express_Shipment_Service();

        // Čuvanje D Express opcija pri čuvanju narudžbine
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_dexpress_meta_box'), 10, 1);

        // AJAX akcija za preuzimanje nalepnice
        add_action('wp_ajax_dexpress_get_label', array($this, 'ajax_get_label'));

        // Kreiranje pošiljke nakon završetka narudžbine
        add_action('woocommerce_checkout_order_processed', array($this, 'process_checkout_order'), 10, 3);
    }

    /**
     * Obrada narudžbine nakon checkout-a
     * 
     * @param int $order_id ID narudžbine
     * @param array $posted_data Podaci sa checkout forme
     * @param WC_Order $order Objekat narudžbine
     */
    public function process_checkout_order($order_id, $posted_data, $order)
    {
        // Logging za debug
        dexpress_log('Checkout order processed: ' . $order_id, 'debug');

        // Proveriti da li je odabrana D Express dostava
        $shipping_methods = $order->get_shipping_methods();
        $has_dexpress = false;

        foreach ($shipping_methods as $method) {
            if (strpos($method->get_method_id(), 'dexpress') !== false) {
                $has_dexpress = true;
                break;
            }
        }

        // Logging za debug
        dexpress_log('Has D Express shipping: ' . ($has_dexpress ? 'Yes' : 'No'), 'debug');

        if (!$has_dexpress) {
            return;
        }

        // Provera da li je automatsko kreiranje omogućeno
        $auto_create_enabled = get_option('dexpress_auto_create_shipment', 'no') === 'yes';
        $auto_create_status = get_option('dexpress_auto_create_on_status', 'processing');

        // Kreiranje pošiljke samo ako je automatsko kreiranje omogućeno i status odgovara
        // ili ako je u test modu i automatsko kreiranje je omogućeno
        if ($auto_create_enabled && ($order->get_status() === $auto_create_status || dexpress_is_test_mode())) {
            dexpress_log('Auto-creating shipment for order: ' . $order_id . ' (Test mode: ' . (dexpress_is_test_mode() ? 'Yes' : 'No') . ')', 'debug');
            $shipment_service = new D_Express_Shipment_Service();
            $result = $shipment_service->create_shipment($order);

            if (is_wp_error($result)) {
                dexpress_log('Failed to create shipment: ' . $result->get_error_message(), 'error');
            } else {
                dexpress_log('Shipment created successfully, ID: ' . $result, 'debug');
            }
        } else {
            dexpress_log('Automatic shipment creation is disabled or status does not match. Auto create: ' .
                ($auto_create_enabled ? 'Enabled' : 'Disabled') .
                ', Current status: ' . $order->get_status() .
                ', Required status: ' . $auto_create_status, 'debug');
        }
    }
    /**
     * Dodavanje meta box-a za D Express opcije
     */




    /**
     * Čuvanje D Express opcija pri čuvanju narudžbine
     * 
     * @param int $order_id ID narudžbine
     */
    public function save_dexpress_meta_box($order_id)
    {
        // Provera nonce-a
        if (!isset($_POST['dexpress_meta_box_nonce']) || !wp_verify_nonce($_POST['dexpress_meta_box_nonce'], 'dexpress_meta_box')) {
            return;
        }

        // Čuvanje D Express opcija
        if (isset($_POST['dexpress_shipment_type'])) {
            update_post_meta($order_id, '_dexpress_shipment_type', sanitize_text_field($_POST['dexpress_shipment_type']));
        }

        if (isset($_POST['dexpress_payment_by'])) {
            update_post_meta($order_id, '_dexpress_payment_by', sanitize_text_field($_POST['dexpress_payment_by']));
        }

        if (isset($_POST['dexpress_payment_type'])) {
            update_post_meta($order_id, '_dexpress_payment_type', sanitize_text_field($_POST['dexpress_payment_type']));
        }

        if (isset($_POST['dexpress_return_doc'])) {
            update_post_meta($order_id, '_dexpress_return_doc', sanitize_text_field($_POST['dexpress_return_doc']));
        }

        if (isset($_POST['dexpress_content'])) {
            update_post_meta($order_id, '_dexpress_content', sanitize_text_field($_POST['dexpress_content']));
        }
    }

    /**
     * AJAX akcija za preuzimanje nalepnice
     */
    public function ajax_get_label()
    {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-admin-nonce')) {
            wp_send_json_error(array(
                'message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')
            ));
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')
            ));
        }

        // Provera ID-a pošiljke
        if (!isset($_POST['shipment_id']) || empty($_POST['shipment_id'])) {
            wp_send_json_error(array(
                'message' => __('ID pošiljke je obavezan.', 'd-express-woo')
            ));
        }

        $shipment_id = intval($_POST['shipment_id']);

        // Dobijanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
            $shipment_id
        ));

        if (!$shipment) {
            wp_send_json_error(array(
                'message' => __('Pošiljka nije pronađena.', 'd-express-woo')
            ));
        }

        // Generisanje nalepnice
        $label_generator = new D_Express_Label_Generator();
        $label_url = admin_url('admin-ajax.php?action=dexpress_download_label&shipment_id=' . $shipment_id . '&nonce=' . wp_create_nonce('dexpress-download-label'));

        wp_send_json_success(array(
            'message' => __('Nalepnica uspešno generisana.', 'd-express-woo'),
            'url' => $label_url
        ));
    }
}
