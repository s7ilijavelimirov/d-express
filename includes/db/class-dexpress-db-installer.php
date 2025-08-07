<?php

/**
 * D Express DB Installer klasa
 * 
 * Klasa za kreiranje potrebnih tabela u bazi podataka
 */

defined('ABSPATH') || exit;

class D_Express_DB_Installer
{

    /**
     * Instalacija tabela
     */
    public function install()
    {
        D_Express_DB::update_shipments_table_schema();
        $this->create_tables();

        $this->migrate_bank_account_removal();
        $this->migrate_sender_data();

        // Dodaj indekse za optimizaciju performansi
        global $wpdb;

        // Proveri da li indeksi već postoje
        $shipments_index_exists = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}dexpress_shipments WHERE Key_name = 'idx_tracking_number'");
        $statuses_index_exists = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}dexpress_statuses WHERE Key_name = 'idx_shipment_code'");

        // Dodaj indekse ako ne postoje
        if (empty($shipments_index_exists)) {
            $wpdb->query("CREATE INDEX idx_tracking_number ON {$wpdb->prefix}dexpress_shipments(tracking_number)");
        }

        if (empty($statuses_index_exists)) {
            $wpdb->query("CREATE INDEX idx_shipment_code ON {$wpdb->prefix}dexpress_statuses(shipment_code)");
            $wpdb->query("CREATE INDEX idx_reference_id ON {$wpdb->prefix}dexpress_statuses(reference_id)");
        }
    }
    /**
     * DODAJ ovu novu metodu u klasu
     */
    private function migrate_sender_data()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        // Proveri da li već postoje lokacije
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        if ($existing > 0) {
            return; // Već migráno
        }

        // Uzmi postojeće podatke iz options
        $sender_name = get_option('dexpress_sender_name', '');
        $sender_address = get_option('dexpress_sender_address', '');
        $sender_address_num = get_option('dexpress_sender_address_num', '');
        $sender_town_id = get_option('dexpress_sender_town_id', 0);
        $sender_contact_name = get_option('dexpress_sender_contact_name', '');
        $sender_contact_phone = get_option('dexpress_sender_contact_phone', '');

        // Kreiraj glavnu lokaciju ako postoje podaci
        if (!empty($sender_name)) {
            $wpdb->insert($table_name, [
                'name' => $sender_name,
                'address' => $sender_address,
                'address_num' => $sender_address_num,
                'town_id' => intval($sender_town_id),
                'contact_name' => $sender_contact_name,
                'contact_phone' => $sender_contact_phone,
                'is_default' => 1,
                'is_active' => 1
                // UKLONIO: bank_account liniju
            ]);

            if (function_exists('dexpress_log')) {
                dexpress_log('Migrirani podaci pošiljaoca u sender_locations', 'info');
            }
        }
    }
    /**
     * NOVA METODA - Uklanja bank_account kolonu iz sender_locations
     */
    private function migrate_bank_account_removal()
    {
        global $wpdb;

        $current_version = get_option('dexpress_db_version', '1.0');

        if (version_compare($current_version, '1.1', '<')) {
            dexpress_log('Starting database migration 1.1: Removing bank_account from sender_locations', 'info');

            $table_name = $wpdb->prefix . 'dexpress_sender_locations';

            // Proveri da li tabela postoji
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

            if ($table_exists) {
                // Proveri da li kolona postoji
                $column_exists = $wpdb->get_results($wpdb->prepare(
                    "SHOW COLUMNS FROM {$table_name} LIKE %s",
                    'bank_account'
                ));

                if (!empty($column_exists)) {
                    // Pre brisanja, migriraj podatke u globalne settings ako je potrebno
                    $existing_accounts = $wpdb->get_results(
                        "SELECT DISTINCT bank_account FROM {$table_name} WHERE bank_account IS NOT NULL AND bank_account != ''"
                    );

                    if (!empty($existing_accounts)) {
                        // Uzmi prvi pronađeni račun kao globalni (ako globalni ne postoji)
                        $global_account = get_option('dexpress_buyout_account', '');
                        if (empty($global_account) && !empty($existing_accounts[0]->bank_account)) {
                            update_option('dexpress_buyout_account', $existing_accounts[0]->bank_account);
                            dexpress_log('Migrated bank account to global settings: ' . $existing_accounts[0]->bank_account, 'info');
                        }
                    }

                    // Sada ukloni kolonu
                    $result = $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN bank_account");

                    if ($result !== false) {
                        dexpress_log('Successfully removed bank_account column from sender_locations', 'info');
                    } else {
                        dexpress_log('Error removing bank_account column: ' . $wpdb->last_error, 'error');
                    }
                } else {
                    dexpress_log('bank_account column does not exist in sender_locations', 'debug');
                }
            }

            // Ažuriraj verziju baze
            update_option('dexpress_db_version', '1.1');
            dexpress_log('Database migration 1.1 completed', 'info');
        }
    }
    /**
     * Kreiranje potrebnih tabela
     */
    private function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Niz SQL upita za kreiranje tabela
        $tables = array();

        // 1. Tabela za pošiljke
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_shipments (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            shipment_id varchar(50) NOT NULL,
            tracking_number varchar(50) NOT NULL,
            reference_id varchar(100) NOT NULL,
            status_code varchar(20) DEFAULT NULL,
            status_description varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            shipment_data longtext DEFAULT NULL,
            is_test tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY shipment_id (shipment_id),
            KEY tracking_number (tracking_number),
            KEY reference_id (reference_id),
            KEY status_code (status_code),
            KEY created_at (created_at)
        ) $charset_collate;";

        // 2. Tabela za pakete
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_packages (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            shipment_id bigint(20) NOT NULL,
            package_code varchar(50) NOT NULL,
            package_reference_id varchar(100) DEFAULT NULL,
            package_index int(11) DEFAULT NULL,
            total_packages int(11) DEFAULT NULL,
            mass int(11) DEFAULT NULL,
            dim_x int(11) DEFAULT NULL,
            dim_y int(11) DEFAULT NULL,
            dim_z int(11) DEFAULT NULL,
            v_mass int(11) DEFAULT NULL,
            dimensions varchar(100) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY shipment_id (shipment_id),
            KEY package_code (package_code),
            KEY package_reference_id (package_reference_id)
        ) $charset_collate;";

        // 3. Tabela za statuse pošiljki
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_statuses (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            shipment_id varchar(50) DEFAULT NULL,
            notification_id varchar(100) NOT NULL,
            reference_id varchar(100) DEFAULT NULL,
            shipment_code varchar(50) DEFAULT NULL,
            status_id varchar(20) DEFAULT NULL,
            status_date datetime DEFAULT NULL,
            raw_data longtext DEFAULT NULL,
            is_processed tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY notification_id (notification_id),
            KEY shipment_id (shipment_id),
            KEY reference_id (reference_id),
            KEY status_id (status_id),
            KEY status_date (status_date)
        ) $charset_collate;";

        // 4. Tabela za šifarnik statusa pošiljki
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_statuses_index (
            id int(11) NOT NULL,
            name varchar(100) NOT NULL,
            description varchar(255) DEFAULT NULL,
            last_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY name (name)
        ) $charset_collate;";

        // 5. Tabela za šifarnik opština
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_municipalities (
           id int(11) NOT NULL,
            name varchar(100) NOT NULL,
            ptt_no int(11) DEFAULT NULL,
            order_num int(11) DEFAULT NULL,
            last_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY name (name)
        ) $charset_collate;";

        // 6. Tabela za šifarnik gradova
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_towns (
            id INT(11) NOT NULL,
            name VARCHAR(100) NOT NULL,
            display_name VARCHAR(100) DEFAULT NULL,
            center_id INT(11) DEFAULT NULL,
            municipality_id INT(11) DEFAULT NULL,
            postal_code INT(11) DEFAULT NULL,
            order_num INT(11) DEFAULT NULL,
            delivery_days VARCHAR(100) DEFAULT NULL,
            cut_off_pickup_time VARCHAR(100) DEFAULT NULL,
            last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // 7. Tabela za šifarnik ulica
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_streets (
            id int(11) NOT NULL,
            name varchar(100) NOT NULL,
            TId int(11) NOT NULL,
            deleted TINYINT(1) NOT NULL DEFAULT 0,
            last_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY name (name),
            KEY town_id (TId)
        ) $charset_collate;";

        // 8. Tabela za lokacije i prodavnice
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_locations (
            id int(11) NOT NULL,
            name varchar(100) NOT NULL,
            description varchar(255) DEFAULT NULL,
            address varchar(100) DEFAULT NULL,
            town varchar(50) DEFAULT NULL,
            town_id int(11) DEFAULT NULL,
            working_hours varchar(100) DEFAULT NULL,
            work_hours varchar(100) DEFAULT NULL,
            work_days varchar(100) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            latitude varchar(20) DEFAULT NULL,
            longitude varchar(20) DEFAULT NULL,
            location_type varchar(20) DEFAULT NULL,
            pay_by_cash tinyint(1) DEFAULT 0,
            pay_by_card tinyint(1) DEFAULT 0,
            last_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY town_id (town_id),
            KEY location_type (location_type)
        ) $charset_collate;";

        // 9. Tabela za automate za pakete
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_dispensers (
            id int(11) NOT NULL,
            name varchar(100) NOT NULL,
            address varchar(100) DEFAULT NULL,
            town varchar(50) DEFAULT NULL,
            town_id int(11) DEFAULT NULL,
            work_hours varchar(100) DEFAULT NULL,
            work_days varchar(100) DEFAULT NULL,
            latitude DECIMAL(10, 8) DEFAULT NULL,
            longitude DECIMAL(11, 8) DEFAULT NULL,
            pay_by_cash tinyint(1) NOT NULL DEFAULT 0,
            pay_by_card tinyint(1) NOT NULL DEFAULT 0,
            deleted tinyint(1) NOT NULL DEFAULT 0,
            last_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY town_id (town_id)
        ) $charset_collate;";
        // 10. Tabela za prodavnice
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_shops (
            id int(11) NOT NULL,
            name varchar(100) NOT NULL,
            description varchar(255) DEFAULT NULL,
            address varchar(100) DEFAULT NULL,
            town varchar(50) DEFAULT NULL,
            town_id int(11) DEFAULT NULL,
            working_hours varchar(100) DEFAULT NULL,
            work_days varchar(100) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            latitude varchar(20) DEFAULT NULL,
            longitude varchar(20) DEFAULT NULL,
            location_type varchar(20) DEFAULT NULL,
            pay_by_cash tinyint(1) DEFAULT 0,
            pay_by_card tinyint(1) DEFAULT 0,
            last_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY town_id (town_id),
            KEY location_type (location_type)
        ) $charset_collate;";

        // 11.Tabela za regionalne centre
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_centres (
            id int(11) NOT NULL,
            name varchar(100) NOT NULL,
            prefix varchar(50) DEFAULT NULL,
            address varchar(100) DEFAULT NULL,
            town varchar(50) DEFAULT NULL,
            town_id int(11) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            latitude varchar(20) DEFAULT NULL,
            longitude varchar(20) DEFAULT NULL,
            working_hours varchar(100) DEFAULT NULL,
            work_hours varchar(100) DEFAULT NULL,
            work_days varchar(100) DEFAULT NULL,
            last_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY town_id (town_id)
        ) $charset_collate;";

        // 12. Tabela za lokacije pošiljaoca (NOVA!)
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_sender_locations (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL COMMENT 'Naziv lokacije/prodavnice',
            address varchar(255) NOT NULL COMMENT 'Naziv ulice',
            address_num varchar(20) NOT NULL COMMENT 'Kućni broj',
            town_id int(11) NOT NULL COMMENT 'ID grada iz dexpress_towns',
            contact_name varchar(255) NOT NULL COMMENT 'Ime kontakt osobe',
            contact_phone varchar(20) NOT NULL COMMENT 'Telefon (+381...)',
            is_default tinyint(1) DEFAULT 0 COMMENT 'Glavna lokacija',
            is_active tinyint(1) DEFAULT 1 COMMENT 'Aktivna lokacija',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_is_default (is_default),
            KEY idx_is_active (is_active),
            KEY idx_town_id (town_id)
        ) $charset_collate;";
        // Učitavanje dbDelta funkcije
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Kreiranje tabela
        foreach ($tables as $table_sql) {
            dbDelta($table_sql);
        }
    }
}
