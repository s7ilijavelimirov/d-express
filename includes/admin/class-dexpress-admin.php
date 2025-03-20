<?php

/**
 * D Express Admin klasa
 * 
 * Klasa za administratorski interfejs
 */

defined('ABSPATH') || exit;

class D_Express_Admin
{

    /**
     * Inicijalizacija admin funkcionalnosti
     */
    public function init()
    {

        // Dodavanje admin menija
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Registracija admin stilova i skripti
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Obrada admin akcija
        add_action('admin_init', array($this, 'handle_admin_actions'));

        // Dodavanje metabox-a na stranici narudžbine
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));

        // Dodavanje akcija za narudžbine
        add_action('woocommerce_order_actions', array($this, 'add_order_actions'));
        add_action('woocommerce_order_action_dexpress_create_shipment', array($this, 'process_create_shipment_action'));

        // Dodavanje kolone za praćenje u listi narudžbina
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_tracking_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'show_order_tracking_column_data'), 10, 2);
    }

    /**
     * Dodavanje admin menija
     */
    public function add_admin_menu()
    {
        static $added = false;
        if ($added) {
            error_log('D_Express_Admin::add_admin_menu prevented duplicate call');
            return;
        }
        $added = true;

        error_log('D_Express_Admin::add_admin_menu called');
        add_submenu_page(
            'woocommerce',
            __('D Express', 'd-express-woo'),
            __('D Express', 'd-express-woo'),
            'manage_woocommerce',
            'dexpress-settings',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'woocommerce',
            __('D Express Pošiljke', 'd-express-woo'),
            __('D Express Pošiljke', 'd-express-woo'),
            'manage_woocommerce',
            'dexpress-shipments',
            array($this, 'render_shipments_page')
        );
    }

    /**
     * Registracija admin stilova i skripti
     */
    public function enqueue_admin_assets($hook)
    {
        // Učitavaj stilove i skripte samo na D Express stranicama
        if (
            strpos($hook, 'dexpress') !== false ||
            (isset($_GET['page']) && strpos($_GET['page'], 'dexpress') !== false)
        ) {

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

            wp_localize_script('dexpress-admin-js', 'dexpressAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('dexpress-admin-nonce'),
                'i18n' => array(
                    'confirmDelete' => __('Da li ste sigurni da želite da obrišete ovu pošiljku?', 'd-express-woo'),
                    'error' => __('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'),
                    'success' => __('Operacija uspešno izvršena.', 'd-express-woo'),
                )
            ));
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
        $auto_create_shipment = get_option('dexpress_auto_create_shipment', 'no');
        $auto_create_on_status = get_option('dexpress_auto_create_on_status', 'processing');
        $sender_name = get_option('dexpress_sender_name', '');
        $sender_address = get_option('dexpress_sender_address', '');
        $sender_address_num = get_option('dexpress_sender_address_num', '');
        $sender_town_id = get_option('dexpress_sender_town_id', '');
        $sender_contact_name = get_option('dexpress_sender_contact_name', '');
        $sender_contact_phone = get_option('dexpress_sender_contact_phone', '');
        $shipment_type = get_option('dexpress_shipment_type', '2');
        $payment_by = get_option('dexpress_payment_by', '0');
        $payment_type = get_option('dexpress_payment_type', '2');
        $return_doc = get_option('dexpress_return_doc', '0');
        $default_content = get_option('dexpress_default_content', __('Roba iz web prodavnice', 'd-express-woo'));
        $webhook_secret = get_option('dexpress_webhook_secret', wp_generate_password(32, false));
        // Dodajte ovo u deo gde se inicijalizuju opcije
        $buyout_account = get_option('dexpress_buyout_account', '');
        // Dobijanje WooCommerce statusa narudžbina
        $order_statuses = wc_get_order_statuses();

        // Dobijanje lista gradova za dropdown
        $towns_options = dexpress_get_towns_options();
        // HTML za stranicu podešavanja
?>
        <div class="wrap">
            <h1><?php echo __('D Express Podešavanja', 'd-express-woo'); ?></h1>

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
            <form method="post" action="" class="dexpress-settings-form">
                <?php wp_nonce_field('dexpress_settings_nonce'); ?>

                <!-- API Podešavanja -->
                <div class="dexpress-settings-section">
                    <h2><?php _e('API Podešavanja', 'd-express-woo'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="dexpress_api_username"><?php _e('API Korisničko ime', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dexpress_api_username" name="dexpress_api_username"
                                    value="<?php echo esc_attr($api_username); ?>" class="regular-text">
                                <p class="description"><?php _e('Korisničko ime dobijeno od D Express-a.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_api_password"><?php _e('API Lozinka', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="dexpress_api_password" name="dexpress_api_password"
                                    value="<?php echo esc_attr($api_password); ?>" class="regular-text">
                                <p class="description"><?php _e('Lozinka dobijena od D Express-a.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_client_id"><?php _e('Client ID', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dexpress_client_id" name="dexpress_client_id"
                                    value="<?php echo esc_attr($client_id); ?>" class="regular-text">
                                <p class="description"><?php _e('Client ID u formatu UK12345.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_test_mode"><?php _e('Test režim', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" id="dexpress_test_mode" name="dexpress_test_mode"
                                    value="yes" <?php checked($test_mode, 'yes'); ?>>
                                <p class="description"><?php _e('Aktivirajte test režim tokom razvoja i testiranja.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_enable_logging"><?php _e('Uključi logovanje', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" id="dexpress_enable_logging" name="dexpress_enable_logging"
                                    value="yes" <?php checked($enable_logging, 'yes'); ?>>
                                <p class="description"><?php _e('Aktivirajte logovanje API zahteva i odgovora.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Podešavanja kodova pošiljki -->
                <div class="dexpress-settings-section">
                    <h2><?php _e('Kodovi pošiljki', 'd-express-woo'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="dexpress_code_prefix"><?php _e('Prefiks koda', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dexpress_code_prefix" name="dexpress_code_prefix"
                                    value="<?php echo esc_attr($code_prefix); ?>" class="regular-text">
                                <p class="description"><?php _e('Prefiks koda paketa (npr. TT).', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_code_range_start"><?php _e('Početak opsega', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="dexpress_code_range_start" name="dexpress_code_range_start"
                                    value="<?php echo esc_attr($code_range_start); ?>" class="small-text">
                                <p class="description"><?php _e('Početni broj za kodove paketa.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_code_range_end"><?php _e('Kraj opsega', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="dexpress_code_range_end" name="dexpress_code_range_end"
                                    value="<?php echo esc_attr($code_range_end); ?>" class="small-text">
                                <p class="description"><?php _e('Krajnji broj za kodove paketa.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Podešavanja automatske kreacije pošiljki -->
                <div class="dexpress-settings-section">
                    <h2><?php _e('Automatsko kreiranje pošiljki', 'd-express-woo'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="dexpress_validate_address"><?php _e('Validacija adrese', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" id="dexpress_validate_address" name="dexpress_validate_address"
                                    value="yes" <?php checked(get_option('dexpress_validate_address', 'yes'), 'yes'); ?>>
                                <p class="description"><?php _e('Proveri validnost adrese pre kreiranja pošiljke putem D Express API-ja', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_auto_create_shipment"><?php _e('Automatsko kreiranje', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" id="dexpress_auto_create_shipment" name="dexpress_auto_create_shipment"
                                    value="yes" <?php checked($auto_create_shipment, 'yes'); ?>>
                                <p class="description"><?php _e('Automatski kreiraj pošiljku kada narudžbina dobije određeni status.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_auto_create_on_status"><?php _e('Status za kreiranje', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <select id="dexpress_auto_create_on_status" name="dexpress_auto_create_on_status">
                                    <?php foreach ($order_statuses as $status => $name): ?>
                                        <?php $status_key = str_replace('wc-', '', $status); ?>
                                        <option value="<?php echo esc_attr($status_key); ?>" <?php selected($auto_create_on_status, $status_key); ?>>
                                            <?php echo esc_html($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php _e('Izaberite status narudžbine koji će pokrenuti kreiranje pošiljke.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Podešavanja pošiljaoca -->
                <div class="dexpress-settings-section">
                    <h2><?php _e('Podaci pošiljaoca', 'd-express-woo'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="dexpress_sender_name"><?php _e('Naziv pošiljaoca', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dexpress_sender_name" name="dexpress_sender_name"
                                    value="<?php echo esc_attr($sender_name); ?>" class="regular-text">
                                <p class="description"><?php _e('Naziv pošiljaoca koji će biti prikazan na pošiljci.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_sender_address"><?php _e('Ulica', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dexpress_sender_address" name="dexpress_sender_address"
                                    value="<?php echo esc_attr($sender_address); ?>" class="regular-text">
                                <p class="description"><?php _e('Naziv ulice (bez broja).', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_sender_address_num"><?php _e('Broj', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dexpress_sender_address_num" name="dexpress_sender_address_num"
                                    value="<?php echo esc_attr($sender_address_num); ?>" class="regular-text">
                                <p class="description"><?php _e('Kućni broj.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_sender_town_id"><?php _e('Grad', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <select id="dexpress_sender_town_id" name="dexpress_sender_town_id" class="regular-text">
                                    <option value=""><?php _e('- Izaberite grad -', 'd-express-woo'); ?></option>
                                    <?php foreach ($towns_options as $id => $name): ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected($sender_town_id, $id); ?>>
                                            <?php echo esc_html($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php _e('Izaberite grad iz D Express šifarnika.', 'd-express-woo'); ?></p>
                                <?php if (empty($towns_options)): ?>
                                    <p class="notice notice-warning">
                                        <?php _e('Molimo ažurirajte šifarnike klikom na dugme "Ažuriraj šifarnike" na dnu strane.', 'd-express-woo'); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_sender_contact_name"><?php _e('Kontakt osoba', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dexpress_sender_contact_name" name="dexpress_sender_contact_name"
                                    value="<?php echo esc_attr($sender_contact_name); ?>" class="regular-text">
                                <p class="description"><?php _e('Ime i prezime kontakt osobe.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_sender_contact_phone"><?php _e('Kontakt telefon', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dexpress_sender_contact_phone" name="dexpress_sender_contact_phone"
                                    value="<?php echo esc_attr($sender_contact_phone); ?>" class="regular-text">
                                <p class="description"><?php _e('Telefon kontakt osobe (u formatu 381xxxxxxxxx).', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_require_buyout_account"><?php _e('Obavezan račun za otkupninu', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" id="dexpress_require_buyout_account" name="dexpress_require_buyout_account"
                                    value="yes" <?php checked(get_option('dexpress_require_buyout_account', 'no'), 'yes'); ?>>
                                <p class="description">
                                    <?php _e('Spreči kreiranje pošiljki sa pouzećem ako bankovni račun nije podešen', 'd-express-woo'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_buyout_account"><?php _e('Broj računa za otkupninu', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dexpress_buyout_account" name="dexpress_buyout_account"
                                    value="<?php echo esc_attr(get_option('dexpress_buyout_account', '')); ?>"
                                    class="regular-text"
                                    placeholder="XXX-XXXXXXXXXX-XX">
                                <p class="description"><?php _e('Broj računa na koji će D Express uplaćivati iznose prikupljene pouzećem. Format: XXX-XXXXXXXXXX-XX (npr. 160-0000000000-00).', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Podešavanja pošiljke -->
                <div class="dexpress-settings-section">
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
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dexpress_default_content"><?php _e('Podrazumevani sadržaj', 'd-express-woo'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dexpress_default_content" name="dexpress_default_content"
                                    value="<?php echo esc_attr($default_content); ?>" class="regular-text">
                                <p class="description"><?php _e('Podrazumevani opis sadržaja pošiljke.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Webhook podešavanja -->
                <div class="dexpress-settings-section">
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
                                <p class="description"><?php _e('URL koji treba dostaviti D Express-u za primanje notifikacija.', 'd-express-woo'); ?></p>
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
                                <p class="description"><?php _e('Tajni ključ koji treba dostaviti D Express-u za verifikaciju notifikacija.', 'd-express-woo'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                <!-- Clean Uninstall podešavanja -->
                <div class="dexpress-settings-section">
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
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                <!-- Dugmad za akcije -->
                <div class="dexpress-settings-actions">
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
        </div>

        <script>
            function copyToClipboard(element) {
                var $temp = jQuery("<input>");
                jQuery("body").append($temp);
                $temp.val(jQuery(element).val()).select();
                document.execCommand("copy");
                $temp.remove();
                alert("<?php _e('URL je kopiran u clipboard!', 'd-express-woo'); ?>");
            }

            function generateWebhookSecret() {
                // Generisanje nasumičnog stringa od 32 karaktera
                var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                var secret = '';
                for (var i = 0; i < 32; i++) {
                    secret += chars.charAt(Math.floor(Math.random() * chars.length));
                }

                jQuery('#dexpress_webhook_secret').val(secret);
            }
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

        // API podešavanja
        $api_username = isset($_POST['dexpress_api_username']) ? sanitize_text_field($_POST['dexpress_api_username']) : '';
        $api_password = isset($_POST['dexpress_api_password']) ? sanitize_text_field($_POST['dexpress_api_password']) : '';
        $client_id = isset($_POST['dexpress_client_id']) ? sanitize_text_field($_POST['dexpress_client_id']) : '';
        $test_mode = isset($_POST['dexpress_test_mode']) ? 'yes' : 'no';
        $enable_logging = isset($_POST['dexpress_enable_logging']) ? 'yes' : 'no';

        // Kodovi pošiljki
        $code_prefix = isset($_POST['dexpress_code_prefix']) ? sanitize_text_field($_POST['dexpress_code_prefix']) : '';
        $code_range_start = isset($_POST['dexpress_code_range_start']) ? intval($_POST['dexpress_code_range_start']) : '';
        $code_range_end = isset($_POST['dexpress_code_range_end']) ? intval($_POST['dexpress_code_range_end']) : '';

        // Automatsko kreiranje pošiljki
        $auto_create_shipment = isset($_POST['dexpress_auto_create_shipment']) ? 'yes' : 'no';
        $auto_create_on_status = isset($_POST['dexpress_auto_create_on_status']) ? sanitize_text_field($_POST['dexpress_auto_create_on_status']) : 'processing';
        $validate_address = isset($_POST['dexpress_validate_address']) ? 'yes' : 'no';

        // Podaci pošiljaoca
        $sender_name = isset($_POST['dexpress_sender_name']) ? sanitize_text_field($_POST['dexpress_sender_name']) : '';
        $sender_address = isset($_POST['dexpress_sender_address']) ? sanitize_text_field($_POST['dexpress_sender_address']) : '';
        $sender_address_num = isset($_POST['dexpress_sender_address_num']) ? sanitize_text_field($_POST['dexpress_sender_address_num']) : '';
        $sender_town_id = isset($_POST['dexpress_sender_town_id']) ? intval($_POST['dexpress_sender_town_id']) : '';
        $sender_contact_name = isset($_POST['dexpress_sender_contact_name']) ? sanitize_text_field($_POST['dexpress_sender_contact_name']) : '';
        $sender_contact_phone = isset($_POST['dexpress_sender_contact_phone']) ? sanitize_text_field($_POST['dexpress_sender_contact_phone']) : '';

        // Podešavanja pošiljke
        $shipment_type = isset($_POST['dexpress_shipment_type']) ? sanitize_text_field($_POST['dexpress_shipment_type']) : '2';
        $payment_by = isset($_POST['dexpress_payment_by']) ? sanitize_text_field($_POST['dexpress_payment_by']) : '0';
        $payment_type = isset($_POST['dexpress_payment_type']) ? sanitize_text_field($_POST['dexpress_payment_type']) : '2';
        $return_doc = isset($_POST['dexpress_return_doc']) ? sanitize_text_field($_POST['dexpress_return_doc']) : '0';
        $default_content = isset($_POST['dexpress_default_content']) ? sanitize_text_field($_POST['dexpress_default_content']) : __('Roba iz web prodavnice', 'd-express-woo');

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
        update_option('dexpress_auto_create_shipment', $auto_create_shipment);
        update_option('dexpress_auto_create_on_status', $auto_create_on_status);
        update_option('dexpress_validate_address', $validate_address);
        update_option('dexpress_sender_name', $sender_name);
        update_option('dexpress_buyout_account', $buyout_account);
        update_option('dexpress_sender_address', $sender_address);
        update_option('dexpress_sender_address_num', $sender_address_num);
        update_option('dexpress_sender_town_id', $sender_town_id);
        update_option('dexpress_sender_contact_name', $sender_contact_name);
        update_option('dexpress_sender_contact_phone', $sender_contact_phone);
        update_option('dexpress_shipment_type', $shipment_type);
        update_option('dexpress_payment_by', $payment_by);
        update_option('dexpress_payment_type', $payment_type);
        update_option('dexpress_return_doc', $return_doc);
        update_option('dexpress_default_content', $default_content);
        update_option('dexpress_webhook_secret', $webhook_secret);
        update_option('dexpress_require_buyout_account', $require_buyout_account);
        update_option('dexpress_clean_uninstall', $clean_uninstall);

        // Beležimo u log da su podešavanja ažurirana
        if ($enable_logging === 'yes') {
            dexpress_log('Podešavanja su ažurirana od strane korisnika ID: ' . get_current_user_id(), 'info');
        }

        // Redirekcija nazad na stranicu podešavanja sa porukom o uspehu
        wp_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=dexpress-settings')));
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
     * Dodavanje metabox-a na stranici narudžbine
     */
    public function add_order_metabox()
    {
        add_meta_box(
            'dexpress_order_metabox',
            __('D Express Pošiljka', 'd-express-woo'),
            array($this, 'render_order_metabox'),
            'shop_order',
            'side',
            'default'
        );
    }

    /**
     * Render metabox-a na stranici narudžbine
     */
    public function render_order_metabox($post)
    {
        $order_id = $post->ID;
        $order = wc_get_order($order_id);

        if (!$order) {
            echo '<p>' . __('Narudžbina nije pronađena.', 'd-express-woo') . '</p>';
            return;
        }

        // Proveri da li već postoji pošiljka
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));

        // Ako pošiljka postoji, prikaži detalje i link za štampu
        if ($shipment) {
            // Dobijanje statusa ako postoji
            $status_text = '';
            if (!empty($shipment->status_code)) {
                $status_class = ($shipment->status_code == '130') ? 'dexpress-status-delivered' : (($shipment->status_code == '131') ? 'dexpress-status-failed' : 'dexpress-status-transit');
                $status_text = '<span class="dexpress-status-badge ' . $status_class . '">' .
                    esc_html(dexpress_get_status_name($shipment->status_code)) . '</span>';
            }

            echo '<div class="dexpress-shipment-details">';

            // Prikazujemo datum kreiranja
            echo '<p><strong>' . __('Kreirano:', 'd-express-woo') . '</strong> ' .
                date_i18n(get_option('date_format'), strtotime($shipment->created_at)) . '</p>';

            // Prikazujemo Tracking broj sa linkom za praćenje
            echo '<p><strong>' . __('Tracking broj:', 'd-express-woo') . '</strong> ';
            if ($shipment->is_test) {
                echo esc_html($shipment->tracking_number) . ' <span class="description">(' . __('Test', 'd-express-woo') . ')</span>';
            } else {
                echo '<a href="https://www.dexpress.rs/TrackingParcel?trackingNumber=' .
                    esc_attr($shipment->tracking_number) . '" target="_blank" class="dexpress-tracking-link">' .
                    esc_html($shipment->tracking_number) . '</a>';
            }
            echo '</p>';

            // Prikazujemo Reference ID
            echo '<p><strong>' . __('Reference ID:', 'd-express-woo') . '</strong> ' . esc_html($shipment->reference_id) . '</p>';

            // Prikazujemo status ako postoji
            if (!empty($status_text)) {
                echo '<p><strong>' . __('Status:', 'd-express-woo') . '</strong> ' . $status_text . '</p>';
            }

            // Link za preuzimanje nalepnice
            $nonce = wp_create_nonce('dexpress-download-label');
            $label_url = admin_url('admin-ajax.php?action=dexpress_download_label&shipment_id=' . $shipment->id . '&nonce=' . $nonce);

            echo '<p><a href="' . esc_url($label_url) . '" class="button button-primary" target="_blank">' .
                __('Preuzmi nalepnicu', 'd-express-woo') . '</a></p>';

            echo '</div>';
        } else {
            // Provera da li je izabrana D Express dostava
            $has_dexpress_shipping = false;
            foreach ($order->get_shipping_methods() as $shipping_method) {
                if (strpos($shipping_method->get_method_id(), 'dexpress') !== false) {
                    $has_dexpress_shipping = true;
                    break;
                }
            }

            // Pošiljka ne postoji, prikaži dugme za kreiranje
            echo '<div class="dexpress-create-shipment">';

            if (!$has_dexpress_shipping) {
                echo '<p class="description">' . __('Ova narudžbina nema D Express dostavu.', 'd-express-woo') . '</p>';
            } else {
                echo '<p>' . __('Još uvek nema pošiljke za ovu narudžbinu.', 'd-express-woo') . '</p>';

                // Dugme za kreiranje pošiljke
                echo '<button type="button" class="button button-primary" id="dexpress-create-shipment-btn" 
                  data-order-id="' . esc_attr($order_id) . '">' .
                    __('Kreiraj D Express pošiljku', 'd-express-woo') . '</button>';

                echo '<div id="dexpress-response" style="margin-top: 10px;"></div>';

                // JavaScript za AJAX funkcionalnost
        ?>
                <script>
                    jQuery(document).ready(function($) {
                        $('#dexpress-create-shipment-btn').on('click', function() {
                            var btn = $(this);
                            var order_id = btn.data('order-id');
                            var response_div = $('#dexpress-response');

                            btn.prop('disabled', true).text('<?php _e('Kreiranje...', 'd-express-woo'); ?>');
                            response_div.html('');

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'dexpress_create_shipment',
                                    order_id: order_id,
                                    nonce: '<?php echo wp_create_nonce('dexpress-admin-nonce'); ?>'
                                },
                                success: function(response) {
                                    btn.prop('disabled', false).text('<?php _e('Kreiraj D Express pošiljku', 'd-express-woo'); ?>');

                                    if (response.success) {
                                        response_div.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');

                                        // Osvežavanje stranice nakon uspešnog kreiranja
                                        setTimeout(function() {
                                            location.reload();
                                        }, 1500);
                                    } else {
                                        response_div.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                                    }
                                },
                                error: function() {
                                    btn.prop('disabled', false).text('<?php _e('Kreiraj D Express pošiljku', 'd-express-woo'); ?>');
                                    response_div.html('<div class="notice notice-error inline"><p><?php _e('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'); ?></p></div>');
                                }
                            });
                        });
                    });
                </script>
<?php
            }

            echo '</div>';
        }
    }

    /**
     * Dodavanje akcija za narudžbine
     */
    public function add_order_actions($actions)
    {
        $actions['dexpress_create_shipment'] = __('Kreiraj D Express pošiljku', 'd-express-woo');
        return $actions;
    }

    /**
     * Obrada akcije kreiranja pošiljke
     */
    public function process_create_shipment_action($order)
    {
        // Provera da li je narudžbina već ima kreiranu pošiljku
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order->get_id()
        ));

        if ($existing) {
            $order->add_order_note(__('D Express pošiljka već postoji za ovu narudžbinu.', 'd-express-woo'));
            return;
        }

        // Koristi shipment service umesto order handlera
        $shipment_service = new D_Express_Shipment_Service();
        $result = $shipment_service->create_shipment($order);

        if (is_wp_error($result)) {
            $order->add_order_note(sprintf(
                __('Greška pri kreiranju D Express pošiljke: %s', 'd-express-woo'),
                $result->get_error_message()
            ));
        } else {
            $order->add_order_note(sprintf(
                __('D Express pošiljka uspešno kreirana. ID: %s', 'd-express-woo'),
                $result
            ));
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
                echo '<a href="https://www.dexpress.rs/TrackingParcel?trackingNumber=' . esc_attr($shipment->tracking_number) . '" 
                    target="_blank" class="dexpress-tracking-number">' .
                    esc_html($shipment->tracking_number) . '</a>';

                if (!empty($shipment->status_code)) {
                    $status_class = ($shipment->status_code == '130') ? 'dexpress-status-delivered' : (($shipment->status_code == '131') ? 'dexpress-status-failed' : 'dexpress-status-transit');

                    echo '<br><span class="dexpress-status-badge ' . $status_class . '">' .
                        esc_html(dexpress_get_status_name($shipment->status_code)) . '</span>';
                }
            } else {
                echo '<span class="dexpress-no-shipment">-</span>';
            }
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
        }
    }

    /**
     * Ažuriranje šifarnika
     */
    private function update_indexes()
    {
        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za pristup ovoj stranici.', 'd-express-woo'));
        }

        // Kreiranje instance API klase
        $api = new D_Express_API();

        // Provera API kredencijala
        if (!$api->has_credentials()) {
            wp_redirect(add_query_arg(array(
                'page' => 'dexpress-settings',
                'error' => 'missing_credentials',
            ), admin_url('admin.php')));
            exit;
        }

        // Ažuriranje svih šifarnika
        $result = $api->update_all_indexes();

        if ($result === true) {
            // Uspešno ažuriranje
            wp_redirect(add_query_arg(array(
                'page' => 'dexpress-settings',
                'indexes-updated' => 'success',
            ), admin_url('admin.php')));
        } else {
            // Greška pri ažuriranju
            wp_redirect(add_query_arg(array(
                'page' => 'dexpress-settings',
                'indexes-updated' => 'error',
            ), admin_url('admin.php')));
        }
        exit;
    }

    /**
     * Testiranje konekcije sa API-em
     */
    private function test_connection()
    {
        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za pristup ovoj stranici.', 'd-express-woo'));
        }

        // Kreiranje instance API klase
        $api = new D_Express_API();

        // Provera API kredencijala
        if (!$api->has_credentials()) {
            wp_redirect(add_query_arg(array(
                'page' => 'dexpress-settings',
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
                'connection-test' => 'error',
                'error-message' => urlencode($result->get_error_message()),
            ), admin_url('admin.php')));
        }
        exit;
    }
}
