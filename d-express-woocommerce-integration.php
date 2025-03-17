<?php

/**
 * Plugin Name: D Express WooCommerce Integration
 * Description: Integracija D Express dostave sa WooCommerce prodavnicama
 * Version: 1.0.0
 * Author: S7Code&Design
 * Text Domain: d-express-woo
 * Domain Path: /languages
 * Requires at least: 6.7.2
 * Requires PHP: 7.2
 * WC requires at least: 9.0
 * WC tested up to: 9.6.2
 * WooCommerce: true
 */
/** @var string $WC_VERSION */
defined('ABSPATH') || exit;

// Deklaracija HPOS kompatibilnosti
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});


define('DEXPRESS_WOO_VERSION', '1.0.0');
define('DEXPRESS_WOO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DEXPRESS_WOO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DEXPRESS_WOO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Glavna klasa plugin-a
 */
class D_Express_WooCommerce
{

    /**
     * Instanca klase (singleton pattern)
     */
    private static $instance = null;

    /**
     * Konstruktor klase
     */
    private function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Vraća instancu klase (singleton pattern)
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Uključivanje potrebnih datoteka
     */
    private function includes()
    {
        // Helpers i utility funkcije
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/dexpress-woo-helpers.php';

        // API klasa
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/api/class-dexpress-api.php';

        // Klase za bazu podataka
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/db/class-dexpress-db.php';

        // Admin klase
        if (is_admin()) {
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-admin.php';
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/dexpress-admin-ajax.php'; // Dodaj ovu liniju
        }

        // WooCommerce integracija klase
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/woocommerce/class-dexpress-shipping-method.php';
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/woocommerce/class-dexpress-order-handler.php';

        // Frontend klase
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/frontend/class-dexpress-tracking.php';

        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/api/class-dexpress-label-generator.php';
        // Webhook handler
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/class-dexpress-webhook-handler.php';

        // Checkout klase
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/woocommerce/class-dexpress-checkout.php';
    }

    /**
     * Inicijalizacija hook-ova
     */
    private function init_hooks()
    {
        // Aktivacija plugin-a
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Deaktivacija plugin-a
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Inicijalizacija nakon učitavanja svih plugin-a
        add_action('plugins_loaded', array($this, 'init'), 0);

        // Dodavanje akcija na WooCommerce checkout
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_address'));

        // Dodavanje REST API ruta za webhook
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Aktivacija plugin-a
     */
    public function activate()
    {
        if (is_admin()) {
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-admin.php';
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/dexpress-admin-ajax.php';
        }
        // Kreiranje potrebnih tabela u bazi
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/db/class-dexpress-db-installer.php';
        $installer = new D_Express_DB_Installer();
        $installer->install();

        // Postavljanje potrebnih opcija
        $this->set_default_options();

        // Flush rewrite rules za REST API endpoint
        flush_rewrite_rules();
    }

    /**
     * Deaktivacija plugin-a
     */
    public function deactivate()
    {
        // Čišćenje cron zadataka
        wp_clear_scheduled_hook('dexpress_daily_update_indexes');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Inicijalizacija plugin-a
     */
    public function init()
    {
        $label_generator = new D_Express_Label_Generator();

        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Provera verzije WooCommerce-a
        $wc_version = WC()->version; // Ovo je sigurniji način
        if (version_compare($wc_version, '9.6', '<')) {
            add_action('admin_notices', array($this, 'woocommerce_version_notice'));
            return;
        }


        // Učitavanje prevoda
        load_plugin_textdomain('d-express-woo', false, dirname(DEXPRESS_WOO_PLUGIN_BASENAME) . '/languages');

        // Inicijalizacija CRON zadataka
        $this->init_cron_jobs();

        // Inicijalizacija checkout klase
        $checkout = new D_Express_Checkout();
        $checkout->init();

        // Inicijalizacija admin klasa
        if (is_admin()) {
            $admin = new D_Express_Admin();
            $admin->init();
        }

        // Inicijalizacija frontend klasa
        $tracking = new D_Express_Tracking();
        $tracking->init();

        // Inicijalizacija order handlera
        $order_handler = new D_Express_Order_Handler();
        $order_handler->init();
    }

    /**
     * Inicijalizacija CRON zadataka
     */
    private function init_cron_jobs()
    {
        // Dnevno ažuriranje šifarnika
        if (!wp_next_scheduled('dexpress_daily_update_indexes')) {
            wp_schedule_event(time(), 'daily', 'dexpress_daily_update_indexes');
        }

        // Dodavanje hook-a za ažuriranje šifarnika
        add_action('dexpress_daily_update_indexes', array($this, 'update_indexes'));
    }

    /**
     * Postavlja podrazumevane opcije plugin-a
     */
    private function set_default_options()
    {
        $default_options = array(
            'dexpress_api_username' => '',
            'dexpress_api_password' => '',
            'dexpress_client_id' => '',
            'dexpress_code_prefix' => '',
            'dexpress_code_range_start' => '',
            'dexpress_code_range_end' => '',
            'dexpress_test_mode' => 'yes',
            'dexpress_auto_create_shipment' => 'no',
            'dexpress_auto_create_on_status' => 'processing',
            'dexpress_webhook_secret' => wp_generate_password(32, false),
        );

        foreach ($default_options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }

    /**
     * Validacija adrese na checkout-u
     */
    public function validate_checkout_address()
    {
        // Validacija adrese ako je izabrana D Express dostava
        // Implementacija u sledećem koraku
    }

    /**
     * Registracija REST API ruta za webhook
     */
    public function register_rest_routes()
    {
        register_rest_route('dexpress-woo/v1', '/notify', array(
            'methods' => 'POST',
            'callback' => array(new D_Express_Webhook_Handler(), 'handle_notify'),
            'permission_callback' => array(new D_Express_Webhook_Handler(), 'check_permission'),
        ));
    }

    /**
     * Ažuriranje šifarnika
     */
    public function update_indexes()
    {
        $api = new D_Express_API();
        $api->update_all_indexes();
    }

    /**
     * Obaveštenje o nedostatku WooCommerce-a
     */
    public function woocommerce_missing_notice()
    {
        echo '<div class="error"><p>' .
            sprintf(
                __('D Express WooCommerce Integration zahteva WooCommerce plugin. Molimo %sinstalirajte i aktivirajte WooCommerce%s.', 'd-express-woo'),
                '<a href="' . admin_url('plugin-install.php?s=woocommerce&tab=search&type=term') . '">',
                '</a>'
            ) .
            '</p></div>';
    }
    /**
     * Obaveštenje o neodgovarajućoj verziji WooCommerce-a
     */
    public function woocommerce_version_notice()
    {
        echo '<div class="error"><p>' .
            sprintf(
                __('D Express WooCommerce Integration zahteva WooCommerce %s ili noviju verziju. Molimo nadogradite WooCommerce.', 'd-express-woo'),
                '9.6'
            ) .
            '</p></div>';
    }
}

// Inicijalizacija glavnog plugin objekta
function D_Express_WooCommerce_Init()
{
    return D_Express_WooCommerce::get_instance();
}

// Pokretanje plugin-a
D_Express_WooCommerce_Init();
