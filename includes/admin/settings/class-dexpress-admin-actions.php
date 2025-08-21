<?php

/**
 * D Express Admin Actions Handler
 * 
 * Odgovoran za handling admin akcija kao što su test konekcije, update indexes, itd.
 */

defined('ABSPATH') || exit;

class D_Express_Admin_Actions
{
    /**
     * Konstruktor
     */
    public function __construct()
    {
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('wp_ajax_dexpress_dismiss_cron_optimization', array($this, 'dismiss_cron_optimization'));
        add_action('admin_post_dexpress_test_cron', array($this, 'test_cron'));
        add_action('admin_post_dexpress_reset_cron', array($this, 'reset_cron'));
        add_action('admin_notices', array($this, 'display_buyout_account_notice'));
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
     * CRON optimizacija dismiss
     */
    public function dismiss_cron_optimization()
    {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'dexpress_cron')) {
            wp_die('Invalid nonce');
        }

        if (!current_user_can('manage_options')) {
            wp_die('No permission');
        }

        $action = sanitize_text_field($_POST['dismiss_action'] ?? 'skip');
        update_option('dexpress_cron_setup_dismissed', true);

        dexpress_log("CRON Optimizacija: Korisnik odabrao '{$action}'", 'info');
        wp_send_json_success(['message' => 'CRON optimizacija dismissed', 'action' => $action]);
    }

    /**
     * Ažuriranje šifarnika
     */
    /**
     * Ažuriranje šifarnika
     */
    public function update_indexes()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za pristup ovoj stranici.', 'd-express-woo'));
        }

        try {
            // PROMENI OVO - direktno pozovi API umesto cron manager
            $api = new D_Express_API();
            $result = $api->update_all_indexes();

            dexpress_log('Manual šifarnik ažuriranje: ' . ($result ? 'uspešno' : 'neuspešno'), 'info');
        } catch (Exception $e) {
            dexpress_log('Manual update greška: ' . $e->getMessage(), 'error');
            $result = false;
        }

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
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za pristup ovoj stranici.', 'd-express-woo'));
        }

        $api = D_Express_API::get_instance();
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';

        if (!$api->has_credentials()) {
            wp_redirect(add_query_arg(array(
                'page' => 'dexpress-settings',
                'tab' => $active_tab,
                'connection-test' => 'missing_credentials',
            ), admin_url('admin.php')));
            exit;
        }

        $result = $api->get_statuses();

        if (!is_wp_error($result)) {
            if (get_option('dexpress_enable_logging', 'no') === 'yes') {
                dexpress_log('Test konekcije uspešan. Dobijeno ' . (is_array($result) ? count($result) : 0) . ' statusa.', 'info');
            }

            wp_redirect(add_query_arg(array(
                'page' => 'dexpress-settings',
                'tab' => $active_tab,
                'connection-test' => 'success',
            ), admin_url('admin.php')));
        } else {
            if (get_option('dexpress_enable_logging', 'no') === 'yes') {
                dexpress_log('Test konekcije neuspešan: ' . $result->get_error_message(), 'error');
            }

            wp_redirect(add_query_arg(array(
                'page' => 'dexpress-settings',
                'tab' => $active_tab,
                'connection-test' => 'error',
                'error-message' => urlencode($result->get_error_message()),
            ), admin_url('admin.php')));
        }
        exit;
    }

    /**
     * Test CRON sistema
     */
    public function test_cron()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za pristup ovoj stranici.', 'd-express-woo'));
        }

        dexpress_log('Manual CRON test pokrenuo admin user', 'info');
        D_Express_Cron_Manager::manual_test(); // PROMENI OVO

        wp_redirect(add_query_arg([
            'page' => 'dexpress-settings',
            'tab' => 'cron',
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

        D_Express_Cron_Manager::reset_cron_system(); // PROMENI OVO

        dexpress_log('CRON sistem resetovan od strane admin user-a', 'info');

        wp_redirect(add_query_arg([
            'page' => 'dexpress-settings',
            'tab' => 'cron',
            'cron-reset' => 'success',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Prikaz notice-a za bankovni račun
     */
    public function display_buyout_account_notice()
    {
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'false') {
            $errors = get_settings_errors('dexpress_settings');

            foreach ($errors as $error) {
                if ($error['code'] === 'invalid_buyout_account' || $error['code'] === 'api_buyout_account_error') {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>' . esc_html($error['message']) . '</strong></p>';
                    echo '<p>' . __('Molimo unesite valjan bankovni račun u formatu XXX-XXXXXXXXXX-XX.', 'd-express-woo') . '</p>';
                    echo '</div>';
                }
            }
        }
    }
}
