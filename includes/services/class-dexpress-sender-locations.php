<?php

/**
 * D Express Sender Locations Service
 * File: includes/services/class-dexpress-sender-locations.php
 */

defined('ABSPATH') || exit;

class D_Express_Sender_Locations
{

    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Kreiranje nove lokacije
     */
    public function create_location($data)
    {
        global $wpdb;

        $validated = $this->validate_location_data($data);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        // Ako je označena kao default, ukloni default sa ostalih
        if (!empty($data['is_default'])) {
            $this->clear_default_locations();
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => sanitize_text_field($data['name']),
                'address' => sanitize_text_field($data['address']),
                'address_num' => sanitize_text_field($data['address_num']),
                'town_id' => intval($data['town_id']),
                'contact_name' => sanitize_text_field($data['contact_name']),
                'contact_phone' => sanitize_text_field($data['contact_phone']),
                'bank_account' => sanitize_text_field($data['bank_account'] ?? ''),
                'is_default' => !empty($data['is_default']) ? 1 : 0,
                'is_active' => 1
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Greška pri kreiranju lokacije: ' . $wpdb->last_error);
        }

        $location_id = $wpdb->insert_id;
        dexpress_log("Kreirana nova sender lokacija ID: {$location_id}", 'info');

        return $location_id;
    }

    /**
     * Ažuriranje lokacije
     */
    public function update_location($location_id, $data)
    {
        global $wpdb;

        $validated = $this->validate_location_data($data);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        // Proveri da li lokacija postoji
        $location = $this->get_location($location_id);
        if (!$location) {
            return new WP_Error('not_found', 'Lokacija nije pronađena');
        }

        // Ako je označena kao default, ukloni default sa ostalih
        if (!empty($data['is_default']) && !$location->is_default) {
            $this->clear_default_locations();
        }

        $result = $wpdb->update(
            $table_name,
            array(
                'name' => sanitize_text_field($data['name']),
                'address' => sanitize_text_field($data['address']),
                'address_num' => sanitize_text_field($data['address_num']),
                'town_id' => intval($data['town_id']),
                'contact_name' => sanitize_text_field($data['contact_name']),
                'contact_phone' => sanitize_text_field($data['contact_phone']),
                'bank_account' => sanitize_text_field($data['bank_account'] ?? ''),
                'is_default' => !empty($data['is_default']) ? 1 : 0
            ),
            array('id' => intval($location_id)),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d'),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Greška pri ažuriranju lokacije: ' . $wpdb->last_error);
        }

        dexpress_log("Ažurirana sender lokacija ID: {$location_id}", 'info');
        return true;
    }

    /**
     * Brisanje lokacije
     */
    public function delete_location($location_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        $location = $this->get_location($location_id);
        if (!$location) {
            return new WP_Error('not_found', 'Lokacija nije pronađena');
        }

        // Ne dozvoli brisanje default lokacije ako je jedina
        if ($location->is_default) {
            $total_locations = $this->get_locations_count();
            if ($total_locations <= 1) {
                return new WP_Error('cannot_delete_last', 'Ne možete obrisati poslednju lokaciju');
            }
        }

        $result = $wpdb->delete(
            $table_name,
            array('id' => intval($location_id)),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Greška pri brisanju lokacije: ' . $wpdb->last_error);
        }

        // Ako je obrisana default lokacija, postavi prvu aktivnu kao default
        if ($location->is_default) {
            $this->set_first_active_as_default();
        }

        dexpress_log("Obrisana sender lokacija ID: {$location_id}", 'info');
        return true;
    }

    /**
     * Dohvatanje jedne lokacije
     */
    public function get_location($location_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND is_active = 1",
            intval($location_id)
        ));
    }

    /**
     * Dohvatanje svih aktivnih lokacija
     */
    public function get_all_locations($include_inactive = false)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        $where_clause = $include_inactive ? '' : 'WHERE is_active = 1';

        return $wpdb->get_results(
            "SELECT sl.*, t.name as town_name 
             FROM $table_name sl 
             LEFT JOIN {$wpdb->prefix}dexpress_towns t ON sl.town_id = t.id 
             $where_clause 
             ORDER BY sl.is_default DESC, sl.name ASC"
        );
    }

    /**
     * Dohvatanje default lokacije
     */
    public function get_default_location()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        return $wpdb->get_row(
            "SELECT sl.*, t.name as town_name 
             FROM $table_name sl 
             LEFT JOIN {$wpdb->prefix}dexpress_towns t ON sl.town_id = t.id 
             WHERE sl.is_default = 1 AND sl.is_active = 1 
             LIMIT 1"
        );
    }

    /**
     * Postavljanje lokacije kao default
     */
    public function set_as_default($location_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        $location = $this->get_location($location_id);
        if (!$location) {
            return new WP_Error('not_found', 'Lokacija nije pronađena');
        }

        // Ukloni default sa svih
        $this->clear_default_locations();

        // Postavi novu default
        $result = $wpdb->update(
            $table_name,
            array('is_default' => 1),
            array('id' => intval($location_id)),
            array('%d'),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Greška pri postavljanju default lokacije');
        }

        dexpress_log("Postavljena default sender lokacija ID: {$location_id}", 'info');
        return true;
    }

    /**
     * Broj aktivnih lokacija
     */
    public function get_locations_count()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        return intval($wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE is_active = 1"
        ));
    }

    /**
     * Validacija podataka lokacije
     */
    private function validate_location_data($data)
    {
        $errors = new WP_Error();

        if (empty($data['name'])) {
            $errors->add('empty_name', 'Naziv lokacije je obavezan');
        }

        if (empty($data['address'])) {
            $errors->add('empty_address', 'Adresa je obavezna');
        }

        if (empty($data['address_num'])) {
            $errors->add('empty_address_num', 'Broj adrese je obavezan');
        }

        if (empty($data['town_id']) || !is_numeric($data['town_id'])) {
            $errors->add('invalid_town', 'Grad mora biti izabran');
        }

        if (empty($data['contact_name'])) {
            $errors->add('empty_contact_name', 'Kontakt osoba je obavezna');
        }

        if (empty($data['contact_phone'])) {
            $errors->add('empty_contact_phone', 'Kontakt telefon je obavezan');
        } elseif (class_exists('D_Express_Validator') && !D_Express_Validator::validate_phone($data['contact_phone'])) {
            $errors->add('invalid_phone', 'Neispravan format telefona (+381XXXXXXXXX)');
        }

        if ($errors->get_error_codes()) {
            return $errors;
        }

        return true;
    }

    /**
     * Ukloni default sa svih lokacija
     */
    private function clear_default_locations()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        $wpdb->update(
            $table_name,
            array('is_default' => 0),
            array('is_default' => 1),
            array('%d'),
            array('%d')
        );
    }

    /**
     * Postavi prvu aktivnu lokaciju kao default
     */
    private function set_first_active_as_default()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        $first_location = $wpdb->get_row(
            "SELECT * FROM $table_name WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
        );

        if ($first_location) {
            $wpdb->update(
                $table_name,
                array('is_default' => 1),
                array('id' => $first_location->id),
                array('%d'),
                array('%d')
            );
        }
    }
}
