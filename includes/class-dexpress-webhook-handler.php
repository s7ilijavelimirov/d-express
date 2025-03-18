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
     */
    public function check_permission()
    {
        // Ova metoda uvek vraća true jer ćemo sigurnosnu proveru raditi u handle_notify metodi
        return true;
    }

    /**
     * Obrada webhook notifikacije
     */
    public function handle_notify(WP_REST_Request $request)
    {
        global $wpdb;

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
            return new WP_REST_Response('ERROR: Invalid data structure', 400);
        }

        // Provera passcode-a
        $webhook_secret = get_option('dexpress_webhook_secret', '');
        if (empty($webhook_secret) || $data['cc'] !== $webhook_secret) {
            return new WP_REST_Response('ERROR: Invalid passcode', 403);
        }

        // Provera da li već imamo ovaj nID (duplikat)
        $table_name = $wpdb->prefix . 'dexpress_statuses';
        $existing_notification = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE notification_id = %s",
            $data['nID']
        ));

        if ($existing_notification) {
            // Ako je duplikat, vraćamo OK (prema specifikaciji API-ja)
            return new WP_REST_Response('OK', 200);
        }

        try {
            // Ubacivanje u bazu podataka
            $status_date = $this->format_date($data['dt']);

            $inserted = $wpdb->insert(
                $table_name,
                array(
                    'notification_id' => $data['nID'],
                    'shipment_code' => $data['code'],
                    'reference_id' => $data['rID'],
                    'status_id' => $data['sID'],
                    'status_date' => $status_date,
                    'raw_data' => json_encode($data),
                    'is_processed' => 0,
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
            );

            if ($inserted === false) {
                dexpress_log('Greška pri ubacivanju webhook podataka u bazu: ' . $wpdb->last_error, 'error');
                return new WP_REST_Response('ERROR: Database error', 500);
            }

            // Pozivanje asinhronog procesa za obradu notifikacije (ne blokiramo odgovor)
            $this->schedule_notification_processing($wpdb->insert_id);

            return new WP_REST_Response('OK', 200);
        } catch (Exception $e) {
            dexpress_log('Exception u webhook handleru: ' . $e->getMessage(), 'error');
            return new WP_REST_Response('ERROR: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Formatiranje datuma iz D Express formata u MySQL format
     */
    private function format_date($date_string)
    {
        // Format iz D Express-a: yyyyMMddHHmmss
        // Format za MySQL: Y-m-d H:i:s
        if (strlen($date_string) !== 14) {
            return current_time('mysql');
        }

        $year = substr($date_string, 0, 4);
        $month = substr($date_string, 4, 2);
        $day = substr($date_string, 6, 2);
        $hour = substr($date_string, 8, 2);
        $minute = substr($date_string, 10, 2);
        $second = substr($date_string, 12, 2);

        return "$year-$month-$day $hour:$minute:$second";
    }

    /**
     * Zakazivanje asinhrone obrade notifikacije
     */
    private function schedule_notification_processing($notification_id)
    {
        // Koristimo WordPress Cron za asinhronu obradu
        if (!wp_next_scheduled('dexpress_process_notification', array($notification_id))) {
            wp_schedule_single_event(time(), 'dexpress_process_notification', array($notification_id));
        }
    }

    /**
     * Logovanje webhook zahteva (samo u test modu)
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
        $data = array(
            'time' => current_time('mysql'),
            'ip' => $_SERVER['REMOTE_ADDR'],
            'method' => $request->get_method(),
            'headers' => $request->get_headers(),
            'params' => $request->get_json_params()
        );

        // Pisanje u log fajl
        file_put_contents(
            $log_file,
            json_encode($data, JSON_PRETTY_PRINT) . "\n\n",
            FILE_APPEND
        );
    }
}

/**
 * Obrada notifikacije (pozvana asinhrono preko WP Cron-a)
 */
function dexpress_process_notification_callback($notification_id)
{
    global $wpdb;
    $db = new D_Express_DB();

    // Dobijanje notifikacije iz baze
    $table_name = $wpdb->prefix . 'dexpress_statuses';
    $notification = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $notification_id
    ));

    if (!$notification) {
        return;
    }

    // Pronalaženje pošiljke na osnovu reference_id
    $shipment = $db->get_shipment_by_reference($notification->reference_id);

    if (!$shipment) {
        // Alternativno, pokušaj pronalaženja po shipment_code
        $shipment = $db->get_shipment_by_shipment_id($notification->shipment_code);
    }

    if ($shipment) {
        // Ažuriranje statusa pošiljke
        $status_desc = dexpress_format_status($notification->status_id);
        $db->update_shipment_status($shipment->shipment_id, $notification->status_id, $status_desc);

        // Ažuriranje statusa narudžbine ako je potrebno
        update_order_status_from_shipment_status($shipment->order_id, $notification->status_id, $status_desc);
    }

    // Označavanje notifikacije kao obrađene
    $db->mark_status_as_processed($notification_id);
}

// Registracija callback funkcije za cron
add_action('dexpress_process_notification', 'dexpress_process_notification_callback');

/**
 * Ažuriranje statusa narudžbine na osnovu statusa pošiljke
 */
function update_order_status_from_shipment_status($order_id, $status_id, $status_desc)
{
    $order = wc_get_order($order_id);

    if (!$order) {
        return;
    }

    // Mapiranje statusa pošiljke na statuse narudžbine
    $status_mapping = apply_filters('dexpress_status_mapping', array(
        '130' => 'completed', // Isporučeno - Kompletno završeno
        '131' => 'failed',    // Neisporučeno - Neuspešno
    ));

    if (isset($status_mapping[$status_id]) && $order->get_status() !== $status_mapping[$status_id]) {
        // Ažuriranje statusa narudžbine
        $order->update_status(
            $status_mapping[$status_id],
            sprintf(__('Status ažuriran od strane D Express: %s', 'd-express-woo'), $status_desc)
        );
    }

    // Dodavanje napomene o statusu pošiljke
    $order->add_order_note(
        sprintf(__('D Express status pošiljke: %s', 'd-express-woo'), $status_desc)
    );
}
/**
 * Implementacija email obaveštenja pri promeni statusa
 *
 * @param int $shipment_id ID pošiljke
 * @param string $status_code Kod statusa
 * @param string $status_name Naziv statusa
 */
function dexpress_send_status_notification($shipment_id, $status_code, $status_name)
{
    global $wpdb;

    // Dobavite podatke o pošiljci
    $shipment = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
        $shipment_id
    ));

    if (!$shipment) return;

    // Dobavite narudžbinu
    $order = wc_get_order($shipment->order_id);
    if (!$order) return;

    // Standardni podaci za email
    $email_data = array(
        'order' => $order,
        'shipment' => $shipment,
        'tracking_number' => $shipment->tracking_number,
        'reference_id' => $shipment->reference_id,
        'status_code' => $status_code,
        'status_name' => $status_name,
        'shipment_date' => $shipment->created_at,
        'is_test' => (bool) $shipment->is_test
    );

    // Odaberite email šablon na osnovu statusa
    if ($status_code == '130') { // Isporučeno
        // Pošaljite email o isporuci
        dexpress_send_delivered_email($email_data);
    } elseif ($status_code == '131') { // Neisporučeno
        // Pošaljite email o neuspeloj isporuci
        dexpress_send_failed_email($email_data);
    } else {
        // Pošaljite email o promeni statusa
        //dexpress_send_status_change_email($email_data);
    }
}

/**
 * Email za isporučenu pošiljku
 */
function dexpress_send_delivered_email($data)
{
    $order = $data['order'];

    // Priprema mailer objekta
    $mailer = WC()->mailer();

    // Priprema email naslova i recipijenta
    $email_subject = sprintf(__('Vaša pošiljka #%s je isporučena', 'd-express-woo'), $data['tracking_number']);
    $email_recipient = $order->get_billing_email();

    // Generišite HTML sadržaj koristeći dobar WooCommerce šablon
    ob_start();
    include DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/shipment-delivered.php';
    $email_content = ob_get_clean();

    // Wrap email u WooCommerce šablon
    $email_content = $mailer->wrap_message($email_subject, $email_content);

    // Pošaljite email
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $mailer->send($email_recipient, $email_subject, $email_content, $headers);

    // Dodajte napomenu na narudžbinu
    $order->add_order_note(
        sprintf(__('Email o isporuci pošiljke #%s je poslat kupcu.', 'd-express-woo'), $data['tracking_number'])
    );
}

/**
 * Email za neisporučenu pošiljku
 */
function dexpress_send_failed_email($data)
{
    $order = $data['order'];

    // Priprema mailer objekta
    $mailer = WC()->mailer();

    // Priprema email naslova i recipijenta
    $email_subject = sprintf(__('Problem sa isporukom Vaše pošiljke #%s', 'd-express-woo'), $data['tracking_number']);
    $email_recipient = $order->get_billing_email();

    // Generišite HTML sadržaj koristeći dobar WooCommerce šablon
    ob_start();
    include DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/shipment-failed.php';
    $email_content = ob_get_clean();

    // Wrap email u WooCommerce šablon
    $email_content = $mailer->wrap_message($email_subject, $email_content);

    // Pošaljite email
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $mailer->send($email_recipient, $email_subject, $email_content, $headers);

    // Dodajte napomenu na narudžbinu
    $order->add_order_note(
        sprintf(__('Email o neuspeloj isporuci pošiljke #%s je poslat kupcu.', 'd-express-woo'), $data['tracking_number'])
    );
}
