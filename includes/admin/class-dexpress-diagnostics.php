<?php

/**
 * D Express dijagnostički sistem
 * 
 * Proverava da li su svi preduslovi za rad plugin-a ispunjeni
 */

// Klasa za dijagnostiku
class D_Express_Diagnostics
{

    // Svojstva za čuvanje rezultata
    private $results = array();
    private $is_success = true;
    private $critical_issues = 0;
    private $warnings = 0;

    /**
     * Pokreće sve dijagnostičke testove
     * 
     * @return array Rezultati testova
     */
    public function run_diagnostics()
    {
        // Resetujemo stanje
        $this->results = array();
        $this->is_success = true;
        $this->critical_issues = 0;
        $this->warnings = 0;

        // Pokrenemo sve testove
        $this->check_php_version();
        $this->check_wordpress_version();
        $this->check_woocommerce();
        $this->check_server_requirements();
        $this->check_api_credentials();
        $this->check_api_connection();
        $this->check_file_permissions();
        $this->check_ssl();
        $this->check_conflicting_plugins();
        $this->check_indexes_data();

        // Dodajemo zbirne informacije
        $this->results['summary'] = array(
            'is_success' => $this->is_success,
            'critical_issues' => $this->critical_issues,
            'warnings' => $this->warnings
        );

        return $this->results;
    }

    /**
     * Provera PHP verzije
     */
    private function check_php_version()
    {
        $required_version = '7.3.0';
        $current_version = PHP_VERSION;
        $status = version_compare($current_version, $required_version, '>=');

        $message = 'PHP verzija: ' . $current_version;
        $details = '';

        if (!$status) {
            $this->is_success = false;
            $this->critical_issues++;
            $details = 'D Express zahteva PHP verziju ' . $required_version . ' ili noviju. Kontaktirajte vašeg hosting provajdera za nadogradnju.';
        }

        $this->add_result('php_version', $status, $message, $details, 'critical');
    }

    /**
     * Provera WordPress verzije
     */
    private function check_wordpress_version()
    {
        global $wp_version;
        $required_version = '5.6';
        $status = version_compare($wp_version, $required_version, '>=');

        $message = 'WordPress verzija: ' . $wp_version;
        $details = '';

        if (!$status) {
            $this->is_success = false;
            $this->critical_issues++;
            $details = 'D Express zahteva WordPress verziju ' . $required_version . ' ili noviju. Molimo nadogradite vaš WordPress.';
        }

        $this->add_result('wordpress_version', $status, $message, $details, 'critical');
    }

    /**
     * Provera WooCommerce-a
     */
    private function check_woocommerce()
    {
        $status = class_exists('WooCommerce');
        $message = 'WooCommerce';
        $details = '';

        if ($status) {
            $wc_version = WC()->version;
            $required_version = '5.0.0';
            $version_status = version_compare($wc_version, $required_version, '>=');

            $message .= ' verzija: ' . $wc_version;

            if (!$version_status) {
                $status = false;
                $this->is_success = false;
                $this->critical_issues++;
                $details = 'D Express zahteva WooCommerce verziju ' . $required_version . ' ili noviju. Molimo nadogradite WooCommerce.';
            }
        } else {
            $this->is_success = false;
            $this->critical_issues++;
            $details = 'WooCommerce nije instaliran ili aktiviran. D Express radi samo sa WooCommerce-om.';
        }

        $this->add_result('woocommerce', $status, $message, $details, 'critical');
    }

    /**
     * Provera zahteva servera
     */
    private function check_server_requirements()
    {
        // cURL provera
        $curl_status = function_exists('curl_version');
        $curl_message = 'cURL ekstenzija: ' . ($curl_status ? 'Omogućena' : 'Nedostaje');
        $curl_details = '';

        if (!$curl_status) {
            $this->is_success = false;
            $this->critical_issues++;
            $curl_details = 'D Express zahteva cURL PHP ekstenziju za komunikaciju sa API-em. Kontaktirajte vašeg hosting provajdera.';
        }

        $this->add_result('curl', $curl_status, $curl_message, $curl_details, 'critical');

        // JSON provera
        $json_status = function_exists('json_encode') && function_exists('json_decode');
        $json_message = 'JSON ekstenzija: ' . ($json_status ? 'Omogućena' : 'Nedostaje');
        $json_details = '';

        if (!$json_status) {
            $this->is_success = false;
            $this->critical_issues++;
            $json_details = 'D Express zahteva JSON PHP ekstenziju za obradu API odgovora. Kontaktirajte vašeg hosting provajdera.';
        }

        $this->add_result('json', $json_status, $json_message, $json_details, 'critical');

        // Memorijski limit
        $memory_limit = ini_get('memory_limit');
        $required_memory = '64M';
        $memory_status = $this->is_memory_enough($memory_limit, $required_memory);
        $memory_message = 'PHP memorijski limit: ' . $memory_limit;
        $memory_details = '';

        if (!$memory_status) {
            $this->warnings++;
            $memory_details = 'Preporučeni memorijski limit je najmanje ' . $required_memory . '. Trenutni limit može biti nedovoljan za obradu većeg broja pošiljki.';
        }

        $this->add_result('memory_limit', $memory_status, $memory_message, $memory_details, 'warning');

        // Max execution time
        $execution_time = ini_get('max_execution_time');
        $execution_status = ($execution_time >= 30 || $execution_time == 0);
        $execution_message = 'PHP max execution time: ' . ($execution_time == 0 ? 'Neograničeno' : $execution_time . ' sekundi');
        $execution_details = '';

        if (!$execution_status) {
            $this->warnings++;
            $execution_details = 'Preporučeno vreme izvršavanja je najmanje 30 sekundi. Trenutna vrednost može izazvati timeout prilikom komunikacije sa API-em.';
        }

        $this->add_result('execution_time', $execution_status, $execution_message, $execution_details, 'warning');
    }

    /**
     * Provera API kredencijala
     */
    private function check_api_credentials()
    {
        $api_username = get_option('dexpress_api_username', '');
        $api_password = get_option('dexpress_api_password', '');
        $client_id = get_option('dexpress_client_id', '');

        $status = !empty($api_username) && !empty($api_password) && !empty($client_id);
        $message = 'API kredencijali';
        $details = '';

        if (!$status) {
            $this->is_success = false;
            $this->critical_issues++;
            $details = 'API kredencijali nisu konfigurisani. Molimo unesite korisničko ime, lozinku i client ID dobijene od D Express-a.';
        }

        $this->add_result('api_credentials', $status, $message, $details, 'critical');
    }

    /**
     * Provera API konekcije - ispravljena verzija
     */
    private function check_api_connection()
    {
        $api = new D_Express_API();

        if (!$api->has_credentials()) {
            $status = false;
            $message = 'API konekcija: Nedostaju kredencijali';
            $details = 'Nije moguće testirati konekciju bez API kredencijala.';

            $this->add_result('api_connection', $status, $message, $details, 'warning');
            return;
        }

        // Koristimo get_statuses direktno za test
        $test_result = $api->get_statuses();
        $status = !is_wp_error($test_result);

        $message = 'API konekcija: ' . ($status ? 'Uspešna' : 'Neuspešna');
        $details = '';

        if (!$status) {
            $this->is_success = false;
            $this->critical_issues++;
            $details = 'Nije moguće uspostaviti konekciju sa D Express API-em. Greška: ' . $test_result->get_error_message();
        }

        $this->add_result('api_connection', $status, $message, $details, 'critical');
    }

    /**
     * Provera dozvola za fajlove
     */
    private function check_file_permissions()
    {
        $upload_dir = wp_upload_dir();
        $dexpress_dir = $upload_dir['basedir'] . '/dexpress';

        // Provera da li direktorijum postoji ili može biti kreiran
        if (!file_exists($dexpress_dir)) {
            if (!mkdir($dexpress_dir, 0755, true)) {
                $status = false;
                $message = 'Direktorijum za fajlove: Kreiranje neuspešno';
                $details = 'Nije moguće kreirati direktorijum za čuvanje PDF nalepnica: ' . $dexpress_dir;

                $this->is_success = false;
                $this->critical_issues++;

                $this->add_result('file_permissions', $status, $message, $details, 'critical');
                return;
            }
        }

        // Provera dozvola za pisanje
        $test_file = $dexpress_dir . '/test_write.txt';
        $write_test = @file_put_contents($test_file, 'Test');

        if ($write_test === false) {
            $status = false;
            $message = 'Direktorijum za fajlove: Nema dozvole za pisanje';
            $details = 'D Express nema dozvole za pisanje u direktorijum: ' . $dexpress_dir;

            $this->is_success = false;
            $this->critical_issues++;
        } else {
            @unlink($test_file); // Obrišemo test fajl
            $status = true;
            $message = 'Direktorijum za fajlove: Dozvole OK';
            $details = '';
        }

        $this->add_result('file_permissions', $status, $message, $details, 'critical');
    }

    /**
     * Provera SSL-a
     */
    private function check_ssl()
    {
        $status = is_ssl();
        $message = 'SSL: ' . ($status ? 'Omogućen' : 'Nije omogućen');
        $details = '';

        if (!$status) {
            $this->warnings++;
            $details = 'Preporučuje se korišćenje SSL-a za sigurnu komunikaciju sa D Express API-em i sigurnost podataka korisnika.';
        }

        $this->add_result('ssl', $status, $message, $details, 'warning');
    }

    /**
     * Provera konfliktnih plugin-a
     */
    private function check_conflicting_plugins()
    {
        $conflicts = array();

        // Lista potencijalno konfliktnih plugin-a
        $potentially_conflicting = array(
            'another-dexpress-plugin' => 'Another D Express Plugin',
            'custom-shipping-plugin' => 'Custom Shipping Plugin'
            // Dodajte druge plugin-e koji mogu izazvati konflikte
        );

        foreach ($potentially_conflicting as $plugin_slug => $plugin_name) {
            if (is_plugin_active($plugin_slug . '/' . $plugin_slug . '.php')) {
                $conflicts[] = $plugin_name;
            }
        }

        $status = empty($conflicts);
        $message = 'Konfliktni plugin-i: ' . ($status ? 'Nisu pronađeni' : count($conflicts) . ' pronađeno');
        $details = '';

        if (!$status) {
            $this->warnings++;
            $details = 'Sledeći aktivni plugin-i mogu izazvati konflikte sa D Express-om: ' . implode(', ', $conflicts);
        }

        $this->add_result('conflicting_plugins', $status, $message, $details, 'warning');
    }

    /**
     * Provera podataka u šifarnicima
     */
    private function check_indexes_data()
    {
        global $wpdb;

        // Proveravamo da li imamo podatke u šifarnicima
        $towns_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_towns");
        $statuses_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_statuses_index");

        // Proveravamo datum poslednjeg ažuriranja
        $last_updated_towns = $wpdb->get_var("SELECT MAX(last_updated) FROM {$wpdb->prefix}dexpress_towns");
        $last_updated_statuses = $wpdb->get_var("SELECT MAX(last_updated) FROM {$wpdb->prefix}dexpress_statuses_index");

        $status = ($towns_count > 0 && $statuses_count > 0);
        $message = 'Šifarnici: ' . ($status ? 'Popunjeni' : 'Nepotpuni');
        $details = '';

        if (!$status) {
            $this->warnings++;
            $details = 'Šifarnici gradova i statusa nisu popunjeni. Molimo kliknite na "Ažuriraj šifarnike" dugme.';
        } else {
            $details = 'Gradova: ' . $towns_count . ', Statusa: ' . $statuses_count . '. ';

            // Dodajemo informaciju o posledenjem ažuriranju
            if ($last_updated_towns) {
                $details .= 'Gradovi ažurirani: ' . date('d.m.Y H:i', strtotime($last_updated_towns)) . '. ';
            }

            if ($last_updated_statuses) {
                $details .= 'Statusi ažurirani: ' . date('d.m.Y H:i', strtotime($last_updated_statuses)) . '.';
            }
        }

        $this->add_result('indexes_data', $status, $message, $details, 'warning');
    }

    /**
     * Pomoćna funkcija za proveru memorijskog limita
     * 
     * @param string $current_limit Trenutni limit
     * @param string $required_limit Potrebni limit
     * @return bool Da li je limit dovoljan
     */
    private function is_memory_enough($current_limit, $required_limit)
    {
        $current_value = $this->return_bytes($current_limit);
        $required_value = $this->return_bytes($required_limit);

        return ($current_value >= $required_value);
    }

    /**
     * Konvertuje memorijski limit u bajtove
     * 
     * @param string $size_str Format kao 64M, 128K itd.
     * @return int Veličina u bajtovima
     */
    private function return_bytes($size_str)
    {
        switch (substr($size_str, -1)) {
            case 'M':
            case 'm':
                return (int)$size_str * 1048576;
            case 'K':
            case 'k':
                return (int)$size_str * 1024;
            case 'G':
            case 'g':
                return (int)$size_str * 1073741824;
            default:
                return $size_str;
        }
    }

    /**
     * Dodaje rezultat dijagnostike
     * 
     * @param string $id ID testa
     * @param bool $status Da li je test prošao
     * @param string $message Poruka
     * @param string $details Detalji
     * @param string $type Tip testa (critical ili warning)
     */
    private function add_result($id, $status, $message, $details, $type)
    {
        $this->results[$id] = array(
            'status' => $status,
            'message' => $message,
            'details' => $details,
            'type' => $type
        );
    }
}

/**
 * Funkcija za dodavanje stranice dijagnostike
 */
// function dexpress_add_diagnostics_page()
// {
//     add_submenu_page(
//         'woocommerce',
//         __('D Express Dijagnostika', 'd-express-woo'),
//         __('D Express Dijagnostika', 'd-express-woo'),
//         'manage_woocommerce',
//         'dexpress-diagnostics',
//         'dexpress_render_diagnostics_page'
//     );
// }
// add_action('admin_menu', 'dexpress_add_diagnostics_page');

/**
 * Funkcija za renderovanje stranice dijagnostike
 */

function dexpress_render_diagnostics_page()
{
    // Pokrećemo dijagnostiku
    $diagnostics = new D_Express_Diagnostics();
    $results = $diagnostics->run_diagnostics();

    // Renderujemo stranicu
?>
    <div class="wrap">
        <h1><?php _e('D Express Dijagnostika', 'd-express-woo'); ?></h1>

        <div class="dexpress-diagnostics-summary">
            <?php if ($results['summary']['is_success']): ?>
                <div class="notice notice-success">
                    <p><?php _e('Svi kritični testovi su uspešno prošli! Plugin je spreman za korišćenje.', 'd-express-woo'); ?></p>
                </div>
            <?php else: ?>
                <div class="notice notice-error">
                    <p><?php printf(__('Otkriveno je %d kritičnih problema koji moraju biti rešeni pre korišćenja plugin-a.', 'd-express-woo'), $results['summary']['critical_issues']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($results['summary']['warnings'] > 0): ?>
                <div class="notice notice-warning">
                    <p><?php printf(__('Otkriveno je %d upozorenja koja mogu uticati na funkcionisanje plugin-a.', 'd-express-woo'), $results['summary']['warnings']); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="dexpress-diagnostics-results">
            <h2><?php _e('Rezultati testova', 'd-express-woo'); ?></h2>

            <table class="widefat dexpress-diagnostics-table">
                <thead>
                    <tr>
                        <th><?php _e('Test', 'd-express-woo'); ?></th>
                        <th><?php _e('Status', 'd-express-woo'); ?></th>
                        <th><?php _e('Detalji', 'd-express-woo'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $id => $result):
                        if ($id === 'summary') continue; // Preskačemo summary
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($result['message']); ?></strong></td>
                            <td>
                                <?php if ($result['status']): ?>
                                    <span class="dexpress-status-ok"><?php _e('OK', 'd-express-woo'); ?></span>
                                <?php else: ?>
                                    <?php if ($result['type'] === 'critical'): ?>
                                        <span class="dexpress-status-error"><?php _e('Greška', 'd-express-woo'); ?></span>
                                    <?php else: ?>
                                        <span class="dexpress-status-warning"><?php _e('Upozorenje', 'd-express-woo'); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($result['details'])): ?>
                                    <p><?php echo esc_html($result['details']); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="dexpress-diagnostics-actions">
            <h2><?php _e('Akcije', 'd-express-woo'); ?></h2>

            <p><?php _e('Možete izvršiti sledeće akcije za rešavanje otkrivenih problema:', 'd-express-woo'); ?></p>

            <div class="dexpress-action-buttons">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dexpress-settings')); ?>" class="button button-primary">
                    <?php _e('Podešavanja D Express-a', 'd-express-woo'); ?>
                </a>

                <a href="<?php echo esc_url(admin_url('admin.php?page=dexpress-settings&action=update_indexes')); ?>" class="button button-secondary">
                    <?php _e('Ažuriraj šifarnike', 'd-express-woo'); ?>
                </a>

                <a href="<?php echo esc_url(admin_url('admin.php?page=dexpress-diagnostics')); ?>" class="button button-secondary">
                    <?php _e('Ponovi testove', 'd-express-woo'); ?>
                </a>
            </div>
        </div>

        <div class="dexpress-system-info">
            <h2><?php _e('Sistemske informacije', 'd-express-woo'); ?></h2>

            <p><?php _e('Ove informacije će vam biti potrebne pri kontaktiranju podrške:', 'd-express-woo'); ?></p>

            <textarea readonly class="dexpress-system-info-text" rows="10"><?php echo esc_textarea(dexpress_get_system_info()); ?></textarea>

            <p>
                <button type="button" class="button button-secondary" onclick="copySystemInfo()">
                    <?php _e('Kopiraj sistemske informacije', 'd-express-woo'); ?>
                </button>
            </p>
        </div>
    </div>

    <script>
        function copySystemInfo() {
            var copyText = document.querySelector(".dexpress-system-info-text");
            copyText.select();
            document.execCommand("copy");
            alert("<?php _e('Sistemske informacije su kopirane u clipboard!', 'd-express-woo'); ?>");
        }
    </script>

    <style>
        .dexpress-diagnostics-summary {
            margin: 20px 0;
        }

        .dexpress-diagnostics-table {
            margin-top: 15px;
        }

        .dexpress-status-ok {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            background-color: #d4edda;
            color: #155724;
            font-weight: bold;
        }

        .dexpress-status-error {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            background-color: #f8d7da;
            color: #721c24;
            font-weight: bold;
        }

        .dexpress-status-warning {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            background-color: #fff3cd;
            color: #856404;
            font-weight: bold;
        }

        .dexpress-diagnostics-actions {
            margin: 30px 0;
        }

        .dexpress-action-buttons {
            margin: 15px 0;
        }

        .dexpress-action-buttons .button {
            margin-right: 10px;
        }

        .dexpress-system-info {
            margin: 30px 0;
        }

        .dexpress-system-info-text {
            width: 100%;
            font-family: monospace;
            margin: 10px 0;
        }
    </style>
<?php
}

/**
 * Funkcija za dobijanje sistemskih informacija
 * 
 * @return string Sistemske informacije
 */
function dexpress_get_system_info()
{
    global $wpdb;

    // WordPress informacije
    $system_info = "### WordPress i sistemske informacije ###\n\n";
    $system_info .= "WordPress verzija: " . get_bloginfo('version') . "\n";
    $system_info .= "PHP verzija: " . PHP_VERSION . "\n";
    $system_info .= "MySQL verzija: " . $wpdb->db_version() . "\n";
    $system_info .= "WooCommerce verzija: " . (class_exists('WooCommerce') ? WC()->version : 'Nije instalirano') . "\n";
    $system_info .= "D Express verzija: " . DEXPRESS_WOO_VERSION . "\n";
    $system_info .= "Server software: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
    $system_info .= "PHP memory_limit: " . ini_get('memory_limit') . "\n";
    $system_info .= "PHP max_execution_time: " . ini_get('max_execution_time') . "\n";
    $system_info .= "SSL: " . (is_ssl() ? 'Da' : 'Ne') . "\n";
    $system_info .= "CURL: " . (function_exists('curl_version') ? 'Da' : 'Ne') . "\n";

    // D Express opcije
    $system_info .= "\n### D Express konfiguracija ###\n\n";
    $system_info .= "Test mode: " . (get_option('dexpress_test_mode', 'yes') === 'yes' ? 'Da' : 'Ne') . "\n";
    $system_info .= "API Username: " . (empty(get_option('dexpress_api_username', '')) ? 'Nije postavljeno' : 'Postavljeno') . "\n";
    $system_info .= "API Password: " . (empty(get_option('dexpress_api_password', '')) ? 'Nije postavljeno' : 'Postavljeno') . "\n";
    $system_info .= "Client ID: " . (empty(get_option('dexpress_client_id', '')) ? 'Nije postavljeno' : 'Postavljeno') . "\n";
    $system_info .= "Logging: " . (get_option('dexpress_enable_logging', 'no') === 'yes' ? 'Uključeno' : 'Isključeno') . "\n";
    $system_info .= "Auto kreiranje pošiljki: " . (get_option('dexpress_auto_create_shipment', 'no') === 'yes' ? 'Uključeno' : 'Isključeno') . "\n";

    // Šifarnici
    $system_info .= "\n### D Express šifarnici ###\n\n";
    $towns_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_towns");
    $statuses_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_statuses");
    $system_info .= "Gradovi: " . $towns_count . "\n";
    $system_info .= "Statusi: " . $statuses_count . "\n";

    // Aktivni plugin-i
    $system_info .= "\n### Aktivni plugin-i ###\n\n";
    $active_plugins = get_option('active_plugins');
    foreach ($active_plugins as $plugin) {
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
        $system_info .= $plugin_data['Name'] . ': ' . $plugin_data['Version'] . "\n";
    }

    return $system_info;
}

/**
 * Dodajemo test API konekcije u D_Express_API klasu
 * Dodajte ovu metodu u vašu postojeću D_Express_API klasu
 */
/*
public function test_connection() {
    // Pokušavamo da dobavimo statuse kao jednostavan test
    $result = $this->get_statuses();
    
    // Ako je rezultat array, konekcija je uspešna
    if (is_array($result)) {
        return true;
    }
    
    // Inače, vraćamo error
    return $result;
}
*/