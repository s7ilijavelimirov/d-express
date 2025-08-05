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

        // CRON
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/class-dexpress-cron-manager.php';

        // EXTERNAL CRON
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/class-dexpress-external-cron.php';

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

        add_action('admin_init', array($this, 'check_and_update_schema'));

        // Dodavanje webhook obrade za cron - pomereno pre plugins_loaded
        add_action('dexpress_process_notification', array($this, 'process_notification'));

        // Inicijalizacija nakon učitavanja svih plugin-a
        add_action('plugins_loaded', array($this, 'init'), 20);

        // Dodaj hook za simulaciju
        add_action('init', array($this, 'auto_simulate_test_statuses'));

        // Registrujemo česte provere statusa
        add_action('init', array($this, 'register_frequent_status_checks'));
    }
    /**
     * Registracija češćih provera statusa
     */
    public function register_frequent_status_checks()
    {
        // Dodajemo prilagođeni interval za češće provere
        add_filter('cron_schedules', function ($schedules) {
            $schedules['five_minutes'] = array(
                'interval' => 300,
                'display'  => __('Svakih 5 minuta', 'd-express-woo')
            );
            return $schedules;
        });

        // Registrujemo češće provere
        if (!wp_next_scheduled('dexpress_check_pending_statuses')) {
            wp_schedule_event(time(), 'five_minutes', 'dexpress_check_pending_statuses');
        }

        add_action('dexpress_check_pending_statuses', array($this, 'check_pending_statuses'));
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
        if (!class_exists('WooCommerce')) {
            return;
        }
        // Proveravamo da li je test režim aktivan
        if (!dexpress_is_test_mode()) {
            return;
        }
        if (!class_exists('D_Express_Order_Timeline')) {
            return;
        }
        global $wpdb;
        //dexpress_log('Pokrenuta automatska simulacija statusa pošiljki', 'info');

        // Dohvatamo pošiljke iz test režima koje su nedavno kreirane
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
            wp_die(__('D Express WooCommerce Integration zahteva WooCommerce plugin. Molimo instalirajte i aktivirajte WooCommerce.', 'd-express-woo'));
        }

        // Postojeći activation kod...
        if (is_admin()) {
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-admin.php';
        }

        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/db/class-dexpress-db-installer.php';
        $installer = new D_Express_DB_Installer();
        $installer->install();

        $db = new D_Express_DB();
        $db->add_shipment_index();
        D_Express_DB::update_multiple_shipments_schema();
        D_Express_DB::update_package_code_schema();

        update_option('dexpress_schema_version', '1.2.0');
        $this->set_default_options();

        if (class_exists('D_Express_Cron_Manager')) {
            D_Express_Cron_Manager::register_cron_endpoint();
        }
        flush_rewrite_rules();
    }
    /**
     * Provera i ažuriranje database schema
     */
    public function check_and_update_schema()
    {
        $schema_version = get_option('dexpress_schema_version', '1.0.0');
        $current_version = '1.2.0'; // Promeni na 1.2.0

        if (version_compare($schema_version, $current_version, '<')) {
            if (class_exists('D_Express_DB')) {
                // Dodaj sve schema update-ove
                D_Express_DB::update_shipments_table_schema();
                D_Express_DB::update_multiple_shipments_schema();
                D_Express_DB::update_package_code_schema(); // DODAJ OVO

                update_option('dexpress_schema_version', $current_version);

                if (function_exists('dexpress_log')) {
                    dexpress_log('Schema ažurirana na verziju ' . $current_version, 'info');
                }
            }
        }
    }
    /**
     * Deaktivacija plugin-a
     */
    public function deactivate()
    {
        if (class_exists('D_Express_Cron_Manager')) {
            D_Express_Cron_Manager::clear_all_cron_jobs();
        }

        // ✅ DODAJ ovu liniju:
        if (class_exists('D_Express_External_Cron_Service')) {
            D_Express_External_Cron_Service::cleanup();
        }

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

        // Inicijalizacija CRON zadataka (ne zavisi od WooCommerce)
        $this->init_autonomous_cron_jobs();
        $this->init_external_cron_service();
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
                    add_action('wp_ajax_dexpress_delete_shipment', array($admin_ajax, 'ajax_delete_shipment'));
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
    private function init_autonomous_cron_jobs()
    {
        D_Express_Cron_Manager::init_cron_jobs();
    }
    private function init_external_cron_service()
    {
        D_Express_External_Cron_Service::init();
        D_Express_External_Cron_Service::init_ajax();
    }
    private function ensure_woocommerce_active()
    {
        return class_exists('WooCommerce') && function_exists('wc_get_order');
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
            // AŽURIRANE DEFAULT VREDNOSTI:
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
     * Obrada webhook notifikacije (poziva se iz cron-a)
     * 
     * @param int $notification_id ID notifikacije u bazi
     */
    public function process_notification($notification_id)
    {
        global $wpdb;

        dexpress_log('Započeta obrada notifikacije ID: ' . $notification_id, 'debug');

        // Dohvatanje podataka o notifikaciji
        $notification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_statuses WHERE id = %d AND is_processed = 0",
            $notification_id
        ));

        if (!$notification) {
            dexpress_log('Notifikacija ID: ' . $notification_id . ' nije pronađena ili je već obrađena', 'warning');
            return;
        }

        // Dohvatanje pošiljke na osnovu reference_id ili shipment_code
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments 
    WHERE reference_id = %s 
       OR shipment_id = %s 
       OR tracking_number = %s
    ORDER BY created_at DESC LIMIT 1",
            $notification->reference_id,
            $notification->shipment_code,
            $notification->shipment_code
        ));

        if (!$shipment) {
            dexpress_log('Nije pronađena pošiljka za notifikaciju ID: ' . $notification_id, 'warning');
            // Označi kao obrađenu iako nije pronađena pošiljka
            $wpdb->update(
                $wpdb->prefix . 'dexpress_statuses',
                array('is_processed' => 1),
                array('id' => $notification_id)
            );
            return;
        }

        // Ažuriranje statusa pošiljke
        $wpdb->update(
            $wpdb->prefix . 'dexpress_shipments',
            array(
                'status_code' => $notification->status_id,
                'status_description' => dexpress_get_status_name($notification->status_id),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $shipment->id)
        );

        // Označi notifikaciju kao obrađenu
        $wpdb->update(
            $wpdb->prefix . 'dexpress_statuses',
            array('is_processed' => 1),
            array('id' => $notification_id)
        );

        // Dohvatanje narudžbine
        $order = wc_get_order($shipment->order_id);
        if ($order) {
            // Dodavanje napomene o promeni statusa
            $order->add_order_note(
                sprintf(
                    __('D Express status ažuriran: %s', 'd-express-woo'),
                    dexpress_get_status_name($notification->status_id)
                )
            );

            // Obrada specifičnih statusa - isporučeno, neisporučeno itd.
            $this->process_status_notification($notification, $shipment, $order);
        }

        dexpress_log('Notifikacija ID: ' . $notification_id . ' uspešno obrađena', 'debug');
    }

    /**
     * Obrada specifičnih statusa notifikacije
     * 
     * @param object $notification Podaci o notifikaciji
     * @param object $shipment Podaci o pošiljci
     * @param WC_Order $order WooCommerce narudžbina
     */
    private function process_status_notification($notification, $shipment, $order)
    {
        // Dobijanje informacija o statusu
        $status_id = $notification->status_id;
        $all_statuses = dexpress_get_all_status_codes();

        // Provera opcije za automatske email-ove
        $send_emails = get_option('dexpress_auto_status_emails', 'yes') === 'yes';

        // Proveri kojoj grupi pripada status
        $status_group = isset($all_statuses[$status_id]) ? $all_statuses[$status_id]['group'] : 'transit';
        $status_name = isset($all_statuses[$status_id]) ? $all_statuses[$status_id]['name'] : '';

        // Dodaj napomenu u narudžbinu
        $order->add_order_note(sprintf(__('D Express status: %s', 'd-express-woo'), $status_name));

        // Ako su email-ovi isključeni, samo izađi
        if (!$send_emails) {
            return;
        }

        // Obrada na osnovu grupe statusa
        switch ($status_group) {
            // Isporučeno
            case 'delivered':
                // Pošiljka dostavljena / isporučena
                if ($order->get_status() === 'processing' || $order->get_status() === 'on-hold') {
                    // Promeni status narudžbine na 'completed'
                    $order->update_status('completed', __('D Express pošiljka isporučena.', 'd-express-woo'));
                }
                // Pošalji email korisniku
                $this->send_status_email($shipment, $order, 'delivered');
                break;

            // Preuzeta od pošiljaoca
            case 'transit':
                if ($status_id == '3') {  // '3' je kod za "Preuzeto od pošiljaoca"
                    // Pošalji email da je kurir preuzeo pošiljku
                    $this->send_status_email($shipment, $order, 'picked_up');
                } else {
                    // Pošalji generalni email za pošiljke u tranzitu
                    $this->send_status_email($shipment, $order, 'in_transit');
                }
                break;

            // Pošiljka spremna za isporuku
            case 'out_for_delivery':
                $this->send_status_email($shipment, $order, 'out_for_delivery');
                break;

            // Neuspešna isporuka / vraćeno
            case 'failed':
            case 'returned':
                // Promeni status narudžbine na 'failed'
                if ($order->get_status() !== 'failed' && $order->get_status() !== 'cancelled') {
                    $order->update_status('failed', __('D Express nije uspeo da isporuči pošiljku.', 'd-express-woo'));
                }
                // Pošalji email korisniku
                $this->send_status_email($shipment, $order, 'failed');
                break;

            // Pokušana isporuka (npr. nema nikoga kod kuće)
            case 'delayed':
                $this->send_status_email($shipment, $order, 'attempted');
                break;

            // Pošiljka čeka preuzimanje (npr. u paketomatu)
            case 'pending_pickup':
                $this->send_status_email($shipment, $order, 'ready_for_pickup');
                break;
        }
    }
    private function check_woocommerce_activation()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }
        return true;
    }
    /**
     * Slanje email notifikacije o promeni statusa
     * 
     * @param object $shipment Podaci o pošiljci
     * @param WC_Order $order WooCommerce narudžbina
     * @param string $status_type Tip statusa (delivered, failed, attempted)
     */
    private function send_status_email($shipment, $order, $status_type)
    {
        if (get_option('dexpress_auto_status_emails', 'yes') !== 'yes') {
            return;
        }

        $mailer = WC()->mailer();
        $recipient = $order->get_billing_email();

        switch ($status_type) {
            case 'delivered':
                $subject = sprintf(__('Vaša porudžbina #%s je isporučena', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-delivered.php';
                break;
            case 'failed':
                $subject = sprintf(__('Problem sa isporukom porudžbine #%s', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-failed.php';
                break;
            case 'picked_up':
                $subject = sprintf(__('Vaša porudžbina #%s je preuzeta od strane kurira', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-picked-up.php';
                break;
            case 'in_transit':
                $subject = sprintf(__('Vaša porudžbina #%s je u tranzitu', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-in-transit.php';
                break;
            case 'out_for_delivery':
                $subject = sprintf(__('Vaša porudžbina #%s je spremna za isporuku danas', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-out-for-delivery.php';
                break;
            case 'attempted':
                $subject = sprintf(__('Pokušaj isporuke porudžbine #%s', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-attempted.php';
                break;
            case 'ready_for_pickup':
                $subject = sprintf(__('Vaša porudžbina #%s je spremna za preuzimanje', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-ready-for-pickup.php';
                break;
            default:
                $subject = sprintf(__('Ažuriranje statusa pošiljke za porudžbinu #%s', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-status-change.php';
        }

        $email_heading = $subject;

        // Pripremamo podatke za šablon
        $tracking_number = $shipment->tracking_number;
        $status_name = dexpress_get_status_name($shipment->status_code);

        // Dohvati paketomat ako je paketomat dostava
        $dispenser = null;
        $dispenser_id = get_post_meta($order->get_id(), '_dexpress_dispenser_id', true);
        if (!empty($dispenser_id)) {
            global $wpdb;
            $dispenser = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dexpress_dispensers WHERE id = %d",
                intval($dispenser_id)
            ));
        }

        // Kreiramo email objekat
        $email = new WC_Email();

        // Učitavanje sadržaja emaila iz šablona
        ob_start();

        // Provera da li šablon postoji
        $template_path = DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/' . $template;
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            // Ako specifični šablon ne postoji, koristi generički
            include DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/shipment-status-change.php';
        }

        $email_content = ob_get_clean();

        // Slanje emaila
        $headers = "Content-Type: text/html\r\n";
        $mailer->send($recipient, $subject, $email_content, $headers);

        dexpress_log('[EMAIL] Poslat email o promeni statusa "' . $status_type . '" na: ' . $recipient, 'debug');
    }
}

// Inicijalizacija glavnog plugin objekta
function D_Express_WooCommerce_Init()
{
    return D_Express_WooCommerce::get_instance();
}

// Pokretanje plugin-a
D_Express_WooCommerce_Init();
