<?php
/**
 * D Express Uninstaller
 * 
 * Klasa za brisanje podataka pri deinstalaciji
 */

defined('ABSPATH') || exit;

class D_Express_Uninstaller {
    
    /**
     * Brisanje svih podataka plugina
     */
    public static function uninstall() {
        if (get_option('dexpress_clean_uninstall') !== 'yes') {
            return;
        }

        global $wpdb;

        // Lista tabela za brisanje
        $tables = array(
            'dexpress_shipments',
            'dexpress_packages',
            'dexpress_statuses',
            'dexpress_statuses_index',
            'dexpress_municipalities',
            'dexpress_towns',
            'dexpress_streets',
            'dexpress_locations',
            'dexpress_dispensers',
            'dexpress_shops',
            'dexpress_centres'
        );

        // Brisanje tabela
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }

        // Lista opcija za brisanje
        $options = array(
            'dexpress_api_username',
            'dexpress_api_password',
            'dexpress_client_id',
            'dexpress_code_prefix',
            'dexpress_code_range_start',
            'dexpress_code_range_end',
            'dexpress_test_mode',
            'dexpress_enable_logging',
            'dexpress_auto_create_shipment',
            'dexpress_auto_create_on_status',
            'dexpress_webhook_secret',
            'dexpress_clean_uninstall'
        );

        // Brisanje opcija
        foreach ($options as $option) {
            delete_option($option);
        }

        // Čišćenje WP cron-a
        wp_clear_scheduled_hook('dexpress_daily_update_indexes');
    }
}