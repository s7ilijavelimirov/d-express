<?php

/**
 * D Express Webhook Handler
 * 
 * Klasa za obradu webhook zahteva od D Express-a
 */

defined('ABSPATH') || exit;

class D_Express_Webhook_Handler
{
    /**
     * Provera dozvole za pristup webhook-u
     * 
     * @param WP_REST_Request $request Trenutni request
     * @return bool|WP_Error True ako je dozvoljeno, WP_Error ako nije
     */
    public function check_permission(WP_REST_Request $request)
    {
        // 1. Provera webhook_secret parametra
        $data = $request->get_json_params();
        
        if (!isset($data['cc']) || empty($data['cc'])) {
            dexpress_log('Webhook pristup odbijen: Nedostaje passcode (cc)', 'error');
            return new WP_Error(
                'invalid_request', 
                __('Neispravan zahtev: Nedostaje passcode', 'd-express-woo'),
                ['status' => 403]
            );
        }
        
        $webhook_secret = get_option('dexpress_webhook_secret', '');
        
        if (empty($webhook_secret) || $data['cc'] !== $webhook_secret) {
            dexpress_log('Webhook pristup odbijen: Neispravan passcode', 'error');
            return new WP_Error(
                'invalid_credentials', 
                __('Neispravan passcode', 'd-express-woo'),
                ['status' => 403]
            );
        }
        
        // 2. Dodatna validacija IP adrese (opciono)
        $allowed_ips = $this->get_allowed_ips();
        $remote_ip = $this->get_client_ip();
        
        if (!empty($allowed_ips) && !in_array($remote_ip, $allowed_ips)) {
            dexpress_log('Webhook pristup odbijen: Neovlašćena IP adresa: ' . $remote_ip, 'error');
            return new WP_Error(
                'unauthorized_ip', 
                __('Neovlašćena IP adresa', 'd-express-woo'),
                ['status' => 403]
            );
        }
        
        // Sve provere su prošle
        return true;
    }
    
    /**
     * Dobijanje liste dozvoljenih IP adresa
     * 
     * @return array Lista dozvoljenih IP adresa
     */
    private function get_allowed_ips()
    {
        // Implementiraj opciju za čuvanje i definisanje dozvoljenih IP adresa
        // Za sad koristimo praznu listu što znači da su sve IP adrese dozvoljene
        $allowed_ips = get_option('dexpress_allowed_webhook_ips', '');
        
        if (empty($allowed_ips)) {
            return []; // Sve IP adrese su dozvoljene ako nema definisanih
        }
        
        return array_map('trim', explode(',', $allowed_ips));
    }
    
    /**
     * Dobijanje IP adrese klijenta
     * 
     * @return string IP adresa klijenta
     */
    private function get_client_ip()
    {
        // Dobijanje stvarne IP adrese klijenta i iza proxy-ja
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_array = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ip_array[0]);
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return $ip;
    }

    /**
     * Obrada webhook notifikacije
     * 
     * @param WP_REST_Request $request Request objekat
     * @return WP_REST_Response Odgovor
     */
    public function handle_notify(WP_REST_Request $request)
    {
        global $wpdb;
        
        // Validacija zahteva 
        $permission_check = $this->check_permission($request);
        if (is_wp_error($permission_check)) {
            return new WP_REST_Response('ERROR: ' . $permission_check->get_error_message(), 403);
        }

        // Logovanje dolazećih podataka (samo u test modu)
        if (dexpress_is_test_mode()) {
            $this->log_webhook_request($request);
        }

        // Dobijanje podataka iz zahteva
        $data = $request->get_json_params();

        // Provera da li su svi potrebni parametri prisutni
        if (
            !isset($data['cc']) || !isset($data['nID']) || !isset($data['code']) ||
            !isset($data['rID']) || !isset($data['sID']) || !isset($data['dt'])
        ) {
            dexpress_log('Webhook: Nedostaju obavezni parametri', 'error');
            return new WP_REST_Response('ERROR: Invalid data structure', 400);
        }
        
        // Validacija tipova podataka
        if (!is_string($data['nID']) || !is_string($data['code']) || !is_string($data['rID'])) {
            dexpress_log('Webhook: Neispravni tipovi podataka', 'error');
            return new WP_REST_Response('ERROR: Invalid data types', 400);
        }

        // Provera da li već imamo ovaj nID (duplikat)
        $table_name = $wpdb->prefix . 'dexpress_statuses';
        $notification_id = $wpdb->_real_escape($data['nID']);
        
        $existing_notification = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE notification_id = %s",
            $notification_id
        ));

        if ($existing_notification) {
            // Ako je duplikat, vraćamo OK (prema specifikaciji API-ja)
            dexpress_log('Webhook: Duplikat notifikacije ' . $notification_id, 'info');
            return new WP_REST_Response('OK', 200);
        }

        try {
            // Ubacivanje u bazu podataka
            $status_date = $this->format_date($data['dt']);

            $status_data = [
                'notification_id' => $notification_id,
                'shipment_code' => $wpdb->_real_escape($data['code']),
                'reference_id' => $wpdb->_real_escape($data['rID']),
                'status_id' => $wpdb->_real_escape($data['sID']),
                'status_date' => $status_date,
                'raw_data' => json_encode($data),
                'is_processed' => 0,
                'created_at' => current_time('mysql')
            ];

            $inserted = $wpdb->insert(
                $table_name,
                $status_data,
                [
                    '%s', // notification_id
                    '%s', // shipment_code
                    '%s', // reference_id
                    '%s', // status_id
                    '%s', // status_date
                    '%s', // raw_data
                    '%d', // is_processed
                    '%s', // created_at
                ]
            );

            if ($inserted === false) {
                dexpress_log('Greška pri ubacivanju webhook podataka u bazu: ' . $wpdb->last_error, 'error');
                return new WP_REST_Response('ERROR: Database error', 500);
            }

            $insert_id = $wpdb->insert_id;
            dexpress_log('Webhook: Uspešno upisana notifikacija ' . $notification_id . ' (ID: ' . $insert_id . ')', 'info');

            // Pozivanje asinhronog procesa za obradu notifikacije (ne blokiramo odgovor)
            $this->schedule_notification_processing($insert_id);

            return new WP_REST_Response('OK', 200);
        } catch (Exception $e) {
            dexpress_log('Exception u webhook handleru: ' . $e->getMessage(), 'error');
            return new WP_REST_Response('ERROR: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Formatiranje datuma iz D Express formata u MySQL format
     * 
     * @param string $date_string Datum u D Express formatu
     * @return string Datum u MySQL formatu
     */
    private function format_date($date_string)
    {
        // Format iz D Express-a: yyyyMMddHHmmss
        // Format za MySQL: Y-m-d H:i:s
        if (!is_string($date_string) || strlen($date_string) !== 14 || !ctype_digit($date_string)) {
            dexpress_log('Webhook: Neispravan format datuma: ' . $date_string, 'warning');
            return current_time('mysql');
        }

        $year = substr($date_string, 0, 4);
        $month = substr($date_string, 4, 2);
        $day = substr($date_string, 6, 2);
        $hour = substr($date_string, 8, 2);
        $minute = substr($date_string, 10, 2);
        $second = substr($date_string, 12, 2);

        // Validacija delova datuma
        if (
            !checkdate((int)$month, (int)$day, (int)$year) || 
            (int)$hour > 23 || (int)$minute > 59 || (int)$second > 59
        ) {
            dexpress_log('Webhook: Nevalidan datum nakon parsiranja: ' . $date_string, 'warning');
            return current_time('mysql');
        }

        return "$year-$month-$day $hour:$minute:$second";
    }

    /**
     * Zakazivanje asinhrone obrade notifikacije
     * 
     * @param int $notification_id ID notifikacije
     */
    private function schedule_notification_processing($notification_id)
    {
        // Koristimo WordPress Cron za asinhronu obradu
        if (!wp_next_scheduled('dexpress_process_notification', [$notification_id])) {
            $scheduled = wp_schedule_single_event(
                time() + 5, // Dodajemo malo odlaganja da se REST odgovor vrati pre obrade
                'dexpress_process_notification', 
                [$notification_id]
            );
            
            if (!$scheduled) {
                dexpress_log('Webhook: Greška pri zakazivanju obrade notifikacije ID: ' . $notification_id, 'error');
            }
        }
    }

    /**
     * Logovanje webhook zahteva (samo u test modu)
     * 
     * @param WP_REST_Request $request Request objekat
     */
    private function log_webhook_request($request)
    {
        $log_file = DEXPRESS_WOO_PLUGIN_DIR . 'logs/webhook-' . date('Y-m-d') . '.log';
        $log_dir = dirname($log_file);

        // Kreiranje direktorijuma za logove ako ne postoji
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // Dobijanje podataka za logovanje
        $data = [
            'time' => current_time('mysql'),
            'ip' => $this->get_client_ip(),
            'method' => $request->get_method(),
            'headers' => $this->sanitize_headers($request->get_headers()),
            'params' => $request->get_json_params()
        ];

        // Pisanje u log fajl
        file_put_contents(
            $log_file,
            json_encode($data, JSON_PRETTY_PRINT) . "\n\n",
            FILE_APPEND
        );
    }
    
    /**
     * Sanitizacija header-a za logovanje
     * 
     * @param array $headers HTTP headeri
     * @return array Sanitizovani headeri
     */
    private function sanitize_headers($headers) 
    {
        // Uklanjamo osetljive informacije iz headera
        $sensitive_headers = ['authorization', 'cookie', 'php-auth-user', 'php-auth-pw'];
        
        foreach ($sensitive_headers as $header) {
            if (isset($headers[$header])) {
                $headers[$header] = '[REDACTED]';
            }
        }
        
        return $headers;
    }
}