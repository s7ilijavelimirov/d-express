<?php

/**
 * D Express Order Handler klasa
 * 
 * Klasa za rukovanje WooCommerce narudžbinama
 */

defined('ABSPATH') || exit;

class D_Express_Order_Handler
{
    /**
     * Inicijalizacija order handlera
     */
    public function init()
    {
        // Dodavanje akcije za automatsko kreiranje pošiljke kada se promeni status narudžbine
        add_action('woocommerce_order_status_changed', array($this, 'maybe_create_shipment_on_status_change'), 10, 4);

        // Dodavanje meta box-a za opcije D Express pošiljke
        add_action('add_meta_boxes', array($this, 'add_dexpress_meta_box'));

        // Čuvanje D Express opcija pri čuvanju narudžbine
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_dexpress_meta_box'), 10, 1);

        // AJAX akcija za kreiranje pošiljke
        add_action('wp_ajax_dexpress_create_shipment', array($this, 'ajax_create_shipment'));

        // AJAX akcija za preuzimanje nalepnice
        add_action('wp_ajax_dexpress_get_label', array($this, 'ajax_get_label'));

        // Dodavanje kolone za tracking u admin listi narudžbina
        add_filter('manage_edit-shop_order_columns', array($this, 'add_tracking_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'show_tracking_column_content'), 10, 2);

        // Dodavanje tracking informacija u email sa narudžbinom
        add_action('woocommerce_email_after_order_table', array($this, 'add_tracking_to_emails'), 10, 4);

        // Dodavanje tracking informacija na stranicu detalja narudžbine
        add_action('woocommerce_order_details_after_order_table', array($this, 'add_tracking_to_order_page'));

        // Kreiranje pošiljke nakon završetka narudžbine
        add_action('woocommerce_checkout_order_processed', array($this, 'process_checkout_order'), 10, 3);
    }

    /**
     * Obrada narudžbine nakon checkout-a
     * 
     * @param int $order_id ID narudžbine
     * @param array $posted_data Podaci sa checkout forme
     * @param WC_Order $order Objekat narudžbine
     */
    public function process_checkout_order($order_id, $posted_data, $order)
    {
        // Logging za debug
        dexpress_log('Checkout order processed: ' . $order_id, 'debug');

        // Proveriti da li je odabrana D Express dostava
        $shipping_methods = $order->get_shipping_methods();
        $has_dexpress = false;

        foreach ($shipping_methods as $method) {
            if (strpos($method->get_method_id(), 'dexpress') !== false) {
                $has_dexpress = true;
                break;
            }
        }

        // Logging za debug
        dexpress_log('Has D Express shipping: ' . ($has_dexpress ? 'Yes' : 'No'), 'debug');

        if (!$has_dexpress) {
            return;
        }

        // Provera da li je automatsko kreiranje omogućeno
        $auto_create_enabled = get_option('dexpress_auto_create_shipment', 'no') === 'yes';
        $auto_create_status = get_option('dexpress_auto_create_on_status', 'processing');

        // Kreiranje pošiljke samo ako je automatsko kreiranje omogućeno i status odgovara
        // ili ako je u test modu i automatsko kreiranje je omogućeno
        if ($auto_create_enabled && ($order->get_status() === $auto_create_status || dexpress_is_test_mode())) {
            dexpress_log('Auto-creating shipment for order: ' . $order_id . ' (Test mode: ' . (dexpress_is_test_mode() ? 'Yes' : 'No') . ')', 'debug');
            $result = $this->create_shipment($order);

            if (is_wp_error($result)) {
                dexpress_log('Failed to create shipment: ' . $result->get_error_message(), 'error');
            } else {
                dexpress_log('Shipment created successfully, ID: ' . $result, 'debug');
            }
        } else {
            dexpress_log('Automatic shipment creation is disabled or status does not match. Auto create: ' .
                ($auto_create_enabled ? 'Enabled' : 'Disabled') .
                ', Current status: ' . $order->get_status() .
                ', Required status: ' . $auto_create_status, 'debug');
        }
    }

    /**
     * Proverava da li treba kreirati pošiljku pri promeni statusa narudžbine
     * 
     * @param int $order_id ID narudžbine
     * @param string $from_status Prethodni status
     * @param string $to_status Novi status
     * @param WC_Order $order WooCommerce narudžbina
     */
    public function maybe_create_shipment_on_status_change($order_id, $from_status, $to_status, $order)
    {
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
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));

        if ($existing) {
            return;
        }

        // Kreiranje pošiljke
        $this->create_shipment($order);
    }

    /**
     * Dodavanje meta box-a za D Express opcije
     */
    public function add_dexpress_meta_box()
    {
        add_meta_box(
            'dexpress_shipping',
            __('D Express Dostava', 'd-express-woo'),
            array($this, 'render_dexpress_meta_box'),
            'shop_order',
            'side',
            'high'
        );
    }

    /**
     * Prikaz meta box-a za D Express opcije
     * 
     * @param WP_Post $post Post objekat
     */
    public function render_dexpress_meta_box($post)
    {
        $order = wc_get_order($post->ID);

        if (!$order) {
            return;
        }

        // Provera da li je odabrana D Express dostava
        $shipping_methods = $order->get_shipping_methods();
        $has_dexpress = false;

        foreach ($shipping_methods as $method) {
            if (strpos($method->get_method_id(), 'dexpress') !== false) {
                $has_dexpress = true;
                break;
            }
        }

        // Dobijanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order->get_id()
        ));

        // Provera da li je narudžbina plaćena
        $is_paid = $order->is_paid() || $order->get_payment_method() === 'cod';

        // Render template-a
        include DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/views/order-metabox.php';
    }

    /**
     * Čuvanje D Express opcija pri čuvanju narudžbine
     * 
     * @param int $order_id ID narudžbine
     */
    public function save_dexpress_meta_box($order_id)
    {
        // Provera nonce-a
        if (!isset($_POST['dexpress_meta_box_nonce']) || !wp_verify_nonce($_POST['dexpress_meta_box_nonce'], 'dexpress_meta_box')) {
            return;
        }

        // Čuvanje D Express opcija
        if (isset($_POST['dexpress_shipment_type'])) {
            update_post_meta($order_id, '_dexpress_shipment_type', sanitize_text_field($_POST['dexpress_shipment_type']));
        }

        if (isset($_POST['dexpress_payment_by'])) {
            update_post_meta($order_id, '_dexpress_payment_by', sanitize_text_field($_POST['dexpress_payment_by']));
        }

        if (isset($_POST['dexpress_payment_type'])) {
            update_post_meta($order_id, '_dexpress_payment_type', sanitize_text_field($_POST['dexpress_payment_type']));
        }

        if (isset($_POST['dexpress_return_doc'])) {
            update_post_meta($order_id, '_dexpress_return_doc', sanitize_text_field($_POST['dexpress_return_doc']));
        }

        if (isset($_POST['dexpress_content'])) {
            update_post_meta($order_id, '_dexpress_content', sanitize_text_field($_POST['dexpress_content']));
        }
    }

    /**
     * AJAX akcija za kreiranje pošiljke
     */
    /**
     * AJAX handler za kreiranje pošiljke
     */
    public function ajax_create_shipment()
    {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-admin-nonce')) {
            wp_send_json_error(array(
                'message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')
            ));
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')
            ));
        }

        // Provera ID-a narudžbine
        if (!isset($_POST['order_id']) || empty($_POST['order_id'])) {
            wp_send_json_error(array(
                'message' => __('ID narudžbine je obavezan.', 'd-express-woo')
            ));
        }

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(array(
                'message' => __('Narudžbina nije pronađena.', 'd-express-woo')
            ));
        }

        // Provera da li pošiljka već postoji
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));

        if ($existing) {
            wp_send_json_error(array(
                'message' => __('Pošiljka već postoji za ovu narudžbinu.', 'd-express-woo')
            ));
        }

        // Kreiranje instance Order Handler klase
        $order_handler = new D_Express_Order_Handler();

        // Kreiranje pošiljke
        $result = $order_handler->create_shipment($order);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        } else {
            wp_send_json_success(array(
                'message' => __('Pošiljka je uspešno kreirana.', 'd-express-woo'),
                'shipment_id' => $result
            ));
        }
    }

    /**
     * Kreiranje D Express pošiljke
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return int|WP_Error ID pošiljke ili WP_Error
     */
    public function create_shipment($order)
    {
        try {
            global $wpdb;

            // Početni log za kreiranje pošiljke
            dexpress_log('[SHIPPING] Započinjem kreiranje pošiljke za narudžbinu #' . $order->get_id(), 'debug');

            // Provera da li pošiljka već postoji
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
                $order->get_id()
            ));

            if ($existing) {
                dexpress_log('[SHIPPING] Pošiljka već postoji za narudžbinu #' . $order->get_id(), 'debug');
                return new WP_Error('shipment_exists', __('Pošiljka već postoji za ovu narudžbinu.', 'd-express-woo'));
            }

            // Kreiranje instance API klase
            $api = new D_Express_API();

            // Provera da li su postavljeni API kredencijali
            if (!$api->has_credentials()) {
                dexpress_log('[SHIPPING] Nedostaju API kredencijali za narudžbinu #' . $order->get_id(), 'error');
                return new WP_Error('missing_credentials', __('Nedostaju API kredencijali. Molimo podesite API kredencijale u podešavanjima.', 'd-express-woo'));
            }

            // Dobijanje podataka za pošiljku iz narudžbine
            dexpress_log('[SHIPPING] Priprema podataka za narudžbinu #' . $order->get_id(), 'debug');
            $shipment_data = $api->prepare_shipment_data_from_order($order);

            if (is_wp_error($shipment_data)) {
                dexpress_log('[SHIPPING] Greška pri pripremi podataka: ' . $shipment_data->get_error_message(), 'error');
                return $shipment_data;
            }

            // Generisanje referentnog ID-a ako nije postavljen
            if (empty($shipment_data['ReferenceID'])) {
                $shipment_data['ReferenceID'] = $api->generate_reference_id($order->get_id());
                dexpress_log('[SHIPPING] Generisan Reference ID: ' . $shipment_data['ReferenceID'], 'debug');
            }

            // Generisanje koda paketa ako nije postavljen
            if (empty($shipment_data['PackageList'][0]['Code'])) {
                $shipment_data['PackageList'][0]['Code'] = $api->generate_package_code();
                dexpress_log('[SHIPPING] Generisan kod paketa: ' . $shipment_data['PackageList'][0]['Code'], 'debug');
            }

            // Logovanje podataka ako je test mode aktivan
            if (dexpress_is_test_mode()) {
                dexpress_log('Kreiranje pošiljke. Podaci: ' . print_r($shipment_data, true));
            }

            // Logujemo ključne podatke za debugging bez obzira na test mode
            dexpress_log('[SHIPPING] Ključni podaci za slanje: RAddress=' . $shipment_data['RAddress'] .
                ', RAddressNum=' . $shipment_data['RAddressNum'] .
                ', RTownID=' . $shipment_data['RTownID'] .
                ', BuyOut=' . $shipment_data['BuyOut'] .
                ', BuyOutAccount=' . $shipment_data['BuyOutAccount'], 'debug');

            // Kreiranje pošiljke preko API-ja
            dexpress_log('[SHIPPING] Šaljem zahtev ka D-Express API-ju', 'debug');
            $response = $api->add_shipment($shipment_data);

            if (is_wp_error($response)) {
                dexpress_log('[SHIPPING] Greška pri kreiranju pošiljke: ' . $response->get_error_message(), 'error');
                return $response;
            }

            // Logovanje odgovora ako je test mode aktivan
            if (dexpress_is_test_mode()) {
                dexpress_log('API Odgovor: ' . print_r($response, true));
            }

            dexpress_log('[SHIPPING] API odgovor primljen uspešno', 'debug');

            // Kreiranje tracking broja ako nije vraćen iz API-ja
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
            $result = $wpdb->insert(
                $wpdb->prefix . 'dexpress_shipments',
                array(
                    'order_id' => $order->get_id(),
                    'shipment_id' => $shipment_id,
                    'tracking_number' => $tracking_number,
                    'reference_id' => $shipment_data['ReferenceID'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'shipment_data' => json_encode($response),
                    'is_test' => dexpress_is_test_mode() ? 1 : 0
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
            );

            if ($result === false) {
                dexpress_log('[SHIPPING] Greška pri upisu pošiljke u bazu: ' . $wpdb->last_error, 'error');
                return new WP_Error('db_error', __('Greška pri upisu pošiljke u bazu.', 'd-express-woo'));
            }

            $insert_id = $wpdb->insert_id;

            // Čuvanje paketa
            if (isset($shipment_data['PackageList']) && is_array($shipment_data['PackageList'])) {
                foreach ($shipment_data['PackageList'] as $package) {
                    $mass = isset($package['Mass']) ? $package['Mass'] : $shipment_data['Mass'];

                    $wpdb->insert(
                        $wpdb->prefix . 'dexpress_packages',
                        array(
                            'shipment_id' => $insert_id,
                            'package_code' => $package['Code'],
                            'mass' => $mass,
                            'created_at' => current_time('mysql')
                        ),
                        array('%d', '%s', '%d', '%s')
                    );
                }
            }

            dexpress_log('[SHIPPING] Pošiljka uspešno kreirana sa ID: ' . $insert_id, 'debug');
            return $insert_id;
        } catch (Exception $e) {
            dexpress_log('[SHIPPING] Exception pri kreiranju pošiljke: ' . $e->getMessage(), 'error');
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * AJAX akcija za preuzimanje nalepnice
     */
    public function ajax_get_label()
    {
        // Provera nonce-a
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-admin-nonce')) {
            wp_send_json_error(array(
                'message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')
            ));
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')
            ));
        }

        // Provera ID-a pošiljke
        if (!isset($_POST['shipment_id']) || empty($_POST['shipment_id'])) {
            wp_send_json_error(array(
                'message' => __('ID pošiljke je obavezan.', 'd-express-woo')
            ));
        }

        $shipment_id = intval($_POST['shipment_id']);

        // Dobijanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
            $shipment_id
        ));

        if (!$shipment) {
            wp_send_json_error(array(
                'message' => __('Pošiljka nije pronađena.', 'd-express-woo')
            ));
        }

        // Generisanje nalepnice
        $label_generator = new D_Express_Label_Generator();
        $label_url = admin_url('admin-ajax.php?action=dexpress_download_label&shipment_id=' . $shipment_id . '&nonce=' . wp_create_nonce('dexpress-download-label'));

        wp_send_json_success(array(
            'message' => __('Nalepnica uspešno generisana.', 'd-express-woo'),
            'url' => $label_url
        ));
    }

    /**
     * Dodavanje kolone za tracking u admin listi narudžbina
     * 
     * @param array $columns Postojeće kolone
     * @return array Modifikovane kolone
     */
    public function add_tracking_column($columns)
    {
        $new_columns = array();

        foreach ($columns as $column_id => $column_name) {
            $new_columns[$column_id] = $column_name;

            if ($column_id === 'order_status') {
                $new_columns['dexpress_tracking'] = __('D Express', 'd-express-woo');
            }
        }

        return $new_columns;
    }

    /**
     * Prikaz sadržaja u koloni za tracking
     * 
     * @param string $column Naziv kolone
     * @param int $order_id ID narudžbine
     */
    public function show_tracking_column_content($column, $order_id)
    {
        if ($column === 'dexpress_tracking') {
            // Dobijanje podataka o pošiljci
            global $wpdb;
            $shipment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
                $order_id
            ));

            if ($shipment) {
                echo '<mark class="order-status"><span>' . esc_html($shipment->tracking_number) . '</span></mark>';
            } else {
                echo '<span class="na">&ndash;</span>';
            }
        }
    }

    /**
     * Dodavanje tracking informacija u email sa narudžbinom
     * 
     * @param WC_Order $order Narudžbina
     * @param bool $sent_to_admin Da li se email šalje adminu
     * @param bool $plain_text Da li je email u plain text formatu
     * @param WC_Email $email Email objekat
     */
    public function add_tracking_to_emails($order, $sent_to_admin, $plain_text, $email)
    {
        // Samo za email-ove koji se šalju kupcima
        if ($sent_to_admin || !is_a($order, 'WC_Order')) {
            return;
        }

        // Samo za email-ove o dovršenim narudžbinama
        if ($email->id !== 'customer_completed_order' && $email->id !== 'customer_processing_order') {
            return;
        }

        // Dobijanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order->get_id()
        ));

        if (!$shipment) {
            return;
        }

        // Prikaz informacija o praćenju
        if ($plain_text) {
            include DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/plain/tracking-info.php';
        } else {
            include DEXPRESS_WOO_PLUGIN_DIR . 'templates/emails/tracking-info.php';
        }
    }

    /**
     * Dodavanje tracking informacija na stranicu detalja narudžbine
     * 
     * @param WC_Order $order Narudžbina
     */
    public function add_tracking_to_order_page($order)
    {
        // Dobijanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order->get_id()
        ));

        if (!$shipment) {
            return;
        }

        include DEXPRESS_WOO_PLUGIN_DIR . 'templates/myaccount/tracking.php';
    }

    /**
     * Vraća HTML sa detaljima pošiljke za AJAX odgovor
     * 
     * @param int $shipment_id ID pošiljke
     * @return string HTML
     */
    private function get_shipment_details_html($shipment_id)
    {
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
            $shipment_id
        ));

        if (!$shipment) {
            return '';
        }

        ob_start();
?>
        <div class="dexpress-shipment-details">
            <p>
                <strong><?php _e('Tracking broj:', 'd-express-woo'); ?></strong>
                <?php echo esc_html($shipment->tracking_number); ?>
            </p>
            <p>
                <strong><?php _e('Reference ID:', 'd-express-woo'); ?></strong>
                <?php echo esc_html($shipment->reference_id); ?>
            </p>
            <p>
                <strong><?php _e('Kreirano:', 'd-express-woo'); ?></strong>
                <?php echo esc_html($shipment->created_at); ?>
            </p>
            <?php if ($shipment->status_code): ?>
                <p>
                    <strong><?php _e('Status:', 'd-express-woo'); ?></strong>
                    <?php echo esc_html(dexpress_get_status_name($shipment->status_code)); ?>
                </p>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=dexpress_download_label&shipment_id=' . $shipment->id . '&nonce=' . wp_create_nonce('dexpress-download-label'))); ?>" class="button button-primary" target="_blank">
                    <?php _e('Preuzmi nalepnicu', 'd-express-woo'); ?>
                </a>
            </p>
        </div>
<?php
        return ob_get_clean();
    }
}
