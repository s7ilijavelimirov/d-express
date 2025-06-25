<?php

/**
 * ČISTO CRON REŠENJE - class-dexpress-cron-manager.php
 * Zamenjuje sve postojeće CRON funkcionalnosti
 */

class D_Express_Cron_Manager
{
    /**
     * Inicijalizacija JEDNOG CRON-a koji radi sve
     */
    public static function init_cron_jobs()
    {
        // SAMO JEDAN CRON koji radi sve po rasporedu
        if (!wp_next_scheduled('dexpress_unified_update')) {
            wp_schedule_event(
                strtotime('tomorrow 3:00 am'),
                'daily',
                'dexpress_unified_update'
            );
        }

        // Registracija hook-a
        add_action('dexpress_unified_update', [__CLASS__, 'run_daily_updates']);

        // Čišćenje starih CRON-ova ako postoje
        self::cleanup_old_crons();
    }

    /**
     * Glavni dnevni update - pametno raspoređen
     */
    public static function run_daily_updates()
    {
        // Proveri da li su API kredencijali podešeni
        $api = D_Express_API::get_instance();
        if (!$api->has_credentials()) {
            dexpress_log('CRON: API kredencijali nisu podešeni', 'warning');
            return;
        }

        dexpress_log('CRON: Započeto dnevno ažuriranje', 'info');

        $today = date('w'); // 0=nedеlja, 1=ponedеljak, ...
        $day_of_month = date('j');

        // SVAKI DAN: Paketi (najvažnije)
        self::update_dispensers_safely();

        // NEDELJOM: Ulice (jednom nedeljno)
        if ($today == 0) {
            self::update_streets_safely();
        }

        // 1. U MESECU: Mesta i opštine (jednom mesečno)  
        if ($day_of_month == 1) {
            self::update_locations_safely();
        }

        // SVAKI DAN: Osnovni šifarnici (statusi, centri)
        self::update_basic_indexes();

        update_option('dexpress_last_unified_update', time());
        dexpress_log('CRON: Završeno dnevno ažuriranje', 'info');
    }

    /**
     * Sigurno ažuriranje paketomata
     */
    private static function update_dispensers_safely()
    {
        try {
            dexpress_log('CRON: Ažuriranje paketomata...', 'info');

            $api = D_Express_API::get_instance();
            $dispensers = $api->get_dispensers();

            if (is_wp_error($dispensers)) {
                dexpress_log('CRON: Greška kod paketomata: ' . $dispensers->get_error_message(), 'error');
                return false;
            }

            if (!is_array($dispensers) || empty($dispensers)) {
                dexpress_log('CRON: Nema paketomata za ažuriranje', 'warning');
                return false;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'dexpress_dispensers';

            // Batch insert/update
            $batch_size = get_option('dexpress_batch_size', 100);
            $chunks = array_chunk($dispensers, $batch_size);
            $total_updated = 0;

            foreach ($chunks as $chunk) {
                foreach ($chunk as $dispenser) {
                    $result = $wpdb->replace($table, [
                        'id' => $dispenser['ID'],
                        'name' => $dispenser['Name'],
                        'address' => $dispenser['Address'] ?? '',
                        'town_id' => $dispenser['TownID'] ?? 0,
                        'latitude' => $dispenser['Latitude'] ?? 0,
                        'longitude' => $dispenser['Longitude'] ?? 0,
                        'active' => $dispenser['Active'] ?? 1,
                        'last_updated' => current_time('mysql')
                    ]);

                    if ($result) $total_updated++;
                }

                // Kratka pauza između batch-eva
                usleep(100000); // 0.1s
            }

            update_option('dexpress_last_dispensers_update', time());
            dexpress_log("CRON: Ažurirano $total_updated paketomata", 'info');
            return true;
        } catch (Exception $e) {
            dexpress_log('CRON: Exception kod paketomata: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Sigurno ažuriranje ulica  
     */
    private static function update_streets_safely()
    {
        try {
            dexpress_log('CRON: Nedeljno ažuriranje ulica...', 'info');

            $api = D_Express_API::get_instance();
            $streets = $api->get_streets();

            if (is_wp_error($streets) || !is_array($streets)) {
                dexpress_log('CRON: Greška kod ulica', 'error');
                return false;
            }

            $api->update_streets_index($streets);
            update_option('dexpress_last_streets_update', time());
            dexpress_log('CRON: Ulice ažurirane', 'info');
            return true;
        } catch (Exception $e) {
            dexpress_log('CRON: Exception kod ulica: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Sigurno ažuriranje lokacija
     */
    private static function update_locations_safely()
    {
        try {
            dexpress_log('CRON: Mesečno ažuriranje lokacija...', 'info');

            $api = D_Express_API::get_instance();

            // Opštine
            $municipalities = $api->get_municipalities();
            if (!is_wp_error($municipalities) && is_array($municipalities)) {
                $api->update_municipalities_index($municipalities);
            }

            // Gradovi
            $towns = $api->get_towns();
            if (!is_wp_error($towns) && is_array($towns)) {
                $api->update_towns_index($towns);
            }

            update_option('dexpress_last_locations_update', time());
            dexpress_log('CRON: Lokacije ažurirane', 'info');
            return true;
        } catch (Exception $e) {
            dexpress_log('CRON: Exception kod lokacija: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Ažuriranje osnovnih šifarnika
     */
    private static function update_basic_indexes()
    {
        try {
            $api = D_Express_API::get_instance();

            // Statusi
            $statuses = $api->get_statuses();
            if (!is_wp_error($statuses) && is_array($statuses)) {
                $api->update_statuses_index($statuses);
            }

            // Centri
            $centres = $api->get_centres();
            if (!is_wp_error($centres) && is_array($centres)) {
                $api->update_centres_index($centres);
            }

            dexpress_log('CRON: Osnovni šifarnici ažurirani', 'info');
        } catch (Exception $e) {
            dexpress_log('CRON: Exception kod osnovnih šifarnika: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Čišćenje starih CRON zadataka
     */
    private static function cleanup_old_crons()
    {
        // Uklanjamo sve stare CRON-ove
        wp_clear_scheduled_hook('dexpress_daily_update_indexes');
        wp_clear_scheduled_hook('dexpress_daily_update_dispensers');
        wp_clear_scheduled_hook('dexpress_weekly_update_streets');
        wp_clear_scheduled_hook('dexpress_monthly_update_locations');
        wp_clear_scheduled_hook('dexpress_check_pending_statuses');
        wp_clear_scheduled_hook('dexpress_check_active_shipments');
    }

    /**
     * Potpuno čišćenje svih CRON-ova
     */
    public static function clear_all_cron_jobs()
    {
        wp_clear_scheduled_hook('dexpress_unified_update');
        self::cleanup_old_crons();
        dexpress_log('CRON: Svi CRON zadaci obrisani', 'info');
    }

    /**
     * Manuelno pokretanje svih ažuriranja (za "Ažuriraj šifarnike" dugme)
     */
    public static function manual_update_all()
    {
        dexpress_log('Manuelno pokretanje svih ažuriranja', 'info');

        $api = D_Express_API::get_instance();
        if (!$api->has_credentials()) {
            return new WP_Error('no_credentials', 'API kredencijali nisu podešeni');
        }

        // Pokreni kompletan update
        $result = $api->update_all_indexes();

        if ($result) {
            update_option('dexpress_last_manual_update', time());
            dexpress_log('Manuelno ažuriranje uspešno završeno', 'info');
            return true;
        } else {
            dexpress_log('Greška pri manuelnom ažuriranju', 'error');
            return new WP_Error('update_failed', 'Greška pri ažuriranju');
        }
    }

    /**
     * Inicijalno učitavanje svih šifarnika (prilikom aktivacije)
     */
    public static function initial_load_all()
    {
        dexpress_log('Inicijalno učitavanje svih šifarnika', 'info');

        $api = D_Express_API::get_instance();
        if (!$api->has_credentials()) {
            dexpress_log('Nema API kredencijala za inicijalno učitavanje', 'warning');
            return false;
        }

        // Forcibno učitaj sve šifarnike
        $result = $api->update_all_indexes();

        if ($result) {
            update_option('dexpress_initial_load_done', time());
            dexpress_log('Inicijalno učitavanje završeno uspešno', 'info');
            return true;
        } else {
            dexpress_log('Greška pri inicijalnom učitavanju', 'error');
            return false;
        }
    }

    /**
     * Status CRON sistema za admin
     */
    public static function get_cron_status()
    {
        $next_run = wp_next_scheduled('dexpress_unified_update');
        $last_run = get_option('dexpress_last_unified_update', 0);

        return [
            'next_run' => $next_run,
            'next_run_formatted' => $next_run ? date('d.m.Y H:i:s', $next_run) : 'N/A',
            'last_run' => $last_run,
            'last_run_formatted' => $last_run ? date('d.m.Y H:i:s', $last_run) : 'Nikad',
            'is_active' => (bool) $next_run,
            'last_dispensers' => get_option('dexpress_last_dispensers_update', 0),
            'last_streets' => get_option('dexpress_last_streets_update', 0),
            'last_locations' => get_option('dexpress_last_locations_update', 0)
        ];
    }
}
