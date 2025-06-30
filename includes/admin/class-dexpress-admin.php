<?php

/**
 * D Express Admin klasa
 * 
 * Klasa za administratorski interfejs
 */

defined('ABSPATH') || exit;

class D_Express_Admin
{
    // private $admin_nonce = 'dexpress_admin_nonce';

    /**
     * Konstruktor klase
     */
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Inicijalizacija AJAX i Metabox handlers
        new D_Express_Admin_Ajax();
        new D_Express_Order_Metabox();

        // Inicijalizuj sve admin funkcionalnosti  
        $this->init_admin_features();
    }

    /**
     * Inicijalizacija admin funkcionalnosti
     */
    private function init_admin_features()
    {

        // Dodavanje kolone za praćenje u listi narudžbina
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_tracking_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'show_order_tracking_column_data'), 10, 2);

        add_filter('woocommerce_get_order_address', array($this, 'format_order_address_phone'), 10, 3);

        // Za WooCommerce Orders HPOS prikaz
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_wc_orders_label_printed_column'), 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'show_wc_orders_label_printed_column'), 20, 2);

        // Za WooCommerce Orders (stari način)
        add_filter('manage_edit-shop_order_columns', array($this, 'add_wc_orders_label_printed_column'), 20);
        add_action('manage_shop_order_posts_custom_column', array($this, 'show_wc_orders_label_printed_column'), 20, 2);

        // Dodavanje status kolone
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_shipment_status_column'), 21);
        add_action('manage_shop_order_posts_custom_column', array($this, 'show_order_shipment_status_column'), 21, 2);

        // Za WooCommerce Orders HPOS prikaz
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_order_shipment_status_column'), 21);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'show_order_shipment_status_column'), 21, 2);
    }

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
        // ali sa različitim tekstom
        add_submenu_page(
            'dexpress-settings',                           // Parent slug
            __('D Express Podešavanja', 'd-express-woo'),  // Naslov stranice
            __('Podešavanja', 'd-express-woo'),            // Tekst podmenija - biće "Podešavanja"
            'manage_woocommerce',
            'dexpress-settings',                           // Isti slug kao glavni meni
            array($this, 'render_settings_page')
        );

        // Dodajemo podmenije direktno pod D Express glavni meni

        // Dodajemo ostale podmenije
        add_submenu_page(
            'dexpress-settings',                      // Parent slug je sada settings
            __('D Express Pošiljke', 'd-express-woo'),
            __('Pošiljke', 'd-express-woo'),
            'manage_woocommerce',
            'dexpress-shipments',
            array($this, 'render_shipments_page')
        );

        add_submenu_page(
            'dexpress-settings',                      // Parent slug je sada settings
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
                    'dexpress-settings',                  // Parent slug je sada settings
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
     * Renderuje glavnu dashboard stranicu za D Express
     * Dodajte ovu metodu u D_Express_Admin klasu
     */
    public function render_dashboard_page()
    {
        // Redirekcija na podešavanja (možete promeniti na bilo koju drugu stranicu)
        wp_redirect(admin_url('admin.php?page=dexpress-settings'));
        exit;
    }
    /**
     * Registracija admin stilova i skripti
     */
    public function enqueue_admin_assets($hook)
    {
        $custom_css = "
            #adminmenu .toplevel_page_dexpress-settings .wp-menu-image img {
                padding: 7px 0px !important;
                width: auto !important;
                height: auto !important;
                max-width: 20px !important;
            }
        ";
        // Registrujemo i dodajemo inline CSS
        wp_register_style('dexpress-admin-icon-style', false);
        wp_enqueue_style('dexpress-admin-icon-style');
        wp_add_inline_style('dexpress-admin-icon-style', $custom_css);
        // Učitavaj stilove i skripte samo na D Express stranicama
        if (
            strpos($hook, 'dexpress') !== false ||
            $hook === 'toplevel_page_dexpress' ||  // Dodato za pristup 1
            (isset($_GET['page']) && strpos($_GET['page'], 'dexpress') !== false)
        ) {
            // Dodajemo WP Pointer skriptu i stilove
            wp_enqueue_script('wp-pointer');
            wp_enqueue_style('wp-pointer');
            wp_enqueue_style('wp-auth-check');
            wp_enqueue_style(
                'dexpress-admin-css',
                DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-admin.css',
                array(),
                DEXPRESS_WOO_VERSION
            );

            wp_enqueue_script(
                'dexpress-admin-js',
                DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-admin.js',
                array('jquery'),
                DEXPRESS_WOO_VERSION,
                true
            );
            wp_enqueue_script(
                'dexpress-locations-js',
                DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-locations.js',
                array('jquery', 'dexpress-admin-js'), // Dependency na main admin script
                DEXPRESS_WOO_VERSION,
                true
            );
            wp_localize_script('dexpress-admin-js', 'dexpressL10n', array(
                'save_alert' => __('Niste sačuvali promene. Da li ste sigurni da želite da napustite ovu stranicu?', 'd-express-woo')
            ));
            // Dodaj admin podatke u oba script-a
            $admin_data = array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('dexpress_admin_nonce'),
                'i18n' => array(
                    'confirmDelete' => __('Da li ste sigurni da želite da obrišete ovu lokaciju?', 'd-express-woo'),
                    'error' => __('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'),
                    'success' => __('Operacija uspešno izvršena.', 'd-express-woo'),
                )
            );

            wp_localize_script('dexpress-admin-js', 'dexpressAdmin', $admin_data);
            wp_localize_script('dexpress-locations-js', 'dexpressAdmin', $admin_data);
        }
    }
    /**
     * Obrada akcija na stranici podešavanja
     */
    public function handle_admin_actions()
    {
        if (!isset($_GET['action'])) {
            return;
        }

        $action = sanitize_text_field($_GET['action']);

        switch ($action) {
            case 'update_indexes':
                $this->update_indexes();
                break;
            case 'test_connection':
                $this->test_connection();
                break;
            case 'test_cron':
                $this->test_cron();
                break;
            case 'reset_cron':
                $this->reset_cron();
                break;
        }
    }
    /**
     * Render stranice za podešavanja
     */
    public function render_settings_page()
    {
        // Obrada forme ako je poslata
        if (isset($_POST['dexpress_save_settings']) && check_admin_referer('dexpress_settings_nonce')) {
            $this->save_settings();
        }

        // Trenutne vrednosti opcija
        $api_username = get_option('dexpress_api_username', '');
        $api_password = get_option('dexpress_api_password', '');
        $client_id = get_option('dexpress_client_id', '');
        $code_prefix = get_option('dexpress_code_prefix', '');
        $code_range_start = get_option('dexpress_code_range_start', '');
        $code_range_end = get_option('dexpress_code_range_end', '');
        $test_mode = get_option('dexpress_test_mode', 'yes');
        $enable_logging = get_option('dexpress_enable_logging', 'no');
        $shipment_type = get_option('dexpress_shipment_type', '2');
        $payment_by = get_option('dexpress_payment_by', '0');
        $payment_type = get_option('dexpress_payment_type', '2');
        $return_doc = get_option('dexpress_return_doc', '0');
        $default_content = get_option('dexpress_default_content', '');
        $webhook_secret = get_option('dexpress_webhook_secret', wp_generate_password(32, false));

        // Dodajte ovo u deo gde se inicijalizuju opcije
        $buyout_account = get_option('dexpress_buyout_account', '');

        // Dobijanje WooCommerce statusa narudžbina
        $order_statuses = wc_get_order_statuses();

        // Dobijanje lista gradova za dropdown
        $towns_options = dexpress_get_towns_options();

        // Odredi aktivni tab
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';
        $allowed_tabs = ['api', 'codes', 'auto', 'sender', 'shipment', 'webhook', 'cron', 'uninstall'];
        if (!in_array($active_tab, $allowed_tabs)) {
            $active_tab = 'api';
        }
        // HTML za stranicu podešavanja
?>

        <div class="wrap">
            <h1 class="dexpress-settings-title">
                <span><?php echo __('D Express Podešavanja', 'd-express-woo'); ?></span>
                <img src="<?php echo plugin_dir_url(__FILE__) . '../../assets/images/Dexpress-logo.jpg'; ?>" alt="Logo" height="50" class="dexpress-settings-logo">
            </h1>

            <hr><br>

            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Podešavanja su uspešno sačuvana.', 'd-express-woo'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['indexes-updated']) && $_GET['indexes-updated'] === 'success'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Šifarnici su uspešno ažurirani.', 'd-express-woo'); ?></p>
                </div>
            <?php elseif (isset($_GET['indexes-updated']) && $_GET['indexes-updated'] === 'error'): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php _e('Došlo je do greške prilikom ažuriranja šifarnika. Proverite API kredencijale i pokušajte ponovo.', 'd-express-woo'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['connection-test']) && $_GET['connection-test'] === 'success'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Konekcija sa D Express API-em je uspešno uspostavljena.', 'd-express-woo'); ?></p>
                </div>
            <?php elseif (isset($_GET['connection-test']) && $_GET['connection-test'] === 'error'): ?>
                <div class="notice notice-error is-dismissible">
                    <p>
                        <?php _e('Nije moguće uspostaviti konekciju sa D Express API-em.', 'd-express-woo'); ?>
                        <?php if (isset($_GET['error-message'])): ?>
                            <br>
                            <strong><?php echo esc_html(urldecode($_GET['error-message'])); ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
            <?php elseif (isset($_GET['connection-test']) && $_GET['connection-test'] === 'missing_credentials'): ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php _e('Nedostaju API kredencijali. Molimo unesite korisničko ime, lozinku i client ID.', 'd-express-woo'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['cron-test']) && $_GET['cron-test'] === 'success'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('CRON test je uspešno pokrenut. Proverite logove za detalje.', 'd-express-woo'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['cron-reset']) && $_GET['cron-reset'] === 'success'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('CRON sistem je uspešno resetovan.', 'd-express-woo'); ?></p>
                </div>
            <?php endif; ?>
            <!-- Forma za sva podešavanja -->
            <?php if (isset($_GET['extended']) && $_GET['extended'] === 'success'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Opseg kodova je uspešno proširen!</strong>
                        Dodano je <?php echo intval($_GET['added']); ?> novih kodova.
                        Novi opseg: <?php echo get_option('dexpress_code_range_start', 1); ?>-<?php echo get_option('dexpress_code_range_end', 99); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['extended']) && $_GET['extended'] === 'error'): ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>Greška pri proširenju opsega kodova:</strong>
                        <?php echo esc_html(urldecode($_GET['error_message'])); ?></p>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo admin_url('admin.php?page=dexpress-settings'); ?>" class="dexpress-settings-form">
                <?php wp_nonce_field('dexpress_settings_nonce'); ?>
                <input type="hidden" name="active_tab" value="<?php echo esc_attr($active_tab); ?>">

                <!-- Navigacija tabova -->
                <div class="dexpress-tab-links">
                    <?php
                    $tab_titles = [
                        'api' => __('API Podešavanja', 'd-express-woo'),
                        'codes' => __('Kodovi pošiljki', 'd-express-woo'),
                        'auto' => __('Kreiranje pošiljki', 'd-express-woo'),
                        'sender' => __('Lokacije pošiljaoca', 'd-express-woo'),
                        'shipment' => __('Podešavanja pošiljke', 'd-express-woo'),
                        'webhook' => __('Webhook podešavanja', 'd-express-woo'),
                        'cron' => __('Automatsko ažuriranje', 'd-express-woo'),
                        'uninstall' => __('Clean Uninstall', 'd-express-woo')
                    ];

                    foreach ($allowed_tabs as $tab): ?>
                        <a href="#tab-<?php echo esc_attr($tab); ?>"
                            class="dexpress-tab-link <?php echo $active_tab === $tab ? 'active' : ''; ?>"
                            data-tab="<?php echo esc_attr($tab); ?>"
                            onclick="switchTab(event, '<?php echo esc_attr($tab); ?>')">
                            <?php echo esc_html($tab_titles[$tab]); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Sadržaj tabova -->
                <div class="dexpress-tabs">
                    <!-- API Podešavanja -->
                    <div id="tab-api" class="dexpress-tab <?php echo $active_tab === 'api' ? 'active' : ''; ?>">
                        <h2><?php _e('API Podešavanja', 'd-express-woo'); ?></h2>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_api_username"><?php _e('API Korisničko ime', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="dexpress_api_username" name="dexpress_api_username"
                                        value="<?php echo esc_attr($api_username); ?>" class="regular-text">
                                    <p class="description"><?php _e('Korisničko ime dobijeno od D Express-a.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info"
                                            data-wp-tooltip="<?php _e('Unesite korisničko ime koje ste dobili od D Express-a za pristup njihovom API-ju. Ovo je jedinstveni identifikator u formatu UUID (npr. XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXX).<br><br> D Express vam dodeljuje posebne kredencijale za test i produkciono okruženje.', 'd-express-woo'); ?>">
                                        </span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_api_password"><?php _e('API Lozinka', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <div class="wp-pwd">
                                        <input type="password" id="dexpress_api_password" name="dexpress_api_password"
                                            value="<?php echo esc_attr($api_password); ?>" class="regular-text">
                                        <button type="button" class="button button-secondary wp-hide-pw hide-if-no-js" data-toggle="0" aria-label="<?php esc_attr_e('Prikaži lozinku', 'd-express-woo'); ?>">
                                            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                    <p class="description"><?php _e('Lozinka dobijena od D Express-a.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Unesite lozinku koju ste dobili od D Express-a. Zajedno sa korisničkim imenom služi za Basic Authentication kod svih API poziva.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_client_id"><?php _e('Client ID', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="dexpress_client_id" name="dexpress_client_id"
                                        value="<?php echo esc_attr($client_id); ?>" class="regular-text">
                                    <p class="description"><?php _e('Client ID u formatu UK12345.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Vaš jedinstveni identifikator u D Express sistemu u formatu UKXXXXX (npr. UK12345). Ovaj podatak je neophodan i koristi se u svakom API pozivu kao CClientID parametar. Ovaj ID predstavlja vašu kompaniju u D Express sistemu.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_test_mode"><?php _e('Test režim', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="checkbox" id="dexpress_test_mode" name="dexpress_test_mode"
                                        value="yes" <?php checked($test_mode, 'yes'); ?>>
                                    <p class="description"><?php _e('Aktivirajte test režim tokom razvoja i testiranja.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Kada je aktiviran, plugin koristi test nalog za komunikaciju sa D Express API-jem. Pošiljke kreirane u test režimu neće biti fizički isporučene, ali će prolaziti kroz sve faze obrade u API-ju. Idealno za testiranje integracije pre produkcije.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_enable_logging"><?php _e('Uključi logovanje', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="checkbox" id="dexpress_enable_logging" name="dexpress_enable_logging"
                                        value="yes" <?php checked($enable_logging, 'yes'); ?>>
                                    <p class="description"><?php _e('Aktivirajte logovanje API zahteva i odgovora.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Aktivira detaljan zapis (log) svih API komunikacija sa D Express servisom. Log fajlovi se čuvaju u logs/ direktorijumu i sadrže detalje o zahtevima, odgovorima i greškama. Korisno za debagiranje prilikom razvoja i rešavanje problema u produkciji.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_log_level"><?php _e('Nivo logovanja', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <select id="dexpress_log_level" name="dexpress_log_level">
                                        <option value="debug" <?php selected(get_option('dexpress_log_level', 'debug'), 'debug'); ?>><?php _e('Debug (sve poruke)', 'd-express-woo'); ?></option>
                                        <option value="info" <?php selected(get_option('dexpress_log_level', 'debug'), 'info'); ?>><?php _e('Info (informacije i greške)', 'd-express-woo'); ?></option>
                                        <option value="warning" <?php selected(get_option('dexpress_log_level', 'debug'), 'warning'); ?>><?php _e('Warning (upozorenja i greške)', 'd-express-woo'); ?></option>
                                        <option value="error" <?php selected(get_option('dexpress_log_level', 'debug'), 'error'); ?>><?php _e('Error (samo greške)', 'd-express-woo'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('Odredite koji nivo poruka će biti zabeležen u log fajlovima.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Kontroliše količinu informacija u logovima. Debug prikazuje sve poruke, Error prikazuje samo kritične greške. Za produkcione sajtove preporučuje se Info ili Warning nivo.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <!-- Podešavanja kodova pošiljki -->

                    <div id="tab-codes" class="dexpress-tab <?php echo $active_tab === 'codes' ? 'active' : ''; ?>">
                        <h2><?php _e('Kodovi pošiljki', 'd-express-woo'); ?></h2>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_code_prefix"><?php _e('Prefiks koda', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="dexpress_code_prefix" name="dexpress_code_prefix"
                                        value="<?php echo esc_attr($code_prefix); ?>" class="regular-text">
                                    <p class="description"><?php _e('Prefiks koda paketa (npr. TT).', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Dvoslovni prefiks za kodove paketa koji vam je dodelio D Express (npr. \'TT\' za testiranje). Svaki partner dobija jedinstveni prefiks za produkciju kako bi se izbegle kolizije kodova paketa. Ovaj prefiks se koristi u PackageList.Code parametru.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_code_range_start"><?php _e('Početak opsega', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="dexpress_code_range_start" name="dexpress_code_range_start"
                                        value="<?php echo esc_attr($code_range_start); ?>" class="small-text">
                                    <p class="description"><?php _e('Početni broj za kodove paketa.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Početni broj u dodeljenom opsegu kodova paketa (obično 1). U kombinaciji sa prefiksom i formatiranjem na 10 cifara formira kompletan kod paketa (npr. TT0000000001). D Express će vam dodeliti produkcioni opseg pre prelaska u produkciju.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_code_range_end"><?php _e('Kraj opsega', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="dexpress_code_range_end" name="dexpress_code_range_end"
                                        value="<?php echo esc_attr($code_range_end); ?>" class="small-text">
                                    <p class="description"><?php _e('Krajnji broj za kodove paketa.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Krajnji broj u dodeljenom opsegu kodova paketa (npr. 99 za test). Kada brojač dostigne ovu vrednost, resetovaće se na početni broj. Za produkciju ćete dobiti veći opseg. Važno je pratiti korišćenje kako ne biste ponovili već korišćene kodove.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php _e('Trenutni status', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <?php

                                    $current_index = intval(get_option('dexpress_current_code_index', intval($code_range_start)));
                                    $code_range_start = intval($code_range_start);
                                    $code_range_end = intval($code_range_end);

                                    if ($code_range_end == 0 || $code_range_start == 0) {
                                        $remaining = 0;
                                        $total_codes = 0;
                                        $used_percentage = 0;
                                    } else {
                                        $remaining = $code_range_end - $current_index;
                                        $total_codes = $code_range_end - $code_range_start + 1;
                                        $used_percentage = round((($current_index - $code_range_start) / $total_codes) * 100, 1);
                                    }
                                    ?>
                                    <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                                        <p><strong>Sledeći kod:</strong> <code><?php echo $code_prefix . sprintf('%010d', $current_index + 1); ?></code></p>
                                        <p><strong>Preostalo kodova:</strong> <?php echo $remaining; ?> od <?php echo $total_codes; ?></p>
                                        <p><strong>Iskorišćeno:</strong> <?php echo $used_percentage; ?>%</p>

                                        <div style="width: 100%; height: 20px; background: #f0f0f0; border-radius: 10px; overflow: hidden; margin: 10px 0;">
                                            <div style="width: <?php echo $used_percentage; ?>%; height: 100%; background: <?php echo $used_percentage > 80 ? '#dc3545' : ($used_percentage > 50 ? '#ffc107' : '#28a745'); ?>;"></div>
                                        </div>

                                        <?php if ($remaining <= 10): ?>
                                            <div class="notice notice-error inline">
                                                <p><strong>UPOZORENJE:</strong> Ostalo je samo <?php echo $remaining; ?> kodova! Proširi te opseg što pre.</p>
                                            </div>
                                        <?php elseif ($used_percentage >= 80): ?>
                                            <div class="notice notice-warning inline">
                                                <p><strong>PAŽNJA:</strong> Iskorišćeno je <?php echo $used_percentage; ?>% opsega. Razmislite o proširenju.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label><?php _e('Proširenje opsega', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; border-radius: 4px;">
                                        <h4><?php _e('Dodaj novi opseg kodova', 'd-express-woo'); ?></h4>
                                        <p class="description"><?php _e('Kada dobijete novi opseg od D Express-a, ovde možete proširiti postojeći opseg.', 'd-express-woo'); ?></p>

                                        <table style="margin-top: 15px;">
                                            <tr>
                                                <td style="padding-right: 15px;">
                                                    <label for="dexpress_extend_range_end"><?php _e('Novi opseg od D Express-a:', 'd-express-woo'); ?></label><br>
                                                    <input type="number" id="dexpress_extend_range_end" name="dexpress_extend_range_end"
                                                        min="<?php echo $code_range_end + 1; ?>" style="width: 120px;"
                                                        placeholder="<?php _e('Unesite krajnji broj', 'd-express-woo'); ?>">
                                                    <p class="description" style="margin-top: 5px;">
                                                        <?php printf(__('Trenutni opseg: %s-%s. Unesite krajnji broj novog opsega koji ste dobili od D Express-a.', 'd-express-woo'), $code_range_start, $code_range_end); ?>
                                                    </p>
                                                </td>
                                                <td style="vertical-align: top; padding-top: 25px;">
                                                    <div id="extend-preview" style="display: none; background: #e7f3ff; padding: 10px; border-radius: 4px; border: 1px solid #b3d7ff;">
                                                        <strong><?php _e('Pregled:', 'd-express-woo'); ?></strong><br>
                                                        <span id="preview-text"></span>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>

                                        <div class="notice notice-info inline" style="margin-top: 15px;">
                                            <p><strong><?php _e('Napomena:', 'd-express-woo'); ?></strong> <?php _e('Unesite novi kraj opsega i kliknite "Sačuvaj podešavanja" na dnu stranice.', 'd-express-woo'); ?></p>
                                        </div>


                                        <div style="margin-top: 15px; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;">
                                            <p style="margin: 0; font-size: 13px;">
                                                <strong><?php _e('Kako funkcioniše:', 'd-express-woo'); ?></strong><br>
                                                • <?php _e('Kontaktirajte D Express kada se opseg bliži kraju', 'd-express-woo'); ?><br>
                                                • <?php _e('Oni će vam dodeliti novi opseg (npr. produžiti do 5000)', 'd-express-woo'); ?><br>
                                                • <?php _e('Unesite novi KRAJ opsega koji su vam dodelili', 'd-express-woo'); ?><br>
                                                • <?php _e('Kliknite "Sačuvaj podešavanja" i opseg će biti automatski proširen', 'd-express-woo'); ?><br>
                                                • <?php _e('Postojeći kodovi ostaju netaknuti - plugin nastavlja odakle je stao', 'd-express-woo'); ?>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <script>
                                // Live preview i validacija
                                document.getElementById('dexpress_extend_range_end').addEventListener('input', function() {
                                    const newEnd = parseInt(this.value);
                                    const currentEnd = <?php echo $code_range_end; ?>;
                                    const preview = document.getElementById('extend-preview');
                                    const previewText = document.getElementById('preview-text');

                                    // Resetuj stilove
                                    this.style.borderColor = '';
                                    this.style.backgroundColor = '';

                                    if (newEnd) {
                                        if (newEnd <= currentEnd) {
                                            // Error - manji ili jednak
                                            this.style.borderColor = '#dc3545';
                                            this.style.backgroundColor = '#fff5f5';
                                            previewText.innerHTML = '<span style="color: #dc3545;">❌ Novi kraj mora biti veći od ' + currentEnd + '</span>';
                                            preview.style.display = 'block';
                                            preview.style.background = '#f8d7da';
                                            preview.style.borderColor = '#dc3545';
                                        } else if (newEnd > 9999999) {
                                            // Error - previše velik
                                            this.style.borderColor = '#dc3545';
                                            this.style.backgroundColor = '#fff5f5';
                                            previewText.innerHTML = '<span style="color: #dc3545;">❌ Maksimalno je 9.999.999</span>';
                                            preview.style.display = 'block';
                                            preview.style.background = '#f8d7da';
                                            preview.style.borderColor = '#dc3545';
                                        } else {
                                            // Success
                                            this.style.borderColor = '#28a745';
                                            this.style.backgroundColor = '#f8fff9';
                                            const added = newEnd - currentEnd;
                                            previewText.innerHTML = '<span style="color: #28a745;">✅ Novi opseg: <?php echo $code_range_start; ?>-' + newEnd + '<br>Dodaće se: ' + added.toLocaleString() + ' novih kodova</span>';
                                            preview.style.display = 'block';
                                            preview.style.background = '#d4edda';
                                            preview.style.borderColor = '#28a745';
                                        }
                                    } else {
                                        preview.style.display = 'none';
                                    }
                                });
                            </script>
                        </table>
                    </div>
                    <!-- Podešavanja automatske kreacije pošiljki -->
                    <div id="tab-auto" class="dexpress-tab <?php echo $active_tab === 'auto' ? 'active' : ''; ?>">
                        <h2><?php _e('Kreiranje pošiljki', 'd-express-woo'); ?></h2>

                        <table class="form-table">

                            <tr>
                                <th scope="row">
                                    <label><?php _e('Način kreiranja pošiljki', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <div style="background: #e7f3ff; padding: 15px; border: 1px solid #b3d7ff; border-radius: 4px;">
                                        <p><strong><?php _e('RUČNO KREIRANJE AKTIVNO', 'd-express-woo'); ?></strong></p>
                                        <p class="description"><?php _e('Pošiljke se kreiraju isključivo ručno kroz admin panel pojedinačnih porudžbina.', 'd-express-woo'); ?></p>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_validate_address"><?php _e('Validacija adrese', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="checkbox" id="dexpress_validate_address" name="dexpress_validate_address"
                                        value="yes" <?php checked(get_option('dexpress_validate_address', 'yes'), 'yes'); ?>>
                                    <p class="description"><?php _e('Proveri validnost adrese pre kreiranja pošiljke putem D Express API-ja', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Proverava validnost adrese primaoca kroz D Express API pozivanjem checkaddress metode pre kreiranja pošiljke. Ovo osigurava da adresa primaoca postoji u D Express sistemu i sprečava greške pri unosu adrese koje bi mogle izazvati probleme prilikom dostave.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_enable_myaccount_tracking"><?php _e('Praćenje u Moj Nalog', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="checkbox" id="dexpress_enable_myaccount_tracking" name="dexpress_enable_myaccount_tracking"
                                        value="yes" <?php checked(get_option('dexpress_enable_myaccount_tracking', 'yes'), 'yes'); ?>>
                                    <p class="description"><?php _e('Omogući praćenje pošiljki u "Moj nalog" sekciji na frontend-u.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Kada je aktivirano, dodaje tab za praćenje pošiljki u korisničkom nalogu (My Account page) gde korisnici mogu pratiti status svojih pošiljki.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_auto_status_emails"><?php _e('Automatski email-ovi o statusu', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="checkbox" id="dexpress_auto_status_emails" name="dexpress_auto_status_emails"
                                        value="yes" <?php checked(get_option('dexpress_auto_status_emails', 'yes'), 'yes'); ?>>
                                    <p class="description"><?php _e('Automatski šalji email kupcu pri promeni statusa pošiljke.', 'd-express-woo'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <!-- Podešavanja pošiljaoca -->
                    <div id="tab-sender" class="dexpress-tab <?php echo $active_tab === 'sender' ? 'active' : ''; ?>">
                        <h2><?php _e('Lokacije pošiljaoca', 'd-express-woo'); ?></h2>

                        <?php
                        // Dohvati lokacije
                        $locations_service = D_Express_Sender_Locations::get_instance();
                        $locations = $locations_service->get_all_locations();
                        $towns_options = dexpress_get_towns_options();
                        ?>

                        <!-- Lista postojećih lokacija -->
                        <div class="dexpress-locations-list">
                            <h3><?php _e('Postojeće lokacije', 'd-express-woo'); ?></h3>

                            <?php if (!empty($locations)): ?>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Naziv', 'd-express-woo'); ?></th>
                                            <th><?php _e('Adresa', 'd-express-woo'); ?></th>
                                            <th><?php _e('Grad', 'd-express-woo'); ?></th>
                                            <th><?php _e('Kontakt', 'd-express-woo'); ?></th>
                                            <th><?php _e('Status', 'd-express-woo'); ?></th>
                                            <th><?php _e('Akcije', 'd-express-woo'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($locations as $location): ?>
                                            <tr data-location-id="<?php echo esc_attr($location->id); ?>">
                                                <td>
                                                    <strong><?php echo esc_html($location->name); ?></strong>
                                                    <?php if ($location->is_default): ?>
                                                        <span class="dexpress-default-badge"><?php _e('Glavna', 'd-express-woo'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo esc_html($location->address . ' ' . $location->address_num); ?>
                                                </td>
                                                <td>
                                                    <?php echo esc_html($location->town_name); ?>
                                                </td>
                                                <td>
                                                    <?php echo esc_html($location->contact_name); ?><br>
                                                    <small><?php echo esc_html($location->contact_phone); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($location->is_default): ?>
                                                        <span class="status-active"><?php _e('Glavna lokacija', 'd-express-woo'); ?></span>
                                                    <?php else: ?>
                                                        <span class="status-secondary"><?php _e('Dodatna lokacija', 'd-express-woo'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="button button-small dexpress-edit-location"
                                                        data-location-id="<?php echo esc_attr($location->id); ?>">
                                                        <?php _e('Uredi', 'd-express-woo'); ?>
                                                    </button>

                                                    <?php if (!$location->is_default): ?>
                                                        <button type="button" class="button button-small dexpress-set-default"
                                                            data-location-id="<?php echo esc_attr($location->id); ?>">
                                                            <?php _e('Postavi kao glavnu', 'd-express-woo'); ?>
                                                        </button>

                                                        <button type="button" class="button button-small button-link-delete dexpress-delete-location"
                                                            data-location-id="<?php echo esc_attr($location->id); ?>">
                                                            <?php _e('Obriši', 'd-express-woo'); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="notice notice-info inline">
                                    <p><?php _e('Nema definisanih lokacija. Dodajte prvu lokaciju da biste počeli.', 'd-express-woo'); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Dugme za dodavanje nove lokacije -->
                        <div class="dexpress-add-location-section" style="margin-top: 20px;">
                            <button type="button" id="dexpress-add-location-btn" class="button button-primary">
                                <?php _e('+ Dodaj novu lokaciju', 'd-express-woo'); ?>
                            </button>
                        </div>
                    </div>
                    <!-- Podešavanja pošiljke -->

                    <div id="tab-shipment" class="dexpress-tab <?php echo $active_tab === 'shipment' ? 'active' : ''; ?>">
                        <h2><?php _e('Podešavanja pošiljke', 'd-express-woo'); ?></h2>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_shipment_type"><?php _e('Tip pošiljke', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <select id="dexpress_shipment_type" name="dexpress_shipment_type">
                                        <?php foreach (dexpress_get_shipment_types() as $type_id => $type_name): ?>
                                            <option value="<?php echo esc_attr($type_id); ?>" <?php selected($shipment_type, $type_id); ?>>
                                                <?php echo esc_html($type_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php _e('Izaberite tip pošiljke.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Određuje prioritet dostave: 1 - Hitna isporuka (za danas, uz dodatnu naplatu i samo u određenim zonama) ili 2 - Redovna isporuka (1-3 dana). Ovaj parametar se mapira na DlTypeID polje u API pozivu i direktno utiče na cenu dostave i brzinu isporuke.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_payment_by"><?php _e('Ko plaća dostavu', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <select id="dexpress_payment_by" name="dexpress_payment_by">
                                        <?php foreach (dexpress_get_payment_by_options() as $option_id => $option_name): ?>
                                            <option value="<?php echo esc_attr($option_id); ?>" <?php selected($payment_by, $option_id); ?>>
                                                <?php echo esc_html($option_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php _e('Određuje ko plaća troškove dostave.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Određuje ko snosi troškove dostave: 0 - Pošiljalac (vi), 1 - Primalac (kupac) ili 2 - Treća strana. Ovo polje se mapira na PaymentBy parametar u API pozivu i definiše kome će D Express fakturisati uslugu dostave.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_payment_type"><?php _e('Način plaćanja dostave', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <select id="dexpress_payment_type" name="dexpress_payment_type">
                                        <?php foreach (dexpress_get_payment_type_options() as $type_id => $type_name): ?>
                                            <option value="<?php echo esc_attr($type_id); ?>" <?php selected($payment_type, $type_id); ?>>
                                                <?php echo esc_html($type_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php _e('Definiše način plaćanja troškova dostave.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Definiše način plaćanja troškova dostave: 0 - Gotovina, 1 - Kartica, 2 - Faktura. Ovaj parametar se mapira na PaymentType polje u API pozivu i određuje kako će biti naplaćeni troškovi dostave od strane označene u \'Ko plaća dostavu\'.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_return_doc"><?php _e('Povraćaj dokumenata', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <select id="dexpress_return_doc" name="dexpress_return_doc">
                                        <?php foreach (dexpress_get_return_doc_options() as $option_id => $option_name): ?>
                                            <option value="<?php echo esc_attr($option_id); ?>" <?php selected($return_doc, $option_id); ?>>
                                                <?php echo esc_html($option_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php _e('Kontroliše povraćaj potpisanih dokumenata.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Upravlja vraćanjem potpisanih dokumenata: 0 - Bez povraćaja, 1 - Obavezan povraćaj, 2 - Povraćaj ako je potrebno. Ovaj parametar se mapira na ReturnDoc polje u API pozivu. Za paketomatsku dostavu mora biti 0 (bez povraćaja).', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_content_type"><?php _e('Način opisa sadržaja', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <select id="dexpress_content_type" name="dexpress_content_type">
                                        <option value="category" <?php selected(get_option('dexpress_content_type', 'category'), 'category'); ?>>
                                            <?php _e('Kategorije proizvoda (preporučeno)', 'd-express-woo'); ?>
                                        </option>
                                        <option value="name" <?php selected(get_option('dexpress_content_type', 'category'), 'name'); ?>>
                                            <?php _e('Kratki nazivi proizvoda', 'd-express-woo'); ?>
                                        </option>
                                        <option value="custom" <?php selected(get_option('dexpress_content_type', 'category'), 'custom'); ?>>
                                            <?php _e('Prilagođeni tekst', 'd-express-woo'); ?>
                                        </option>
                                    </select>
                                    <p class="description">
                                        <?php _e('D Express očekuje kratke opise kao: Elektronika, Odeća, Kozmetika.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info"
                                            data-wp-tooltip="<?php _e('Kategorije: koristi WooCommerce kategorije (npr. 3x Elektronika). Nazivi: kratki nazivi proizvoda (npr. 3x iPhone...). Prilagođeni: fiksni tekst.', 'd-express-woo'); ?>">
                                        </span>
                                    </p>
                                </td>
                            </tr>

                            <tr id="custom-content-row" style="<?php echo get_option('dexpress_content_type', 'category') !== 'custom' ? 'display:none;' : ''; ?>">
                                <th scope="row">
                                    <label for="dexpress_default_content"><?php _e('Prilagođeni sadržaj', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="dexpress_default_content" name="dexpress_default_content"
                                        value="<?php echo esc_attr($default_content); ?>" class="regular-text"
                                        placeholder="npr. Rezervni delovi, Tekstil, Dokumenti">
                                    <p class="description">
                                        <?php _e('Fiksni tekst koji će se koristiti kao opis sadržaja.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info"
                                            data-wp-tooltip="<?php _e('Mora sadržavati samo slova, brojevi, crtice, zarezi, zagrade, kose crte. Max 50 karaktera.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>

                            <script>
                                jQuery(document).ready(function($) {
                                    function toggleCustomContentField() {
                                        var contentType = $('#dexpress_content_type').val();
                                        var $customRow = $('#custom-content-row');

                                        if (contentType === 'custom') {
                                            $customRow.show();
                                        } else {
                                            $customRow.hide();
                                        }
                                    }

                                    // Pokreni na početku
                                    toggleCustomContentField();

                                    // Pokreni kad se promeni dropdown
                                    $('#dexpress_content_type').change(toggleCustomContentField);
                                });
                            </script>
                        </table>
                    </div>
                    <!-- Webhook podešavanja -->
                    <div id="tab-webhook" class="dexpress-tab <?php echo $active_tab === 'webhook' ? 'active' : ''; ?>">
                        <h2><?php _e('Webhook podešavanja', 'd-express-woo'); ?></h2>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_allowed_webhook_ips"><?php _e('Dozvoljene IP adrese', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="dexpress_allowed_webhook_ips" name="dexpress_allowed_webhook_ips"
                                        value="<?php echo esc_attr(get_option('dexpress_allowed_webhook_ips', '')); ?>" class="regular-text">
                                    <p class="description">
                                        <?php _e('Lista dozvoljenih IP adresa za webhook, razdvojenih zarezima. Ostavite prazno da dozvolite sve IP adrese.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Lista IP adresa D Express servera sa kojih se primaju webhook pozivi. Ograničavanje ove liste povećava sigurnost, sprečavajući da bilo ko drugi može slati lažne notifikacije o statusima. Ostavite prazno za prihvatanje svih IP adresa.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_webhook_url"><?php _e('Webhook URL', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="dexpress_webhook_url" readonly
                                        value="<?php echo esc_url(rest_url('dexpress-woo/v1/notify')); ?>" class="regular-text">
                                    <button type="button" class="button button-secondary" onclick="copyToClipboard('#dexpress_webhook_url')">
                                        <?php _e('Kopiraj', 'd-express-woo'); ?>
                                    </button>
                                    <p class="description"><?php _e('URL koji treba dostaviti D Express-u za primanje notifikacija.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Endpoint URL vaše WordPress instalacije koji prihvata D Express webhook notifikacije o statusima pošiljki. Dostavite ovaj URL D Express-u kako bi mogli automatski slati ažuriranja statusa pošiljki (metoda notify). Format je {vaš-sajt}/wp-json/dexpress-woo/v1/notify.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_webhook_secret"><?php _e('Webhook tajni ključ', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="dexpress_webhook_secret" name="dexpress_webhook_secret"
                                        value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text">
                                    <button type="button" class="button button-secondary" onclick="generateWebhookSecret()">
                                        <?php _e('Generiši novi', 'd-express-woo'); ?>
                                    </button>
                                    <p class="description"><?php _e('Tajni ključ koji treba dostaviti D Express-u za verifikaciju notifikacija.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Sigurnosni token koji se šalje kao \'cc\' parametar u webhook pozivu za verifikaciju autentičnosti. Dostavite ovaj ključ D Express-u prilikom aktivacije webhook servisa. Služi kao zaštita od lažnih notifikacija.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_google_maps_api_key"><?php _e('Google Maps API ključ', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="dexpress_google_maps_api_key" name="dexpress_google_maps_api_key"
                                        value="<?php echo esc_attr(get_option('dexpress_google_maps_api_key', '')); ?>" class="regular-text">
                                    <p class="description">
                                        <?php _e('Unesite Google Maps API ključ za prikazivanje mape paketomata. Možete ga dobiti na <a href="https://developers.google.com/maps/documentation/javascript/get-api-key" target="_blank">Google Developers Console</a>.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Google Maps API ključ za prikazivanje interaktivne mape sa lokacijama paketomata u checkout procesu. Ovaj ključ možete dobiti kroz Google Cloud Console, i neophodan je za korišćenje paketomatske dostave sa mapom za izbor lokacije.', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <!-- NOVI CRON TAB - zameni komplet tab-cron div -->
                    <div id="tab-cron" class="dexpress-tab <?php echo $active_tab === 'cron' ? 'active' : ''; ?>">
                        <h2><?php _e('Automatsko ažuriranje', 'd-express-woo'); ?></h2>

                        <!-- Status CRON sistema -->
                        <div class="dexpress-cron-status">
                            <h3>Status automatskog ažuriranja</h3>
                            <?php $cron_status = D_Express_Cron_Manager::get_cron_status(); ?>

                            <table class="widefat">
                                <thead>
                                    <tr>
                                        <th>Sistem</th>
                                        <th>Status</th>
                                        <th>Sledeće pokretanje</th>
                                        <th>Poslednje pokretanje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Glavni CRON zadatak</strong></td>
                                        <td>
                                            <span class="status-<?php echo $cron_status['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $cron_status['is_active'] ? 'Aktivan' : 'Neaktivan'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($cron_status['next_run_formatted']); ?></td>
                                        <td><?php echo esc_html($cron_status['last_run_formatted']); ?></td>
                                    </tr>
                                </tbody>
                            </table>

                            <div style="margin-top: 15px;">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dexpress-settings&action=test_cron')); ?>"
                                    class="button button-secondary">
                                    Test CRON zadatka
                                </a>

                                <a href="<?php echo esc_url(admin_url('admin.php?page=dexpress-settings&action=reset_cron')); ?>"
                                    class="button button-secondary"
                                    onclick="return confirm('Da li ste sigurni da želite da resetujete CRON?')">
                                    Reset CRON sistema
                                </a>
                            </div>
                        </div>

                        <!-- Poslednja ažuriranja po tipovima -->
                        <div class="dexpress-last-updates" style="margin-top: 30px;">
                            <h3>Poslednja ažuriranja po tipovima</h3>
                            <table class="widefat">
                                <thead>
                                    <tr>
                                        <th>Tip podataka</th>
                                        <th>Kada se ažurira</th>
                                        <th>Poslednje ažuriranje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Paketi (paketomati)</strong></td>
                                        <td>Svaki dan u 03:00</td>
                                        <td><?php echo $this->format_last_update_time('dexpress_last_dispensers_update'); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Ulice</td>
                                        <td>Nedeljom u 03:00</td>
                                        <td><?php echo $this->format_last_update_time('dexpress_last_streets_update'); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Mesta i opštine</td>
                                        <td>1. u mesecu u 03:00</td>
                                        <td><?php echo $this->format_last_update_time('dexpress_last_locations_update'); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Osnovni šifarnici</td>
                                        <td>Svaki dan u 03:00</td>
                                        <td><?php echo $this->format_last_update_time('dexpress_last_unified_update'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Podešavanja -->
                        <div class="dexpress-auto-update-settings" style="margin-top: 30px;">
                            <h3>Podešavanja</h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="dexpress_enable_auto_updates"><?php _e('Omogući automatsko ažuriranje', 'd-express-woo'); ?></label>
                                    </th>
                                    <td>
                                        <input type="checkbox" id="dexpress_enable_auto_updates" name="dexpress_enable_auto_updates"
                                            value="yes" <?php checked(get_option('dexpress_enable_auto_updates', 'yes'), 'yes'); ?>>
                                        <p class="description">Ako je isključeno, CRON neće automatski ažurirati podatke.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="dexpress_batch_size"><?php _e('Veličina batch-a', 'd-express-woo'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="dexpress_batch_size" name="dexpress_batch_size"
                                            value="<?php echo esc_attr(get_option('dexpress_batch_size', '100')); ?>"
                                            min="50" max="500" class="small-text">
                                        <p class="description">Broj zapisa koji se obrađuje odjednom. Preporučeno: 100.</p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- Info o tome kako sistem radi -->
                        <div class="dexpress-cron-info" style="margin-top: 30px; padding: 15px; background: #f0f8ff; border-left: 4px solid #0073aa;">
                            <h4>Kako funkcioniše automatsko ažuriranje:</h4>
                            <ul>
                                <li><strong>Svaki dan u 03:00:</strong> Ažuriraju se paketi i osnovni šifarnici (najvažnije)</li>
                                <li><strong>Nedeljom u 03:00:</strong> Dodatno se ažuriraju ulice</li>
                                <li><strong>1. u mesecu u 03:00:</strong> Dodatno se ažuriraju mesta i opštine</li>
                                <li><strong>Manuelno:</strong> Dugme "Ažuriraj šifarnike" na vrhu rade sve odjednom</li>
                            </ul>
                            <p><em>Ovaj pristup optimizuje performanse tako što često ažurira važne podatke (paketi), a ređe ažurira podatke koji se manje menjaju (mesta, ulice).</em></p>
                        </div>
                    </div>
                    <!-- Clean Uninstall podešavanja -->
                    <div id="tab-uninstall" class="dexpress-tab <?php echo $active_tab === 'uninstall' ? 'active' : ''; ?>">
                        <h2><?php _e('Clean Uninstall Podešavanja', 'd-express-woo'); ?></h2>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dexpress_clean_uninstall"><?php _e('Clean Uninstall', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            id="dexpress_clean_uninstall"
                                            name="dexpress_clean_uninstall"
                                            value="yes"
                                            <?php checked(get_option('dexpress_clean_uninstall'), 'yes'); ?>>
                                        <span>
                                            <strong><?php _e('Obriši sve podatke pri brisanju plugina', 'd-express-woo'); ?></strong>
                                        </span>
                                    </label>
                                    <p class="description" style="color: red;">
                                        <?php _e('UPOZORENJE: Ako je ova opcija označena, svi podaci plugina (uključujući sve tabele u bazi) će biti obrisani kada se plugin obriše.', 'd-express-woo'); ?>
                                        <span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="<?php _e('Kontroliše brisanje podataka prilikom deaktivacije plugina. Kada je aktivirano, prilikom deinstalacije plugina biće obrisane sve tabele (dexpress_shipments, dexpress_packages, dexpress_statuses, itd.) i sva podešavanja.<br><b>UPOZORENJE:</b> Ovo trajno briše istoriju pošiljki i sve konfiguracije!', 'd-express-woo'); ?>"></span>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Dugmad za akcije za sve tabove -->
                    <div class="dexpress-settings-actions" style="margin-top: 20px;">
                        <button type="submit" name="dexpress_save_settings" class="button button-primary">
                            <?php _e('Sačuvaj podešavanja', 'd-express-woo'); ?>
                        </button>

                        <a href="<?php echo esc_url(admin_url('admin.php?page=dexpress-settings&action=update_indexes')); ?>" class="button button-secondary">
                            <?php _e('Ažuriraj šifarnike', 'd-express-woo'); ?>
                        </a>

                        <?php if (dexpress_is_test_mode()): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=dexpress-settings&action=test_connection')); ?>" class="button button-secondary">
                                <?php _e('Testiraj konekciju', 'd-express-woo'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
            </form>
            <div id="dexpress-location-modal" class="dexpress-modal" style="display: none;">
                <div class="dexpress-modal-content">
                    <div class="dexpress-modal-header">
                        <h3 id="dexpress-modal-title"><?php _e('Dodaj novu lokaciju', 'd-express-woo'); ?></h3>
                        <span class="dexpress-modal-close">&times;</span>
                    </div>

                    <form id="dexpress-location-form">
                        <input type="hidden" id="location-id" name="location_id" value="">

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="location-name"><?php _e('Naziv lokacije *', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="location-name" name="name" class="regular-text" data-required="true">
                                    <p class="description"><?php _e('Naziv prodavnice/lokacije', 'd-express-woo'); ?></p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="location-address"><?php _e('Ulica *', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="location-address" name="address" class="regular-text" data-required="true">
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="location-address-num"><?php _e('Broj *', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="location-address-num" name="address_num" class="small-text" data-required="true">
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="location-town"><?php _e('Grad *', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <select id="location-town" name="town_id" class="regular-text" data-required="true">
                                        <option value=""><?php _e('Izaberite grad...', 'd-express-woo'); ?></option>
                                        <?php foreach ($towns_options as $town_id => $town_name): ?>
                                            <option value="<?php echo esc_attr($town_id); ?>"><?php echo esc_html($town_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="location-contact-name"><?php _e('Kontakt osoba *', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="location-contact-name" name="contact_name" class="regular-text" data-required="true">
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="location-contact-phone"><?php _e('Kontakt telefon *', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="location-contact-phone" name="contact_phone" class="regular-text" data-required="true" placeholder="+381641234567">
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="location-bank-account"><?php _e('Broj računa za otkupninu', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="location-bank-account" name="bank_account" class="regular-text" placeholder="160-0000000000-00">
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="location-is-default"><?php _e('Glavna lokacija', 'd-express-woo'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="location-is-default" name="is_default" value="1">
                                        <?php _e('Postavi kao glavnu lokaciju', 'd-express-woo'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <div class="dexpress-modal-footer">
                            <button type="button" class="button" id="dexpress-cancel-location"><?php _e('Otkaži', 'd-express-woo'); ?></button>
                            <button type="submit" class="button button-primary" id="dexpress-save-location">
                                <?php _e('Sačuvaj lokaciju', 'd-express-woo'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="dexpress-support-section">
                <h2><?php _e('Podrška', 'd-express-woo'); ?></h2>

                <div class="dexpress-support-content">
                    <div class="dexpress-support-card">
                        <div class="card-icon">
                            <span class="dashicons dashicons-email-alt"></span>
                        </div>
                        <div class="card-content">
                            <h3><?php _e('Email podrška', 'd-express-woo'); ?></h3>
                            <p><?php _e('Imate pitanje ili vam je potrebna pomoć? Pošaljite nam email.', 'd-express-woo'); ?></p>
                            <p class="support-email"><a href="mailto:podrska@example.com">podrska@example.com</a></p>
                        </div>
                    </div>

                    <div class="dexpress-support-card">
                        <div class="card-icon">
                            <span class="dashicons dashicons-book"></span>
                        </div>
                        <div class="card-content">
                            <h3><?php _e('Dokumentacija', 'd-express-woo'); ?></h3>
                            <p><?php _e('Pogledajte našu detaljnu dokumentaciju za pomoć oko korišćenja plugin-a.', 'd-express-woo'); ?></p>
                            <p><a href="https://example.com/dokumentacija" target="_blank" class="button button-secondary"><?php _e('Dokumentacija', 'd-express-woo'); ?></a></p>
                        </div>
                    </div>

                    <div class="dexpress-support-card">
                        <div class="card-icon">
                            <span class="dashicons dashicons-phone"></span>
                        </div>
                        <div class="card-content">
                            <h3><?php _e('Telefonska podrška', 'd-express-woo'); ?></h3>
                            <p><?php _e('Dostupni smo radnim danima od 8-16h za hitna pitanja.', 'd-express-woo'); ?></p>
                            <p class="support-phone">+381 11 123 4567</p>
                        </div>
                    </div>
                </div>

                <div class="dexpress-version-info">
                    <p><?php printf(__('D Express WooCommerce Plugin v%s', 'd-express-woo'), DEXPRESS_WOO_VERSION); ?></p>
                </div>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const contentType = document.getElementById('dexpress_content_type');
                const customRow = document.getElementById('custom-content-row');
                const preview = document.getElementById('content-preview');
                const previewText = document.getElementById('content-preview-text');
                const customContent = document.getElementById('dexpress_default_content');
                const charCount = document.getElementById('char-count');

                if (!contentType) return;

                // Show/hide custom content field
                contentType.addEventListener('change', function() {
                    if (this.value === 'custom') {
                        if (customRow) customRow.style.display = 'table-row';
                        if (preview) preview.style.display = 'none';
                    } else {
                        if (customRow) customRow.style.display = 'none';
                        showPreview();
                    }
                });

                // Character count for custom content
                if (customContent && charCount) {
                    customContent.addEventListener('input', function() {
                        const count = this.value.length;
                        charCount.textContent = `(${count}/100)`;
                        charCount.style.color = count > 100 ? '#dc3545' : '#666';
                    });
                }

                // Show preview for non-custom options
                function showPreview() {
                    const type = contentType.value;
                    let exampleText = '';

                    switch (type) {
                        case 'auto':
                            exampleText = 'Elektronika: Samsung Galaxy S21, 2x Proizvod: iPhone 13';
                            break;
                        case 'category':
                            exampleText = 'Elektronika, Proizvod';
                            break;
                        case 'name':
                            exampleText = 'Samsung Galaxy S21, 2x iPhone 13 Pro Max';
                            break;
                    }

                    if (exampleText && preview && previewText) {
                        previewText.textContent = exampleText;
                        preview.style.display = 'block';
                    }
                }

                // Initial setup
                if (contentType.value !== 'custom') {
                    showPreview();
                }
            });
        </script>
<?php
    }
    /**
     * Poboljšana validacija bankovnog računa
     * 
     * @param string $account_number Broj bankovnog računa
     * @return string Formatirani broj računa ili prazan string ako je nevalidan
     */
    private function validate_and_format_bank_account($account_number)
    {
        // Uklanjamo sve osim brojeva i crtice
        $account_number = preg_replace('/[^0-9\-]/', '', $account_number);

        // Ako je prazan string, vraćamo prazan string
        if (empty($account_number)) {
            return '';
        }

        // Ako su crtice već postavljene, proveravamo format
        if (strpos($account_number, '-') !== false) {
            $parts = explode('-', $account_number);

            // Ako nemamo 3 dela, pokušavamo da formatiramo
            if (count($parts) !== 3) {
                // Uklanjamo sve crtice i formatiramo ispočetka
                $account_number = str_replace('-', '', $account_number);
            } else {
                // Proveravamo da li delovi imaju ispravan broj cifara
                if (strlen($parts[0]) === 3 && strlen($parts[2]) === 2) {
                    return $account_number; // Već je dobro formatiran
                }

                // Uklanjamo sve crtice i formatiramo ispočetka
                $account_number = str_replace('-', '', $account_number);
            }
        }

        // Uklonili smo sve crtice, sada pokušavamo da formatiramo
        $digits_only = preg_replace('/[^0-9]/', '', $account_number);

        // Proveravamo da li imamo dovoljno cifara (minimalno 12-15 cifara)
        if (strlen($digits_only) < 12 || strlen($digits_only) > 18) {
            return ''; // Nevažeći broj računa
        }

        // Formatiramo broj računa u standardnom formatu 3-10-2 (ili više u srednjem delu)
        return substr($digits_only, 0, 3) . '-' .
            substr($digits_only, 3, strlen($digits_only) - 5) . '-' .
            substr($digits_only, -2);
    }

    /**
     * Čuvanje podešavanja
     */
    private function save_settings()
    {
        // Provera nonce-a još jednom za sigurnost
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'dexpress_settings_nonce')) {
            wp_die(__('Sigurnosna provera nije uspela.', 'd-express-woo'));
        }
        // Provera za proširenje opsega kodova - SA VALIDACIJOM
        $extend_range_end = isset($_POST['dexpress_extend_range_end']) ? intval($_POST['dexpress_extend_range_end']) : 0;
        $current_range_end = intval(get_option('dexpress_code_range_end', 99));
        $range_extended = false;
        $added_codes = 0;
        $range_error = '';

        // Validacija proširenja opsega
        if ($extend_range_end > 0) {
            if ($extend_range_end <= $current_range_end) {
                $range_error = sprintf(
                    __('Novi kraj opsega (%d) mora biti veći od trenutnog kraja (%d).', 'd-express-woo'),
                    $extend_range_end,
                    $current_range_end
                );
            } elseif ($extend_range_end > 9999999) {
                $range_error = __('Novi kraj opsega je previše velik. Maksimalno je 9.999.999.', 'd-express-woo');
            } else {
                // Validacija je prošla - proširujemo opseg
                $added_codes = $extend_range_end - $current_range_end;

                // Čuva istoriju proširenja
                $extend_log = get_option('dexpress_code_range_history', []);
                $extend_log[] = [
                    'date' => current_time('mysql'),
                    'old_range' => get_option('dexpress_code_range_start', 1) . '-' . $current_range_end,
                    'new_range' => get_option('dexpress_code_range_start', 1) . '-' . $extend_range_end,
                    'added_codes' => $added_codes
                ];
                update_option('dexpress_code_range_history', $extend_log);

                $range_extended = true;
            }
        }
        // API podešavanja
        $api_username = isset($_POST['dexpress_api_username']) ? sanitize_text_field($_POST['dexpress_api_username']) : '';
        $api_password = isset($_POST['dexpress_api_password']) ? sanitize_text_field($_POST['dexpress_api_password']) : '';
        $client_id = isset($_POST['dexpress_client_id']) ? sanitize_text_field($_POST['dexpress_client_id']) : '';
        $test_mode = isset($_POST['dexpress_test_mode']) ? 'yes' : 'no';
        $enable_logging = isset($_POST['dexpress_enable_logging']) ? 'yes' : 'no';

        // Kodovi pošiljki
        $code_prefix = isset($_POST['dexpress_code_prefix']) ? sanitize_text_field($_POST['dexpress_code_prefix']) : '';
        $code_range_start = isset($_POST['dexpress_code_range_start']) ? intval($_POST['dexpress_code_range_start']) : '';
        $code_range_end = $range_extended ? $extend_range_end : (isset($_POST['dexpress_code_range_end']) ? intval($_POST['dexpress_code_range_end']) : '');

        // Automatsko kreiranje pošiljki

        $validate_address = isset($_POST['dexpress_validate_address']) ? 'yes' : 'no';
        $enable_myaccount_tracking = isset($_POST['dexpress_enable_myaccount_tracking']) ? 'yes' : 'no';
        // CRON podešavanja  
        $enable_auto_updates = isset($_POST['dexpress_enable_auto_updates']) ? 'yes' : 'no';
        $update_time = isset($_POST['dexpress_update_time']) ? sanitize_text_field($_POST['dexpress_update_time']) : '03:00';
        $batch_size = isset($_POST['dexpress_batch_size']) ? intval($_POST['dexpress_batch_size']) : 100;

        // Podešavanja pošiljke
        $shipment_type = isset($_POST['dexpress_shipment_type']) ? sanitize_text_field($_POST['dexpress_shipment_type']) : '2';
        $payment_by = isset($_POST['dexpress_payment_by']) ? sanitize_text_field($_POST['dexpress_payment_by']) : '0';
        $payment_type = isset($_POST['dexpress_payment_type']) ? sanitize_text_field($_POST['dexpress_payment_type']) : '2';
        $return_doc = isset($_POST['dexpress_return_doc']) ? sanitize_text_field($_POST['dexpress_return_doc']) : '0';
        $default_content = isset($_POST['dexpress_default_content']) ? sanitize_text_field($_POST['dexpress_default_content']) : '';
        // Dodaj ovo sa ostalim opcijama
        $content_type = isset($_POST['dexpress_content_type']) ? sanitize_text_field($_POST['dexpress_content_type']) : 'auto';

        // Clean Uninstall opcija
        $clean_uninstall = isset($_POST['dexpress_clean_uninstall']) ? 'yes' : 'no';

        // Validacija i formatiranje bankovnog računa
        $buyout_account = isset($_POST['dexpress_buyout_account']) ? sanitize_text_field($_POST['dexpress_buyout_account']) : '';
        $buyout_account = $this->validate_and_format_bank_account($buyout_account);

        // Provera validnosti bankovnog računa ako nije prazan
        if (!empty($buyout_account) && !preg_match('/^\d{3}-\d{8,13}-\d{2}$/', $buyout_account)) {
            // Ako format nije dobar, dodajemo obaveštenje
            add_settings_error(
                'dexpress_settings',
                'invalid_buyout_account',
                __('Broj računa za otkupninu mora biti u formatu XXX-XXXXXXXXXX-XX.', 'd-express-woo'),
                'error'
            );
            // Ali i dalje čuvamo vrednost koju je korisnik uneo
        }

        $require_buyout_account = isset($_POST['dexpress_require_buyout_account']) ? 'yes' : 'no';

        // Webhook podešavanja
        $webhook_secret = isset($_POST['dexpress_webhook_secret']) ? sanitize_text_field($_POST['dexpress_webhook_secret']) : wp_generate_password(32, false);

        // Google Maps API ključ
        $google_maps_api_key = isset($_POST['dexpress_google_maps_api_key']) ? sanitize_text_field($_POST['dexpress_google_maps_api_key']) : '';

        // Ažuriranje opcija
        update_option('dexpress_api_username', $api_username);
        if (!empty($api_password)) {
            update_option('dexpress_api_password', $api_password);
        }
        update_option('dexpress_client_id', $client_id);
        update_option('dexpress_test_mode', $test_mode);
        update_option('dexpress_enable_logging', $enable_logging);
        update_option('dexpress_code_prefix', $code_prefix);
        update_option('dexpress_code_range_start', $code_range_start);
        update_option('dexpress_code_range_end', $code_range_end);
        update_option('dexpress_validate_address', $validate_address);
        update_option('dexpress_shipment_type', $shipment_type);
        update_option('dexpress_payment_by', $payment_by);
        update_option('dexpress_payment_type', $payment_type);
        update_option('dexpress_return_doc', $return_doc);
        update_option('dexpress_default_content', $default_content);
        update_option('dexpress_webhook_secret', $webhook_secret);
        update_option('dexpress_require_buyout_account', $require_buyout_account);
        update_option('dexpress_clean_uninstall', $clean_uninstall);
        update_option('dexpress_google_maps_api_key', $google_maps_api_key);
        update_option('dexpress_enable_myaccount_tracking', $enable_myaccount_tracking);
        update_option('dexpress_enable_auto_updates', $enable_auto_updates);
        update_option('dexpress_update_time', $update_time);
        update_option('dexpress_batch_size', $batch_size);
        update_option('dexpress_content_type', $content_type);

        // Beležimo u log da su podešavanja ažurirana
        if ($enable_logging === 'yes') {
            dexpress_log('Podešavanja su ažurirana od strane korisnika ID: ' . get_current_user_id(), 'info');
        }
        // Nivo logovanja
        $log_level = isset($_POST['dexpress_log_level']) ? sanitize_key($_POST['dexpress_log_level']) : 'debug';
        update_option('dexpress_log_level', $log_level);
        // Na kraju save_settings funkcije
        $active_tab = isset($_POST['active_tab']) ? sanitize_key($_POST['active_tab']) : 'api';
        $redirect_params = ['settings-updated' => 'true', 'tab' => $active_tab];

        // Dodaj parametre za proširenje opsega
        if ($range_extended) {
            $redirect_params['extended'] = 'success';
            $redirect_params['added'] = $added_codes;
        } elseif (!empty($range_error)) {
            $redirect_params['extended'] = 'error';
            $redirect_params['error_message'] = urlencode($range_error);
        }

        wp_redirect(add_query_arg($redirect_params, admin_url('admin.php?page=dexpress-settings')));
        exit;
    }
    /**
     * Render stranice za pošiljke
     */
    public function render_shipments_page()
    {
        if (class_exists('D_Express_Shipments_List')) {
            // Pozovite funkciju koja će prikazati listu pošiljki
            dexpress_shipments_list();
        } else {
            // Prikažite obaveštenje ako klasa ne postoji
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
     * Dodavanje kolone za praćenje u listi narudžbina
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
     * Prikazivanje podataka u koloni za praćenje
     */
    public function show_order_tracking_column_data($column, $order_id)
    {
        if ($column === 'dexpress_tracking') {
            global $wpdb;

            // Dobijamo podatke o pošiljci iz baze
            $shipment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
                $order_id
            ));

            if ($shipment) {
                // Prikazujemo tracking broj i status ako postoji
                echo '<a href="https://www.dexpress.rs/rs/pracenje-posiljaka/' . esc_attr($shipment->tracking_number) . '" 
                target="_blank" class="dexpress-tracking-number">' .
                    esc_html($shipment->tracking_number) . '</a>';

                if (!empty($shipment->status_code)) {
                    $status_class = dexpress_get_status_css_class($shipment->status_code);
                    $status_name = dexpress_get_status_name($shipment->status_code);

                    echo '<br><span class="dexpress-status-badge ' . $status_class . '">' .
                        esc_html($status_name) . '</span>';
                }
            } else {
                echo '<span class="dexpress-no-shipment">-</span>';
            }
        }
    }
    /**
     * Obrada akcija na stranici podešavanja
     */
    public function update_indexes()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za pristup ovoj stranici.', 'd-express-woo'));
        }

        $result = D_Express_Cron_Manager::manual_update_all();

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';

        if ($result === true) {
            wp_redirect(add_query_arg([
                'page' => 'dexpress-settings',
                'tab' => $active_tab,
                'indexes-updated' => 'success',
            ], admin_url('admin.php')));
        } else {
            wp_redirect(add_query_arg([
                'page' => 'dexpress-settings',
                'tab' => $active_tab,
                'indexes-updated' => 'error',
            ], admin_url('admin.php')));
        }
        exit;
    }
    /**
     * Testiranje konekcije sa API-em
     */
    public function test_connection()
    {
        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za pristup ovoj stranici.', 'd-express-woo'));
        }

        // Kreiranje instance API klase
        $api = D_Express_API::get_instance();

        // Dodati ovo za zadržavanje aktivnog taba
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';

        // Provera API kredencijala
        if (!$api->has_credentials()) {
            wp_redirect(add_query_arg(array(
                'page' => 'dexpress-settings',
                'tab' => $active_tab,  // Dodato zadržavanje taba
                'connection-test' => 'missing_credentials',
            ), admin_url('admin.php')));
            exit;
        }

        // Testiranje konekcije - pokušaj preuzimanja statusa
        $result = $api->get_statuses();

        if (!is_wp_error($result)) {
            // Uspešna konekcija

            // Beležimo rezultat u log ako je logovanje uključeno
            if (get_option('dexpress_enable_logging', 'no') === 'yes') {
                dexpress_log('Test konekcije uspešan. Dobijeno ' . (is_array($result) ? count($result) : 0) . ' statusa.', 'info');
            }

            wp_redirect(add_query_arg(array(
                'page' => 'dexpress-settings',
                'tab' => $active_tab,  // Dodato zadržavanje taba
                'connection-test' => 'success',
            ), admin_url('admin.php')));
        } else {
            // Greška pri konekciji

            // Beležimo grešku u log
            if (get_option('dexpress_enable_logging', 'no') === 'yes') {
                dexpress_log('Test konekcije neuspešan: ' . $result->get_error_message(), 'error');
            }

            wp_redirect(add_query_arg(array(
                'page' => 'dexpress-settings',
                'tab' => $active_tab,  // Dodato zadržavanje taba
                'connection-test' => 'error',
                'error-message' => urlencode($result->get_error_message()),
            ), admin_url('admin.php')));
        }
        exit;
    }
    public function add_wc_orders_label_printed_column($columns)
    {
        $new_columns = $columns;

        // Dodaj kolonu na kraj liste
        $new_columns['dexpress_label_printed'] = __('D Express nalepnica', 'd-express-woo');

        return $new_columns;
    }

    // 2. Prikaz sadržaja kolone
    // U class-dexpress-admin.php, modifikujemo funkciju show_wc_orders_label_printed_column

    public function show_wc_orders_label_printed_column($column, $post_or_order_id)
    {
        if ($column !== 'dexpress_label_printed') {
            return;
        }
        global $wpdb;
        // Dobijanje ID-a narudžbine (radi i sa objektom i sa ID-em)
        $order_id = is_object($post_or_order_id) ? $post_or_order_id->get_id() : $post_or_order_id;

        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));

        if (!$shipment) {
            echo '<div class="dexpress-no-shipment" style="text-align: center; color: #999;">
                <span class="dashicons dashicons-minus" style="font-size: 24px; width: 24px; height: 24px; margin: 0 auto;"></span>
                <div style="font-size: 12px; margin-top: 4px;">' . esc_html__('Nema pošiljke', 'd-express-woo') . '</div>
              </div>';
            return;
        }

        $is_printed = get_post_meta($order_id, '_dexpress_label_printed', true);

        if ($is_printed === 'yes') {
            echo '<div class="dexpress-label-printed" style="text-align: center;">
                <span class="dashicons dashicons-yes-alt" style="color: #5cb85c; font-size: 28px; width: 28px; height: 28px; margin: 0 auto;"></span>
                <div style="font-size: 12px; margin-top: 4px;">' . esc_html__('Odštampano', 'd-express-woo') . '</div>
              </div>';
        } else {
            echo '<div class="dexpress-label-not-printed" style="text-align: center;">
                <span class="dashicons dashicons-no-alt" style="color: red; font-size: 28px; width: 28px; height: 28px; margin: 0 auto;"></span>
                <div style="font-size: 12px; margin-top: 4px;">' . esc_html__('Nije štampano', 'd-express-woo') . '</div>
              </div>';
        }
    }
    // Dodaj ove nove funkcije u klasu D_Express_Admin
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

    public function show_order_shipment_status_column($column, $post_or_order_id)
    {
        if ($column !== 'dexpress_shipment_status') {
            return;
        }

        // Dobijanje ID-a narudžbine (radi i sa objektom i sa ID-em)
        $order_id = is_object($post_or_order_id) ? $post_or_order_id->get_id() : $post_or_order_id;

        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));

        if (!$shipment) {
            echo '<span class="dexpress-no-shipment">-</span>';
            return;
        }

        // Koristi centralizovane funkcije za dobijanje statusa
        $status_code = $shipment->status_code;
        $status_name = dexpress_get_status_name($status_code);
        $status_group = dexpress_get_status_group($status_code);
        $css_class = dexpress_get_status_css_class($status_code);
        $icon = dexpress_get_status_icon($status_code);

        // Za test režim, dodaj [TEST] oznaku
        if ($shipment->is_test) {
            $status_name .= ' [TEST]';
        }

        // Prikazivanje statusa sa odgovarajućom ikonom i stilom
        echo '<div class="' . esc_attr($css_class) . '" style="display:flex; align-items:center; justify-content:center; flex-direction:column;">';
        echo '<span class="dashicons ' . esc_attr($icon) . '" style="margin-bottom:3px; font-size:20px;"></span>';
        echo '<span style="font-size:12px; text-align:center; line-height:1.2;">' . esc_html($status_name) . '</span>';

        // Dodaj indikator testa ako je test pošiljka
        if ($shipment->is_test) {
            echo '<span class="dexpress-test-badge" style="background:#f8f9fa; color:#6c757d; font-size:10px; padding:1px 4px; border-radius:3px; margin-top:2px;">TEST</span>';
        }

        echo '</div>';
    }

    private function format_last_update_time($option_name)
    {
        $timestamp = get_option($option_name, 0);
        if (!$timestamp) {
            return 'Nikad';
        }

        $time_diff = time() - $timestamp;

        if ($time_diff < HOUR_IN_SECONDS) {
            return 'Pre ' . round($time_diff / 60) . ' minuta';
        } elseif ($time_diff < DAY_IN_SECONDS) {
            return 'Pre ' . round($time_diff / HOUR_IN_SECONDS) . ' sati';
        } else {
            return date('d.m.Y H:i', $timestamp);
        }
    }
    /**
     * Dohvatanje poslednjih logova
     */
    private function get_recent_logs()
    {
        $log_file = DEXPRESS_WOO_PLUGIN_DIR . 'logs/dexpress.log';
        if (!file_exists($log_file)) {
            return 'Log fajl ne postoji.';
        }

        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) {
            return 'Log fajl je prazan.';
        }

        // Uzmi poslednih 50 linija
        $recent_lines = array_slice($lines, -50);
        $log_content = implode("\n", $recent_lines);
        if (empty($log_content)) {
            return 'Nema logova za prikaz.';
        }

        return nl2br(esc_html($log_content));
    }
    /**
     * Test CRON sistema
     */
    public function test_cron()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za pristup ovoj stranici.', 'd-express-woo'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'cron';

        dexpress_log('Test CRON sistema pokrenuo admin user', 'info');

        // Pokreni manuelno update
        D_Express_Cron_Manager::run_daily_updates();

        wp_redirect(add_query_arg([
            'page' => 'dexpress-settings',
            'tab' => $active_tab,
            'cron-test' => 'success',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Reset CRON sistema
     */
    public function reset_cron()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za pristup ovoj stranici.', 'd-express-woo'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'cron';

        // Obriši sve CRON-ove i ponovo ih kreiraj
        D_Express_Cron_Manager::clear_all_cron_jobs();
        D_Express_Cron_Manager::init_cron_jobs();

        dexpress_log('CRON sistem resetovan od strane admin user-a', 'info');

        wp_redirect(add_query_arg([
            'page' => 'dexpress-settings',
            'tab' => $active_tab,
            'cron-reset' => 'success',
        ], admin_url('admin.php')));
        exit;
    }
}
