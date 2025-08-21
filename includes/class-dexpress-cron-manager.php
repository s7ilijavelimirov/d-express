<?php

/**
 * D Express CRON Manager - Jednostavna verzija
 * SAMO ažuriranje šifarnika jednom dnevno u 03:00
 */

class D_Express_Cron_Manager
{
    /**
     * Inicijalizacija - SAMO osnovni WordPress CRON
     */
    public static function init_cron_jobs()
    {
        // Jedini CRON zadatak - jednom dnevno u 03:00
        if (!wp_next_scheduled('dexpress_daily_update')) {
            wp_schedule_event(
                strtotime('tomorrow 3:00 am'),
                'daily',
                'dexpress_daily_update'
            );
        }
        
        add_action('dexpress_daily_update', [__CLASS__, 'run_daily_updates']);
        
        // Cleanup starih CRON-ova iz prethodnih verzija
        self::cleanup_old_crons();
    }

    /**
     * Glavna funkcija - pametno raspoređivanje prema D Express preporukama
     */
    public static function run_daily_updates()
    {
        // Proveri da li je omogućeno
        if (get_option('dexpress_enable_auto_updates', 'yes') !== 'yes') {
            dexpress_log('CRON: Automatsko ažuriranje je onemogućeno', 'info');
            return;
        }

        // Proveri API kredencijale
        $api = D_Express_API::get_instance();
        if (!$api->has_credentials()) {
            dexpress_log('CRON: Nema API kredencijala', 'warning');
            return;
        }

        $today = date('w'); // 0=nedelja, 1=ponedeljak...
        $day_of_month = date('j'); // 1-31
        $completed_tasks = [];

        dexpress_log('CRON: Pokretanje u ' . current_time('H:i:s') . ' (dan u nedelji: ' . $today . ', dan u mesecu: ' . $day_of_month . ')', 'info');

        // DNEVNO - Paketomati (svaki dan)
        if (self::update_dispensers()) {
            $completed_tasks[] = 'Paketomati';
        }

        // NEDELJNO - Ulice (nedeljom)
        if ($today == 0) { // nedelja
            if (self::update_streets()) {
                $completed_tasks[] = 'Ulice';
            }
        }

        // MESEČNO - Mesta i opštine (1. u mesecu)
        if ($day_of_month == 1) {
            if (self::update_towns()) {
                $completed_tasks[] = 'Gradovi';
            }
            if (self::update_municipalities()) {
                $completed_tasks[] = 'Opštine';
            }
        }

        // Status šifarnik (jednom nedeljno)
        if ($today == 0) {
            if (self::update_status_index()) {
                $completed_tasks[] = 'Statusi';
            }
        }

        // Sačuvaj vreme poslednjeg ažuriranja
        update_option('dexpress_last_update', time());

        $tasks_text = empty($completed_tasks) ? 'Samo PING (nema zadataka za danas)' : implode(', ', $completed_tasks);
        dexpress_log("CRON: Završeno - {$tasks_text}", 'info');
    }

    /**
     * Ažuriranje paketomata
     */
    private static function update_dispensers()
    {
        try {
            dexpress_log('CRON: Ažuriranje paketomata...', 'debug');

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
            $updated_count = 0;

            foreach ($dispensers as $dispenser) {
                $data = [
                    'id' => $dispenser['ID'],
                    'name' => $dispenser['Name'],
                    'address' => $dispenser['Address'] ?? '',
                    'town' => $dispenser['Town'] ?? '',
                    'town_id' => $dispenser['TownID'] ?? 0,
                    'work_hours' => $dispenser['WorkHours'] ?? '',
                    'work_days' => $dispenser['WorkDays'] ?? '',
                    'latitude' => isset($dispenser['Latitude']) ? (float)$dispenser['Latitude'] : null,
                    'longitude' => isset($dispenser['Longitude']) ? (float)$dispenser['Longitude'] : null,
                    'pay_by_cash' => isset($dispenser['PayByCash']) ? (int)$dispenser['PayByCash'] : 0,
                    'pay_by_card' => isset($dispenser['PayByCard']) ? (int)$dispenser['PayByCard'] : 0,
                    'last_updated' => current_time('mysql')
                ];

                $result = $wpdb->replace($table, $data);
                if ($result !== false) {
                    $updated_count++;
                }
            }

            dexpress_log("CRON: Ažurirano {$updated_count} paketomata", 'info');
            update_option('dexpress_last_dispensers_update', time());
            return true;

        } catch (Exception $e) {
            dexpress_log('CRON: Greška pri ažuriranju paketomata: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Ažuriranje gradova (mesečno)
     */
    private static function update_towns()
    {
        try {
            dexpress_log('CRON: Ažuriranje gradova (mesečno)...', 'debug');

            $api = D_Express_API::get_instance();
            $towns = $api->get_towns();

            if (is_wp_error($towns)) {
                dexpress_log('CRON: Greška kod gradova: ' . $towns->get_error_message(), 'error');
                return false;
            }

            if (!is_array($towns) || empty($towns)) {
                dexpress_log('CRON: Nema gradova za ažuriranje', 'warning');
                return false;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'dexpress_towns';
            $updated_count = 0;

            foreach ($towns as $town) {
                $data = [
                    'id' => $town['ID'],
                    'name' => $town['Name'],
                    'display_name' => $town['DisplayName'] ?? $town['Name'],
                    'center_id' => $town['CenterID'] ?? null,
                    'municipality_id' => $town['MunicipalityID'] ?? null,
                    'order_num' => $town['OrderNum'] ?? null,
                    'postal_code' => $town['PostalCode'] ?? null,
                    'delivery_days' => $town['DeliveryDays'] ?? null,
                    'cut_off_pickup_time' => $town['CutOffPickupTime'] ?? null,
                    'last_updated' => current_time('mysql')
                ];

                $result = $wpdb->replace($table, $data);
                if ($result !== false) {
                    $updated_count++;
                }
            }

            dexpress_log("CRON: Ažurirano {$updated_count} gradova", 'info');
            update_option('dexpress_last_towns_update', time());
            return true;

        } catch (Exception $e) {
            dexpress_log('CRON: Greška pri ažuriranju gradova: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Ažuriranje opština (mesečno)
     */
    private static function update_municipalities()
    {
        try {
            dexpress_log('CRON: Ažuriranje opština (mesečno)...', 'debug');

            $api = D_Express_API::get_instance();
            $municipalities = $api->get_municipalities();

            if (is_wp_error($municipalities)) {
                dexpress_log('CRON: Greška kod opština: ' . $municipalities->get_error_message(), 'error');
                return false;
            }

            if (!is_array($municipalities) || empty($municipalities)) {
                dexpress_log('CRON: Nema opština za ažuriranje', 'warning');
                return false;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'dexpress_municipalities';
            $updated_count = 0;

            foreach ($municipalities as $municipality) {
                $data = [
                    'id' => $municipality['ID'],
                    'name' => $municipality['Name'],
                    'ptt_no' => $municipality['PTTNo'] ?? null,
                    'order_num' => $municipality['OrderNum'] ?? null,
                    'last_updated' => current_time('mysql')
                ];

                $result = $wpdb->replace($table, $data);
                if ($result !== false) {
                    $updated_count++;
                }
            }

            dexpress_log("CRON: Ažurirano {$updated_count} opština", 'info');
            update_option('dexpress_last_municipalities_update', time());
            return true;

        } catch (Exception $e) {
            dexpress_log('CRON: Greška pri ažuriranju opština: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Ažuriranje ulica (nedeljno)
     */
    private static function update_streets()
    {
        try {
            dexpress_log('CRON: Ažuriranje ulica (nedeljno)...', 'debug');

            $api = D_Express_API::get_instance();
            $streets = $api->get_streets();

            if (is_wp_error($streets)) {
                dexpress_log('CRON: Greška kod ulica: ' . $streets->get_error_message(), 'error');
                return false;
            }

            if (!is_array($streets) || empty($streets)) {
                dexpress_log('CRON: Nema ulica za ažuriranje', 'warning');
                return false;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'dexpress_streets';
            $updated_count = 0;

            foreach ($streets as $street) {
                $data = [
                    'id' => $street['ID'],
                    'name' => $street['Name'],
                    'TId' => $street['TId'] ?? 0,
                    'deleted' => isset($street['Deleted']) ? (int)$street['Deleted'] : 0,
                    'last_updated' => current_time('mysql')
                ];

                $result = $wpdb->replace($table, $data);
                if ($result !== false) {
                    $updated_count++;
                }
            }

            dexpress_log("CRON: Ažurirano {$updated_count} ulica", 'info');
            update_option('dexpress_last_streets_update', time());
            return true;

        } catch (Exception $e) {
            dexpress_log('CRON: Greška pri ažuriranju ulica: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Ažuriranje status šifarnika
     */
    private static function update_status_index()
    {
        try {
            dexpress_log('CRON: Ažuriranje statusa...', 'debug');

            $api = D_Express_API::get_instance();
            $statuses = $api->get_statuses();

            if (is_wp_error($statuses)) {
                dexpress_log('CRON: Greška kod statusa: ' . $statuses->get_error_message(), 'error');
                return false;
            }

            if (!is_array($statuses) || empty($statuses)) {
                dexpress_log('CRON: Nema statusa za ažuriranje', 'warning');
                return false;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'dexpress_statuses_index';
            $updated_count = 0;

            foreach ($statuses as $status) {
                $data = [
                    'id' => $status['ID'],
                    'name' => $status['Name'],
                    'description' => $status['NameEn'] ?? '',
                    'last_updated' => current_time('mysql')
                ];

                $result = $wpdb->replace($table, $data);
                if ($result !== false) {
                    $updated_count++;
                }
            }

            dexpress_log("CRON: Ažurirano {$updated_count} statusa", 'info');
            update_option('dexpress_last_statuses_update', time());
            return true;

        } catch (Exception $e) {
            dexpress_log('CRON: Greška pri ažuriranju statusa: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Manual pokretanje (za admin)
     */
    public static function manual_test()
    {
        dexpress_log('MANUAL-TEST: Admin pokretanje CRON-a', 'info');
        self::run_daily_updates();
        return true;
    }

    /**
     * Status CRON-a za admin sa detaljnim informacijama
     */
    public static function get_cron_status()
    {
        $next_run = wp_next_scheduled('dexpress_daily_update');
        $last_run = get_option('dexpress_last_update', 0);
        
        // Poslednja ažuriranja po tipovima
        $last_dispensers = get_option('dexpress_last_dispensers_update', 0);
        $last_towns = get_option('dexpress_last_towns_update', 0);
        $last_municipalities = get_option('dexpress_last_municipalities_update', 0);
        $last_streets = get_option('dexpress_last_streets_update', 0);
        $last_statuses = get_option('dexpress_last_statuses_update', 0);

        return [
            'next_run' => $next_run,
            'next_run_formatted' => $next_run ? date('d.m.Y H:i:s', $next_run) : 'N/A',
            'last_run' => $last_run,
            'last_run_formatted' => $last_run ? date('d.m.Y H:i:s', $last_run) : 'Nikad',
            'is_active' => (bool) $next_run,
            'auto_enabled' => get_option('dexpress_enable_auto_updates', 'yes') === 'yes',
            'hours_since_update' => $last_run ? round((time() - $last_run) / HOUR_IN_SECONDS, 1) : null,
            
            // Detaljan status po tipovima
            'detailed_status' => [
                'dispensers' => [
                    'frequency' => 'Dnevno',
                    'last_update' => $last_dispensers,
                    'last_update_formatted' => $last_dispensers ? date('d.m.Y H:i', $last_dispensers) : 'Nikad',
                    'days_since' => $last_dispensers ? floor((time() - $last_dispensers) / DAY_IN_SECONDS) : null
                ],
                'streets' => [
                    'frequency' => 'Nedeljno (nedelja)',
                    'last_update' => $last_streets,
                    'last_update_formatted' => $last_streets ? date('d.m.Y H:i', $last_streets) : 'Nikad',
                    'days_since' => $last_streets ? floor((time() - $last_streets) / DAY_IN_SECONDS) : null
                ],
                'towns' => [
                    'frequency' => 'Mesečno (1. u mesecu)',
                    'last_update' => $last_towns,
                    'last_update_formatted' => $last_towns ? date('d.m.Y H:i', $last_towns) : 'Nikad',
                    'days_since' => $last_towns ? floor((time() - $last_towns) / DAY_IN_SECONDS) : null
                ],
                'municipalities' => [
                    'frequency' => 'Mesečno (1. u mesecu)',
                    'last_update' => $last_municipalities,
                    'last_update_formatted' => $last_municipalities ? date('d.m.Y H:i', $last_municipalities) : 'Nikad',
                    'days_since' => $last_municipalities ? floor((time() - $last_municipalities) / DAY_IN_SECONDS) : null
                ],
                'statuses' => [
                    'frequency' => 'Nedeljno (nedelja)',
                    'last_update' => $last_statuses,
                    'last_update_formatted' => $last_statuses ? date('d.m.Y H:i', $last_statuses) : 'Nikad',
                    'days_since' => $last_statuses ? floor((time() - $last_statuses) / DAY_IN_SECONDS) : null
                ]
            ]
        ];
    }

    /**
     * Reset CRON sistema
     */
    public static function reset_cron_system()
    {
        // Obriši postojeći
        wp_clear_scheduled_hook('dexpress_daily_update');
        
        // Reinicijalizuj
        self::init_cron_jobs();
        
        dexpress_log('CRON: Sistem resetovan', 'info');
        return true;
    }

    /**
     * Brisanje svih CRON zadataka
     */
    public static function clear_all_cron_jobs()
    {
        wp_clear_scheduled_hook('dexpress_daily_update');
        self::cleanup_old_crons();
    }

    /**
     * Cleanup starih CRON-ova iz prethodnih verzija
     */
    private static function cleanup_old_crons()
    {
        // Stari nazivi iz komplikovanije verzije
        wp_clear_scheduled_hook('dexpress_unified_update');
        wp_clear_scheduled_hook('dexpress_backup_trigger_1');
        wp_clear_scheduled_hook('dexpress_backup_trigger_2');
        wp_clear_scheduled_hook('dexpress_backup_trigger_3');
        wp_clear_scheduled_hook('dexpress_self_ping');
        wp_clear_scheduled_hook('dexpress_check_pending_statuses');
        wp_clear_scheduled_hook('dexpress_force_check');
        wp_clear_scheduled_hook('dexpress_hourly_ping');
        wp_clear_scheduled_hook('dexpress_hourly_check');
    }
}