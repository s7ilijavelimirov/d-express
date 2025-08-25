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

        $this->create_tables();
        $this->migrate_sender_data();
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
            ]);

            if (function_exists('dexpress_log')) {
                dexpress_log('Migrirani podaci pošiljaoca u sender_locations', 'info');
            }
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
            reference_id varchar(100) NOT NULL,
            sender_location_id int(11) DEFAULT NULL,
            
            split_index int(11) DEFAULT NULL,
            total_splits int(11) DEFAULT NULL,
            
            status_code varchar(20) DEFAULT NULL,
            status_description varchar(255) DEFAULT NULL,
            
            value_in_para int(11) DEFAULT 0,
            buyout_in_para int(11) DEFAULT 0,
            payment_by tinyint(1) DEFAULT 0,
            payment_type tinyint(1) DEFAULT 2,
            shipment_type tinyint(1) DEFAULT 2,
            return_doc tinyint(1) DEFAULT 0,
            content varchar(50) DEFAULT NULL,
            total_mass int(11) DEFAULT 0,
            note varchar(150) DEFAULT NULL,
            
            api_response varchar(20) DEFAULT NULL,
            is_test tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY reference_id (reference_id),
            KEY order_id (order_id),
            KEY sender_location_id (sender_location_id)
        ) $charset_collate;";
        // 2. Tabela za pakete
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_packages (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            shipment_id bigint(20) NOT NULL,
            package_code varchar(50) NOT NULL,
            package_reference_id varchar(100) DEFAULT NULL,
            package_index int(11) DEFAULT 1,
            total_packages int(11) DEFAULT 1,             
            mass int(11) DEFAULT NULL,
            content varchar(50) DEFAULT NULL,
            dim_x int(11) DEFAULT NULL,
            dim_y int(11) DEFAULT NULL,
            dim_z int(11) DEFAULT NULL,
            v_mass int(11) DEFAULT NULL,
            current_status_id varchar(20) DEFAULT NULL,
            current_status_name varchar(100) DEFAULT NULL,
            status_updated_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           PRIMARY KEY (id),
            UNIQUE KEY package_code (package_code),
            KEY shipment_id (shipment_id),
            KEY current_status_id (current_status_id)
        ) $charset_collate;";

        // 3. Tabela za statuse pošiljki
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_statuses (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            notification_id varchar(100) NOT NULL,
            shipment_code varchar(50) NOT NULL,
            package_id bigint(20) DEFAULT NULL,
            reference_id varchar(100) DEFAULT NULL,
            status_id varchar(20) DEFAULT NULL,
            status_date datetime DEFAULT NULL,
            raw_data longtext DEFAULT NULL,
            is_processed tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY notification_id (notification_id),
            KEY shipment_code (shipment_code),
            KEY package_id (package_id),
            KEY reference_id (reference_id),
            KEY status_id (status_id)
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
            order_num INT(11) DEFAULT NULL,
            postal_code varchar(20) DEFAULT NULL,  -- IZMENA: varchar umesto int
            delivery_days VARCHAR(50) DEFAULT NULL,
            cut_off_pickup_time VARCHAR(50) DEFAULT NULL,
            last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY center_id (center_id),
            KEY municipality_id (municipality_id)
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
        // Tabela za payments
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_payments (
            id int(11) NOT NULL AUTO_INCREMENT,
            payment_reference varchar(100) NOT NULL COMMENT 'Referenca sa bankovnog izvoda',
            shipment_code varchar(50) NOT NULL COMMENT 'D Express kod pošiljke',
            buyout_amount int(11) NOT NULL COMMENT 'Iznos otkupnine u parama',
            reference_id varchar(50) NOT NULL COMMENT 'Naš order ID',
            receiver_name varchar(255) DEFAULT NULL COMMENT 'Ime primaoca',
            receiver_address varchar(255) DEFAULT NULL COMMENT 'Adresa primaoca', 
            receiver_town varchar(100) DEFAULT NULL COMMENT 'Grad primaoca',
            payment_date date NOT NULL COMMENT 'Datum plaćanja',
            imported_at datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'Kada je uvezeno',
            processed tinyint(1) DEFAULT 0 COMMENT 'Da li je obrađeno',
            PRIMARY KEY (id),
            KEY idx_payment_reference (payment_reference),
            KEY idx_reference_id (reference_id),
            KEY idx_payment_date (payment_date)
        ) $charset_collate;";
        // 13. Tabela za lokacije pošiljaoca
        $tables[] = "CREATE TABLE {$wpdb->prefix}dexpress_sender_locations (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL COMMENT 'Naziv lokacije/prodavnice',
            address varchar(255) NOT NULL COMMENT 'Naziv ulice',
            address_num varchar(20) NOT NULL COMMENT 'Kućni broj',
            address_description varchar(50) DEFAULT '' COMMENT 'Dodatni opis adrese',
            town_id int(11) NOT NULL COMMENT 'ID grada iz dexpress_towns',
            town_name varchar(100) DEFAULT NULL COMMENT 'Naziv grada',
            town_postal_code varchar(20) DEFAULT NULL COMMENT 'Poštanski kod',
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
