<?php

/**
 * D Express Sender Locations Service - Popravljena verzija
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
     * Dohvati sve lokacije sa podacima o gradu
     */
    public function get_all_locations()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';
        $towns_table = $wpdb->prefix . 'dexpress_towns';

        $sql = "SELECT l.*, t.display_name as town_name, t.postal_code as town_postal_code
        FROM {$table_name} l 
        LEFT JOIN {$towns_table} t ON l.town_id = t.id 
        WHERE l.is_active = 1 
        ORDER BY l.is_default DESC, l.name ASC";

        $results = $wpdb->get_results($sql);

        if ($wpdb->last_error) {
            error_log('DExpress get_all_locations error: ' . $wpdb->last_error);
            return array();
        }

        return $results;
    }

    /**
     * Dohvati pojedinačnu lokaciju
     */
    public function get_location($location_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';
        $towns_table = $wpdb->prefix . 'dexpress_towns';

        $sql = $wpdb->prepare(
            "SELECT l.*, t.display_name as town_name, t.postal_code as town_postal_code
     FROM {$table_name} l 
     LEFT JOIN {$towns_table} t ON l.town_id = t.id 
     WHERE l.id = %d AND l.is_active = 1",
            $location_id
        );

        $result = $wpdb->get_row($sql);

        if ($wpdb->last_error) {
            error_log('DExpress get_location error: ' . $wpdb->last_error);
            return null;
        }

        return $result;
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
                'address_description' => sanitize_text_field($data['address_description'] ?? ''),
                'is_default' => !empty($data['is_default']) ? 1 : 0,
                'is_active' => 1
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d') // Uklonjen %s za bank_account
        );

        if ($result === false) {
            error_log('DExpress create_location DB error: ' . $wpdb->last_error);
            return new WP_Error('db_error', 'Greška pri kreiranju lokacije: ' . $wpdb->last_error);
        }

        $location_id = $wpdb->insert_id;

        // Log successful creation
        dexpress_log("Kreirana nova sender lokacija ID: {$location_id}", 'info');

        return $location_id;
    }

    /**
     * Ažuriranje lokacije
     */
    public function update_location($location_id, $data)
    {
        global $wpdb;

        if (!$location_id) {
            return new WP_Error('invalid_id', 'Nevaljan ID lokacije');
        }

        $validated = $this->validate_location_data($data);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        // Proverava da li lokacija postoji
        $existing = $this->get_location($location_id);
        if (!$existing) {
            return new WP_Error('not_found', 'Lokacija nije pronađena');
        }

        // Ako je označena kao default, ukloni default sa ostalih
        if (!empty($data['is_default'])) {
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
                'address_description' => sanitize_text_field($data['address_description'] ?? ''),
                'is_default' => !empty($data['is_default']) ? 1 : 0
            ),
            array('id' => $location_id),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d'), 
            array('%d')
        );

        if ($result === false) {
            error_log('DExpress update_location DB error: ' . $wpdb->last_error);
            return new WP_Error('db_error', 'Greška pri ažuriranju lokacije: ' . $wpdb->last_error);
        }

        dexpress_log("Ažurirana sender lokacija ID: {$location_id}", 'info');

        return true;
    }

    /**
     * Postavljanje lokacije kao default
     */
    public function set_as_default($location_id)
    {
        global $wpdb;

        if (!$location_id) {
            return new WP_Error('invalid_id', 'Nevaljan ID lokacije');
        }

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        // Proverava da li lokacija postoji
        $existing = $this->get_location($location_id);
        if (!$existing) {
            return new WP_Error('not_found', 'Lokacija nije pronađena');
        }

        // Prvo ukloni default sa svih lokacija
        $this->clear_default_locations();

        // Postavi novu default lokaciju
        $result = $wpdb->update(
            $table_name,
            array('is_default' => 1),
            array('id' => $location_id),
            array('%d'),
            array('%d')
        );

        if ($result === false) {
            error_log('DExpress set_as_default DB error: ' . $wpdb->last_error);
            return new WP_Error('db_error', 'Greška pri postavljanju default lokacije: ' . $wpdb->last_error);
        }

        dexpress_log("Postavljena default sender lokacija ID: {$location_id}", 'info');

        return true;
    }
    /**
     * Brisanje lokacije (PRAVO brisanje iz baze)
     */
    public function delete_location($location_id)
    {
        global $wpdb;

        if (!$location_id) {
            return new WP_Error('invalid_id', 'Nevaljan ID lokacije');
        }

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        // Proverava da li lokacija postoji
        $existing = $this->get_location($location_id);
        if (!$existing) {
            return new WP_Error('not_found', 'Lokacija nije pronađena');
        }

        error_log("DExpress Delete: Lokacija pronađena - ID: {$location_id}, Name: {$existing->name}, is_default: {$existing->is_default}");

        // Ne dozvoli brisanje glavne lokacije
        if ($existing->is_default == 1) {
            error_log("DExpress Delete: Pokušano brisanje glavne lokacije");
            return new WP_Error('cannot_delete_default', 'Ne možete obrisati glavnu lokaciju. Prvo postavite drugu lokaciju kao glavnu.');
        }

        // Proveri da li postoje aktivne pošiljke sa ovom lokacijom
        $shipments_table = $wpdb->prefix . 'dexpress_shipments';
        $active_shipments = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$shipments_table} WHERE sender_location_id = %d",
            $location_id
        ));

        error_log("DExpress Delete: Pronađeno {$active_shipments} aktivnih pošiljki za lokaciju {$location_id}");

        if ($active_shipments > 0) {
            return new WP_Error(
                'has_active_shipments',
                sprintf(
                    'Ne možete obrisati lokaciju koja ima %d povezanih pošiljki. Prvo rešite te pošiljke.',
                    $active_shipments
                )
            );
        }

        // Proveri da li postoje narudžbine koje koriste ovu lokaciju
        $orders_with_location = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}postmeta 
         WHERE meta_key IN ('_dexpress_used_sender_location_id', '_dexpress_selected_sender_location_id') 
         AND meta_value = %d",
            $location_id
        ));

        if ($orders_with_location > 0) {
            error_log("DExpress Delete: Upozorenje - Brisanje lokacije koja je korišćena u {$orders_with_location} narudžbina/e");
            // Nastavljamo jer su to istorijski podaci
        }

        // KLJUČNO: PRAVO brisanje iz baze umesto soft delete
        error_log("DExpress Delete: Početak pravog brisanja iz baze");

        $deleted = $wpdb->delete(
            $table_name,
            array('id' => $location_id),
            array('%d')
        );

        error_log("DExpress Delete: wpdb->delete rezultat: " . var_export($deleted, true));
        error_log("DExpress Delete: wpdb->last_error: " . $wpdb->last_error);

        if ($deleted === false) {
            error_log('DExpress delete_location DB error: ' . $wpdb->last_error);
            return new WP_Error('db_error', 'Greška pri brisanju iz baze: ' . $wpdb->last_error);
        }

        if ($deleted === 0) {
            error_log('DExpress delete_location: Nijedan red nije obrisan');
            return new WP_Error('not_deleted', 'Lokacija nije pronađena ili već obrisana');
        }

        // Očisti cache
        wp_cache_delete('dexpress_sender_locations', 'dexpress');

        error_log("DExpress Delete: USPEŠNO obrisana lokacija ID: {$location_id} - obrisano {$deleted} redova");
        dexpress_log("STVARNO obrisana sender lokacija ID: {$location_id} od strane korisnika: " . get_current_user_id(), 'info');

        return true;
    }

    /**
     * Dohvati default lokaciju
     */
    public function get_default_location()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';
        $towns_table = $wpdb->prefix . 'dexpress_towns';

        $sql = "SELECT l.*, t.display_name as town_name, t.postal_code as town_postal_code
        FROM {$table_name} l 
        LEFT JOIN {$towns_table} t ON l.town_id = t.id 
        WHERE l.is_default = 1 AND l.is_active = 1 
        LIMIT 1";

        $result = $wpdb->get_row($sql);

        if ($wpdb->last_error) {
            error_log('DExpress get_default_location error: ' . $wpdb->last_error);
            return null;
        }

        return $result;
    }

    /**
     * Validacija podataka lokacije
     */
    private function validate_location_data($data)
    {
        $required_fields = ['name', 'address', 'address_num', 'town_id', 'contact_name', 'contact_phone'];

        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Polje '{$field}' je obavezno");
            }
        }

        // Validacija town_id
        if (!is_numeric($data['town_id']) || intval($data['town_id']) <= 0) {
            return new WP_Error('invalid_town', 'Morate izabrati valjan grad');
        }

        // Proverava da li grad postoji
        global $wpdb;
        $towns_table = $wpdb->prefix . 'dexpress_towns';
        $town_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$towns_table} WHERE id = %d",
            intval($data['town_id'])
        ));

        if (!$town_exists) {
            return new WP_Error('town_not_found', 'Izabrani grad ne postoji');
        }

        // Validacija telefona
        $phone = sanitize_text_field($data['contact_phone']);
        if (!preg_match('/^\+381\d{8,9}$/', $phone)) {
            return new WP_Error('invalid_phone', 'Telefon mora biti u formatu +381XXXXXXXX');
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
            array('is_active' => 1),
            array('%d'),
            array('%d')
        );
    }

    /**
     * Broji aktivne lokacije
     */
    private function get_active_locations_count()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_sender_locations';

        return $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE is_active = 1");
    }
}
