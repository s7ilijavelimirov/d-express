<?php

/**
 * D Express Order Columns Handler
 * 
 * Odgovoran za dodavanje i upravljanje custom kolona u WooCommerce order listama
 */

defined('ABSPATH') || exit;

class D_Express_Order_Columns
{
    private $column_renderer;

    /**
     * Konstruktor
     */
    public function __construct()
    {
        $this->column_renderer = new D_Express_Column_Renderer();
        $this->init_hooks();
    }

    /**
     * Inicijalizacija hook-ova
     */
    private function init_hooks()
    {
        // Dodavanje kolona
        $this->add_tracking_column_hooks();
        $this->add_label_printed_column_hooks();
        $this->add_shipment_status_column_hooks();
    }

    /**
     * Hook-ovi za tracking kolonu
     */
    private function add_tracking_column_hooks()
    {
        // Za stari način (posts)
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_tracking_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'show_order_tracking_column_data'), 10, 2);

        // Za WooCommerce HPOS
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_order_tracking_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'show_order_tracking_column_data'), 10, 2);
    }

    /**
     * Hook-ovi za label printed kolonu
     */
    private function add_label_printed_column_hooks()
    {
        // Za stari način (posts)
        add_filter('manage_edit-shop_order_columns', array($this, 'add_wc_orders_label_printed_column'), 20);
        add_action('manage_shop_order_posts_custom_column', array($this, 'show_wc_orders_label_printed_column'), 20, 2);

        // Za WooCommerce HPOS
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_wc_orders_label_printed_column'), 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'show_wc_orders_label_printed_column'), 20, 2);
    }

    /**
     * Hook-ovi za shipment status kolonu
     */
    private function add_shipment_status_column_hooks()
    {
        // Za stari način (posts)
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_shipment_status_column'), 21);
        add_action('manage_shop_order_posts_custom_column', array($this, 'show_order_shipment_status_column'), 21, 2);

        // Za WooCommerce HPOS
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_order_shipment_status_column'), 21);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'show_order_shipment_status_column'), 21, 2);
    }

    /**
     * Dodavanje tracking kolone
     */
    public function add_order_tracking_column($columns)
    {
        $new_columns = array();

        foreach ($columns as $column_name => $column_info) {
            $new_columns[$column_name] = $column_info;

            if ($column_name === 'order_status') {
                $new_columns['dexpress_tracking'] = __('D Express Praćenje', 'd-express-woo');
            }
        }

        return $new_columns;
    }

    /**
     * Prikazivanje tracking kolone
     */
    public function show_order_tracking_column_data($column, $order_id)
    {
        if ($column === 'dexpress_tracking') {
            $this->column_renderer->render_tracking_column($order_id);
        }
    }

    /**
     * Dodavanje label printed kolone
     */
    public function add_wc_orders_label_printed_column($columns)
    {
        $new_columns = $columns;
        $new_columns['dexpress_label_printed'] = __('D Express nalepnica', 'd-express-woo');
        return $new_columns;
    }

    /**
     * Prikazivanje label printed kolone
     */
    public function show_wc_orders_label_printed_column($column, $post_or_order_id)
    {
        if ($column === 'dexpress_label_printed') {
            $order_id = is_object($post_or_order_id) ? $post_or_order_id->get_id() : $post_or_order_id;
            $this->column_renderer->render_label_printed_column($order_id);
        }
    }

    /**
     * Dodavanje shipment status kolone
     */
    public function add_order_shipment_status_column($columns)
    {
        $new_columns = array();

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            // Dodaj našu kolonu nakon kolone za D Express nalepnicu
            if ($key === 'dexpress_label_printed') {
                $new_columns['dexpress_shipment_status'] = __('Status pošiljke', 'd-express-woo');
            }
        }

        // Ako nije ubačena, dodaj na kraj
        if (!isset($new_columns['dexpress_shipment_status'])) {
            $new_columns['dexpress_shipment_status'] = __('Status pošiljke', 'd-express-woo');
        }

        return $new_columns;
    }

    /**
     * Prikazivanje shipment status kolone
     */
    public function show_order_shipment_status_column($column, $post_or_order_id)
    {
        if ($column === 'dexpress_shipment_status') {
            $order_id = is_object($post_or_order_id) ? $post_or_order_id->get_id() : $post_or_order_id;
            $this->column_renderer->render_shipment_status_column($order_id);
        }
    }
}