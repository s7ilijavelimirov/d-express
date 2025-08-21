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

        $defaults = array(
            'split_index' => null,
            'total_splits' => null,
            'status_code' => null,
            'status_description' => null,
            'value_in_para' => 0,
            'buyout_in_para' => 0,
            'payment_by' => intval(get_option('dexpress_payment_by', 0)),
            'payment_type' => intval(get_option('dexpress_payment_type', 2)),
            'shipment_type' => intval(get_option('dexpress_shipment_type', 2)),
            'return_doc' => intval(get_option('dexpress_return_doc', 0)),
            'content' => null,
            'total_mass' => 0,
            'note' => null,
            'api_response' => null,
            'is_test' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        $shipment_data = array_merge($defaults, $shipment_data);

        $ordered_data = array(
            'order_id' => $shipment_data['order_id'],
            'reference_id' => $shipment_data['reference_id'],
            'sender_location_id' => $shipment_data['sender_location_id'],
            'split_index' => $shipment_data['split_index'],
            'total_splits' => $shipment_data['total_splits'],
            'status_code' => $shipment_data['status_code'],
            'status_description' => $shipment_data['status_description'],
            'value_in_para' => $shipment_data['value_in_para'],
            'buyout_in_para' => $shipment_data['buyout_in_para'],
            'payment_by' => $shipment_data['payment_by'],
            'payment_type' => $shipment_data['payment_type'],
            'shipment_type' => $shipment_data['shipment_type'],
            'return_doc' => $shipment_data['return_doc'],
            'content' => $shipment_data['content'],
            'total_mass' => $shipment_data['total_mass'],
            'note' => $shipment_data['note'],
            'api_response' => $shipment_data['api_response'],
            'is_test' => $shipment_data['is_test'],
            'created_at' => $shipment_data['created_at'],
            'updated_at' => $shipment_data['updated_at']
        );

        $format = array('%d', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s');

        $result = $wpdb->insert($wpdb->prefix . 'dexpress_shipments', $ordered_data, $format);

        if ($result === false) {
            dexpress_log('[DB] Greška: ' . $wpdb->last_error, 'error');
            return false;
        }

        $this->clear_shipment_cache($shipment_data['order_id']);
        return $wpdb->insert_id;
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

        $defaults = array(
            'package_reference_id' => null,
            'package_index' => 1,
            'total_packages' => 1,
            'mass' => 0,
            'content' => null,
            'dim_x' => null,
            'dim_y' => null,
            'dim_z' => null,
            'v_mass' => null,
            'current_status_id' => null,
            'current_status_name' => null,
            'status_updated_at' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        $package_data = array_merge($defaults, $package_data);

        $result = $wpdb->insert(
            $wpdb->prefix . 'dexpress_packages',
            array(
                'shipment_id' => $package_data['shipment_id'],
                'package_code' => $package_data['package_code'],
                'package_reference_id' => $package_data['package_reference_id'],
                'package_index' => $package_data['package_index'],
                'total_packages' => $package_data['total_packages'],
                'mass' => $package_data['mass'],
                'content' => $package_data['content'],
                'dim_x' => $package_data['dim_x'],
                'dim_y' => $package_data['dim_y'],
                'dim_z' => $package_data['dim_z'],
                'v_mass' => $package_data['v_mass'],
                'current_status_id' => $package_data['current_status_id'],
                'current_status_name' => $package_data['current_status_name'],
                'status_updated_at' => $package_data['status_updated_at'],
                'created_at' => $package_data['created_at'],
                'updated_at' => $package_data['updated_at']
            ),
            array('%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
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
            array('id' => $shipment_id), // PROMENI OVO
            array('%s', '%s', '%s'),
            array('%d') // PROMENI OVO
        ) !== false;

        if ($result) {
            // Dobij order_id iz ID-a
            $order_id = $wpdb->get_var($wpdb->prepare(
                "SELECT order_id FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d", // PROMENI OVO
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

        // ISPRAVKA: Osiguraj da je order_id integer
        if (is_object($order_id)) {
            if (method_exists($order_id, 'get_id')) {
                $order_id = $order_id->get_id();
            } else {
                return null;
            }
        }

        $order_id = intval($order_id);

        if ($order_id <= 0) {
            return null;
        }

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
        wp_cache_delete('dexpress_shipments_' . $order_id);
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
     * Pronađi shipment na osnovu package_code
     */
    public function find_shipment_by_package_code($package_code)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT s.* FROM {$wpdb->prefix}dexpress_shipments s 
         INNER JOIN {$wpdb->prefix}dexpress_packages p ON s.id = p.shipment_id 
         WHERE p.package_code = %s",
            $package_code
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
                '%s', // notification_id
                '%s', // shipment_code
                '%d', // package_id
                '%s', // reference_id
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

    /**
     * Dobija sve pošiljke za određenu narudžbinu sa location podacima
     */
    public function get_shipments_by_order_id($order_id)
    {
        global $wpdb;

        // ISPRAVKA: Osiguraj da je order_id integer
        if (is_object($order_id)) {
            if (method_exists($order_id, 'get_id')) {
                $order_id = $order_id->get_id();
            } else {
                return array(); // Nevaljan objekat
            }
        }

        $order_id = intval($order_id);

        if ($order_id <= 0) {
            return array();
        }

        $cache_key = 'dexpress_shipments_' . $order_id;
        $cached = wp_cache_get($cache_key);

        if (false !== $cached) {
            return $cached;
        }

        $shipments = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, sl.name as location_name 
     FROM {$wpdb->prefix}dexpress_shipments s
     LEFT JOIN {$wpdb->prefix}dexpress_sender_locations sl ON s.sender_location_id = sl.id
     WHERE s.order_id = %d 
     ORDER BY s.split_index ASC, s.created_at ASC",
            $order_id
        ));

        // Dodaj package informacije
        foreach ($shipments as &$shipment) {
            $shipment->packages = $this->get_packages_by_shipment_id($shipment->id);
        }

        wp_cache_set($cache_key, $shipments, '', HOUR_IN_SECONDS);
        return $shipments;
    }
    /**
     * Dobija pakete za određenu pošiljku
     */
    public function get_packages_by_shipment_id($shipment_id)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d ORDER BY created_at ASC",
            $shipment_id
        ));
    }
    /**
     * Dobija informacije o split-ovima za narudžbinu
     */
    public function get_shipment_splits_info($order_id)
    {
        global $wpdb;

        // ISPRAVKA: Osiguraj da je order_id integer
        if (is_object($order_id)) {
            if (method_exists($order_id, 'get_id')) {
                $order_id = $order_id->get_id();
            } else {
                return null;
            }
        }

        $order_id = intval($order_id);

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT 
        COUNT(*) as total_shipments,
        MAX(total_splits) as expected_splits,
        MIN(created_at) as first_created,
        MAX(created_at) as last_created
     FROM {$wpdb->prefix}dexpress_shipments 
     WHERE order_id = %d",
            $order_id
        ));

        return $result;
    }
    /**
     * Proverava da li narudžbina ima multiple shipments
     */
    public function has_multiple_shipments($order_id)
    {
        global $wpdb;

        // ISPRAVKA: Osiguraj da je order_id integer
        if (is_object($order_id)) {
            if (method_exists($order_id, 'get_id')) {
                $order_id = $order_id->get_id();
            } else {
                return false;
            }
        }

        $order_id = intval($order_id);

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));

        return intval($count) > 1;
    }

    /**
     * Dobijanje statistika split pošiljki za dashboard
     */
    public function get_split_shipments_stats()
    {
        global $wpdb;

        return $wpdb->get_results("
        SELECT 
            DATE(created_at) as date,
            COUNT(DISTINCT order_id) as orders_with_splits,
            COUNT(*) as total_shipments,
            AVG(total_splits) as avg_splits_per_order
        FROM {$wpdb->prefix}dexpress_shipments 
        WHERE total_splits > 1
        GROUP BY DATE(created_at)
        ORDER BY date DESC
        LIMIT 30
    ");
    }
    /**
     * Briše pošiljku i povezane pakete
     */
    public function delete_shipment($shipment_id)
    {
        global $wpdb;

        // Prvo dobij order_id za cache clearing
        $shipment = $this->get_shipment($shipment_id);

        // Obriši pakete
        $wpdb->delete(
            $wpdb->prefix . 'dexpress_packages',
            array('shipment_id' => $shipment_id),
            array('%d')
        );

        // Obriši pošiljku
        $result = $wpdb->delete(
            $wpdb->prefix . 'dexpress_shipments',
            array('id' => $shipment_id),
            array('%d')
        );

        if ($result && $shipment) {
            $this->clear_shipment_cache($shipment->order_id);
        }

        return $result !== false;
    }
    /**
     * PACKAGE TRACKING METODE
     */
    public function get_package_by_code($package_code)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE package_code = %s",
            $package_code
        ));
    }

    public function get_shipment_by_package_code($package_code)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT s.* FROM {$wpdb->prefix}dexpress_shipments s 
         INNER JOIN {$wpdb->prefix}dexpress_packages p ON s.id = p.shipment_id 
         WHERE p.package_code = %s",
            $package_code
        ));
    }

    public function update_package_status($package_id, $status_id, $status_name = '')
    {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'dexpress_packages',
            array(
                'current_status_id' => $status_id,
                'current_status_name' => $status_name,
                'status_updated_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $package_id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        ) !== false;
    }
    public function get_package_statuses($package_id)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_statuses 
         WHERE package_id = %d 
         ORDER BY status_date ASC, created_at ASC",
            $package_id
        ));
    }
}
