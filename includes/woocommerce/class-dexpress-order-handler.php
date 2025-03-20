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
        // Kreiranje instance servisne klase
        $shipment_service = new D_Express_Shipment_Service();

        // Dodavanje akcije za automatsko kreiranje pošiljke kada se promeni status narudžbine
       // add_action('woocommerce_order_status_changed', array($shipment_service, 'maybe_create_shipment_on_status_change'), 10, 4);

        // Dodavanje meta box-a za opcije D Express pošiljke
        add_action('add_meta_boxes', array($this, 'add_dexpress_meta_box'));

        // Čuvanje D Express opcija pri čuvanju narudžbine
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_dexpress_meta_box'), 10, 1);

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
            $shipment_service = new D_Express_Shipment_Service();
            $result = $shipment_service->create_shipment($order);

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

        // Samo za email-ove o dovršenim narudžbinama ili procesuiranim
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
