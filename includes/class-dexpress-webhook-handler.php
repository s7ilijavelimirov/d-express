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
     * Obrada notifikacije - jednostavna verzija
     */
    public function process_notification($notification_id)
    {
        global $wpdb;

        try {
            // Dobij notifikaciju
            $notification = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dexpress_statuses WHERE id = %d AND is_processed = 0",
                $notification_id
            ));

            if (!$notification) {
                dexpress_log('Notifikacija ' . $notification_id . ' nije pronađena ili je već obrađena', 'warning');
                return;
            }

            // Pronađi paket i pošiljku
            if ($notification->package_id) {
                $package = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE id = %d",
                    $notification->package_id
                ));
            } else {
                // Fallback - pronađi preko shipment_code (package_code)
                $package = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE package_code = %s",
                    $notification->shipment_code
                ));
            }

            if (!$package) {
                dexpress_log('Paket nije pronađen za notifikaciju ' . $notification_id, 'warning');
                // Označi kao obrađeno da se ne pokušava ponovo
                $wpdb->update(
                    $wpdb->prefix . 'dexpress_statuses',
                    ['is_processed' => 1],
                    ['id' => $notification_id]
                );
                return;
            }

            // Dobij pošiljku
            $shipment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
                $package->shipment_id
            ));

            if (!$shipment) {
                dexpress_log('Pošiljka nije pronađena za paket ' . $package->package_code, 'warning');
                $wpdb->update(
                    $wpdb->prefix . 'dexpress_statuses',
                    ['is_processed' => 1],
                    ['id' => $notification_id]
                );
                return;
            }

            // Ažuriraj package status
            $wpdb->update(
                $wpdb->prefix . 'dexpress_packages',
                [
                    'current_status_id' => $notification->status_id,
                    'current_status_name' => dexpress_get_status_name($notification->status_id),
                    'status_updated_at' => $notification->status_date,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $package->id]
            );

            // Ažuriraj shipment status
            $wpdb->update(
                $wpdb->prefix . 'dexpress_shipments',
                [
                    'status_code' => $notification->status_id,
                    'status_description' => dexpress_get_status_name($notification->status_id),
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $shipment->id]
            );

            // Dodaj napomenu u narudžbinu
            $order = wc_get_order($shipment->order_id);
            if ($order) {
                $status_name = dexpress_get_status_name($notification->status_id);
                $note = sprintf(
                    'D Express: %s - %s',
                    $status_name,
                    $package->package_code
                );
                $order->add_order_note($note);

                // Automatski promeni status narudžbine ako je potrebno
                if ($notification->status_id == '1' && in_array($order->get_status(), ['processing', 'on-hold'])) {
                    $order->update_status('completed', 'D Express paket isporučen');
                }
            }

            // Označi kao obrađeno
            $wpdb->update(
                $wpdb->prefix . 'dexpress_statuses',
                ['is_processed' => 1],
                ['id' => $notification_id]
            );

            dexpress_log('Uspešno obrađena notifikacija ' . $notification_id, 'info');
        } catch (Exception $e) {
            dexpress_log('Greška pri obradi notifikacije ' . $notification_id . ': ' . $e->getMessage(), 'error');
        }
    }
    /**
     * Provera dozvole za pristup webhook-u
     * 
     * @param WP_REST_Request $request Trenutni request
     * @return bool|WP_Error True ako je dozvoljeno, WP_Error ako nije
     */
    public function check_permission(WP_REST_Request $request)
    {
        // Logging za debug
        dexpress_log('Webhook zahtev primljen: ' . json_encode($request->get_params()), 'debug');

        $data = $request->get_json_params();

        // Ako nema JSON body-ja, proveri URL parametre (za PUT metodu)
        if (empty($data)) {
            $data = $request->get_params();
        }

        dexpress_log('Webhook podaci: ' . json_encode($data), 'debug');

        // Validacija passcode-a (webhook secret)
        if (!isset($data['cc']) || $data['cc'] !== get_option('dexpress_webhook_secret')) {
            dexpress_log('Webhook: Nevažeći webhook secret (cc): ' . ($data['cc'] ?? 'nije postavljen'), 'error');
            return new WP_Error('invalid_request', 'Invalid webhook secret', ['status' => 403]);
        }

        // Validacija obaveznih parametara
        $required_params = ['nID', 'code', 'rID', 'sID', 'dt'];
        foreach ($required_params as $param) {
            if (!isset($data[$param]) || empty($data[$param])) {
                dexpress_log('Webhook: Nedostaje obavezan parametar ili je prazan: ' . $param, 'error');
                return new WP_Error('invalid_request', "Missing or empty parameter: {$param}", ['status' => 400]);
            }
        }

        // Provera IP ograničenja ako su postavljena
        $allowed_ips = $this->get_allowed_ips();
        if (!empty($allowed_ips)) {
            $client_ip = $this->get_client_ip();

            if (!in_array($client_ip, $allowed_ips)) {
                dexpress_log('Webhook: Zabranjen pristup sa IP: ' . $client_ip, 'error');
                return new WP_Error('forbidden_ip', 'Access not allowed from this IP', ['status' => 403]);
            }
        }

        return true;
    }

    /**
     * Dobijanje liste dozvoljenih IP adresa
     * 
     * @return array Lista dozvoljenih IP adresa
     */
    private function get_allowed_ips()
    {
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
     * Obrada webhook notifikacije - AŽURIRANO za package tracking
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
            dexpress_log('Webhook: Neuspela provera dozvole: ' . $permission_check->get_error_message(), 'error');
            return new WP_REST_Response('ERROR: ' . $permission_check->get_error_message(), 403);
        }

        // Log dolazećih podataka
        dexpress_log('Webhook: Zahtev prošao validaciju, obrađujem...', 'debug');

        // Dobijanje podataka iz zahteva
        $data = $request->get_json_params();

        // Ako nema JSON body-ja, koristi URL parametre (za PUT metodu)
        if (empty($data)) {
            $data = $request->get_params();
        }

        // Parametri
        $notification_id = sanitize_text_field($data['nID']);
        $shipment_code = sanitize_text_field($data['code']);    // Tracking number (package_code)
        $reference_id = sanitize_text_field($data['rID']);
        $status_id = sanitize_text_field($data['sID']);        // Status ID
        $date_str = sanitize_text_field($data['dt']);

        // Dodatna validacija datuma (format yyyyMMddHHmmss)
        if (!preg_match('/^\d{14}$/', $date_str)) {
            dexpress_log('Webhook: Neispravan format datuma: ' . $date_str, 'warning');
            return new WP_REST_Response('OK', 200);
        }

        // Provera da li već imamo ovaj nID (duplikat)
        $existing_notification = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dexpress_statuses WHERE notification_id = %s",
            $notification_id
        ));

        if ($existing_notification) {
            dexpress_log('Webhook: Duplikat notifikacije ' . $notification_id . ', vraćam OK', 'info');
            return new WP_REST_Response('OK', 200);
        }

        try {
            // NOVO: Pokušaj pronaći paket po package_code ili preko reference_id
            $package = null;
            $shipment = null;

            // 1. Prvo pokušaj preko package_code (code parametar)
            if (!empty($shipment_code)) {
                $package = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE package_code = %s",
                    $shipment_code
                ));

                if ($package) {
                    $shipment = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
                        $package->shipment_id
                    ));
                    dexpress_log('Webhook: Pronađen paket po package_code: ' . $shipment_code, 'debug');
                }
            }

            // 2. Fallback - pokušaj preko reference_id ako paket nije pronađen
            if (!$package && !empty($reference_id)) {
                $shipment = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE reference_id = %s",
                    $reference_id
                ));

                if ($shipment) {
                    // Uzmi bilo koji paket iz te pošiljke (obično prvi)
                    $package = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d ORDER BY package_index ASC LIMIT 1",
                        $shipment->id
                    ));
                    dexpress_log('Webhook: Pronađena pošiljka po reference_id: ' . $reference_id, 'debug');
                }
            }

            if (!$package || !$shipment) {
                dexpress_log('Webhook: Paket/pošiljka nije pronađena. Code: ' . $shipment_code . ', rID: ' . $reference_id, 'warning');
                // Ipak sačuvaj notifikaciju za kasnije povezivanje
                $package_id = null;
            } else {
                $package_id = $package->id;
                dexpress_log('Webhook: Povezujem sa paket ID: ' . $package_id, 'debug');
            }

            // Ubacivanje u bazu podataka sa reference_id kolumnom
            $status_data = [
                'notification_id' => $notification_id,
                'shipment_code' => $shipment_code,
                'package_id' => $package_id,
                'reference_id' => $reference_id, 
                'status_id' => $status_id,
                'status_date' => $this->format_date($date_str),
                'raw_data' => json_encode($data),
                'is_processed' => 0,
                'created_at' => current_time('mysql')
            ];

            $inserted = $wpdb->insert(
                $wpdb->prefix . 'dexpress_statuses',
                $status_data,
                ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s']
            );

            if ($inserted === false) {
                dexpress_log('Webhook: Greška pri ubacivanju podataka u bazu: ' . $wpdb->last_error, 'error');
                return new WP_REST_Response('ERROR: Database error', 500);
            }

            $insert_id = $wpdb->insert_id;
            dexpress_log('Webhook: Uspešno upisana notifikacija ' . $notification_id . ' (ID: ' . $insert_id . ')', 'info');

            // Pozivanje asinhronog procesa za obradu notifikacije
            $this->schedule_notification_processing($insert_id);

            return new WP_REST_Response('OK', 200);
        } catch (Exception $e) {
            dexpress_log('Webhook: Exception u webhook handleru: ' . $e->getMessage(), 'error');
            return new WP_REST_Response('ERROR: ' . $e->getMessage(), 500);
        }
    }
    /**
     * Formatiranje datuma iz D Express formata u MySQL format
     * 
     * @param string $date_string Datum u D Express formatu (yyyyMMddHHmmss)
     * @return string Datum u MySQL formatu (Y-m-d H:i:s)
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
    /**
     * Registracija notifikacije o uspešnom prijemu
     */
    // Umesto trenutne implementacije
    public function send_receipt_confirmation($notification_id)
    {
        // Ova funkcija nije potrebna jer već vraćamo "OK" u handle_notify
        // API dokumentacija ne zahteva dodatnu potvrdu
        dexpress_log('Notifikacija #' . $notification_id . ' uspešno primljena', 'info');
    }
}
