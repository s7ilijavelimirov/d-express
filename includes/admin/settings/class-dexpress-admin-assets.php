<?php

/**
 * D Express Admin Assets Handler
 * 
 * Odgovoran za uključivanje CSS i JS fajlova u admin panel
 */

defined('ABSPATH') || exit;

class D_Express_Admin_Assets
{
    /**
     * Konstruktor
     */
    public function __construct()
    {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Registracija admin stilova i skripti
     */
    public function enqueue_admin_assets($hook)
    {
        // Uvek učitaj osnovni CSS za ikonicu
        $this->enqueue_icon_styles();

        // Učitavaj ostale stilove i skripte samo na D Express stranicama
        if ($this->is_dexpress_page($hook)) {
            $this->enqueue_core_assets();
            $this->enqueue_dexpress_assets();
            $this->localize_scripts();
        }
    }

    /**
     * Proverava da li je trenutna stranica D Express stranica
     */
    private function is_dexpress_page($hook)
    {
        return (
            strpos($hook, 'dexpress') !== false ||
            $hook === 'toplevel_page_dexpress' ||
            (isset($_GET['page']) && strpos($_GET['page'], 'dexpress') !== false)
        );
    }

    /**
     * Učitava osnovne stilove za ikonicu menija
     */
    private function enqueue_icon_styles()
    {
        $custom_css = "
            #adminmenu .toplevel_page_dexpress-settings .wp-menu-image img {
                padding: 4px 0px !important;
                width: 26px !important;
                height: auto !important;
            }
        ";

        wp_register_style('dexpress-admin-icon-style', false);
        wp_enqueue_style('dexpress-admin-icon-style');
        wp_add_inline_style('dexpress-admin-icon-style', $custom_css);
    }

    /**
     * Učitava core WordPress assets
     */
    private function enqueue_core_assets()
    {
        // WordPress core skripte i stilove koje koristimo
        wp_enqueue_script('wp-pointer');
        wp_enqueue_style('wp-pointer');
        wp_enqueue_style('wp-auth-check');
    }

    /**
     * Učitava D Express specifične assets
     */
    private function enqueue_dexpress_assets()
    {
        if (isset($_GET['page']) && $_GET['page'] === 'dexpress-shipments') {
            wp_enqueue_style(
                'dexpress-shipments-css',
                DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-shipment-list.css',
                array(),
                DEXPRESS_WOO_VERSION
            );
        }
        // CSS
        wp_enqueue_style(
            'dexpress-admin-css',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-admin.css',
            array(),
            DEXPRESS_WOO_VERSION
        );

        // Main admin JS
        wp_enqueue_script(
            'dexpress-admin-js',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-admin.js',
            array('jquery'),
            DEXPRESS_WOO_VERSION,
            true
        );

        // Locations JS
        wp_enqueue_script(
            'dexpress-locations-js',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-locations.js',
            array('jquery', 'dexpress-admin-js'),
            DEXPRESS_WOO_VERSION,
            true
        );
    }

    /**
     * Localize skripte sa potrebnim podacima
     */
    private function localize_scripts()
    {
        // Osnovni admin podaci
        $admin_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dexpress_admin_nonce'),
            'i18n' => array(
                'confirmDelete' => __('Da li ste sigurni da želite da obrišete ovu lokaciju?', 'd-express-woo'),
                'error' => __('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'),
                'success' => __('Operacija uspešno izvršena.', 'd-express-woo'),
            )
        );

        // Localize za main admin script
        wp_localize_script('dexpress-admin-js', 'dexpressAdmin', $admin_data);
        wp_localize_script('dexpress-admin-js', 'dexpressL10n', array(
            'save_alert' => __('Niste sačuvali promene. Da li ste sigurni da želite da napustite ovu stranicu?', 'd-express-woo')
        ));

        // Localize za locations script
        wp_localize_script('dexpress-locations-js', 'dexpressAdmin', $admin_data);
    }
}
