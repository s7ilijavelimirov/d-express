<?php

/**
 * Plugin Name: D Express WooCommerce Integration
 * Description: Integracija D Express dostave sa WooCommerce prodavnicama
 * Version: 1.0.3
 * Author: S7Code&Design
 * Text Domain: d-express-woo
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.2
 * WC requires at least: 9.0
 * WC tested up to: 9.6.2
 * WooCommerce: true
 */
/** @var string $WC_VERSION */
defined('ABSPATH') || exit;

define('DEXPRESS_WOO_VERSION', '1.0.3');
define('DEXPRESS_WOO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DEXPRESS_WOO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DEXPRESS_WOO_PLUGIN_BASENAME', plugin_basename(__FILE__));


// Deklaracija HPOS kompatibilnosti
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});
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
        // Helpers i utility funkcije - uvek učitaj
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/dexpress-woo-helpers.php';

        // API klasa
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/api/class-dexpress-api.php';

        // Klase za bazu podataka
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/db/class-dexpress-db.php';

        // Servisne klase
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/services/class-dexpress-sender-locations.php';

        // // CRON
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/class-dexpress-cron-manager.php';

        // Validator
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/class-dexpress-validator.php';

        // Webhook handler
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/class-dexpress-webhook-handler.php';

        // Conditional loading - samo ako je WooCommerce aktivan
        add_action('woocommerce_loaded', array($this, 'load_woocommerce_dependent_files'));
    }
    public function load_woocommerce_dependent_files()
    {
        // Proveri da li je WooCommerce stvarno aktivan
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Timeline
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-order-timeline.php';

        // Admin klase - samo ako je admin
        if (is_admin()) {
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-admin.php';
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-admin-ajax.php';
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-order-metabox.php';
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-shipments-list.php';
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-reports.php';
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-diagnostics.php';

            // Dashboard widget - samo ako je WooCommerce aktivan
            if (function_exists('wc_get_order')) {
                require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-dashboard-widget.php';
            }
        }

        // Servisne klase
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/services/class-dexpress-shipment-service.php';

        // EMAIL
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/class-dexpress-email-manager.php';

        // WooCommerce integracija klase
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/woocommerce/class-dexpress-shipping-method.php';
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/woocommerce/class-dexpress-order-handler.php';
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/woocommerce/class-dexpress-checkout.php';
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/woocommerce/class-dexpress-dispenser-shipping-method.php';

        // Frontend klase
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/frontend/class-dexpress-tracking.php';

        // API klase
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/api/class-dexpress-label-generator.php';
    }
    /**
     * Inicijalizacija hook-ova
     */
    private function init_hooks()
    {
        // Plugin activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Hook za deaktivaciju WooCommerce-a
        add_action('deactivated_plugin', array($this, 'on_woocommerce_deactivated'), 10, 2);

        // Dodavanje REST API ruta za webhook - pomereno pre plugins_loaded
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Dodavanje webhook obrade za cron - pomereno pre plugins_loaded
        add_action('dexpress_process_notification', array($this, 'process_notification'));

        // Inicijalizacija nakon učitavanja svih plugin-a
        add_action('plugins_loaded', array($this, 'init'), 20);

        // Dodaj hook za simulaciju
        //add_action('init', array($this, 'auto_simulate_test_statuses'));

        if (dexpress_is_test_mode()) {
            add_action('init', array($this, 'register_frequent_status_checks_test_only'));
        }
    }
    public function register_frequent_status_checks_test_only()
    {
        if (!dexpress_is_test_mode()) {
            wp_clear_scheduled_hook('dexpress_test_simulation');
            return;
        }
        add_filter('cron_schedules', function ($schedules) {
            $schedules['thirty_minutes'] = array(
                'interval' => 1800, // 30 minuta
                'display'  => __('Svakih 30 minuta', 'd-express-woo')
            );
            return $schedules;
        });

        if (!wp_next_scheduled('dexpress_test_simulation')) {
            wp_schedule_event(time(), 'thirty_minutes', 'dexpress_test_simulation');
        }

        add_action('dexpress_test_simulation', array($this, 'auto_simulate_test_statuses'));
    }
    public function on_woocommerce_deactivated($plugin, $network_deactivating)
    {
        // Proveri da li je deaktiviran WooCommerce
        if (strpos($plugin, 'woocommerce.php') !== false) {
            // Gracefully handle WooCommerce deactivation
            $this->cleanup_on_woocommerce_deactivation();
        }
    }

    /**
     * 5. DODAJ ovu metodu u klasu:
     */
    private function cleanup_on_woocommerce_deactivation()
    {
        // Ukloni scheduled cron jobs
        if (class_exists('D_Express_Cron_Manager')) {
            D_Express_Cron_Manager::clear_all_cron_jobs();
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Log deactivation
        if (function_exists('dexpress_log')) {
            dexpress_log('WooCommerce deaktiviran - D Express plugin se privremeno suspenduje', 'info');
        }
    }
    /**
     * Provera statusa pošiljki koje su u obradi
     */
    public function check_pending_statuses()
    {
        global $wpdb;

        // 1. Optimizovano dohvatanje pošiljki koje zaista treba proveriti
        $all_statuses = dexpress_get_all_status_codes();
        $pending_status_ids = array();

        foreach ($all_statuses as $id => $status_info) {
            // Ažuriramo SAMO pošiljke u tranzitu ili čekanju, a ne sve
            if (in_array($status_info['group'], ['pending', 'transit', 'out_for_delivery'])) {
                $pending_status_ids[] = "'" . esc_sql($id) . "'";
            }
        }

        // 2. Efikasnije dohvatanje po batch-evima
        $batch_size = 50; // Manji batch za efikasnije izvršavanje
        $max_batches = 10; // Maksimalno 500 pošiljki po cron izvršavanju
        $offset = 0;
        $batch_count = 0;

        // 3. Pametnije filtriranje - samo najnovije pošiljke i one u određenim statusima
        $status_condition = "(status_code IS NULL";
        if (!empty($pending_status_ids)) {
            $status_condition .= " OR status_code IN (" . implode(',', $pending_status_ids) . ")";
        }
        $status_condition .= ")";

        do {
            // 4. Dohvati pošiljke po batch-evima
            $pending_shipments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, order_id, shipment_id, tracking_number, reference_id, 
                            status_code, is_test, created_at
                     FROM {$wpdb->prefix}dexpress_shipments 
                     WHERE %s 
                     AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                     ORDER BY created_at DESC
                     LIMIT %d, %d",
                    $status_condition,
                    $offset,
                    $batch_size
                )
            );

            if (empty($pending_shipments)) {
                break;
            }

            dexpress_log('Obrada batch-a pošiljki: ' . count($pending_shipments), 'debug');

            // 5. Efikasnije procesiranje statusa
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/services/class-dexpress-shipment-service.php';
            $shipment_service = new D_Express_Shipment_Service();

            foreach ($pending_shipments as $shipment) {
                // 6. Izbegavanje API poziva za testirane pošiljke
                if ($shipment->is_test) {
                    $timeline = new D_Express_Order_Timeline();
                    $timeline->simulate_test_mode_statuses($shipment->id);
                } else {
                    // 7. Pametnije ažuriranje - proverimo vreme poslednjeg ažuriranja
                    $last_check = get_post_meta($shipment->order_id, '_dexpress_last_status_check', true);
                    $current_time = time();

                    // Ažuriraj status samo ako je prošlo više od 30 minuta od poslednje provere
                    if (empty($last_check) || ($current_time - intval($last_check)) > 1800) {
                        $shipment_service->sync_shipment_status($shipment->id);
                        update_post_meta($shipment->order_id, '_dexpress_last_status_check', $current_time);
                    }
                }
            }

            $offset += $batch_size;
            $batch_count++;

            // 8. Preveniranje preopterećenja - ograničenje maksimalnog broja batch-eva po izvršavanju
            if ($batch_count >= $max_batches) {
                break;
            }
        } while (count($pending_shipments) == $batch_size);
    }
    /**
     * Simulacija statusa pošiljki u test režimu
     */
    public function auto_simulate_test_statuses()
    {
        if (!class_exists('WooCommerce') || !dexpress_is_test_mode()) {
            return;
        }

        global $wpdb;

        // Dohvati test pošiljke kreirane u poslednjih 7 dana
        $test_shipments = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments 
        WHERE is_test = 1 
        AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at ASC"
        );

        if (empty($test_shipments)) {
            return;
        }

        $timeline = new D_Express_Order_Timeline();
        foreach ($test_shipments as $shipment) {
            // Pozovi simulaciju za svaku pošiljku
            $timeline->simulate_test_mode_statuses($shipment->id);
        }
    }
    /**
     * Aktivacija plugin-a
     */
    public function activate()
    {
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('D Express WooCommerce Integration zahteva WooCommerce plugin.', 'd-express-woo'));
        }

        // Postojeći activation kod...
        if (is_admin()) {
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-admin.php';
        }

        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/db/class-dexpress-db-installer.php';
        $installer = new D_Express_DB_Installer();
        $installer->install();

        // Schema verzija
        update_option('dexpress_schema_version', '2.0.0');

        // Default opcije
        $this->set_default_options();

        // Clear cache
        wp_clear_scheduled_hook('dexpress_check_pending_statuses');
        wp_clear_scheduled_hook('dexpress_daily_update_indexes');

        flush_rewrite_rules();
    }
    /**
     * Deaktivacija plugin-a
     */
    public function deactivate()
    {
        if (class_exists('D_Express_Cron_Manager')) {
            D_Express_Cron_Manager::clear_all_cron_jobs();
        }
        wp_clear_scheduled_hook('dexpress_check_pending_statuses');
        wp_clear_scheduled_hook('dexpress_daily_update_indexes');
        wp_clear_scheduled_hook('dexpress_test_simulation'); // OK za deaktivaciju

        flush_rewrite_rules();
    }

    /**
     * Inicijalizacija plugin-a
     */
    public function init()
    {
        // Prvo učitaj prevode DIREKTNO
        load_plugin_textdomain('d-express-woo', false, dirname(DEXPRESS_WOO_PLUGIN_BASENAME) . '/languages');

        // Proveri da li WooCommerce postoji
        if (!$this->check_woocommerce_activation()) {
            return;
        }

        // Inicijalizacija samo osnovnih klasa koje ne koriste WooCommerce odmah
        if (did_action('woocommerce_loaded')) {
            $this->init_after_woocommerce();
        } else {
            add_action('woocommerce_loaded', array($this, 'init_after_woocommerce'));
        }
        D_Express_Cron_Manager::init_cron_jobs();
    }

    /**
     * Inicijalizacija nakon što se WooCommerce učita
     */
    public function init_after_woocommerce()
    {
        // Proveri da li je WooCommerce stvarno aktivan
        if (!class_exists('WooCommerce') || !function_exists('wc_get_order')) {
            return;
        }

        // Provera verzije WooCommerce-a
        if (version_compare(WC()->version, '9.0', '<')) {
            add_action('admin_notices', array($this, 'woocommerce_version_notice'));
            return;
        }

        try {
            // Inicijalizacija klasa koje koriste WooCommerce
            if (class_exists('D_Express_Label_Generator')) {
                $label_generator = new D_Express_Label_Generator();
            }

            // Inicijalizacija checkout klase
            if (class_exists('D_Express_Checkout')) {
                $checkout = new D_Express_Checkout();
                $checkout->init();
            }

            // Inicijalizacija admin klasa
            if (is_admin()) {
                if (class_exists('D_Express_Admin')) {
                    $admin = new D_Express_Admin();
                }

                if (class_exists('D_Express_Admin_Ajax')) {
                    $admin_ajax = new D_Express_Admin_Ajax();
                }
            }

            // Inicijalizacija frontend klasa
            if (class_exists('D_Express_Tracking')) {
                $tracking = new D_Express_Tracking();
                $tracking->init();
            }

            if (class_exists('D_Express_Order_Handler')) {
                $order_handler = new D_Express_Order_Handler();
                $order_handler->init();
            }

            // Inicijalizacija timeline-a
            if (class_exists('D_Express_Order_Timeline')) {
                $timeline = new D_Express_Order_Timeline();
                $timeline->init();
            }
        } catch (Exception $e) {
            if (function_exists('dexpress_log')) {
                dexpress_log('Greška pri inicijalizaciji D Express plugin-a: ' . $e->getMessage(), 'error');
            }
        }
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
            'dexpress_payment_by' => '0',        // 0 = Nalogodavac (umesto Pošiljalac)
            'dexpress_shipment_type' => '2',     // 2 = Redovna isporuka (jedina opcija)
            'dexpress_payment_type' => '2',      // 2 = Faktura (default)
            'dexpress_return_doc' => '0',        // 0 = Bez povraćaja (default)
        );

        foreach ($default_options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }

    /**
     * Registracija REST API ruta za webhook
     */
    public function register_rest_routes()
    {
        register_rest_route('dexpress-woo/v1', '/notify', [
            'methods' => 'POST',
            'callback' => [new D_Express_Webhook_Handler(), 'handle_notify'],
            'permission_callback' => [new D_Express_Webhook_Handler(), 'check_permission'],
            'args' => [
                'cc' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Webhook passcode',
                ],
                'nID' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Notification ID',
                ],
                'code' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Shipment code',
                ],
                'rID' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Reference ID',
                ],
                'sID' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Status ID',
                ],
                'dt' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Date time in format yyyyMMddHHmmss',
                ],
            ],
        ]);
    }

    /**
     * Ažuriranje šifarnika
     */
    public function update_indexes()
    {
        $api = D_Express_API::get_instance();

        // Ažuriranje statusa
        $statuses = $api->get_statuses();
        if (!is_wp_error($statuses) && is_array($statuses)) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'dexpress_statuses_index';

            foreach ($statuses as $status) {
                $wpdb->replace(
                    $table_name,
                    array(
                        'id' => $status['ID'],
                        'name' => $status['Name'],
                        'description' => isset($status['NameEn']) ? $status['NameEn'] : null,
                        'last_updated' => current_time('mysql')
                    ),
                    array('%d', '%s', '%s', '%s')
                );
            }

            dexpress_log('Uspešno ažurirani statusi iz API-ja. Ukupno: ' . count($statuses), 'info');
        } else {
            dexpress_log('Greška pri ažuriranju statusa: ' . (is_wp_error($statuses) ? $statuses->get_error_message() : 'Nepoznata greška'), 'error');
        }

        // Pozivanje ostalih metoda za ažuriranje ostalih šifarnika
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
    /**
     * Obrada notifikacije - delegira webhook handler-u
     */
    public function process_notification($notification_id)
    {
        $webhook_handler = new D_Express_Webhook_Handler();
        $webhook_handler->process_notification($notification_id);
    }
    private function check_woocommerce_activation()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }
        return true;
    }
}

// Inicijalizacija glavnog plugin objekta
function D_Express_WooCommerce_Init()
{
    return D_Express_WooCommerce::get_instance();
}

// Pokretanje plugin-a
D_Express_WooCommerce_Init();
