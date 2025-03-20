<?php
/**
 * D Express Admin AJAX
 * 
 * AJAX funkcije za admin panel
 */
// dexpress-admin-ajax.php
defined('ABSPATH') || exit;

// Staro:
// add_action('wp_ajax_dexpress_create_shipment', array(D_Express_WooCommerce::get_instance()->get_order_handler(), 'ajax_create_shipment'));

// Novo:
add_action('wp_ajax_dexpress_create_shipment', function() {
    $service = new D_Express_Shipment_Service();
    
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

    // Provera ID-a narudžbine
    if (!isset($_POST['order_id']) || empty($_POST['order_id'])) {
        wp_send_json_error(array(
            'message' => __('ID narudžbine je obavezan.', 'd-express-woo')
        ));
    }

    $order_id = intval($_POST['order_id']);
    $order = wc_get_order($order_id);

    if (!$order) {
        wp_send_json_error(array(
            'message' => __('Narudžbina nije pronađena.', 'd-express-woo')
        ));
    }

    // Kreiranje pošiljke
    $result = $service->create_shipment($order);

    if (is_wp_error($result)) {
        wp_send_json_error(array(
            'message' => $result->get_error_message()
        ));
    } else {
        wp_send_json_success(array(
            'message' => __('Pošiljka je uspešno kreirana.', 'd-express-woo'),
            'shipment_id' => $result
        ));
    }
});