<?php

/**
 * D Express Settings Renderer
 * 
 * Odgovoran za renderovanje HTML sadržaja settings stranice
 */

defined('ABSPATH') || exit;

class D_Express_Settings_Renderer
{
    private $active_tab;
    private $settings_handler;

    /**
     * Konstruktor
     */
    public function __construct()
    {
        $this->active_tab = D_Express_Settings_Tabs::get_active_tab();
        // NE kreiraj handler ovde - kreiraj tek kad je potreban
    }

    /**
     * Glavna render funkcija
     */
    public function render()
    {
        // Kreiraj handler tek kad je stvarno potreban
        if (!class_exists('D_Express_Settings_Handler')) {
            require_once DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/settings/class-dexpress-settings-handler.php';
        }

        // Kreiraj handler tek kad je stvarno potreban
        if (!$this->settings_handler) {
            $this->settings_handler = new D_Express_Settings_Handler();
        }
        // Obrada forme ako je poslata
        if (isset($_POST['dexpress_save_settings']) && check_admin_referer('dexpress_settings_nonce')) {
            $this->settings_handler->save_settings();
        }

        // Renderuj stranicu
        $this->render_page_header();
        $this->render_notices();
        $this->render_form_start();
        $this->render_tab_navigation();
        $this->render_all_tabs();
        $this->render_action_buttons();
        $this->render_form_end();
        $this->render_modals();
        $this->render_support_section();
        $this->render_javascript();
    }

    /**
     * Header stranice
     */
    private function render_page_header()
    {
        echo '<div class="wrap">';
        echo '<h1 class="dexpress-settings-title">';
        echo '<span>' . __('D Express Podešavanja', 'd-express-woo') . '</span>';
        echo '<img src="' . plugin_dir_url(__FILE__) . '../../../assets/images/Dexpress-logo.jpg" alt="Logo" height="50" class="dexpress-settings-logo">';
        echo '</h1>';
        echo '<hr><br>';
    }

    /**
     * Notice poruke
     */
    private function render_notices()
    {
        $this->render_success_notices();
        $this->render_error_notices();
        $this->render_warning_notices();
        $this->render_cron_notices();
        $this->render_range_extension_notices();
    }


    /**
     * Success notice poruke
     */
    private function render_success_notices()
    {
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . __('Podešavanja su uspešno sačuvana.', 'd-express-woo') . '</p>';
            echo '</div>';
        }

        if (isset($_GET['indexes-updated']) && $_GET['indexes-updated'] === 'success') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . __('Šifarnici su uspešno ažurirani.', 'd-express-woo') . '</p>';
            echo '</div>';
        }

        if (isset($_GET['connection-test']) && $_GET['connection-test'] === 'success') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . __('Konekcija sa D Express API-em je uspešno uspostavljena.', 'd-express-woo') . '</p>';
            echo '</div>';
        }
    }

    /**
     * Error notice poruke
     */
    private function render_error_notices()
    {
        if (isset($_GET['indexes-updated']) && $_GET['indexes-updated'] === 'error') {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>' . __('Došlo je do greške prilikom ažuriranja šifarnika. Proverite API kredencijale i pokušajte ponovo.', 'd-express-woo') . '</p>';
            echo '</div>';
        }

        if (isset($_GET['connection-test']) && $_GET['connection-test'] === 'error') {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>' . __('Nije moguće uspostaviti konekciju sa D Express API-em.', 'd-express-woo');
            if (isset($_GET['error-message'])) {
                echo '<br><strong>' . esc_html(urldecode($_GET['error-message'])) . '</strong>';
            }
            echo '</p>';
            echo '</div>';
        }
    }

    /**
     * Warning notice poruke
     */
    private function render_warning_notices()
    {
        if (isset($_GET['connection-test']) && $_GET['connection-test'] === 'missing_credentials') {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>' . __('Nedostaju API kredencijali. Molimo unesite korisničko ime, lozinku i client ID.', 'd-express-woo') . '</p>';
            echo '</div>';
        }
    }

    /**
     * CRON notice poruke
     */
    private function render_cron_notices()
    {
        if (isset($_GET['cron-test']) && $_GET['cron-test'] === 'success') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . __('CRON test je uspešno pokrenut. Proverite logove za detalje.', 'd-express-woo') . '</p>';
            echo '</div>';
        }

        if (isset($_GET['cron-reset']) && $_GET['cron-reset'] === 'success') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . __('CRON sistem je uspešno resetovan.', 'd-express-woo') . '</p>';
            echo '</div>';
        }
    }

    /**
     * Range extension notice poruke
     */
    private function render_range_extension_notices()
    {
        if (isset($_GET['extended']) && $_GET['extended'] === 'success') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Opseg kodova je uspešno proširen!</strong> ';
            echo 'Dodano je ' . intval($_GET['added']) . ' novih kodova. ';
            echo 'Novi opseg: ' . get_option('dexpress_code_range_start', 1) . '-' . get_option('dexpress_code_range_end', 99) . '</p>';
            echo '</div>';
        }

        if (isset($_GET['extended']) && $_GET['extended'] === 'error') {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>Greška pri proširenju opsega kodova:</strong> ';
            echo esc_html(urldecode($_GET['error_message'])) . '</p>';
            echo '</div>';
        }
    }

    /**
     * Početak forme
     */
    private function render_form_start()
    {
        echo '<form method="post" action="' . admin_url('admin.php?page=dexpress-settings') . '" class="dexpress-settings-form">';
        wp_nonce_field('dexpress_settings_nonce');
        echo '<input type="hidden" name="active_tab" value="' . esc_attr($this->active_tab) . '">';
    }

    /**
     * Tab navigacija
     */
    private function render_tab_navigation()
    {
        D_Express_Settings_Tabs::render_tab_navigation($this->active_tab);
    }

    /**
     * Svi tabovi
     */
    private function render_all_tabs()
    {
        D_Express_Settings_Tabs::render_tabs_wrapper_start();

        $this->render_api_tab();
        $this->render_codes_tab();
        $this->render_auto_tab();
        $this->render_sender_tab();
        $this->render_shipment_tab();
        $this->render_webhook_tab();
        $this->render_cron_tab();
        $this->render_uninstall_tab();

        D_Express_Settings_Tabs::render_tabs_wrapper_end();
    }

    /**
     * API tab
     */
    private function render_api_tab()
    {
        D_Express_Settings_Tabs::render_tab_start('api', $this->active_tab, __('API Podešavanja', 'd-express-woo'));

        echo '<table class="form-table">';
        $this->render_api_username_field();
        $this->render_api_password_field();
        $this->render_client_id_field();
        $this->render_test_mode_field();
        $this->render_logging_field();
        $this->render_log_level_field();
        echo '</table>';

        D_Express_Settings_Tabs::render_tab_end();
    }

    /**
     * API Username polje
     */
    private function render_api_username_field()
    {
        $api_username = get_option('dexpress_api_username', '');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_api_username">' . __('API Korisničko ime', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="dexpress_api_username" name="dexpress_api_username" value="' . esc_attr($api_username) . '" class="regular-text">';
        echo '<p class="description">' . __('Korisničko ime dobijeno od D Express-a.', 'd-express-woo');
        echo '<span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="' . esc_attr__('Unesite korisničko ime koje ste dobili od D Express-a za pristup njihovom API-ju. Ovo je jedinstveni identifikator u formatu UUID (npr. XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXX).', 'd-express-woo') . '"></span>';
        echo '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * API Password polje
     */
    private function render_api_password_field()
    {
        $api_password = get_option('dexpress_api_password', '');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_api_password">' . __('API Lozinka', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<div class="wp-pwd">';
        echo '<input type="password" id="dexpress_api_password" name="dexpress_api_password" value="' . esc_attr($api_password) . '" class="regular-text">';
        echo '<button type="button" class="button button-secondary wp-hide-pw hide-if-no-js" data-toggle="0" aria-label="' . esc_attr__('Prikaži lozinku', 'd-express-woo') . '">';
        echo '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>';
        echo '</button>';
        echo '</div>';
        echo '<p class="description">' . __('Lozinka dobijena od D Express-a.', 'd-express-woo');
        echo '<span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="' . esc_attr__('Unesite lozinku koju ste dobili od D Express-a. Zajedno sa korisničkim imenom služi za Basic Authentication kod svih API poziva.', 'd-express-woo') . '"></span>';
        echo '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Client ID polje
     */
    private function render_client_id_field()
    {
        $client_id = get_option('dexpress_client_id', '');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_client_id">' . __('Client ID', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="dexpress_client_id" name="dexpress_client_id" value="' . esc_attr($client_id) . '" class="regular-text">';
        echo '<p class="description">' . __('Client ID u formatu UK12345.', 'd-express-woo');
        echo '<span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="' . esc_attr__('Vaš jedinstveni identifikator u D Express sistemu u formatu UKXXXXX (npr. UK12345). Ovaj podatak je neophodan i koristi se u svakom API pozivu kao CClientID parametar.', 'd-express-woo') . '"></span>';
        echo '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Test mode polje
     */
    private function render_test_mode_field()
    {
        $test_mode = get_option('dexpress_test_mode', 'yes');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_test_mode">' . __('Test režim', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="checkbox" id="dexpress_test_mode" name="dexpress_test_mode" value="yes"' . checked($test_mode, 'yes', false) . '>';
        echo '<p class="description">' . __('Aktivirajte test režim tokom razvoja i testiranja.', 'd-express-woo');
        echo '<span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="' . esc_attr__('Kada je aktiviran, plugin koristi test nalog za komunikaciju sa D Express API-jem. Pošiljke kreirane u test režimu neće biti fizički isporučene.', 'd-express-woo') . '"></span>';
        echo '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Logging polje
     */
    private function render_logging_field()
    {
        $enable_logging = get_option('dexpress_enable_logging', 'no');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_enable_logging">' . __('Uključi logovanje', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="checkbox" id="dexpress_enable_logging" name="dexpress_enable_logging" value="yes"' . checked($enable_logging, 'yes', false) . '>';
        echo '<p class="description">' . __('Aktivirajte logovanje API zahteva i odgovora.', 'd-express-woo');
        echo '<span class="dexpress-tooltip dashicons dashicons-info" data-wp-tooltip="' . esc_attr__('Aktivira detaljan zapis (log) svih API komunikacija sa D Express servisom. Log fajlovi se čuvaju u logs/ direktorijumu.', 'd-express-woo') . '"></span>';
        echo '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Log level polje
     */
    private function render_log_level_field()
    {
        $log_level = get_option('dexpress_log_level', 'debug');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_log_level">' . __('Nivo logovanja', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<select id="dexpress_log_level" name="dexpress_log_level">';
        echo '<option value="debug"' . selected($log_level, 'debug', false) . '>' . __('Debug (sve poruke)', 'd-express-woo') . '</option>';
        echo '<option value="info"' . selected($log_level, 'info', false) . '>' . __('Info (informacije i greške)', 'd-express-woo') . '</option>';
        echo '<option value="warning"' . selected($log_level, 'warning', false) . '>' . __('Warning (upozorenja i greške)', 'd-express-woo') . '</option>';
        echo '<option value="error"' . selected($log_level, 'error', false) . '>' . __('Error (samo greške)', 'd-express-woo') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('Odredite koji nivo poruka će biti zabeležen u log fajlovima.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Codes tab
     */
    private function render_codes_tab()
    {
        D_Express_Settings_Tabs::render_tab_start('codes', $this->active_tab, __('Kodovi pošiljki', 'd-express-woo'));

        echo '<table class="form-table">';
        $this->render_code_prefix_field();
        $this->render_code_range_fields();
        $this->render_code_status_field();
        $this->render_code_extension_field();
        echo '</table>';

        D_Express_Settings_Tabs::render_tab_end();
    }

    /**
     * Code prefix polje
     */
    private function render_code_prefix_field()
    {
        $code_prefix = get_option('dexpress_code_prefix', '');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_code_prefix">' . __('Prefiks koda', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="dexpress_code_prefix" name="dexpress_code_prefix" value="' . esc_attr($code_prefix) . '" class="regular-text">';
        echo '<p class="description">' . __('Prefiks koda paketa (npr. TT).', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Code range polja
     */
    private function render_code_range_fields()
    {
        $code_range_start = get_option('dexpress_code_range_start', '');
        $code_range_end = get_option('dexpress_code_range_end', '');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_code_range_start">' . __('Početak opsega', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="number" id="dexpress_code_range_start" name="dexpress_code_range_start" value="' . esc_attr($code_range_start) . '" class="small-text">';
        echo '<p class="description">' . __('Početni broj za kodove paketa.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_code_range_end">' . __('Kraj opsega', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="number" id="dexpress_code_range_end" name="dexpress_code_range_end" value="' . esc_attr($code_range_end) . '" class="small-text">';
        echo '<p class="description">' . __('Krajnji broj za kodove paketa.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Code status polje
     */
    private function render_code_status_field()
    {
        $current_index = intval(get_option('dexpress_package_index', intval(get_option('dexpress_code_range_start', 1))));
        $code_range_start = intval(get_option('dexpress_code_range_start', 1));
        $code_range_end = intval(get_option('dexpress_code_range_end', 99));
        $code_prefix = get_option('dexpress_code_prefix', '');

        if ($code_range_end == 0 || $code_range_start == 0) {
            $remaining = 0;
            $total_codes = 0;
            $used_percentage = 0;
        } else {
            $remaining = $code_range_end - $current_index;
            $total_codes = $code_range_end - $code_range_start + 1;
            $used_percentage = round((($current_index - $code_range_start) / $total_codes) * 100, 1);
        }

        echo '<tr>';
        echo '<th scope="row"><label>' . __('Trenutni status', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">';
        echo '<p><strong>Sledeći kod:</strong> <code>' . $code_prefix . sprintf('%010d', $current_index + 1) . '</code></p>';
        echo '<p><strong>Preostalo kodova:</strong> ' . $remaining . ' od ' . $total_codes . '</p>';
        echo '<p><strong>Iskorišćeno:</strong> ' . $used_percentage . '%</p>';

        $progress_color = $used_percentage > 80 ? '#dc3545' : ($used_percentage > 50 ? '#ffc107' : '#28a745');
        echo '<div style="width: 100%; height: 20px; background: #f0f0f0; border-radius: 10px; overflow: hidden; margin: 10px 0;">';
        echo '<div style="width: ' . $used_percentage . '%; height: 100%; background: ' . $progress_color . ';"></div>';
        echo '</div>';

        if ($remaining <= 10) {
            echo '<div class="notice notice-error inline">';
            echo '<p><strong>UPOZORENJE:</strong> Ostalo je samo ' . $remaining . ' kodova! Proširite opseg što pre.</p>';
            echo '</div>';
        } elseif ($used_percentage >= 80) {
            echo '<div class="notice notice-warning inline">';
            echo '<p><strong>PAŽNJA:</strong> Iskorišćeno je ' . $used_percentage . '% opsega. Razmislite o proširenju.</p>';
            echo '</div>';
        }

        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Code extension polje
     */
    private function render_code_extension_field()
    {
        $code_range_start = intval(get_option('dexpress_code_range_start', 1));
        $code_range_end = intval(get_option('dexpress_code_range_end', 99));

        echo '<tr>';
        echo '<th scope="row"><label>' . __('Proširenje opsega', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<div style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; border-radius: 4px;">';
        echo '<h4>' . __('Dodaj novi opseg kodova', 'd-express-woo') . '</h4>';
        echo '<p class="description">' . __('Kada dobijete novi opseg od D Express-a, ovde možete proširiti postojeći opseg.', 'd-express-woo') . '</p>';

        echo '<table style="margin-top: 15px;">';
        echo '<tr>';
        echo '<td style="padding-right: 15px;">';
        echo '<label for="dexpress_extend_range_end">' . __('Novi opseg od D Express-a:', 'd-express-woo') . '</label><br>';
        echo '<input type="number" id="dexpress_extend_range_end" name="dexpress_extend_range_end" min="' . ($code_range_end + 1) . '" style="width: 200px;" placeholder="' . __('Unesite krajnji broj', 'd-express-woo') . '">';
        echo '<p class="description" style="margin-top: 5px;">';
        echo sprintf(__('Trenutni opseg: %s-%s. Unesite krajnji broj novog opsega koji ste dobili od D Express-a.', 'd-express-woo'), $code_range_start, $code_range_end);
        echo '</p>';
        echo '</td>';
        echo '<td style="vertical-align: top; padding-top: 25px;">';
        echo '<div id="extend-preview" style="display: none; background: #e7f3ff; padding: 10px; border-radius: 4px; border: 1px solid #b3d7ff;">';
        echo '<strong>' . __('Pregled:', 'd-express-woo') . '</strong><br>';
        echo '<span id="preview-text"></span>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        echo '<div class="notice notice-info inline" style="margin-top: 15px;">';
        echo '<p><strong>' . __('Napomena:', 'd-express-woo') . '</strong> ' . __('Unesite novi kraj opsega i kliknite "Sačuvaj podešavanja" na dnu stranice.', 'd-express-woo') . '</p>';
        echo '</div>';

        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Auto tab (pojednostavljeno)
     */
    private function render_auto_tab()
    {
        D_Express_Settings_Tabs::render_tab_start('auto', $this->active_tab, __('Kreiranje pošiljki', 'd-express-woo'));

        echo '<table class="form-table">';

        // Način kreiranja - hardcoded na ručno
        echo '<tr>';
        echo '<th scope="row"><label>' . __('Način kreiranja pošiljki', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<div style="background: #e7f3ff; padding: 15px; border: 1px solid #b3d7ff; border-radius: 4px;">';
        echo '<p><strong>' . __('RUČNO KREIRANJE AKTIVNO', 'd-express-woo') . '</strong></p>';
        echo '<p class="description">' . __('Pošiljke se kreiraju isključivo ručno kroz admin panel pojedinačnih porudžbina.', 'd-express-woo') . '</p>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';

        // Validacija adrese
        $validate_address = get_option('dexpress_validate_address', 'yes');
        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_validate_address">' . __('Validacija adrese', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="checkbox" id="dexpress_validate_address" name="dexpress_validate_address" value="yes"' . checked($validate_address, 'yes', false) . '>';
        echo '<p class="description">' . __('Proveri validnost adrese pre kreiranja pošiljke putem D Express API-ja', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';

        // MyAccount tracking
        $enable_myaccount_tracking = get_option('dexpress_enable_myaccount_tracking', 'yes');
        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_enable_myaccount_tracking">' . __('Praćenje u Moj Nalog', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="checkbox" id="dexpress_enable_myaccount_tracking" name="dexpress_enable_myaccount_tracking" value="yes"' . checked($enable_myaccount_tracking, 'yes', false) . '>';
        echo '<p class="description">' . __('Omogući praćenje pošiljki u "Moj nalog" sekciji na frontend-u.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Auto status emails
        $auto_status_emails = get_option('dexpress_auto_status_emails', 'yes');
        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_auto_status_emails">' . __('Automatski email-ovi o statusu', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="checkbox" id="dexpress_auto_status_emails" name="dexpress_auto_status_emails" value="yes"' . checked($auto_status_emails, 'yes', false) . '>';
        echo '<p class="description">' . __('Automatski šalji email kupcu pri promeni statusa pošiljke.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        D_Express_Settings_Tabs::render_tab_end();
    }

    /**
     * Sender tab (pojednostavljeno - delegira na lokacije)
     */
    private function render_sender_tab()
    {
        D_Express_Settings_Tabs::render_tab_start('sender', $this->active_tab, __('Lokacije pošiljaoca', 'd-express-woo'));

        // Globalni bankovni račun
        $buyout_account = get_option('dexpress_buyout_account', '');
        echo '<div class="dexpress-global-bank-account" style="margin-bottom: 30px; padding: 20px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 5px;">';
        echo '<h3 style="margin-top: 0;">' . __('Globalne postavke', 'd-express-woo') . '</h3>';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_buyout_account">' . __('Bankovni račun za otkupninu', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="dexpress_buyout_account" name="dexpress_buyout_account" value="' . esc_attr($buyout_account) . '" placeholder="160-0000123456789-12" class="regular-text">';
        echo '<p class="description">' . __('Bankovni račun na koji D-Express uplaćuje novac od pouzeća. Format: 160-0000123456789-12', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        echo '</div>';

        // Renderuj lokacije sekciju
        $this->render_locations_section();

        D_Express_Settings_Tabs::render_tab_end();
    }
    private function render_locations_section()
    {
        // Dohvati lokacije i towns options
        $locations_service = D_Express_Sender_Locations::get_instance();
        $locations = $locations_service->get_all_locations();
        $towns_options = dexpress_get_towns_options();

        // Lista postojećih lokacija
        echo '<div class="dexpress-locations-list">';
        echo '<h3>' . __('Postojeće lokacije', 'd-express-woo') . '</h3>';

        if (!empty($locations)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . __('Naziv', 'd-express-woo') . '</th>';
            echo '<th>' . __('Adresa', 'd-express-woo') . '</th>';
            echo '<th>' . __('Grad', 'd-express-woo') . '</th>';
            echo '<th>' . __('Kontakt', 'd-express-woo') . '</th>';
            echo '<th>' . __('Status', 'd-express-woo') . '</th>';
            echo '<th>' . __('Akcije', 'd-express-woo') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($locations as $location) {
                echo '<tr data-location-id="' . esc_attr($location->id) . '">';

                // Naziv
                echo '<td>';
                echo '<strong>' . esc_html($location->name) . '</strong>';
                if ($location->is_default) {
                    echo '<span class="dexpress-default-badge">' . __('Glavna', 'd-express-woo') . '</span>';
                }
                echo '</td>';

                // Adresa
                echo '<td>' . esc_html($location->address . ' ' . $location->address_num) . '</td>';

                // Grad
                echo '<td>' . esc_html($location->town_name) . '</td>';

                // Kontakt
                echo '<td>';
                echo esc_html($location->contact_name) . '<br>';
                echo '<small>' . esc_html($location->contact_phone) . '</small>';
                echo '</td>';

                // Status
                echo '<td>';
                if ($location->is_default) {
                    echo '<span class="status-active">' . __('Glavna lokacija', 'd-express-woo') . '</span>';
                } else {
                    echo '<span class="status-secondary">' . __('Dodatna lokacija', 'd-express-woo') . '</span>';
                }
                echo '</td>';

                // Akcije
                echo '<td>';
                echo '<button type="button" class="button button-small dexpress-edit-location" data-location-id="' . esc_attr($location->id) . '">';
                echo __('Uredi', 'd-express-woo');
                echo '</button>';

                if (!$location->is_default) {
                    echo '<button type="button" class="button button-small dexpress-set-default" data-location-id="' . esc_attr($location->id) . '">';
                    echo __('Postavi kao glavnu', 'd-express-woo');
                    echo '</button>';

                    echo '<button type="button" class="button button-small button-link-delete dexpress-delete-location" data-location-id="' . esc_attr($location->id) . '">';
                    echo __('Obriši', 'd-express-woo');
                    echo '</button>';
                }
                echo '</td>';

                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        } else {
            echo '<div class="notice notice-info inline">';
            echo '<p>' . __('Nema definisanih lokacija. Dodajte prvu lokaciju da biste počeli.', 'd-express-woo') . '</p>';
            echo '</div>';
        }
        echo '</div>';

        // Dugme za dodavanje nove lokacije
        echo '<div class="dexpress-add-location-section" style="margin-top: 20px;">';
        echo '<button type="button" id="dexpress-add-location-btn" class="button button-primary">';
        echo __('+ Dodaj novu lokaciju', 'd-express-woo');
        echo '</button>';
        echo '</div>';
    }
    /**
     * Shipment tab
     */
    private function render_shipment_tab()
    {
        D_Express_Settings_Tabs::render_tab_start('shipment', $this->active_tab, __('Podešavanja pošiljke', 'd-express-woo'));

        echo '<table class="form-table">';
        $this->render_shipment_type_field();
        $this->render_payment_by_field();
        $this->render_payment_type_field();
        $this->render_return_doc_field();
        $this->render_content_type_field();
        $this->render_custom_content_field();
        echo '</table>';

        D_Express_Settings_Tabs::render_tab_end();
    }

    /**
     * Shipment type polje
     */
    private function render_shipment_type_field()
    {
        $shipment_type = get_option('dexpress_shipment_type', '2'); // Default na redovna

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_shipment_type">' . __('Tip pošiljke', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<select id="dexpress_shipment_type" name="dexpress_shipment_type">';

        $shipment_types = dexpress_get_shipment_types();
        foreach ($shipment_types as $type_id => $type_name) {
            echo '<option value="' . esc_attr($type_id) . '"' . selected($shipment_type, $type_id, false) . '>';
            echo esc_html($type_name);
            echo '</option>';
        }

        echo '</select>';
        echo '<p class="description">' . __('Pošiljka uvek ima status "Redovna isporuka".', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Payment by polje
     */
    private function render_payment_by_field()
    {
        $payment_by = get_option('dexpress_payment_by', '0'); // Default na nalogodavac

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_payment_by">' . __('Ko plaća dostavu', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<select id="dexpress_payment_by" name="dexpress_payment_by">';

        $payment_by_options = dexpress_get_payment_by_options();
        foreach ($payment_by_options as $option_id => $option_name) {
            echo '<option value="' . esc_attr($option_id) . '"' . selected($payment_by, $option_id, false) . '>';
            echo esc_html($option_name);
            echo '</option>';
        }

        echo '</select>';
        echo '<p class="description">' . __('Nalogodavac (pošiljalac) ili Primalac može da plati dostavu.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Payment type polje
     */
    private function render_payment_type_field()
    {
        $payment_type = get_option('dexpress_payment_type', '2');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_payment_type">' . __('Način plaćanja dostave', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<select id="dexpress_payment_type" name="dexpress_payment_type">';

        $payment_type_options = dexpress_get_payment_type_options();
        foreach ($payment_type_options as $type_id => $type_name) {
            echo '<option value="' . esc_attr($type_id) . '"' . selected($payment_type, $type_id, false) . '>';
            echo esc_html($type_name);
            echo '</option>';
        }

        echo '</select>';
        echo '<p class="description">' . __('Kod virmana D-express fakturiše, kod gotovine primalac plaća kuriru.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Return doc polje
     */
    private function render_return_doc_field()
    {
        $return_doc = get_option('dexpress_return_doc', '0');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_return_doc">' . __('Povraćaj dokumenata', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<select id="dexpress_return_doc" name="dexpress_return_doc">';

        $return_doc_options = dexpress_get_return_doc_options();
        foreach ($return_doc_options as $option_id => $option_name) {
            echo '<option value="' . esc_attr($option_id) . '"' . selected($return_doc, $option_id, false) . '>';
            echo esc_html($option_name);
            echo '</option>';
        }

        echo '</select>';
        echo '<p class="description">' . __('Povraćaj znači da kurir čeka da primalac potpiše i odmah vraća dokumente.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Content type polje
     */
    private function render_content_type_field()
    {
        $content_type = get_option('dexpress_content_type', 'category');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_content_type">' . __('Način opisa sadržaja', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<select id="dexpress_content_type" name="dexpress_content_type">';
        echo '<option value="category"' . selected($content_type, 'category', false) . '>' . __('Kategorije proizvoda (preporučeno)', 'd-express-woo') . '</option>';
        echo '<option value="name"' . selected($content_type, 'name', false) . '>' . __('Kratki nazivi proizvoda', 'd-express-woo') . '</option>';
        echo '<option value="custom"' . selected($content_type, 'custom', false) . '>' . __('Prilagođeni tekst', 'd-express-woo') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('D Express očekuje kratke opise kao: Elektronika, Odeća, Kozmetika.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Custom content polje
     */
    private function render_custom_content_field()
    {
        $default_content = get_option('dexpress_default_content', '');
        $content_type = get_option('dexpress_content_type', 'category');
        $display_style = $content_type !== 'custom' ? 'display:none;' : '';

        echo '<tr id="custom-content-row" style="' . $display_style . '">';
        echo '<th scope="row"><label for="dexpress_default_content">' . __('Prilagođeni sadržaj', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="dexpress_default_content" name="dexpress_default_content" value="' . esc_attr($default_content) . '" class="regular-text" placeholder="npr. Rezervni delovi, Tekstil, Dokumenti">';
        echo '<p class="description">' . __('Fiksni tekst koji će se koristiti kao opis sadržaja.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Webhook tab
     */
    private function render_webhook_tab()
    {
        D_Express_Settings_Tabs::render_tab_start('webhook', $this->active_tab, __('Webhook podešavanja', 'd-express-woo'));

        echo '<table class="form-table">';
        $this->render_allowed_ips_field();
        $this->render_webhook_url_field();
        $this->render_webhook_secret_field();
        $this->render_google_maps_key_field();
        echo '</table>';

        D_Express_Settings_Tabs::render_tab_end();
    }

    /**
     * Allowed IPs polje
     */
    private function render_allowed_ips_field()
    {
        $allowed_webhook_ips = get_option('dexpress_allowed_webhook_ips', '');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_allowed_webhook_ips">' . __('Dozvoljene IP adrese', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="dexpress_allowed_webhook_ips" name="dexpress_allowed_webhook_ips" value="' . esc_attr($allowed_webhook_ips) . '" class="regular-text">';
        echo '<p class="description">' . __('Lista dozvoljenih IP adresa za webhook, razdvojenih zarezima. Ostavite prazno da dozvolite sve IP adrese.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Webhook URL polje
     */
    private function render_webhook_url_field()
    {
        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_webhook_url">' . __('Webhook URL', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="dexpress_webhook_url" readonly value="' . esc_url(rest_url('dexpress-woo/v1/notify')) . '" class="regular-text">';
        echo '<button type="button" class="button button-secondary" onclick="copyToClipboard(\'#dexpress_webhook_url\')">' . __('Kopiraj', 'd-express-woo') . '</button>';
        echo '<p class="description">' . __('URL koji treba dostaviti D Express-u za primanje notifikacija.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Webhook secret polje
     */
    private function render_webhook_secret_field()
    {
        $webhook_secret = get_option('dexpress_webhook_secret', wp_generate_password(32, false));

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_webhook_secret">' . __('Webhook tajni ključ', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="dexpress_webhook_secret" name="dexpress_webhook_secret" value="' . esc_attr($webhook_secret) . '" class="regular-text">';
        echo '<button type="button" class="button button-secondary" onclick="generateWebhookSecret()">' . __('Generiši novi', 'd-express-woo') . '</button>';
        echo '<p class="description">' . __('Tajni ključ koji treba dostaviti D Express-u za verifikaciju notifikacija.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Google Maps key polje
     */
    private function render_google_maps_key_field()
    {
        $google_maps_api_key = get_option('dexpress_google_maps_api_key', '');

        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_google_maps_api_key">' . __('Google Maps API ključ', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="dexpress_google_maps_api_key" name="dexpress_google_maps_api_key" value="' . esc_attr($google_maps_api_key) . '" class="regular-text">';
        echo '<p class="description">' . sprintf(__('Unesite Google Maps API ključ za prikazivanje mape paketomata. Možete ga dobiti na <a href="%s" target="_blank">Google Developers Console</a>.', 'd-express-woo'), 'https://developers.google.com/maps/documentation/javascript/get-api-key') . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * CRON tab
     */
    private function render_cron_tab()
    {
        D_Express_Settings_Tabs::render_tab_start('cron', $this->active_tab, __('Automatsko ažuriranje', 'd-express-woo'));

        $this->render_cron_status();
        $this->render_cron_settings();
        $this->render_cron_info();

        D_Express_Settings_Tabs::render_tab_end();
    }

    /**
     * CRON status
     */
    private function render_cron_status()
    {
        $cron_status = D_Express_Cron_Manager::get_cron_status();

        echo '<div class="dexpress-cron-status">';
        echo '<h3>Status automatskog ažuriranja</h3>';

        // Glavni status
        echo '<table class="widefat">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Sistem</th>';
        echo '<th>Status</th>';
        echo '<th>Sledeće pokretanje</th>';
        echo '<th>Poslednje pokretanje</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        echo '<tr>';
        echo '<td><strong>Glavni CRON zadatak</strong></td>';
        echo '<td><span class="status-' . ($cron_status['is_active'] ? 'active' : 'inactive') . '">' . ($cron_status['is_active'] ? 'Aktivan' : 'Neaktivan') . '</span></td>';
        echo '<td>' . esc_html($cron_status['next_run_formatted']) . '</td>';
        echo '<td>' . esc_html($cron_status['last_run_formatted']) . '</td>';
        echo '</tr>';
        echo '</tbody>';
        echo '</table>';

        // Detaljni status po tipovima
        if (isset($cron_status['detailed_status'])) {
            echo '<h4 style="margin-top: 25px;">Detaljan status po tipovima</h4>';
            echo '<table class="widefat">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Tip</th>';
            echo '<th>Frekvencija</th>';
            echo '<th>Poslednje ažuriranje</th>';
            echo '<th>Starost</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($cron_status['detailed_status'] as $type => $info) {
                echo '<tr>';
                echo '<td><strong>' . ucfirst($type) . '</strong></td>';
                echo '<td>' . esc_html($info['frequency']) . '</td>';
                echo '<td>' . esc_html($info['last_update_formatted']) . '</td>';

                // Status boja na osnovu starosti
                $status_class = 'status-active';
                $status_text = 'OK';

                if ($info['days_since'] === null) {
                    $status_class = 'status-inactive';
                    $status_text = 'Nikad';
                } elseif ($type == 'dispensers' && $info['days_since'] > 2) {
                    $status_class = 'status-warning';
                    $status_text = $info['days_since'] . ' dana';
                } elseif ($type == 'streets' && $info['days_since'] > 10) {
                    $status_class = 'status-warning';
                    $status_text = $info['days_since'] . ' dana';
                } elseif (($type == 'towns' || $type == 'municipalities') && $info['days_since'] > 35) {
                    $status_class = 'status-warning';
                    $status_text = $info['days_since'] . ' dana';
                } else {
                    $status_text = $info['days_since'] . ' dana';
                }

                echo '<td><span class="' . $status_class . '">' . $status_text . '</span></td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        }

        echo '<div style="margin-top: 15px;">';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=dexpress_test_cron'), 'dexpress_cron_test')) . '" class="button button-secondary">Test CRON zadatka</a> ';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=dexpress_reset_cron'), 'dexpress_cron_reset')) . '" class="button button-secondary" onclick="return confirm(\'Da li ste sigurni da želite da resetujete CRON?\')">Reset CRON sistema</a>';
        echo '</div>';
        echo '</div>';

        // CSS za status boje
        echo '<style>
    .status-active { color: #00a32a; font-weight: bold; }
    .status-inactive { color: #646970; }
    .status-warning { color: #dba617; font-weight: bold; }
    </style>';
    }

    /**
     * CRON settings
     */
    private function render_cron_settings()
    {
        echo '<div class="dexpress-auto-update-settings" style="margin-top: 30px;">';
        echo '<h3>Podešavanja</h3>';
        echo '<table class="form-table">';

        // Enable auto updates
        $enable_auto_updates = get_option('dexpress_enable_auto_updates', 'yes');
        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_enable_auto_updates">' . __('Omogući automatsko ažuriranje', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="checkbox" id="dexpress_enable_auto_updates" name="dexpress_enable_auto_updates" value="yes"' . checked($enable_auto_updates, 'yes', false) . '>';
        echo '<p class="description">Ako je isključeno, CRON neće automatski ažurirati podatke.</p>';
        echo '</td>';
        echo '</tr>';

        // Batch size
        $batch_size = get_option('dexpress_batch_size', '400');
        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_batch_size">' . __('Veličina batch-a', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="number" id="dexpress_batch_size" name="dexpress_batch_size" value="' . esc_attr($batch_size) . '" min="50" max="500" class="small-text">';
        echo '<p class="description">Broj zapisa koji se obrađuje odjednom. Preporučeno: 100.</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';
        echo '</div>';
    }

    /**
     * CRON info - WordPress default style
     */
    private function render_cron_info()
    {
        echo '<h3>' . __('Raspored automatskog ažuriranja', 'd-express-woo') . '</h3>';

        echo '<div class="postbox" style="margin: 20px 0;">';
        echo '<div class="postbox-header"><h2 class="hndle">' . __('Prema D Express preporukama', 'd-express-woo') . '</h2></div>';
        echo '<div class="inside">';

        echo '<h4>' . __('Svaki dan u 03:00', 'd-express-woo') . '</h4>';
        echo '<ul><li><strong>Paketomati</strong> (~300 lokacija)</li></ul>';

        echo '<h4>' . __('Nedeljno (nedelja u 03:00)', 'd-express-woo') . '</h4>';
        echo '<ul>';
        echo '<li><strong>Statusi</strong> (~30 kodova)</li>';
        echo '<li><strong>Ulice</strong> (~50,000 zapisa)</li>';
        echo '</ul>';

        echo '<h4>' . __('Mesečno (1. u mesecu u 03:00)', 'd-express-woo') . '</h4>';
        echo '<ul>';
        echo '<li><strong>Gradovi</strong> (~1,500 mesta)</li>';
        echo '<li><strong>Opštine</strong> (~150 opština)</li>';
        echo '</ul>';

        echo '<div class="notice notice-info inline" style="margin-top: 15px;">';
        echo '<p><strong>' . __('Napomena:', 'd-express-woo') . '</strong> ' . __('Ova frekvencija je optimizovana prema D Express preporukama za najbolje performanse.', 'd-express-woo') . '</p>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    /**
     * Uninstall tab
     */
    private function render_uninstall_tab()
    {
        D_Express_Settings_Tabs::render_tab_start('uninstall', $this->active_tab, __('Clean Uninstall Podešavanja', 'd-express-woo'));

        echo '<table class="form-table">';

        $clean_uninstall = get_option('dexpress_clean_uninstall', 'no');
        echo '<tr>';
        echo '<th scope="row"><label for="dexpress_clean_uninstall">' . __('Clean Uninstall', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" id="dexpress_clean_uninstall" name="dexpress_clean_uninstall" value="yes"' . checked($clean_uninstall, 'yes', false) . '>';
        echo '<span><strong>' . __('Obriši sve podatke pri brisanju plugina', 'd-express-woo') . '</strong></span>';
        echo '</label>';
        echo '<p class="description" style="color: red;">' . __('UPOZORENJE: Ako je ova opcija označena, svi podaci plugina (uključujući sve tabele u bazi) će biti obrisani kada se plugin obriše.', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        D_Express_Settings_Tabs::render_tab_end();
    }

    /**
     * Action buttons
     */
    private function render_action_buttons()
    {
        D_Express_Settings_Tabs::render_action_buttons();
    }

    /**
     * Kraj forme
     */
    private function render_form_end()
    {
        echo '</form>';
    }

    /**
     * Modal dialozi
     */
    private function render_modals()
    {
        $this->render_location_modal();
    }
    /**
     * Renderuje location modal
     */
    private function render_location_modal()
    {
        $towns_options = dexpress_get_towns_options();

        echo '<div id="dexpress-location-modal" class="dexpress-modal" style="display: none;">';
        echo '<div class="dexpress-modal-content">';

        // Modal header
        echo '<div class="dexpress-modal-header">';
        echo '<h3 id="dexpress-modal-title">' . __('Dodaj novu lokaciju', 'd-express-woo') . '</h3>';
        echo '<span class="dexpress-modal-close">&times;</span>';
        echo '</div>';

        // Modal form
        echo '<form id="dexpress-location-form">';
        echo '<input type="hidden" id="location-id" name="location_id" value="">';

        echo '<table class="form-table">';

        // Naziv lokacije
        echo '<tr>';
        echo '<th scope="row"><label for="location-name">' . __('Naziv lokacije *', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="location-name" name="name" class="regular-text" data-required="true">';
        echo '<p class="description">' . __('Naziv prodavnice/lokacije', 'd-express-woo') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Ulica
        echo '<tr>';
        echo '<th scope="row"><label for="location-address">' . __('Ulica *', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="location-address" name="address" class="regular-text" data-required="true">';
        echo '</td>';
        echo '</tr>';

        // Broj
        echo '<tr>';
        echo '<th scope="row"><label for="location-address-num">' . __('Broj *', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="location-address-num" name="address_num" class="small-text" data-required="true">';
        echo '</td>';
        echo '</tr>';

        // Grad
        echo '<tr>';
        echo '<th scope="row"><label for="location-town">' . __('Grad *', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<select id="location-town" name="town_id" class="regular-text" data-required="true">';
        echo '<option value="">' . __('Izaberite grad...', 'd-express-woo') . '</option>';
        foreach ($towns_options as $town_id => $town_name) {
            echo '<option value="' . esc_attr($town_id) . '">' . esc_html($town_name) . '</option>';
        }
        echo '</select>';
        echo '</td>';
        echo '</tr>';

        // Kontakt osoba
        echo '<tr>';
        echo '<th scope="row"><label for="location-contact-name">' . __('Kontakt osoba *', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="location-contact-name" name="contact_name" class="regular-text" data-required="true">';
        echo '</td>';
        echo '</tr>';

        // Kontakt telefon
        echo '<tr>';
        echo '<th scope="row"><label for="location-contact-phone">' . __('Kontakt telefon *', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="location-contact-phone" name="contact_phone" class="regular-text" data-required="true" placeholder="+381641234567">';
        echo '</td>';
        echo '</tr>';

        // Glavna lokacija checkbox
        echo '<tr>';
        echo '<th scope="row"><label for="location-is-default">' . __('Glavna lokacija', 'd-express-woo') . '</label></th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" id="location-is-default" name="is_default" value="1">';
        echo __('Postavi kao glavnu lokaciju', 'd-express-woo');
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // Modal footer
        echo '<div class="dexpress-modal-footer">';
        echo '<button type="button" class="button" id="dexpress-cancel-location">' . __('Otkaži', 'd-express-woo') . '</button>';
        echo '<button type="submit" class="button button-primary" id="dexpress-save-location">' . __('Sačuvaj lokaciju', 'd-express-woo') . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
        echo '</div>';
    }
    /**
     * Support sekcija
     */
    private function render_support_section()
    {
        echo '<div class="dexpress-support-section">';
        echo '<h2>' . __('Podrška', 'd-express-woo') . '</h2>';
        echo '<div class="dexpress-support-content">';

        // Email support card
        echo '<div class="dexpress-support-card">';
        echo '<div class="card-icon"><span class="dashicons dashicons-email-alt"></span></div>';
        echo '<div class="card-content">';
        echo '<h3>' . __('Email podrška', 'd-express-woo') . '</h3>';
        echo '<p>' . __('Imate pitanje ili vam je potrebna pomoć? Pošaljite nam email.', 'd-express-woo') . '</p>';
        echo '<p class="support-email"><a href="mailto:podrska@example.com">podrska@example.com</a></p>';
        echo '</div>';
        echo '</div>';

        // Documentation card
        echo '<div class="dexpress-support-card">';
        echo '<div class="card-icon"><span class="dashicons dashicons-book"></span></div>';
        echo '<div class="card-content">';
        echo '<h3>' . __('Dokumentacija', 'd-express-woo') . '</h3>';
        echo '<p>' . __('Pogledajte našu detaljnu dokumentaciju za pomoć oko korišćenja plugin-a.', 'd-express-woo') . '</p>';
        echo '<p><a href="https://example.com/dokumentacija" target="_blank" class="button button-secondary">' . __('Dokumentacija', 'd-express-woo') . '</a></p>';
        echo '</div>';
        echo '</div>';

        // Phone support card
        echo '<div class="dexpress-support-card">';
        echo '<div class="card-icon"><span class="dashicons dashicons-phone"></span></div>';
        echo '<div class="card-content">';
        echo '<h3>' . __('Telefonska podrška', 'd-express-woo') . '</h3>';
        echo '<p>' . __('Dostupni smo radnim danima od 8-16h za hitna pitanja.', 'd-express-woo') . '</p>';
        echo '<p class="support-phone">+381 11 123 4567</p>';
        echo '</div>';
        echo '</div>';

        echo '</div>';

        // Version info
        echo '<div class="dexpress-version-info">';
        echo '<p>' . sprintf(__('D Express WooCommerce Plugin v%s', 'd-express-woo'), DEXPRESS_WOO_VERSION) . '</p>';
        echo '</div>';

        echo '</div>';
        echo '</div>'; // Closing main wrap div
    }

    /**
     * JavaScript za stranicu
     */
    private function render_javascript()
    {
        echo '<script>';
        echo 'document.addEventListener("DOMContentLoaded", function() {';
        echo '    const contentType = document.getElementById("dexpress_content_type");';
        echo '    const customRow = document.getElementById("custom-content-row");';
        echo '    if (contentType && customRow) {';
        echo '        contentType.addEventListener("change", function() {';
        echo '            if (this.value === "custom") {';
        echo '                customRow.style.display = "table-row";';
        echo '            } else {';
        echo '                customRow.style.display = "none";';
        echo '            }';
        echo '        });';
        echo '    }';
        echo '});';
        echo '</script>';
    }
}
