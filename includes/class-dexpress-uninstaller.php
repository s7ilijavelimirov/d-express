<?php
/**
 * D Express Uninstaller
 */

defined('ABSPATH') || exit;

class D_Express_Uninstaller
{
    private static function delete_directory($dir)
    {
        if (!is_dir($dir)) return false;

        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::delete_directory($path) : unlink($path);
        }
        return rmdir($dir);
    }

    public static function uninstall()
    {
        if (get_option('dexpress_clean_uninstall') !== 'yes') {
            return;
        }

        global $wpdb;

        // Brisanje tabela
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');

        $tables = array(
            'dexpress_packages',
            'dexpress_shipments', 
            'dexpress_statuses',
            'dexpress_statuses_index',
            'dexpress_municipalities',
            'dexpress_towns',
            'dexpress_streets',
            'dexpress_locations',
            'dexpress_dispensers',
            'dexpress_shops',
            'dexpress_centres',
            'dexpress_sender_locations'
        );

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }

        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

        // Briše SVE dexpress_ opcije odjednom
        $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE 'dexpress_%'");

        // Brisanje samo npm/node fajlova (NE git)
        $plugin_dir = WP_PLUGIN_DIR . '/d-express-woocommerce-integration/';
        
        $files_to_delete = array(
            'package-lock.json',
            'package.json', 
            '.DS_Store',
            'Thumbs.db'
        );

        foreach ($files_to_delete as $file) {
            $file_path = $plugin_dir . $file;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        // Brisanje node_modules foldera
        $node_modules = $plugin_dir . 'node_modules';
        if (is_dir($node_modules)) {
            self::delete_directory($node_modules);
        }

        // Čišćenje
        wp_clear_scheduled_hook('dexpress_daily_update_indexes');
        wp_clear_scheduled_hook('dexpress_check_pending_statuses');
        wp_cache_flush();

        error_log('D Express: Clean uninstall completed');
    }
}