<?php

/**
 * EXTERNAL CRON SERVICE - Potpuna nezavisnost od WordPress CRON-a
 * Ova klasa osigurava da CRON radi čak i ako nema poseta na sajt
 */

class D_Express_External_Cron_Service
{
    /**
     * Inicijalizacija external CRON servisa
     */
    public static function init()
    {
        // 1. REST API endpoint za external pozive
        add_action('rest_api_init', [__CLASS__, 'register_external_endpoints']);
        
        // 2. Setup external trigger sistema
        add_action('wp_loaded', [__CLASS__, 'maybe_setup_external_services'], 1);
        
        // 3. Admin notice za setup instrukcije
        add_action('admin_notices', [__CLASS__, 'show_external_setup_notice']);
    }

    /**
     * Registruj REST API endpoint-e
     */
    public static function register_external_endpoints()
    {
        // Main trigger endpoint
        register_rest_route('dexpress/v1', '/trigger', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_external_trigger'],
            'permission_callback' => [__CLASS__, 'verify_external_request'],
            'args' => [
                'key' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Secret key for verification'
                ]
            ]
        ]);

        // Status endpoint (za monitoring)
        register_rest_route('dexpress/v1', '/status', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_cron_status_api'],
            'permission_callback' => [__CLASS__, 'verify_external_request']
        ]);
    }

    /**
     * Handle external trigger poziv
     */
    public static function handle_external_trigger($request)
    {
        $source = $request->get_param('source') ?? 'external';
        $remote_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        dexpress_log("EXTERNAL-TRIGGER: Pokrenuto iz {$remote_ip} (source: {$source})", 'info');
        
        // Pokreni CRON
        D_Express_Cron_Manager::run_daily_updates();
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'CRON uspešno pokrenut',
            'timestamp' => current_time('mysql'),
            'source' => $source,
            'ip' => $remote_ip
        ], 200);
    }

    /**
     * API endpoint za status
     */
    public static function get_cron_status_api($request)
    {
        $status = D_Express_Cron_Manager::get_cron_status();
        
        return new WP_REST_Response([
            'cron_status' => $status,
            'external_services' => self::get_external_services_status(),
            'timestamp' => current_time('mysql')
        ], 200);
    }

    /**
     * Verify external request
     */
    public static function verify_external_request($request)
    {
        $provided_key = $request->get_param('key');
        $expected_key = get_option('dexpress_cron_secret');
        
        // Dozvolji i iz local IP-a bez key-a
        $is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
        
        return ($provided_key === $expected_key) || $is_local;
    }

    /**
     * Setup external servisa (automatski)
     */
    public static function maybe_setup_external_services()
    {
        // Setup samo jednom dnevno
        $last_setup = get_option('dexpress_external_setup_check', 0);
        if ((time() - $last_setup) < DAY_IN_SECONDS) {
            return;
        }
        
        self::prepare_external_urls();
        update_option('dexpress_external_setup_check', time());
    }

    /**
     * Pripremi URL-ove za external servise
     */
    private static function prepare_external_urls()
    {
        $secret = get_option('dexpress_cron_secret');
        $site_url = home_url();
        
        // Glavne URL-ovi
        $trigger_url = $site_url . '/wp-json/dexpress/v1/trigger?key=' . $secret;
        $status_url = $site_url . '/wp-json/dexpress/v1/status?key=' . $secret;
        
        // Sačuvaj za admin prikaz
        update_option('dexpress_external_trigger_url', $trigger_url);
        update_option('dexpress_external_status_url', $status_url);
        
        dexpress_log('EXTERNAL-SETUP: URL-ovi pripremljeni za external servise', 'debug');
    }

    /**
     * Get external services status
     */
    public static function get_external_services_status()
    {
        return [
            'trigger_url' => get_option('dexpress_external_trigger_url', ''),
            'status_url' => get_option('dexpress_external_status_url', ''),
            'last_setup_check' => get_option('dexpress_external_setup_check', 0),
            'available_services' => self::get_available_external_services()
        ];
    }

    /**
     * Lista dostupnih external servisa
     */
    public static function get_available_external_services()
    {
        $trigger_url = get_option('dexpress_external_trigger_url', '');
        
        return [
            'cron_job_org' => [
                'name' => 'cron-job.org',
                'url' => 'https://cron-job.org',
                'free' => true,
                'setup_url' => 'https://cron-job.org/en/members/jobs/add/',
                'instructions' => [
                    '1. Registruj se na cron-job.org (besplatno)',
                    '2. Klikni "Create cronjob"',
                    '3. Title: "DExpress Auto Update"',
                    '4. URL: ' . $trigger_url,
                    '5. Schedule: Daily at 03:00',
                    '6. Save and Enable'
                ]
            ],
            'easycron' => [
                'name' => 'EasyCron',
                'url' => 'https://www.easycron.com',
                'free' => true,
                'setup_url' => 'https://www.easycron.com/user/cronjob',
                'instructions' => [
                    '1. Registruj se na EasyCron (besplatno)',
                    '2. Add New Cron Job',
                    '3. URL to call: ' . $trigger_url,
                    '4. When to call: 0 3 * * * (daily at 3:00 AM)',
                    '5. Job Name: DExpress Auto Update',
                    '6. Create Cron Job'
                ]
            ],
            'uptimerobot' => [
                'name' => 'UptimeRobot',
                'url' => 'https://uptimerobot.com',
                'free' => true,
                'setup_url' => 'https://uptimerobot.com/dashboard',
                'instructions' => [
                    '1. Registruj se na UptimeRobot',
                    '2. Add New Monitor',
                    '3. Monitor Type: HTTP(s)',
                    '4. URL: ' . $trigger_url,
                    '5. Monitoring Interval: 24 hours',
                    '6. Create Monitor'
                ]
            ],
            'server_cron' => [
                'name' => 'Server CRON (cPanel)',
                'url' => 'cPanel → CRON Jobs',
                'free' => true,
                'command' => '/usr/bin/curl -s "' . $trigger_url . '" > /dev/null 2>&1',
                'schedule' => '0 3 * * *',
                'instructions' => [
                    '1. Idi u cPanel → CRON Jobs',
                    '2. Add New Cron Job',
                    '3. Common Settings: Once Per Day (0 3 * * *)',
                    '4. Command: ' . '/usr/bin/curl -s "' . $trigger_url . '" > /dev/null 2>&1',
                    '5. Add Cron Job'
                ]
            ]
        ];
    }

    /**
     * Admin notice za setup
     */
    public static function show_external_setup_notice()
    {
        // Prikaži samo na DExpress stranicama
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'dexpress') === false) {
            return;
        }

        // Proveri da li je notice dismissed
        if (get_option('dexpress_external_notice_dismissed', false)) {
            return;
        }

        // Proveri da li je CRON nedavno radio
        $last_update = get_option('dexpress_last_unified_update', 0);
        $hours_since = (time() - $last_update) / HOUR_IN_SECONDS;

        // Prikaži notice samo ako CRON kasni 25+ sati
        if ($hours_since < 25) {
            return;
        }

        $trigger_url = get_option('dexpress_external_trigger_url', '');
        
        ?>
        <div class="notice notice-warning is-dismissible" id="dexpress-external-notice">
            <h3>🚀 DExpress CRON Optimizacija</h3>
            <p><strong>CRON kasni <?php echo round($hours_since, 1); ?> sati!</strong> Za 100% pouzdanost, postavite external CRON servis:</p>
            
            <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 10px 0;">
                <h4>Najbrže rešenje - cron-job.org (besplatno):</h4>
                <ol>
                    <li>Idi na <a href="https://cron-job.org" target="_blank">cron-job.org</a></li>
                    <li>Registruj se (besplatno)</li>
                    <li>Create cronjob sa ovim podacima:</li>
                </ol>
                
                <div style="background: #fff; padding: 10px; border: 1px solid #ddd; margin: 10px 0;">
                    <strong>URL:</strong> <input type="text" readonly value="<?php echo esc_attr($trigger_url); ?>" style="width: 100%; font-family: monospace;" onclick="this.select();">
                    <br><strong>Schedule:</strong> Daily at 03:00
                </div>
            </div>
            
            <p>
                <a href="<?php echo admin_url('admin.php?page=dexpress-settings&tab=cron'); ?>" class="button button-primary">Vidi sve opcije</a>
                <button type="button" class="button" onclick="dexpressDismissExternalNotice()">Dismiss</button>
            </p>
        </div>

        <script>
        function dexpressDismissExternalNotice() {
            jQuery.post(ajaxurl, {
                action: 'dexpress_dismiss_external_notice',
                _wpnonce: '<?php echo wp_create_nonce('dexpress_external_notice'); ?>'
            });
            jQuery('#dexpress-external-notice').fadeOut();
        }
        </script>
        <?php
    }

    /**
     * AJAX handler za dismiss notice
     */
    public static function ajax_dismiss_external_notice()
    {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'dexpress_external_notice')) {
            wp_die('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('No permission');
        }

        update_option('dexpress_external_notice_dismissed', true);
        wp_send_json_success(['message' => 'Notice dismissed']);
    }

    /**
     * Test external trigger (za admin)
     */
    public static function test_external_trigger()
    {
        $trigger_url = get_option('dexpress_external_trigger_url', '');
        
        if (empty($trigger_url)) {
            return new WP_Error('no_url', 'External trigger URL nije konfigurisan');
        }

        // Pozovi sebe
        $response = wp_remote_get($trigger_url . '&source=admin_test', [
            'timeout' => 30,
            'user-agent' => 'DExpress-Admin-Test/1.0'
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $code = wp_remote_retrieve_response_code($response);

        return [
            'success' => $code === 200,
            'response_code' => $code,
            'response_body' => $body,
            'url_tested' => $trigger_url
        ];
    }

    /**
     * Get setup instructions za admin
     */
    public static function get_setup_instructions()
    {
        $services = self::get_available_external_services();
        $current_status = D_Express_Cron_Manager::get_cron_status();
        
        return [
            'current_status' => $current_status,
            'external_services' => $services,
            'recommendations' => self::get_recommendations(),
            'test_url' => get_option('dexpress_external_trigger_url', '')
        ];
    }

    /**
     * Get preporuke na osnovu trenutnog stanja
     */
    private static function get_recommendations()
    {
        $last_update = get_option('dexpress_last_unified_update', 0);
        $hours_since = $last_update ? (time() - $last_update) / HOUR_IN_SECONDS : 999;
        
        $recommendations = [];
        
        if ($hours_since > 48) {
            $recommendations[] = [
                'priority' => 'critical',
                'title' => 'KRITIČNO: CRON nije radio ' . round($hours_since) . ' sati',
                'action' => 'Odmah postaviti external CRON servis'
            ];
        } elseif ($hours_since > 25) {
            $recommendations[] = [
                'priority' => 'high', 
                'title' => 'CRON kasni ' . round($hours_since) . ' sati',
                'action' => 'Preporučuje se external CRON servis'
            ];
        } else {
            $recommendations[] = [
                'priority' => 'low',
                'title' => 'CRON radi normalno',
                'action' => 'External CRON opciono za dodatnu sigurnost'
            ];
        }

        // Hosting preporuke
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $recommendations[] = [
                'priority' => 'medium',
                'title' => 'Development environment',
                'action' => 'Za production, obavezno setup external CRON'
            ];
        }

        return $recommendations;
    }

    /**
     * Initialize AJAX actions
     */
    public static function init_ajax()
    {
        add_action('wp_ajax_dexpress_dismiss_external_notice', [__CLASS__, 'ajax_dismiss_external_notice']);
        add_action('wp_ajax_dexpress_test_external_trigger', [__CLASS__, 'ajax_test_external_trigger']);
    }

    /**
     * AJAX test external trigger
     */
    public static function ajax_test_external_trigger()
    {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'dexpress_external_test')) {
            wp_die('Invalid nonce');
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('No permission');
        }

        $result = self::test_external_trigger();
        
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => 'Test neuspešan: ' . $result->get_error_message()
            ]);
        } else {
            wp_send_json_success([
                'message' => 'External trigger test ' . ($result['success'] ? 'uspešan' : 'neuspešan'),
                'details' => $result
            ]);
        }
    }

    /**
     * Cleanup external servisa
     */
    public static function cleanup()
    {
        delete_option('dexpress_external_trigger_url');
        delete_option('dexpress_external_status_url');
        delete_option('dexpress_external_setup_check');
        delete_option('dexpress_external_notice_dismissed');
    }
}