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

        // Dodaj default vrednosti za nove kolone ako ne postoje
        $defaults = array(
            'package_code' => null,
            'split_index' => null,
            'total_splits' => null,
            'parent_order_id' => null,
            'status_code' => null,
            'status_description' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'shipment_data' => null,
            'is_test' => 0
        );

        // Spoji sa default vrednostima
        $shipment_data = array_merge($defaults, $shipment_data);

        // Definiši redosled kolona prema strukturi tabele
        $ordered_data = array(
            'order_id' => $shipment_data['order_id'],
            'shipment_id' => $shipment_data['shipment_id'],
            'tracking_number' => $shipment_data['tracking_number'],
            'package_code' => $shipment_data['package_code'],
            'reference_id' => $shipment_data['reference_id'],
            'sender_location_id' => $shipment_data['sender_location_id'],
            'split_index' => $shipment_data['split_index'],
            'total_splits' => $shipment_data['total_splits'],
            'parent_order_id' => $shipment_data['parent_order_id'],
            'status_code' => $shipment_data['status_code'],
            'status_description' => $shipment_data['status_description'],
            'created_at' => $shipment_data['created_at'],
            'updated_at' => $shipment_data['updated_at'],
            'shipment_data' => $shipment_data['shipment_data'],
            'is_test' => $shipment_data['is_test']
        );

        // Format specifiers u istom redosledu
        $format = array(
            '%d', // order_id
            '%s', // shipment_id
            '%s', // tracking_number
            '%s', // package_code
            '%s', // reference_id
            '%d', // sender_location_id
            '%d', // split_index
            '%d', // total_splits
            '%d', // parent_order_id
            '%s', // status_code
            '%s', // status_description
            '%s', // created_at
            '%s', // updated_at
            '%s', // shipment_data
            '%d'  // is_test
        );

        // Debug log
        dexpress_log('[DB] Čuvanje pošiljke: ' . print_r($ordered_data, true), 'debug');

        $result = $wpdb->insert(
            $wpdb->prefix . 'dexpress_shipments',
            $ordered_data,
            $format
        );

        if ($result === false) {
            dexpress_log('[DB] Greška pri čuvanju pošiljke: ' . $wpdb->last_error, 'error');
            return false;
        }

        $insert_id = $wpdb->insert_id;
        dexpress_log('[DB] Pošiljka sačuvana sa ID: ' . $insert_id, 'info');

        // Očisti cache
        $this->clear_shipment_cache($shipment_data['order_id']);

        return $insert_id;
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
    /**
     * Ažurira schema tabele za sender_location_id kolonu
     */
    public static function update_shipments_table_schema()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_shipments';

        // Proveri da li kolona postoji
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$table_name} LIKE %s",
            'sender_location_id'
        ));

        // Ako ne postoji, dodaj je
        if (empty($column_exists)) {
            $sql = "ALTER TABLE {$table_name} ADD COLUMN sender_location_id INT(11) NULL AFTER reference_id";

            $result = $wpdb->query($sql);

            if ($result !== false) {
                error_log('DExpress: Dodana sender_location_id kolona u shipments tabelu');

                // Dodaj i index za bolje performanse
                $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_sender_location_id (sender_location_id)");
            } else {
                error_log('DExpress: Greška pri dodavanju sender_location_id kolone: ' . $wpdb->last_error);
            }
        }
    }
    public static function update_multiple_shipments_schema()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_shipments';

        // Dodaj kolone za split shipments ako ne postoje
        $columns_to_add = array(
            'split_index' => "ADD COLUMN split_index INT(11) NULL DEFAULT NULL AFTER sender_location_id",
            'total_splits' => "ADD COLUMN total_splits INT(11) NULL DEFAULT NULL AFTER split_index",
            'parent_order_id' => "ADD COLUMN parent_order_id INT(11) NULL DEFAULT NULL AFTER total_splits"
        );

        foreach ($columns_to_add as $column => $sql) {
            // Proveri da li kolona postoji
            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SHOW COLUMNS FROM {$table_name} LIKE %s",
                $column
            ));

            if (empty($column_exists)) {
                $result = $wpdb->query("ALTER TABLE {$table_name} {$sql}");

                if ($result !== false) {
                    error_log("DExpress: Dodana {$column} kolona u shipments tabelu");
                } else {
                    error_log("DExpress: Greška pri dodavanju {$column} kolone: " . $wpdb->last_error);
                }
            }
        }

        // Dodaj indekse za bolje performanse
        $indexes_to_add = array(
            'idx_parent_order_id' => "ADD INDEX idx_parent_order_id (parent_order_id)",
            'idx_split_info' => "ADD INDEX idx_split_info (order_id, split_index)"
        );

        foreach ($indexes_to_add as $index_name => $sql) {
            // Proveri da li indeks postoji
            $index_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = '{$index_name}'");

            if (empty($index_exists)) {
                $result = $wpdb->query("ALTER TABLE {$table_name} {$sql}");

                if ($result !== false) {
                    error_log("DExpress: Dodan {$index_name} indeks u shipments tabelu");
                } else {
                    error_log("DExpress: Greška pri dodavanju {$index_name} indeksa: " . $wpdb->last_error);
                }
            }
        }
    }
    /**
     * Dobija sve pošiljke za određenu narudžbinu sa location podacima
     */
    public function get_shipments_by_order_id($order_id)
    {
        global $wpdb;

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
     * Ažurira schema tabele za package_code kolonu
     */
    public static function update_package_code_schema()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_shipments';

        // Proveri da li kolona postoji
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$table_name} LIKE %s",
            'package_code'
        ));

        // Ako ne postoji, dodaj je
        if (empty($column_exists)) {
            $sql = "ALTER TABLE {$table_name} ADD COLUMN package_code VARCHAR(50) DEFAULT NULL AFTER tracking_number";

            $result = $wpdb->query($sql);

            if ($result !== false) {
                error_log('DExpress: Dodana package_code kolona u shipments tabelu');

                // Dodaj i index za bolje performanse
                $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_package_code (package_code)");
            } else {
                error_log('DExpress: Greška pri dodavanju package_code kolone: ' . $wpdb->last_error);
            }
        }
    }
}
