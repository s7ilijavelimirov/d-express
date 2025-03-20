<?php
/**
 * D Express Shipment Service
 * 
 * Servisna klasa za upravljanje D Express pošiljkama
 */

defined('ABSPATH') || exit;

class D_Express_Shipment_Service {
    
    /**
     * @var D_Express_API
     */
    private $api;
    
    /**
     * @var D_Express_DB
     */
    private $db;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $this->api = new D_Express_API();
        $this->db = new D_Express_DB();
    }
    
    /**
     * Kreiranje D Express pošiljke
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return int|WP_Error ID pošiljke ili WP_Error
     */
    public function create_shipment($order) {
        try {
            // Početni log 
            dexpress_log('[SHIPPING] Započinjem kreiranje pošiljke za narudžbinu #' . $order->get_id(), 'debug');
            
            // Provera da li pošiljka već postoji
            $existing = $this->db->get_shipment_by_order_id($order->get_id());
            
            if ($existing) {
                dexpress_log('[SHIPPING] Pošiljka već postoji za narudžbinu #' . $order->get_id(), 'debug');
                return new WP_Error('shipment_exists', __('Pošiljka već postoji za ovu narudžbinu.', 'd-express-woo'));
            }
            
            // Provera da li su postavljeni API kredencijali
            if (!$this->api->has_credentials()) {
                dexpress_log('[SHIPPING] Nedostaju API kredencijali za narudžbinu #' . $order->get_id(), 'error');
                return new WP_Error('missing_credentials', __('Nedostaju API kredencijali. Molimo podesite API kredencijale u podešavanjima.', 'd-express-woo'));
            }
            
            // Validacija adrese ako je opcija uključena
            if (get_option('dexpress_validate_address', 'yes') === 'yes') {
                dexpress_log('[SHIPPING] Proveravam adresu za narudžbinu #' . $order->get_id(), 'debug');
                $address_check = $this->api->validate_order_address($order);
                
                if (is_wp_error($address_check)) {
                    dexpress_log('[SHIPPING] Greška pri proveri adrese: ' . $address_check->get_error_message(), 'error');
                    return $address_check;
                }
                
                dexpress_log('[SHIPPING] Adresa validirana uspešno', 'debug');
            }
            
            // Dobijanje podataka za pošiljku
            dexpress_log('[SHIPPING] Priprema podataka za narudžbinu #' . $order->get_id(), 'debug');
            $shipment_data = $this->api->prepare_shipment_data_from_order($order);
            
            if (is_wp_error($shipment_data)) {
                dexpress_log('[SHIPPING] Greška pri pripremi podataka: ' . $shipment_data->get_error_message(), 'error');
                return $shipment_data;
            }
            
            // Logovanje u test modu
            if (dexpress_is_test_mode()) {
                dexpress_log('Kreiranje pošiljke. Podaci: ' . print_r($shipment_data, true));
            }
            
            // Kreiranje pošiljke preko API-ja
            dexpress_log('[SHIPPING] Šaljem zahtev ka D-Express API-ju', 'debug');
            $response = $this->api->add_shipment($shipment_data);
            
            if (is_wp_error($response)) {
                dexpress_log('[SHIPPING] Greška pri kreiranju pošiljke: ' . $response->get_error_message(), 'error');
                return $response;
            }
            
            dexpress_log('[SHIPPING] API odgovor primljen uspešno', 'debug');
            
            // Kreiranje tracking broja
            $tracking_number = isset($response['TrackingNumber']) ? $response['TrackingNumber'] : $shipment_data['PackageList'][0]['Code'];
            $shipment_id = isset($response['ShipmentID']) ? $response['ShipmentID'] : $tracking_number;
            
            dexpress_log('[SHIPPING] Tracking broj: ' . $tracking_number . ', Shipment ID: ' . $shipment_id, 'debug');
            
            // Dodavanje napomene u narudžbinu
            $order->add_order_note(
                sprintf(
                    __('D Express pošiljka je kreirana. Tracking broj: %s, Reference ID: %s', 'd-express-woo'),
                    $tracking_number,
                    $shipment_data['ReferenceID']
                )
            );
            
            // Čuvanje podataka o pošiljci u bazi
            dexpress_log('[SHIPPING] Čuvanje pošiljke u bazu podataka', 'debug');
            $shipment = array(
                'order_id' => $order->get_id(),
                'shipment_id' => $shipment_id,
                'tracking_number' => $tracking_number,
                'reference_id' => $shipment_data['ReferenceID'],
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'shipment_data' => json_encode($response),
                'is_test' => dexpress_is_test_mode() ? 1 : 0
            );
            
            $insert_id = $this->db->add_shipment($shipment);
            
            if (!$insert_id) {
                dexpress_log('[SHIPPING] Greška pri upisu pošiljke u bazu', 'error');
                return new WP_Error('db_error', __('Greška pri upisu pošiljke u bazu.', 'd-express-woo'));
            }
            
            // Čuvanje paketa
            if (isset($shipment_data['PackageList']) && is_array($shipment_data['PackageList'])) {
                foreach ($shipment_data['PackageList'] as $package) {
                    $mass = isset($package['Mass']) ? $package['Mass'] : $shipment_data['Mass'];
                    
                    $package_data = array(
                        'shipment_id' => $insert_id,
                        'package_code' => $package['Code'],
                        'mass' => $mass,
                        'created_at' => current_time('mysql')
                    );
                    
                    $this->db->add_package($package_data);
                }
            }
            
            // Uspešno kreiranje
            dexpress_log('[SHIPPING] Pošiljka uspešno kreirana sa ID: ' . $insert_id, 'debug');
            
            // Hook za dodatne akcije nakon kreiranja pošiljke
            do_action('dexpress_after_shipment_created', $insert_id, $order);
            
            return $insert_id;
            
        } catch (Exception $e) {
            dexpress_log('[SHIPPING] Exception pri kreiranju pošiljke: ' . $e->getMessage(), 'error');
            return new WP_Error('exception', $e->getMessage());
        }
    }
    
    /**
     * Sinhronizacija statusa pošiljke sa D Express API-jem
     * 
     * @param int|string $shipment_id ID pošiljke ili tracking broj
     * @return bool|WP_Error True ako je sinhronizacija uspela, WP_Error ako nije
     */
    public function sync_shipment_status($shipment_id) {
        // Implementacija dolazi kasnije
        return true;
    }
    
    /**
     * Obrada funkcije pri promeni statusa narudžbine
     * 
     * @param int $order_id ID narudžbine
     * @param string $from_status Prethodni status
     * @param string $to_status Novi status
     * @param WC_Order $order WooCommerce narudžbina
     * @return void
     */
    public function maybe_create_shipment_on_status_change($order_id, $from_status, $to_status, $order) {
        // Provera da li je automatsko kreiranje pošiljke omogućeno
        if (get_option('dexpress_auto_create_shipment', 'no') !== 'yes') {
            return;
        }
        
        // Provera da li je novi status onaj koji je postavljen za automatsko kreiranje pošiljke
        $auto_create_status = get_option('dexpress_auto_create_on_status', 'processing');
        
        if ($to_status !== $auto_create_status) {
            return;
        }
        
        // Provera da li pošiljka već postoji
        $existing = $this->db->get_shipment_by_order_id($order_id);
        
        if ($existing) {
            return;
        }
        
        // Kreiranje pošiljke
        $this->create_shipment($order);
    }
}