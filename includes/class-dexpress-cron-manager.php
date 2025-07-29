<?php

/**
 * PRAVI AUTOMATSKI CRON SISTEM - FORSIRA POKRETANJE U 03:00
 */

class D_Express_Cron_Manager
{
    private static $cron_secret = null;

    /**
     * GLAVNA inicijalizacija sa PRAVIM automatskim sistemom
     */
    public static function init_cron_jobs()
    {
        // 1. WordPress CRON (fallback)
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

        // 3. ✅ PRAVI AUTO-TRIGGER SISTEM (FORSIRA POKRETANJE!)
        self::setup_force_trigger_system();

        // 4. Čišćenje starih CRON-ova
        self::cleanup_old_crons();
    }

    /**
     * ✅ SETUP za FORSIRANJE pokretanja
     */
    private static function setup_force_trigger_system()
    {
        // KLJUČNO: Proverava SVAKI SAT da li treba pokretanje
        add_action('wp_loaded', [__CLASS__, 'force_check_and_trigger'], 1);

        // BACKUP: Heartbeat sistem (kad admin uđe)
        add_filter('heartbeat_received', [__CLASS__, 'heartbeat_force_trigger'], 10, 2);

        // ULTRA BACKUP: Self-ping sistem (osigurava wp_loaded)
        add_action('wp_loaded', [__CLASS__, 'ensure_hourly_ping'], 1);
    }

    /**
     * ✅ GLAVNA LOGIKA - FORSIRA POKRETANJE
     */
    public static function force_check_and_trigger()
    {
        // Proveri da li je omogućeno
        if (get_option('dexpress_enable_auto_updates', 'yes') !== 'yes') {
            return;
        }

        $last_update = get_option('dexpress_last_unified_update', 0);
        $last_check = get_option('dexpress_last_force_check', 0);
        $current_time = time();
        $current_hour = (int) current_time('H');

        // Proveri maksimalno jednom po satu
        if (($current_time - $last_check) < HOUR_IN_SECONDS) {
            return;
        }

        update_option('dexpress_last_force_check', $current_time);

        // Izračunaj vreme od poslednjeg ažuriranja
        $hours_since_update = ($current_time - $last_update) / HOUR_IN_SECONDS;

        // ✅ FORSIRANJE LOGIKA:
        $should_force = false;
        $reason = '';

        // 1. IDEALNO: Između 3-9 ujutru i prošlo 20+ sati
        if ($current_hour >= 3 && $current_hour <= 9 && $hours_since_update >= 20) {
            $should_force = true;
            $reason = "jutarnji termin (idealno vreme)";
        }
        // 2. FORSIRANJE: Prošlo je 24+ sati - MORA da se pokrene!
        elseif ($hours_since_update >= 24) {
            $should_force = true;
            $reason = "forsiranje - prošlo {$hours_since_update}h";
        }

        if ($should_force) {
            dexpress_log("🚀 FORCE-TRIGGER: Pokretanje - {$reason} u {$current_hour}h", 'info');
            self::execute_forced_cron();
        }
    }

    /**
     * ✅ IZVRŠAVA FORSIRANO pokretanje
     */
    private static function execute_forced_cron()
    {
        $secret = self::get_cron_secret();
        $cron_url = home_url('/dexpress-cron/?key=' . $secret);

        // POKUŠAJ 1: HTTP poziv ka sebi (non-blocking)
        $response = wp_remote_post($cron_url, [
            'timeout' => 5,
            'blocking' => false, // Ne čeka odgovor
            'sslverify' => false,
            'user-agent' => 'DexpressForceTrigger/1.0',
            'headers' => [
                'X-Forwarded-For' => '127.0.0.1'
            ]
        ]);

        if (is_wp_error($response)) {
            dexpress_log('FORCE-TRIGGER: HTTP poziv neuspešan - ' . $response->get_error_message(), 'warning');

            // POKUŠAJ 2: Direktno pokretanje u PHP-u
            dexpress_log('FORCE-TRIGGER: Pokretanje direktno u PHP', 'info');
            self::run_daily_updates();
        } else {
            dexpress_log('FORCE-TRIGGER: HTTP poziv uspešan ka ' . $cron_url, 'info');
        }

        update_option('dexpress_last_force_trigger', time());
    }

    /**
     * ✅ HEARTBEAT backup (kad admin uđe na sajt)
     */
    public static function heartbeat_force_trigger($response, $data)
    {
        if (!isset($data['dexpress_cron_check'])) {
            return $response;
        }

        $last_update = get_option('dexpress_last_unified_update', 0);
        $hours_since = (time() - $last_update) / HOUR_IN_SECONDS;

        // Ako admin vidi da CRON kasni 25+ sati, FORSIRAJ!
        if ($hours_since >= 25 && get_option('dexpress_enable_auto_updates', 'yes') === 'yes') {
            dexpress_log('HEARTBEAT-FORCE: Admin pokretanje zbog ekstremnog kašnjenja', 'warning');
            self::execute_forced_cron();
        }

        $response['dexpress_cron_status'] = [
            'last_update' => $last_update,
            'hours_since' => round($hours_since, 1),
            'auto_enabled' => get_option('dexpress_enable_auto_updates', 'yes'),
            'force_enabled' => true
        ];

        return $response;
    }

    /**
     * ✅ SELF-PING sistem (osigurava da wp_loaded radi)
     */
    public static function ensure_hourly_ping()
    {
        $last_ping = get_option('dexpress_last_hourly_ping', 0);

        // Svaki sat pošalji sebi ping da osigura wp_loaded
        if ((time() - $last_ping) > HOUR_IN_SECONDS) {

            // Zakaži background ping
            wp_schedule_single_event(time() + 300, 'dexpress_hourly_ping'); // 5min delay
            add_action('dexpress_hourly_ping', [__CLASS__, 'execute_hourly_ping']);

            update_option('dexpress_last_hourly_ping', time());
        }
    }

    /**
     * ✅ IZVRŠAVA hourly ping
     */
    public static function execute_hourly_ping()
    {
        // Tihi ping ka home page-u da pokrene wp_loaded
        $response = wp_remote_get(home_url('/?dexpress_ping=' . time()), [
            'timeout' => 10,
            'blocking' => false,
            'sslverify' => false,
            'user-agent' => 'DexpressHourlyPing/1.0'
        ]);

        dexpress_log('HOURLY-PING: Poslat ping ka ' . home_url(), 'debug');
    }

    /**
     * Server CRON endpoint
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

        // Proveri checkbox
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
        dexpress_log('ENDPOINT CRON: Pokrenuto u ' . current_time('H:i:s'), 'info');
        self::run_daily_updates();

        status_header(200);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'time' => current_time('mysql'),
            'method' => 'endpoint_trigger'
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

        // 📦 PAKETOMATI - SVAKI DAN u 03:00
        if (self::update_dispensers_safely()) {
            $completed_tasks[] = 'Paketomati ✅';
        }

        // 🛣️ ULICE - NEDELJOM u 03:00  
        if ($today == 0) {
            if (self::update_streets_safely()) {
                $completed_tasks[] = 'Ulice ✅ (nedeljno)';
            }
        }

        // 🏙️ MESTA/OPŠTINE - 1. U MESECU u 03:00
        if ($day_of_month == 1) {
            if (self::update_locations_safely()) {
                $completed_tasks[] = 'Mesta/opštine ✅ (mesečno)';
            }
        }

        // 📊 STATUSI - NEDELJOM u 03:00 (ne menjaju se često)
        if ($today == 0) {
            self::update_basic_indexes();
            $completed_tasks[] = 'Statusi ✅ (nedeljno)';
        }

        update_option('dexpress_last_unified_update', time());

        // FINALNI LOG
        $tasks_text = empty($completed_tasks) ? 'Nema zadataka za danas' : implode(', ', $completed_tasks);
        dexpress_log("CRON: 🎯 ZAVRŠENO u " . current_time('H:i:s') . " - {$tasks_text}", 'info');
    }

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
     * Admin notice (opcionalno)
     */
    public static function show_cron_setup_instructions()
    {
        // Onemogući notice - sve radi automatski
        return;
    }

    /**
     * Info za optimizaciju (za admin tab)
     */
    public static function get_cron_optimization_info()
    {
        if (get_option('dexpress_cron_setup_dismissed', false)) {
            return null;
        }

        $secret = self::get_cron_secret();
        $site_url = home_url();

        return [
            'secret' => $secret,
            'site_url' => $site_url,
            'command' => "/usr/bin/curl -s '{$site_url}/dexpress-cron/?key={$secret}' > /dev/null 2>&1",
            'cron_time' => '0 3 * * *'
        ];
    }

    public static function dismiss_cron_notice()
    {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'dexpress_cron')) wp_die('Invalid nonce');
        if (!current_user_can('manage_options')) wp_die('No permission');

        update_option('dexpress_cron_setup_dismissed', true);
        wp_send_json_success(['message' => 'CRON optimizacija dismissed']);
    }

    public static function get_cron_status()
    {
        $next_run = wp_next_scheduled('dexpress_unified_update');
        $last_run = get_option('dexpress_last_unified_update', 0);
        $last_force_check = get_option('dexpress_last_force_check', 0);
        $last_force_trigger = get_option('dexpress_last_force_trigger', 0);
        $secret = self::get_cron_secret();

        return [
            'next_run' => $next_run,
            'next_run_formatted' => $next_run ? date('d.m.Y H:i:s', $next_run) : 'N/A',
            'last_run' => $last_run,
            'last_run_formatted' => $last_run ? date('d.m.Y H:i:s', $last_run) : 'Nikad',
            'is_active' => (bool) $next_run,
            'auto_enabled' => get_option('dexpress_enable_auto_updates', 'yes') === 'yes',
            'force_enabled' => true,
            'hours_since_update' => $last_run ? round((time() - $last_run) / HOUR_IN_SECONDS, 1) : null,
            'last_force_check' => $last_force_check,
            'last_force_trigger' => $last_force_trigger,
            'server_cron_url' => home_url('/dexpress-cron/?key=' . $secret),
            'method' => 'force_auto_cron',
            'last_dispensers' => get_option('dexpress_last_dispensers_update', 0),
            'last_streets' => get_option('dexpress_last_streets_update', 0),
            'last_locations' => get_option('dexpress_last_locations_update', 0)
        ];
    }

    // ========== POSTOJEĆE UPDATE METODE ==========

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
                        'last_updated' => current_time('mysql')
                    ];

                    $format = ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%f', '%f', '%d', '%d', '%s'];

                    if ($wpdb->replace($table, $data, $format)) {
                        $total_updated++;
                    }
                }
                usleep(100000); // 0.1s pauza
            }

            update_option('dexpress_last_dispensers_update', time());
            dexpress_log("CRON: Ažurirano $total_updated paketomata", 'info');
            return true;
        } catch (Exception $e) {
            dexpress_log('CRON: Exception kod paketomata: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    private static function update_streets_safely()
    {
        try {
            dexpress_log('CRON: 🛣️ Nedeljno ažuriranje ulica...', 'info');

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

    private static function update_locations_safely()
    {
        try {
            dexpress_log('CRON: 🏙️ Mesečno ažuriranje lokacija...', 'info');

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

    private static function update_basic_indexes()
    {
        try {
            dexpress_log('CRON: 📊 Ažuriranje statusa (nedeljno)...', 'info');

            $api = D_Express_API::get_instance();

            // SAMO STATUSI - nedeljom (ne menjaju se često)
            $statuses = $api->get_statuses();
            if (!is_wp_error($statuses) && is_array($statuses)) {
                $api->update_statuses_index($statuses);
                dexpress_log('CRON: Statusi ažurirani', 'info');
            }

            // CENTRI UKLONJENI - nisu potrebni

        } catch (Exception $e) {
            dexpress_log('CRON: Exception kod statusa: ' . $e->getMessage(), 'error');
        }
    }

    private static function cleanup_old_crons()
    {
        wp_clear_scheduled_hook('dexpress_daily_update_indexes');
        wp_clear_scheduled_hook('dexpress_daily_update_dispensers');
        wp_clear_scheduled_hook('dexpress_weekly_update_streets');
        wp_clear_scheduled_hook('dexpress_monthly_update_locations');
        wp_clear_scheduled_hook('dexpress_check_pending_statuses');
        wp_clear_scheduled_hook('dexpress_check_active_shipments');
    }

    public static function clear_all_cron_jobs()
    {
        wp_clear_scheduled_hook('dexpress_unified_update');
        wp_clear_scheduled_hook('dexpress_hourly_ping');
        self::cleanup_old_crons();
        dexpress_log('CRON: Svi CRON zadaci obrisani', 'info');
    }

    public static function manual_update_all()
    {
        dexpress_log('MANUAL: Pokretanje svih ažuriranja', 'info');

        $api = D_Express_API::get_instance();
        if (!$api->has_credentials()) {
            return new WP_Error('no_credentials', 'API kredencijali nisu podešeni');
        }

        $result = $api->update_all_indexes();

        if ($result) {
            update_option('dexpress_last_manual_update', time());
            dexpress_log('MANUAL: Ažuriranje uspešno završeno', 'info');
            return true;
        } else {
            dexpress_log('MANUAL: Greška pri ažuriranju', 'error');
            return new WP_Error('update_failed', 'Greška pri ažuriranju');
        }
    }

    public static function initial_load_all()
    {
        dexpress_log('INITIAL: Učitavanje svih šifarnika', 'info');

        $api = D_Express_API::get_instance();
        if (!$api->has_credentials()) {
            dexpress_log('INITIAL: Nema API kredencijala', 'warning');
            return false;
        }

        $result = $api->update_all_indexes();

        if ($result) {
            update_option('dexpress_initial_load_done', time());
            dexpress_log('INITIAL: Učitavanje završeno uspešno', 'info');
            return true;
        } else {
            dexpress_log('INITIAL: Greška pri učitavanju', 'error');
            return false;
        }
    }
}
