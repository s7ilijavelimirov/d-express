<?php

/**
 * D Express Settings Handler
 * 
 * Odgovoran za čuvanje i validaciju settings podataka
 */

defined('ABSPATH') || exit;

class D_Express_Settings_Handler
{
    /**
     * Konstruktor
     */
    public function __construct()
    {
        // Hook se poziva iz glavne admin klase kada je potrebno
    }

    /**
     * Čuvanje podešavanja
     */
    public function save_settings()
    {
        // Provera nonce-a za sigurnost
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'dexpress_settings_nonce')) {
            wp_die(__('Sigurnosna provera nije uspela.', 'd-express-woo'));
        }

        // Validacija i čuvanje svih opcija
        $this->handle_code_range_extension();
        $this->save_api_settings();
        $this->save_code_settings();
        $this->save_auto_settings();
        $this->save_shipment_settings();
        $this->save_webhook_settings();
        $this->save_cron_settings();
        $this->save_uninstall_settings();

        // Logging
        if (get_option('dexpress_enable_logging', 'no') === 'yes') {
            dexpress_log('Podešavanja su ažurirana od strane korisnika ID: ' . get_current_user_id(), 'info');
        }

        // Redirect sa rezultatom
        $this->redirect_after_save();
    }

    /**
     * Handling proširenja opsega kodova
     */
    private function handle_code_range_extension()
    {
        $extend_range_end = isset($_POST['dexpress_extend_range_end']) ? intval($_POST['dexpress_extend_range_end']) : 0;
        $current_range_end = intval(get_option('dexpress_code_range_end', 99));

        if ($extend_range_end > 0) {
            $validation_result = $this->validate_range_extension($extend_range_end, $current_range_end);

            if ($validation_result['success']) {
                $this->execute_range_extension($extend_range_end, $current_range_end);
            } else {
                $this->handle_range_extension_error($validation_result['error']);
            }
        }
    }

    /**
     * Validacija proširenja opsega
     */
    private function validate_range_extension($extend_range_end, $current_range_end)
    {
        if ($extend_range_end <= $current_range_end) {
            return [
                'success' => false,
                'error' => sprintf(
                    __('Novi kraj opsega (%d) mora biti veći od trenutnog kraja (%d).', 'd-express-woo'),
                    $extend_range_end,
                    $current_range_end
                )
            ];
        }

        if ($extend_range_end > 9999999) {
            return [
                'success' => false,
                'error' => __('Novi kraj opsega je previše velik. Maksimalno je 9.999.999.', 'd-express-woo')
            ];
        }

        return ['success' => true];
    }

    /**
     * Izvršavanje proširenja opsega
     */
    private function execute_range_extension($extend_range_end, $current_range_end)
    {
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
        update_option('dexpress_code_range_end', $extend_range_end);

        // Set flag za uspešno proširenje
        $_POST['_range_extended'] = true;
        $_POST['_added_codes'] = $added_codes;
    }

    /**
     * Handling greške proširenja opsega
     */
    private function handle_range_extension_error($error_message)
    {
        $_POST['_range_error'] = $error_message;
    }

    /**
     * Čuvanje API podešavanja
     */
    private function save_api_settings()
    {
        $api_username = isset($_POST['dexpress_api_username']) ? sanitize_text_field($_POST['dexpress_api_username']) : '';
        $api_password = isset($_POST['dexpress_api_password']) ? sanitize_text_field($_POST['dexpress_api_password']) : '';
        $client_id = isset($_POST['dexpress_client_id']) ? sanitize_text_field($_POST['dexpress_client_id']) : '';
        $test_mode = isset($_POST['dexpress_test_mode']) ? 'yes' : 'no';
        $enable_logging = isset($_POST['dexpress_enable_logging']) ? 'yes' : 'no';
        $log_level = isset($_POST['dexpress_log_level']) ? sanitize_key($_POST['dexpress_log_level']) : 'debug';

        update_option('dexpress_api_username', $api_username);
        if (!empty($api_password)) {
            update_option('dexpress_api_password', $api_password);
        }
        update_option('dexpress_client_id', $client_id);
        update_option('dexpress_test_mode', $test_mode);
        update_option('dexpress_enable_logging', $enable_logging);
        update_option('dexpress_log_level', $log_level);
    }

    /**
     * Čuvanje podešavanja kodova
     */
    private function save_code_settings()
    {
        $code_prefix = isset($_POST['dexpress_code_prefix']) ? sanitize_text_field($_POST['dexpress_code_prefix']) : '';
        $code_range_start = isset($_POST['dexpress_code_range_start']) ? intval($_POST['dexpress_code_range_start']) : '';

        // code_range_end se čuva kroz extension handling
        if (!isset($_POST['_range_extended'])) {
            $code_range_end = isset($_POST['dexpress_code_range_end']) ? intval($_POST['dexpress_code_range_end']) : '';
            update_option('dexpress_code_range_end', $code_range_end);
        }

        update_option('dexpress_code_prefix', $code_prefix);
        update_option('dexpress_code_range_start', $code_range_start);
    }

    /**
     * Čuvanje auto kreiranja podešavanja
     */
    private function save_auto_settings()
    {
        $validate_address = isset($_POST['dexpress_validate_address']) ? 'yes' : 'no';
        $enable_myaccount_tracking = isset($_POST['dexpress_enable_myaccount_tracking']) ? 'yes' : 'no';
        $auto_status_emails = isset($_POST['dexpress_auto_status_emails']) ? 'yes' : 'no';

        update_option('dexpress_validate_address', $validate_address);
        update_option('dexpress_enable_myaccount_tracking', $enable_myaccount_tracking);
        update_option('dexpress_auto_status_emails', $auto_status_emails);
    }

    /**
     * Čuvanje podešavanja pošiljke
     */
    private function save_shipment_settings()
    {
        $shipment_type = isset($_POST['dexpress_shipment_type']) ? sanitize_text_field($_POST['dexpress_shipment_type']) : '2';
        $payment_by = isset($_POST['dexpress_payment_by']) ? sanitize_text_field($_POST['dexpress_payment_by']) : '0';
        $payment_type = isset($_POST['dexpress_payment_type']) ? sanitize_text_field($_POST['dexpress_payment_type']) : '2';
        $return_doc = isset($_POST['dexpress_return_doc']) ? sanitize_text_field($_POST['dexpress_return_doc']) : '0';
        $default_content = isset($_POST['dexpress_default_content']) ? sanitize_text_field($_POST['dexpress_default_content']) : '';
        $content_type = isset($_POST['dexpress_content_type']) ? sanitize_text_field($_POST['dexpress_content_type']) : 'category';

        update_option('dexpress_shipment_type', $shipment_type);
        update_option('dexpress_payment_by', $payment_by);
        update_option('dexpress_payment_type', $payment_type);
        update_option('dexpress_return_doc', $return_doc);
        update_option('dexpress_default_content', $default_content);
        update_option('dexpress_content_type', $content_type);
        // Validation za COD narudžbine
        if ($payment_by == '0') { // Nalogodavac plaća transport
            $buyout_account = get_option('dexpress_buyout_account', '');
            if (empty($buyout_account)) {
                add_settings_error(
                    'dexpress_settings',
                    'missing_buyout_account_warning',
                    '<strong>UPOZORENJE:</strong> Niste podesili bankovni račun za COD narudžbine! ' .
                        'COD pošiljke neće moći da se kreiraju bez validnog računa. ' .
                        'Dodajte račun u "Basic Settings" tabu.',
                    'warning'
                );
            } else {
                // Proveri format računa
                $account_digits = preg_replace('/[^0-9]/', '', $buyout_account);
                if (strlen($account_digits) < 15 || strlen($account_digits) > 18) {
                    add_settings_error(
                        'dexpress_settings',
                        'invalid_buyout_account_format',
                        '<strong>UPOZORENJE:</strong> Format bankovnog računa možda nije ispravan. ' .
                            'Očekivani format: 170-0010364556000-77 (15-18 cifara)',
                        'warning'
                    );
                }
            }
        }
    }

    /**
     * Čuvanje webhook podešavanja
     */
    private function save_webhook_settings()
    {
        $webhook_secret = isset($_POST['dexpress_webhook_secret']) ? sanitize_text_field($_POST['dexpress_webhook_secret']) : wp_generate_password(32, false);
        $google_maps_api_key = isset($_POST['dexpress_google_maps_api_key']) ? sanitize_text_field($_POST['dexpress_google_maps_api_key']) : '';
        $allowed_webhook_ips = isset($_POST['dexpress_allowed_webhook_ips']) ? sanitize_text_field($_POST['dexpress_allowed_webhook_ips']) : '';

        // Validacija i formatiranje bankovnog računa
        $buyout_account = isset($_POST['dexpress_buyout_account']) ? sanitize_text_field($_POST['dexpress_buyout_account']) : '';
        if (!empty($buyout_account)) {
            $formatted_account = $this->validate_and_format_bank_account($buyout_account);
            if (empty($formatted_account)) {
                add_settings_error(
                    'dexpress_settings',
                    'invalid_buyout_account',
                    __('Broj računa za otkupninu nije u validnom formatu. Mora imati 12-18 cifara.', 'd-express-woo'),
                    'error'
                );
                $this->redirect_with_error();
                return;
            }
            $buyout_account = $formatted_account;
        }

        update_option('dexpress_webhook_secret', $webhook_secret);
        update_option('dexpress_google_maps_api_key', $google_maps_api_key);
        update_option('dexpress_buyout_account', $buyout_account);
        update_option('dexpress_allowed_webhook_ips', $allowed_webhook_ips);
    }

    /**
     * Čuvanje CRON podešavanja
     */
    private function save_cron_settings()
    {
        $enable_auto_updates = isset($_POST['dexpress_enable_auto_updates']) ? 'yes' : 'no';
        $update_time = isset($_POST['dexpress_update_time']) ? sanitize_text_field($_POST['dexpress_update_time']) : '03:00';
        $batch_size = isset($_POST['dexpress_batch_size']) ? intval($_POST['dexpress_batch_size']) : 100;

        update_option('dexpress_enable_auto_updates', $enable_auto_updates);
        update_option('dexpress_update_time', $update_time);
        update_option('dexpress_batch_size', $batch_size);
    }

    /**
     * Čuvanje uninstall podešavanja
     */
    private function save_uninstall_settings()
    {
        $clean_uninstall = isset($_POST['dexpress_clean_uninstall']) ? 'yes' : 'no';
        update_option('dexpress_clean_uninstall', $clean_uninstall);
    }

    /**
     * Validacija i formatiranje bankovnog računa
     */
    private function validate_and_format_bank_account($account_number)
    {
        $account_number = preg_replace('/[^0-9\-]/', '', $account_number);

        if (empty($account_number)) {
            return '';
        }

        $digits_only = str_replace('-', '', $account_number);

        if (strlen($digits_only) < 12 || strlen($digits_only) > 18) {
            return '';
        }

        $bank_code = substr($digits_only, 0, 3);
        $valid_bank_codes = ['115', '160', '180', '205', '250', '265', '275', '310', '325', '340', '355', '370', '380', '385', '170'];

        if (!in_array($bank_code, $valid_bank_codes)) {
            dexpress_log("Upozorenje: Nepoznat kod banke '{$bank_code}' u računu '{$account_number}'", 'warning');
        }

        return substr($digits_only, 0, 3) . '-' .
            substr($digits_only, 3, strlen($digits_only) - 5) . '-' .
            substr($digits_only, -2);
    }

    /**
     * Redirect nakon čuvanja
     */
    private function redirect_after_save()
    {
        $active_tab = isset($_POST['active_tab']) ? sanitize_key($_POST['active_tab']) : 'api';
        $redirect_params = ['settings-updated' => 'true', 'tab' => $active_tab];

        // Dodaj parametre za proširenje opsega
        if (isset($_POST['_range_extended'])) {
            $redirect_params['extended'] = 'success';
            $redirect_params['added'] = $_POST['_added_codes'];
        } elseif (isset($_POST['_range_error'])) {
            $redirect_params['extended'] = 'error';
            $redirect_params['error_message'] = urlencode($_POST['_range_error']);
        }

        wp_redirect(add_query_arg($redirect_params, admin_url('admin.php?page=dexpress-settings')));
        exit;
    }

    /**
     * Redirect sa greškom
     */
    private function redirect_with_error()
    {
        $active_tab = isset($_POST['active_tab']) ? sanitize_key($_POST['active_tab']) : 'api';
        wp_redirect(add_query_arg(['settings-updated' => 'false', 'tab' => $active_tab], admin_url('admin.php?page=dexpress-settings')));
        exit;
    }
}
