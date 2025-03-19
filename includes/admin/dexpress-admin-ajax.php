<?php
/**
 * D Express Admin AJAX
 * 
 * AJAX funkcije za admin panel
 */

defined('ABSPATH') || exit;

/**
 * AJAX handler za kreiranje D Express pošiljke
 */
function dexpress_ajax_create_shipment() {
    // Provera nonce-a
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-admin-nonce')) {
        wp_send_json_error(['message' => 'Sigurnosna provera nije uspela.']);
        return;
    }
    
    // Provera dozvola
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Nemate dozvolu za ovu akciju.']);
        return;
    }
    
    // Provera order_id
    if (!isset($_POST['order_id']) || empty($_POST['order_id'])) {
        wp_send_json_error(['message' => 'ID narudžbine je obavezan.']);
        return;
    }
    
    $order_id = intval($_POST['order_id']);
    
    // Proveri da li već postoji pošiljka
    global $wpdb;
    $existing_shipment = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
        $order_id
    ));
    
    if ($existing_shipment) {
        wp_send_json_error(['message' => 'Pošiljka već postoji za ovu narudžbinu.']);
        return;
    }
    
    // Kreiraj test pošiljku
    $shipment_id = 'DE' . rand(10000000, 99999999);
    $tracking_number = rand(10000000000000, 99999999999999);
    $reference_id = 'ORDER-' . $order_id . '-' . time();
    
    $wpdb->insert(
        $wpdb->prefix . 'dexpress_shipments',
        [
            'order_id' => $order_id,
            'shipment_id' => $shipment_id,
            'tracking_number' => $tracking_number,
            'reference_id' => $reference_id,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'is_test' => 1
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%d']
    );
    
    $insert_id = $wpdb->insert_id;
    
    if (!$insert_id) {
        wp_send_json_error(['message' => 'Greška pri kreiranju pošiljke: ' . $wpdb->last_error]);
        return;
    }
    
    // Dodaj napomenu na narudžbinu
    $order = wc_get_order($order_id);
    if ($order) {
        $order->add_order_note(sprintf(
            __('D Express pošiljka je kreirana. Tracking broj: %s', 'd-express-woo'),
            $tracking_number
        ));
    }
    
    wp_send_json_success([
        'message' => 'Pošiljka je uspešno kreirana!',
        'shipment_id' => $insert_id,
        'tracking_number' => $tracking_number,
        'label_url' => admin_url('admin-ajax.php?action=dexpress_download_label&shipment_id=' . $insert_id . '&nonce=' . wp_create_nonce('dexpress-download-label'))
    ]);
}
add_action('wp_ajax_dexpress_create_shipment', 'dexpress_ajax_create_shipment');
