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
            mass int(11) DEFAULT NULL,
            dimensions varchar(100) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY shipment_id (shipment_id),
            KEY package_code (package_code)
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
            coordinates text DEFAULT NULL,
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
        // Učitavanje dbDelta funkcije
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Kreiranje tabela
        foreach ($tables as $table_sql) {
            dbDelta($table_sql);
        }
    }
}
