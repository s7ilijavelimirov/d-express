<?php
defined('ABSPATH') || exit;

class D_Express_Payments_Service
{
    private $api;

    public function __construct()
    {
        $this->api = D_Express_API::get_instance();
    }

    /**
     * Importuj plaćanja po referenci
     */
    public function import_payments_by_reference($payment_reference)
    {
        global $wpdb;

        dexpress_log("[PAYMENTS] Početak import-a za referencu: " . $payment_reference, 'info');

        if (dexpress_is_test_mode()) {
            $payments_data = $this->simulate_test_payments($payment_reference);
            dexpress_log("[PAYMENTS] Test simulacija vratila: " . print_r($payments_data, true), 'info');
        } else {
            $payments_data = $this->api->get_payments_by_reference($payment_reference);
        }

        if (is_wp_error($payments_data)) {
            return $payments_data;
        }

        $imported_count = 0;
        $table_name = $wpdb->prefix . 'dexpress_payments';

        foreach ($payments_data as $payment) {
            // Proveri da li već postoji
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} 
                 WHERE payment_reference = %s AND shipment_code = %s",
                $payment_reference,
                $payment['ShCode']
            ));

            if ($exists) {
                continue; // Preskoči duplikate
            }

            // Umetni novo plaćanje
            $result = $wpdb->insert($table_name, [
                'payment_reference' => $payment_reference,
                'shipment_code' => $payment['ShCode'],
                'buyout_amount' => intval($payment['Buyout']),
                'reference_id' => $payment['ReferenceID'],
                'receiver_name' => $payment['RName'],
                'receiver_address' => $payment['RAddress'],
                'receiver_town' => $payment['RTown'],
                'payment_date' => $this->format_payment_date($payment['PaymentDate'])
            ]);

            if ($result) {
                $imported_count++;

                // Ažuriraj WooCommerce order status
                $this->update_order_payment_status($payment['ReferenceID']);
            }
        }
        dexpress_log("[PAYMENTS] Importovano ukupno: " . $imported_count . " plaćanja", 'info');
        return $imported_count;
    }

    /**
     * Formatira datum iz yyyyMMdd u MySQL format
     */
    private function format_payment_date($date_string)
    {
        return DateTime::createFromFormat('Ymd', $date_string)->format('Y-m-d');
    }

    /**
     * Ažurira status WooCommerce narudžbine
     */
    private function update_order_payment_status($reference_id)
    {
        $order = wc_get_order($reference_id);
        if ($order && $order->get_payment_method() === 'cod') {
            $order->add_order_note('Pouzeće naplaćeno od strane D Express-a');
            $order->payment_complete();
        }
    }
    /**
     * Dinamička simulacija na osnovu stvarnih COD pošiljki
     */
    private function simulate_test_payments($payment_reference)
    {
        global $wpdb;

        // Dobij COD pošiljke iz poslednih 30 dana
        $cod_shipments = $wpdb->get_results("
        SELECT s.id, s.order_id, s.reference_id, s.buyout_in_para, s.created_at
        FROM {$wpdb->prefix}dexpress_shipments s
        WHERE s.buyout_in_para > 0 
        AND s.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY s.created_at DESC
        LIMIT 10
    ");

        if (empty($cod_shipments)) {
            return new WP_Error('no_cod_shipments', 'Nema COD pošiljki za simulaciju.');
        }

        // Generiši test podatke na osnovu stvarnih pošiljki
        $test_payments = [];

        foreach ($cod_shipments as $shipment) {
            $order = wc_get_order($shipment->order_id);
            if (!$order) continue;

            // Dobij PRVI paket za ShCode (ili generiši ako nema)
            $package_code = $wpdb->get_var($wpdb->prepare(
                "SELECT package_code FROM {$wpdb->prefix}dexpress_packages 
             WHERE shipment_id = %d ORDER BY id ASC LIMIT 1",
                $shipment->id
            ));

            $test_payments[] = [
                'ShCode' => $package_code ?: 'TT' . str_pad($shipment->id, 10, '0', STR_PAD_LEFT),
                'Buyout' => intval($shipment->buyout_in_para),
                'ReferenceID' => $shipment->reference_id,
                'RName' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                'RAddress' => $order->get_shipping_address_1(),
                'RTown' => $order->get_shipping_city(),
                'PaymentDate' => date('Ymd', strtotime($shipment->created_at))
            ];

            if (count($test_payments) >= 5) break;
        }

        // Različite kombinacije za različite test reference
        switch ($payment_reference) {
            case 'TEST-20250125-001':
                return array_slice($test_payments, 0, 3);

            case 'TEST-20250125-002':
                return array_slice($test_payments, 0, 1);

            case 'ALL-TEST':
                return $test_payments;

            case 'EMPTY-TEST':
                return [];

            default:
                return new WP_Error(
                    'test_not_found',
                    'Nepoznata test referenca. Dostupne: TEST-20250125-001, TEST-20250125-002, ALL-TEST, EMPTY-TEST'
                );
        }
    }
}
