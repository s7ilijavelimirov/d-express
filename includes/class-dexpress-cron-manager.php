<?php

/**
 * POTPUNO AUTONOMAN CRON SISTEM - FORSIRA POKRETANJE U 03:00
 * Verzija 2.0 - Bez zavisnosti od admin login-a
 */

class D_Express_Cron_Manager
{
    private static $cron_secret = null;

    /**
     * GLAVNA inicijalizacija sa POTPUNO autonomnim sistemom
     */
    public static function init_cron_jobs()
    {
        // 1. WordPress CRON (osnovni fallback)
        if (!wp_next_scheduled('dexpress_unified_update')) {
            wp_schedule_event(
                strtotime('tomorrow 3:00 am'),
                'daily',
                'dexpress_unified_update'
            );
        }
        add_action('dexpress_unified_update', [__CLASS__, 'run_daily_updates']);

        // 2. Server CRON endpoint (za cPanel)
        self::setup_server_cron_endpoint();

        // 3. ✅ AUTONOMAN SISTEM (glavna fora - radi bez admin-a)
        self::setup_autonomous_system();

        // 4. BACKUP sistemi (garancija)
        self::setup_backup_systems();

        // 5. Čišćenje starih CRON-ova
        self::cleanup_old_crons();
    }

    /**
     * ✅ AUTONOMAN SISTEM - ne zavisi od admin login-a
     */
    private static function setup_autonomous_system()
    {
        // KLJUČNO: Kada god BILO KO poseti sajt, proveri da li treba CRON
        add_action('wp_loaded', [__CLASS__, 'autonomous_check'], 1);
        
        // Self-ping sistem koji garantuje pokretanje
        add_action('init', [__CLASS__, 'setup_self_ping_system']);
    }

    /**
     * ✅ GLAVNA AUTONOMNA LOGIKA
     */
    public static function autonomous_check()
    {
        // Proveri da li je uključeno
        if (get_option('dexpress_enable_auto_updates', 'yes') !== 'yes') {
            return;
        }

        $last_update = get_option('dexpress_last_unified_update', 0);
        $last_check = get_option('dexpress_last_auto_check', 0);
        $current_time = time();
        $current_hour = (int) current_time('H');

        // Proveri maksimalno jednom po satu da ne spamuje
        if (($current_time - $last_check) < HOUR_IN_SECONDS) {
            return;
        }

        update_option('dexpress_last_auto_check', $current_time);
        $hours_since_update = ($current_time - $last_update) / HOUR_IN_SECONDS;

        // ✅ AUTONOMNA LOGIKA - kada pokrenuti:
        $should_run = false;
        $reason = '';

        // 1. IDEALNO VREME: 3-9 ujutru + prošlo 20+ sati
        if ($current_hour >= 3 && $current_hour <= 9 && $hours_since_update >= 20) {
            $should_run = true;
            $reason = "autonomno - jutarnji termin";
        }
        // 2. KRITIČNO: Prošlo 25+ sati - MORA se pokrenuti bilo kad
        elseif ($hours_since_update >= 25) {
            $should_run = true;
            $reason = "autonomno - kritično kašnjenje {$hours_since_update}h";
        }

        if ($should_run) {
            dexpress_log("🚀 AUTONOMNO: Pokretanje - {$reason} u {$current_hour}h", 'info');
            self::execute_autonomous_cron();
        }
    }

    /**
     * ✅ AUTONOMNO pokretanje CRON-a
     */
    private static function execute_autonomous_cron()
    {
        // DIREKTNO pokretanje - bez HTTP poziva koji može da ne prođe
        dexpress_log('AUTONOMNO: Direktno pokretanje CRON-a', 'info');
        self::run_daily_updates();
        
        update_option('dexpress_last_autonomous_trigger', time());
    }

    /**
     * ✅ SELF-PING SISTEM - garantuje da će se pokrenuti
     */
    public static function setup_self_ping_system()
    {
        // Zakaži self-ping-ove u jutarnjim satima
        $current_hour = (int) current_time('H');
        
        // Ako je između 2-10 ujutru, zakaži ping za sledeći sat
        if ($current_hour >= 2 && $current_hour <= 10) {
            if (!wp_next_scheduled('dexpress_self_ping')) {
                wp_schedule_single_event(
                    time() + HOUR_IN_SECONDS,
                    'dexpress_self_ping'
                );
            }
        }
        
        add_action('dexpress_self_ping', [__CLASS__, 'execute_self_ping']);
    }

    /**
     * ✅ IZVRŠAVA self ping
     */
    public static function execute_self_ping()
    {
        dexpress_log('SELF-PING: Autonomna provera u ' . current_time('H:i'), 'debug');
        
        // Pozovi autonomnu proveru
        self::autonomous_check();
        
        // Zakaži sledeći ping za sat vremena (samo u jutarnjim satima)
        $current_hour = (int) current_time('H');
        if ($current_hour >= 2 && $current_hour <= 10) {
            wp_schedule_single_event(
                time() + HOUR_IN_SECONDS,
                'dexpress_self_ping'
            );
        }
    }

    /**
     * ✅ BACKUP SISTEMI - ako sve ostalo ne radi
     */
    private static function setup_backup_systems()
    {
        // Backup 1: 3:05 AM
        if (!wp_next_scheduled('dexpress_backup_trigger_1')) {
            wp_schedule_event(
                strtotime('tomorrow 3:05 AM'),
                'daily',
                'dexpress_backup_trigger_1'
            );
        }
        add_action('dexpress_backup_trigger_1', [__CLASS__, 'backup_trigger_1']);

        // Backup 2: 3:30 AM  
        if (!wp_next_scheduled('dexpress_backup_trigger_2')) {
            wp_schedule_event(
                strtotime('tomorrow 3:30 AM'),
                'daily',
                'dexpress_backup_trigger_2'
            );
        }
        add_action('dexpress_backup_trigger_2', [__CLASS__, 'backup_trigger_2']);

        // Backup 3: 4:00 AM (konačni)
        if (!wp_next_scheduled('dexpress_backup_trigger_3')) {
            wp_schedule_event(
                strtotime('tomorrow 4:00 AM'),
                'daily',
                'dexpress_backup_trigger_3'
            );
        }
        add_action('dexpress_backup_trigger_3', [__CLASS__, 'backup_trigger_3']);
    }

    /**
     * Backup trigger 1 - 3:05
     */
    public static function backup_trigger_1()
    {
        $last_update = get_option('dexpress_last_unified_update', 0);
        $hours_since = (time() - $last_update) / HOUR_IN_SECONDS;
        
        if ($hours_since >= 2) {
            dexpress_log('BACKUP-1: Pokretanje jer prošlo ' . round($hours_since, 1) . 'h', 'info');
            self::run_daily_updates();
        }
    }

    /**
     * Backup trigger 2 - 3:30  
     */
    public static function backup_trigger_2()
    {
        $last_update = get_option('dexpress_last_unified_update', 0);
        $hours_since = (time() - $last_update) / HOUR_IN_SECONDS;
        
        if ($hours_since >= 3) {
            dexpress_log('BACKUP-2: Kritično pokretanje jer prošlo ' . round($hours_since, 1) . 'h', 'warning');
            self::run_daily_updates();
        }
    }

    /**
     * Backup trigger 3 - 4:00 (MORA da radi)
     */
    public static function backup_trigger_3()
    {
        $last_update = get_option('dexpress_last_unified_update', 0);
        $hours_since = (time() - $last_update) / HOUR_IN_SECONDS;
        
        if ($hours_since >= 4) {
            dexpress_log('BACKUP-3: FINALNI backup - FORSIRANJE pokretanja', 'error');
            self::run_daily_updates();
        }
    }

    /**
     * Server CRON endpoint setup
     */
    private static function setup_server_cron_endpoint()
    {
        add_action('init', [__CLASS__, 'register_cron_endpoint']);
        add_action('template_redirect', [__CLASS__, 'handle_cron_request']);
    }

    public static function register_cron_endpoint()
    {
        add_rewrite_rule(
            '^dexpress-cron/?$',
            'index.php?dexpress_cron=1',
            'top'
        );

        add_filter('query_vars', function ($vars) {
            $vars[] = 'dexpress_cron';
            return $vars;
        });
    }

    public static function handle_cron_request()
    {
        if (!get_query_var('dexpress_cron')) return;

        // Checkbox check
        if (get_option('dexpress_enable_auto_updates', 'yes') !== 'yes') {
            status_header(200);
            echo json_encode([
                'status' => 'disabled',
                'message' => 'Automatsko ažuriranje onemogućeno checkbox-om'
            ]);
            exit;
        }

        // Security check
        $secret = self::get_cron_secret();
        $provided_key = $_GET['key'] ?? '';
        $is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);

        if (!$is_local && $provided_key !== $secret) {
            status_header(403);
            die('Forbidden - Invalid key');
        }

        // Pokreni CRON
        dexpress_log('SERVER-CRON: Pokrenuto u ' . current_time('H:i:s'), 'info');
        self::run_daily_updates();

        status_header(200);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'time' => current_time('mysql'),
            'method' => 'server_endpoint'
        ]);
        exit;
    }

    /**
     * ✅ GLAVNI CRON TASK
     */
    public static function run_daily_updates()
    {
        // Checkbox check
        if (get_option('dexpress_enable_auto_updates', 'yes') !== 'yes') {
            dexpress_log('CRON: Onemogućeno checkbox-om', 'info');
            return;
        }

        // API check
        $api = D_Express_API::get_instance();
        if (!$api->has_credentials()) {
            dexpress_log('CRON: Nema API kredencijala', 'warning');
            return;
        }

        $today = date('w'); // 0=nedelja
        $day_of_month = date('j'); // 1-31
        $today_name = ['Nedelja', 'Ponedeljak', 'Utorak', 'Sreda', 'Četvrtak', 'Petak', 'Subota'][$today];

        dexpress_log("CRON: 🚀 POKRETANJE - {$today_name}, {$day_of_month}. u mesecu u " . current_time('H:i:s'), 'info');

        $completed_tasks = [];

        // Update paketomata
        if (self::update_dispensers_safely()) {
            $completed_tasks[] = 'Paketomati ✅';
        }

        // Update ostali šifarnici (dodaj po potrebi)
        // if (self::update_towns_safely()) {
        //     $completed_tasks[] = 'Gradovi ✅';
        // }

        update_option('dexpress_last_unified_update', time());

        $tasks_text = empty($completed_tasks) ? 'Nema zadataka za danas' : implode(', ', $completed_tasks);
        dexpress_log("CRON: 🎯 ZAVRŠENO u " . current_time('H:i:s') . " - {$tasks_text}", 'info');
    }

    /**
     * Ažuriranje paketomata
     */
    private static function update_dispensers_safely()
    {
        try {
            dexpress_log('CRON: 📦 Ažuriranje paketomata...', 'info');

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
            $batch_size = get_option('dexpress_batch_size', 500);
            $chunks = array_chunk($dispensers, $batch_size);
            $total_updated = 0;

            foreach ($chunks as $chunk) {
                foreach ($chunk as $dispenser) {
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
                        'is_active' => isset($dispenser['IsActive']) ? (int)$dispenser['IsActive'] : 1,
                        'updated_at' => current_time('mysql')
                    ];

                    $result = $wpdb->replace($table, $data);
                    if ($result !== false) {
                        $total_updated++;
                    }
                }
            }

            dexpress_log("CRON: Ažurirano {$total_updated} paketomata", 'info');
            update_option('dexpress_last_dispensers_update', time());
            return true;

        } catch (Exception $e) {
            dexpress_log('CRON: Greška pri ažuriranju paketomata: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get CRON secret
     */
    private static function get_cron_secret()
    {
        if (self::$cron_secret) return self::$cron_secret;

        $secret = get_option('dexpress_cron_secret');
        if (!$secret) {
            $secret = wp_generate_password(32, false);
            update_option('dexpress_cron_secret', $secret);
        }

        self::$cron_secret = $secret;
        return $secret;
    }

    /**
     * Get CRON status za admin
     */
    public static function get_cron_status()
    {
        $next_run = wp_next_scheduled('dexpress_unified_update');
        $last_run = get_option('dexpress_last_unified_update', 0);
        $last_auto_check = get_option('dexpress_last_auto_check', 0);
        $last_autonomous = get_option('dexpress_last_autonomous_trigger', 0);
        $secret = self::get_cron_secret();

        return [
            'next_run' => $next_run,
            'next_run_formatted' => $next_run ? date('d.m.Y H:i:s', $next_run) : 'N/A',
            'last_run' => $last_run,
            'last_run_formatted' => $last_run ? date('d.m.Y H:i:s', $last_run) : 'Nikad',
            'is_active' => (bool) $next_run,
            'auto_enabled' => get_option('dexpress_enable_auto_updates', 'yes') === 'yes',
            'autonomous_enabled' => true,
            'hours_since_update' => $last_run ? round((time() - $last_run) / HOUR_IN_SECONDS, 1) : null,
            'last_auto_check' => $last_auto_check,
            'last_autonomous_trigger' => $last_autonomous,
            'server_cron_url' => home_url('/dexpress-cron/?key=' . $secret),
            'method' => 'autonomous_system',
            'backup_systems' => [
                'backup_1' => wp_next_scheduled('dexpress_backup_trigger_1'),
                'backup_2' => wp_next_scheduled('dexpress_backup_trigger_2'),
                'backup_3' => wp_next_scheduled('dexpress_backup_trigger_3')
            ]
        ];
    }

    /**
     * Info za optimizaciju (za admin)
     */
    public static function get_cron_optimization_info()
    {
        $secret = self::get_cron_secret();
        $site_url = home_url();

        return [
            'secret' => $secret,
            'site_url' => $site_url,
            'server_command' => "/usr/bin/curl -s '{$site_url}/dexpress-cron/?key={$secret}' > /dev/null 2>&1",
            'cron_time' => '0 3 * * *',
            'external_url' => $site_url . '/dexpress-cron/?key=' . $secret,
            'status' => 'autonomous_ready'
        ];
    }

    /**
     * Clear svi CRON job-ovi
     */
    public static function clear_all_cron_jobs()
    {
        // Glavne
        wp_clear_scheduled_hook('dexpress_unified_update');
        
        // Backup sistemi
        wp_clear_scheduled_hook('dexpress_backup_trigger_1');
        wp_clear_scheduled_hook('dexpress_backup_trigger_2');
        wp_clear_scheduled_hook('dexpress_backup_trigger_3');
        
        // Self-ping
        wp_clear_scheduled_hook('dexpress_self_ping');
    }

    /**
     * Cleanup starih CRON-ova
     */
    private static function cleanup_old_crons()
    {
        // Ukloni stare hook-ove iz prethodnih verzija
        wp_clear_scheduled_hook('dexpress_force_check');
        wp_clear_scheduled_hook('dexpress_hourly_ping');
        wp_clear_scheduled_hook('dexpress_hourly_check');
    }

    /**
     * Manual CRON test (za admin)
     */
    public static function manual_test()
    {
        dexpress_log('MANUAL-TEST: Admin pokretanje CRON-a', 'info');
        self::run_daily_updates();
    }

    /**
     * Reset CRON sistema
     */
    public static function reset_cron_system()
    {
        // Obriši sve postojeće
        self::clear_all_cron_jobs();
        
        // Reinicijalizuj
        self::init_cron_jobs();
        
        dexpress_log('CRON-RESET: Sistem resetovan i reinicijalizovan', 'info');
    }
}