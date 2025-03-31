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

        // Validator
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/class-dexpress-validator.php';

        // Timeline
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-order-timeline.php';

        // Admin klase
        if (is_admin()) {
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-admin.php';
            // require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/dexpress-admin-ajax.php'; // Dodaj ovu liniju
            // U glavnoj datoteci plugina ili u funkciji za uključivanje zavisnosti
            include_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-shipments-list.php';
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-reports.php';
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-dashboard-widget.php';
        }

        // Servisne klase
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/services/class-dexpress-shipment-service.php';

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

        // U d-express-woocommerce-integration.php dodati ovu liniju u includes funkciji:
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/woocommerce/class-dexpress-dispenser-shipping-method.php';

        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-diagnostics.php';
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

        // Dodavanje REST API ruta za webhook - pomereno pre plugins_loaded
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Dodavanje webhook obrade za cron - pomereno pre plugins_loaded
        add_action('dexpress_process_notification', array($this, 'process_notification'));

        // Inicijalizacija nakon učitavanja svih plugin-a
        add_action('plugins_loaded', array($this, 'init'), 0);
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
        if (is_admin()) {
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/class-dexpress-admin.php';
            // require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/dexpress-admin-ajax.php';
        }
        // Kreiranje potrebnih tabela u bazi
        require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/db/class-dexpress-db-installer.php';
        $installer = new D_Express_DB_Installer();
        $installer->install();
        $db = new D_Express_DB();
        $db->add_shipment_index();
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

        $order_handler = new D_Express_Order_Handler();
        $order_handler->init();

        $shipment_service = new D_Express_Shipment_Service();
        $shipment_service->register_ajax_handlers();

        // Inicijalizacija timeline-a
        $timeline = new D_Express_Order_Timeline();
        $timeline->init();
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
        WHERE reference_id = %s OR shipment_id = %s",
            $notification->reference_id,
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
        // U zavisnosti od statusa, možemo menjati status narudžbine ili slati notifikacije
        $status_id = $notification->status_id;

        switch ($status_id) {
            // Isporučeno
            case '1':
            case '130':
            case '831':
            case '843':
                // Pošiljka dostavljena / isporučena
                if ($order->get_status() === 'processing' || $order->get_status() === 'on-hold') {
                    // Promeni status narudžbine na 'completed'
                    $order->update_status('completed', __('D Express pošiljka isporučena.', 'd-express-woo'));
                }

                // Opciono: Pošalji email korisniku
                $this->send_status_email($shipment, $order, 'delivered');
                break;

            // Neuspešna isporuka / vraćeno
            case '5':
            case '131':
                // Promeni status narudžbine na 'failed'
                if ($order->get_status() !== 'failed' && $order->get_status() !== 'cancelled') {
                    $order->update_status('failed', __('D Express nije uspeo da isporuči pošiljku.', 'd-express-woo'));
                }

                // Opciono: Pošalji email korisniku
                $this->send_status_email($shipment, $order, 'failed');
                break;

            // Pokušana isporuka, nema nikoga na adresi
            case '6':
            case '106':
                // Dodaj napomenu sa info o pokušaju isporuke
                $order->add_order_note(__('D Express je pokušao isporuku, ali nije bilo nikoga na adresi.', 'd-express-woo'));

                // Opciono: Pošalji email korisniku
                $this->send_status_email($shipment, $order, 'attempted');
                break;
        }
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
        if (get_option('dexpress_enable_status_emails', 'yes') !== 'yes') {
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
            case 'attempted':
                $subject = sprintf(__('Pokušaj isporuke porudžbine #%s', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-attempted.php';
                break;
            default:
                $subject = sprintf(__('Ažuriranje statusa pošiljke za porudžbinu #%s', 'd-express-woo'), $order->get_order_number());
                $template = 'shipment-status-change.php';
        }

        // Priprema email-a
        ob_start();

        // Provera da li šablon postoji, ako ne koristi default
        $template_path = DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/' . $template;
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            // Ako specifični šablon ne postoji, koristi generički
            include DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/shipment-status-change.php';
        }

        $email_content = ob_get_clean();

        // Pošalji email
        $headers = "Content-Type: text/html\r\n";
        $mailer->send($recipient, $subject, $email_content, $headers);

        dexpress_log('Email o statusu pošiljke poslat na: ' . $recipient . ' (tip: ' . $status_type . ')', 'debug');
    }
}

// Inicijalizacija glavnog plugin objekta
function D_Express_WooCommerce_Init()
{
    return D_Express_WooCommerce::get_instance();
}

// Pokretanje plugin-a
D_Express_WooCommerce_Init();
