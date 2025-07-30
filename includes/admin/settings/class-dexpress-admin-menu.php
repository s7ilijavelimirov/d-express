<?php

/**
 * D Express Admin Menu Handler
 * 
 * Odgovoran za kreiranje admin menija i podmenia
 */

defined('ABSPATH') || exit;

class D_Express_Admin_Menu
{
    /**
     * Konstruktor
     */
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    /**
     * Dodavanje admin menija
     */
    public function add_admin_menu()
    {
        // Provjeri da li je WooCommerce aktivan
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Provjeri dozvole
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Koristimo statičku promenljivu da sprečimo duplo dodavanje
        static $added = false;
        if ($added) {
            return;
        }
        $added = true;

        $icon_url = DEXPRESS_WOO_PLUGIN_URL . 'assets/images/dexpress-icon.svg';

        // Dodajemo glavni meni sa SVG ikonicom
        add_menu_page(
            __('D Express Podešavanja', 'd-express-woo'),  // Naslov stranice
            __('D Express', 'd-express-woo'),              // Tekst glavnog menija
            'manage_woocommerce',
            'dexpress-settings',                           // Slug za glavni meni
            array($this, 'render_settings_page'),
            $icon_url,
            56
        );

        // Dodajemo prvi podmeni koji vodi na istu stranicu kao glavni meni
        add_submenu_page(
            'dexpress-settings',                           // Parent slug
            __('D Express Podešavanja', 'd-express-woo'),  // Naslov stranice
            __('Podešavanja', 'd-express-woo'),            // Tekst podmenija
            'manage_woocommerce',
            'dexpress-settings',                           // Isti slug kao glavni meni
            array($this, 'render_settings_page')
        );

        // Pošiljke
        add_submenu_page(
            'dexpress-settings',
            __('D Express Pošiljke', 'd-express-woo'),
            __('Pošiljke', 'd-express-woo'),
            'manage_woocommerce',
            'dexpress-shipments',
            array($this, 'render_shipments_page')
        );

        // Dijagnostika
        add_submenu_page(
            'dexpress-settings',
            __('D Express Dijagnostika', 'd-express-woo'),
            __('Dijagnostika', 'd-express-woo'),
            'manage_woocommerce',
            'dexpress-diagnostics',
            'dexpress_render_diagnostics_page'
        );

        // Izveštaji
        if (class_exists('D_Express_Reports')) {
            $reports = new D_Express_Reports();
            if (method_exists($reports, 'render_reports_page')) {
                add_submenu_page(
                    'dexpress-settings',
                    __('D Express Izveštaji', 'd-express-woo'),
                    __('Izveštaji', 'd-express-woo'),
                    'manage_woocommerce',
                    'dexpress-reports',
                    array($reports, 'render_reports_page')
                );
            }
        }
    }

    /**
     * Renderuje settings stranicu
     * Delegira na D_Express_Settings_Renderer klasu
     */
    public function render_settings_page()
    {
        if (class_exists('D_Express_Settings_Renderer')) {
            $renderer = new D_Express_Settings_Renderer();
            $renderer->render();
        } else {
            echo '<div class="wrap"><h1>D Express Settings</h1><p>Settings renderer not loaded.</p></div>';
        }
    }

    /**
     * Renderuje shipments stranicu
     */
    public function render_shipments_page()
    {
        if (class_exists('D_Express_Shipments_List')) {
            dexpress_shipments_list();
        } else {
            echo '<div class="wrap">';
            echo '<h1>' . __('D Express Pošiljke', 'd-express-woo') . '</h1>';
            echo '<p>' . __('Pregled svih D Express pošiljki.', 'd-express-woo') . '</p>';
            echo '<div class="notice notice-info">';
            echo '<p>' . __('Kompletna stranica za pregled pošiljki još nije implementirana.', 'd-express-woo') . '</p>';
            echo '</div>';
            echo '</div>';
        }
    }

    /**
     * Redirekcija za dashboard
     */
    public function render_dashboard_page()
    {
        wp_redirect(admin_url('admin.php?page=dexpress-settings'));
        exit;
    }
}