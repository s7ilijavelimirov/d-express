<?php

/**
 * D Express DB klasa
 * 
 * Klasa za rad sa bazom podataka
 */

defined('ABSPATH') || exit;

class D_Express_DB
{
    /**
     * Dodaje novu pošiljku u bazu
     *
     * @param array $shipment_data Podaci o pošiljci
     * @return int|false ID ubačenog reda ili false u slučaju greške
     */
    public function add_shipment($shipment_data)
    {
        global $wpdb;

        $result = $wpdb->insert(
            $wpdb->prefix . 'dexpress_shipments',
            $shipment_data,
            array(
                '%d', // order_id
                '%s', // shipment_id
                '%s', // tracking_number
                '%s', // reference_id
                '%s', // status_code
                '%s', // status_description
                '%s', // created_at
                '%s', // updated_at
                '%s', // shipment_data
                '%d', // is_test
            )
        );

        if ($result) {
            $this->clear_shipment_cache($shipment_data['order_id']);
            return $wpdb->insert_id;
        }
        return false;
    }

    /**
     * Dodaje novi paket u bazu
     *
     * @param array $package_data Podaci o paketu
     * @return int|false ID ubačenog reda ili false u slučaju greške
     */
    public function add_package($package_data)
    {
        global $wpdb;

        $result = $wpdb->insert(
            $wpdb->prefix . 'dexpress_packages',
            $package_data,
            array(
                '%d', // shipment_id
                '%s', // package_code
                '%d', // mass
                '%s', // dimensions
                '%s', // created_at
            )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Ažurira status pošiljke
     *
     * @param string $shipment_id ID pošiljke
     * @param string $status_code Kod statusa
     * @param string $status_description Opis statusa
     * @return bool True u slučaju uspeha
     */
    public function update_shipment_status($shipment_id, $status_code, $status_description = '')
    {
        global $wpdb;

        $result = $wpdb->update(
            $wpdb->prefix . 'dexpress_shipments',
            array(
                'status_code' => $status_code,
                'status_description' => $status_description,
                'updated_at' => current_time('mysql')
            ),
            array('shipment_id' => $shipment_id),
            array('%s', '%s', '%s'),
            array('%s')
        ) !== false;

        if ($result) {
            // Dobij order_id iz shipment_id
            $order_id = $wpdb->get_var($wpdb->prepare(
                "SELECT order_id FROM {$wpdb->prefix}dexpress_shipments WHERE shipment_id = %s",
                $shipment_id
            ));
            if ($order_id) {
                $this->clear_shipment_cache($order_id);
            }
        }

        return $result;
    }

    /**
     * Ažurira status pošiljke prema referenci
     *
     * @param string $reference_id Referenca pošiljke
     * @param string $status_code Kod statusa
     * @param string $status_description Opis statusa
     * @return bool True u slučaju uspeha
     */
    public function update_shipment_status_by_reference($reference_id, $status_code, $status_description = '')
    {
        global $wpdb;

        return $wpdb->update(
            $wpdb->prefix . 'dexpress_shipments',
            array(
                'status_code' => $status_code,
                'status_description' => $status_description,
                'updated_at' => current_time('mysql')
            ),
            array('reference_id' => $reference_id),
            array('%s', '%s', '%s'),
            array('%s')
        ) !== false;
    }

    /**
     * Dobijanje pošiljke prema ID-u
     *
     * @param int $id ID pošiljke
     * @return object|null Podaci o pošiljci
     */
    public function get_shipment($id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
            $id
        ));
    }

    /**
     * Dobijanje pošiljke prema ID-u narudžbine
     *
     * @param int $order_id ID narudžbine
     * @return object|null Podaci o pošiljci
     */
    public function get_shipment_by_order_id($order_id)
    {
        // Jedinstveni cache ključ
        $cache_key = 'dexpress_shipment_' . $order_id;

        // Pokušaj učitati iz WordPress transient cache-a
        $result = get_transient($cache_key);

        // Ako nije u cache-u, učitaj iz baze
        if ($result === false) {
            global $wpdb;

            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
                $order_id
            ));

            // Sačuvaj rezultat u cache na 1 sat
            if ($result) {
                set_transient($cache_key, $result, HOUR_IN_SECONDS);
            }
        }

        return $result;
    }

    /**
     * Čišćenje cache-a za pošiljku
     *
     * @param int $order_id ID narudžbine
     */
    public function clear_shipment_cache($order_id)
    {
        delete_transient('dexpress_shipment_' . $order_id);
    }

    public function add_shipment_index()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_shipments';

        // Proverava da li indeks već postoji
        $index_exists = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name = 'order_id'");

        if (empty($index_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD INDEX order_id (order_id)");
        }
    }
    /**
     * Dobijanje pošiljke prema tracking broju
     *
     * @param string $tracking_number Broj za praćenje
     * @return object|null Podaci o pošiljci
     */
    public function get_shipment_by_tracking($tracking_number)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE tracking_number = %s",
            $tracking_number
        ));
    }

    /**
     * Dobijanje pošiljke prema D Express ID-u
     *
     * @param string $shipment_id D Express ID pošiljke
     * @return object|null Podaci o pošiljci
     */
    public function get_shipment_by_shipment_id($shipment_id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE shipment_id = %s",
            $shipment_id
        ));
    }

    /**
     * Dobijanje pošiljke prema referenci
     *
     * @param string $reference_id Referenca pošiljke
     * @return object|null Podaci o pošiljci
     */
    public function get_shipment_by_reference($reference_id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE reference_id = %s",
            $reference_id
        ));
    }

    /**
     * Dobijanje statusa za pošiljku
     *
     * @param string $shipment_id D Express ID pošiljke
     * @return array Lista statusa
     */
    public function get_statuses_for_shipment($shipment_id)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_statuses 
            WHERE shipment_code = %s OR shipment_id = %s 
            ORDER BY status_date DESC",
            $shipment_id,
            $shipment_id
        ));
    }

    /**
     * Dodaje novi status u bazu
     *
     * @param array $status_data Podaci o statusu
     * @return int|false ID ubačenog reda ili false u slučaju greške
     */
    public function add_status($status_data)
    {
        global $wpdb;

        $result = $wpdb->insert(
            $wpdb->prefix . 'dexpress_statuses',
            $status_data,
            array(
                '%s', // shipment_id
                '%s', // notification_id
                '%s', // reference_id
                '%s', // shipment_code
                '%s', // status_id
                '%s', // status_date
                '%s', // raw_data
                '%d', // is_processed
                '%s', // created_at
            )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Označava status kao obrađen
     *
     * @param int $status_id ID statusa
     * @return bool True u slučaju uspeha
     */
    public function mark_status_as_processed($status_id)
    {
        global $wpdb;

        return $wpdb->update(
            $wpdb->prefix . 'dexpress_statuses',
            array('is_processed' => 1),
            array('id' => $status_id),
            array('%d'),
            array('%d')
        ) !== false;
    }
}
