<?php

/**
 * D Express Admin klasa - REFAKTORISANA
 * 
 * Glavna admin klasa koja koordinira sve admin funkcionalnosti
 * Sada je kraća i delegira odgovornosti na specijalizovane klase
 */

defined('ABSPATH') || exit;

class D_Express_Admin
{
    private $menu_handler;
    private $assets_handler;
    private $actions_handler;
    private $columns_handler;

    /**
     * Konstruktor klase
     */
    public function __construct()
    {
        $this->init_handlers();
        $this->init_admin_features();
    }

    /**
     * Inicijalizacija handler klasa
     */
    private function init_handlers()
    {
        // Uključi sve potrebne fajlove
        $this->include_admin_files();

        // Inicijalizuj handler klase
        $this->menu_handler = new D_Express_Admin_Menu();
        $this->assets_handler = new D_Express_Admin_Assets();
        $this->actions_handler = new D_Express_Admin_Actions();
        
        // Order columns handler (ćemo napraviti sledeće)
        if (class_exists('D_Express_Order_Columns')) {
            $this->columns_handler = new D_Express_Order_Columns();
        }

        // Inicijalizacija AJAX i Metabox handlers (postojeće klase)
        if (class_exists('D_Express_Admin_Ajax')) {
            new D_Express_Admin_Ajax();
        }
        
        if (class_exists('D_Express_Order_Metabox')) {
            new D_Express_Order_Metabox();
        }
    }

    /**
     * Uključivanje admin fajlova
     */
    private function include_admin_files()
    {
        $admin_path = DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/';
        
        // Settings fajlovi - VAŽAN REDOSLED!
        $settings_files = [
            'settings/class-dexpress-settings-tabs.php',      // 1. Prvo tabs (nema dependencies)
            'settings/class-dexpress-settings-handler.php',   // 2. Zatim handler
            'settings/class-dexpress-settings-renderer.php',  // 3. Zatim renderer (koristi handler)
            'settings/class-dexpress-admin-menu.php',         // 4. Menu (koristi renderer)
            'settings/class-dexpress-admin-assets.php',       // 5. Assets (premešten u settings)
            'settings/class-dexpress-admin-actions.php'       // 6. Actions (premešten u settings)
        ];

        foreach ($settings_files as $file) {
            $file_path = $admin_path . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                // Log missing file (za debug)
                if (function_exists('dexpress_log')) {
                    dexpress_log("Missing admin file: {$file}", 'warning');
                }
            }
        }

        // Columns fajlovi
        $columns_files = [
            'columns/class-dexpress-column-renderer.php',     // 1. Prvo renderer
            'columns/class-dexpress-order-columns.php'       // 2. Zatim columns (koristi renderer)
        ];

        foreach ($columns_files as $file) {
            $file_path = $admin_path . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }

    /**
     * Inicijalizacija osnovnih admin funkcionalnosti
     */
    private function init_admin_features()
    {
        // Format order address phone (zadržano iz originala)
        add_filter('woocommerce_get_order_address', array($this, 'format_order_address_phone'), 10, 3);
    }

    /**
     * Format order address phone - zadržano iz originalne klase
     */
    public function format_order_address_phone($address, $type, $order)
    {
        if ($type === 'billing' && isset($address['phone'])) {
            // Proveri da li telefon već počinje sa +381
            if (strpos($address['phone'], '+381') !== 0) {
                // Proveriti da li postoji sačuvani API format
                $api_phone = get_post_meta($order->get_id(), '_billing_phone_api_format', true);

                if (!empty($api_phone) && strpos($api_phone, '381') === 0) {
                    // Dodaj + ispred API broja
                    $address['phone'] = '+' . $api_phone;
                } else {
                    // Ako ne, formatiraj standardni telefon
                    $phone = preg_replace('/[^0-9]/', '', $address['phone']);

                    // Ukloni početnu nulu ako postoji
                    if (strlen($phone) > 0 && $phone[0] === '0') {
                        $phone = substr($phone, 1);
                    }

                    // Dodaj prefiks ako ne postoji
                    if (strpos($phone, '381') !== 0) {
                        $address['phone'] = '+381' . $phone;
                    } else {
                        $address['phone'] = '+' . $phone;
                    }
                }
            }
        }

        return $address;
    }

    /**
     * Getter metode za handler klase (ako budu potrebne)
     */
    public function get_menu_handler()
    {
        return $this->menu_handler;
    }

    public function get_assets_handler()
    {
        return $this->assets_handler;
    }

    public function get_actions_handler()
    {
        return $this->actions_handler;
    }

    public function get_columns_handler()
    {
        return $this->columns_handler;
    }
}