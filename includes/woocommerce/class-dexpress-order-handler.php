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
     * @var D_Express_Shipment_Service
     */
    private $shipment_service;

    /**
     * Konstruktor
     */
    public function __construct()
    {
        $this->shipment_service = new D_Express_Shipment_Service();
    }

    /**
     * Inicijalizacija order handlera
     */
    public function init()
    {
        // Čuvanje D Express opcija pri čuvanju narudžbine
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_dexpress_meta_box'), 10, 1);

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
        dexpress_log('Order processed - manual shipment creation required: ' . $order_id, 'debug');
    }

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
}
