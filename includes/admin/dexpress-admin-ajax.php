<?php

/**
 * D Express Admin AJAX
 * 
 * AJAX funkcije za admin panel
 */
// dexpress-admin-ajax.php
defined('ABSPATH') || exit;

add_action('wp_ajax_dexpress_create_shipment', array(D_Express_WooCommerce::get_instance()->get_order_handler(), 'ajax_create_shipment'));
