<?php

/**
 * D Express Label Generator
 * 
 * Klasa za generisanje nalepnica za pošiljke prema D-Express standardu
 */

defined('ABSPATH') || exit;

class D_Express_Label_Generator
{
    /**
     * API instanca
     * 
     * @var D_Express_API
     */
    private $api;

    /**
     * Konstruktor
     */
    public function __construct()
    {
        $this->api = new D_Express_API();

        // Registracija AJAX akcija
        add_action('wp_ajax_dexpress_download_label', array($this, 'ajax_download_label'));
        add_action('wp_ajax_dexpress_generate_label', array($this, 'ajax_generate_label'));
    }

    /**
     * AJAX akcija za preuzimanje nalepnice
     */
    public function ajax_download_label()
    {
        // Provera nonce-a
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'dexpress-download-label')) {
            wp_die(__('Sigurnosna provera nije uspela.', 'd-express-woo'));
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za ovu akciju.', 'd-express-woo'));
        }

        // Provera ID-a pošiljke
        if (!isset($_GET['shipment_id']) || empty($_GET['shipment_id'])) {
            wp_die(__('ID pošiljke je obavezan.', 'd-express-woo'));
        }

        $shipment_id = intval($_GET['shipment_id']);

        // Dobijanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d OR shipment_id = %s",
            $shipment_id, $shipment_id
        ));

        if (!$shipment) {
            wp_die(__('Pošiljka nije pronađena.', 'd-express-woo'));
        }

        // Generisanje HTML nalepnice
        echo $this->generate_dexpress_label_html($shipment);
        exit;
    }

    /**
     * AJAX akcija za generisanje nalepnice
     */
    public function ajax_generate_label()
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

        // Kreiraj URL za preuzimanje
        $url = admin_url('admin-ajax.php?action=dexpress_download_label&shipment_id=' . $shipment_id .
            '&nonce=' . wp_create_nonce('dexpress-download-label'));

        wp_send_json_success(array(
            'message' => __('Nalepnica uspešno generisana.', 'd-express-woo'),
            'url' => $url
        ));
    }

    public function generate_dexpress_label_html($shipment)
    {
        // Dobijanje podataka o narudžbini
        $order = wc_get_order($shipment->order_id);

        if (!$order) {
            return '<p>' . __('Narudžbina nije pronađena.', 'd-express-woo') . '</p>';
        }

        // Pripremanje podataka za nalepnicu
        $order_data = $this->prepare_order_data($order);

        // Tracking broj
        $tracking_number = !empty($shipment->tracking_number) ? $shipment->tracking_number : 'TT' . str_pad(rand(1, 999999), 10, '0', STR_PAD_LEFT);

        // Format otkupnine
        $cod_amount = '';
        if ($order_data['payment_method'] === 'cod') {
            $cod_amount = number_format($order_data['order_total'], 2, ',', '.') . ' RSD';
        }

        // Referentni broj
        $reference_id = !empty($shipment->reference_id) ? $shipment->reference_id : 'ORDER-' . $order->get_id();

        // Datum i vreme štampe
        $print_date = date_i18n('d.m.Y H:i:s');

        // Broj paketa u pošiljci (obično 1)
        $package_count = 1;
        $package_index = 1;

        // HTML za nalepnicu
        ob_start();
?>
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset="UTF-8">
            <title>D Express - <?php echo esc_html($tracking_number); ?></title>
            <style>
                @page {
                    size: A6 portrait;
                    margin: 0;
                }

                body {
                    font-family: Arial, sans-serif;
                    font-size: 10px;
                    margin: 0;
                    padding: 0;
                    width: 105mm;
                    /* height: 148mm;  */
                    /* A6 height */
                }

                .label-container {
                    width: 100%;
                    height: 100%;
                    border-top: 1px solid #000;
                    border-bottom: 1px solid #000;
                    position: relative;
                }

                .header {
                    padding: 3px;
                    /* border-bottom: 1px solid #000; */
                    font-size: 8px;
                    text-align: left;
                    background-color: #fff;
                }

                .package-info {
                    position: absolute;
                    top: 3px;
                    right: 5px;
                    font-weight: bold;
                    font-size: 12px;
                }

                .barcode-container {
                    text-align: center;
                    padding: 10px 0;
                    position: relative;
                    /* border-bottom: 1px solid #000; */
                }

                .barcode {
                    height: 50px;
                    margin: 0 auto;
                }

                .tracking-number {
                    font-size: 14px;
                    font-weight: bold;
                    text-align: center;
                    margin-top: 3px;
                }

                .sender-info {
                    padding: 5px;
                    /* border-bottom: 1px solid #000; */
                    font-size: 9px;
                }

                .recipient-info {
                    padding: 5px;
                    /* border-bottom: 1px solid #000; */
                    text-align: center;
                }

                .recipient-title {
                    margin-bottom: 3px;
                    font-size: 10px;
                }

                .recipient-name {
                    font-size: 16px;
                    font-weight: bold;
                }

                .recipient-address {
                    font-size: 14px;
                    font-weight: bold;
                    margin-top: 2px;
                }

                .recipient-city {
                    font-size: 14px;
                    font-weight: bold;
                    margin-top: 2px;
                }

                .recipient-phone {
                    font-size: 14px;
                    font-weight: bold;
                    margin-top: 2px;
                }

                .shipment-details {
                    padding: 5px;
                    /* border-bottom: 1px solid #000; */
                    font-size: 9px;
                }

                .detail-row {
                    margin-bottom: 2px;
                }

                .detail-label {
                    font-weight: normal;
                    display: inline-block;
                    width: 110px;
                }

                .footer {
                    padding: 3px;

                    width: 100%;
                    box-sizing: border-box;
                    text-align: center;
                    font-size: 8px;
                }

                .print-button {
                    display: block;
                    margin: 20px auto;
                    padding: 10px 20px;
                    background-color: #2271b1;
                    color: white;
                    border: none;
                    border-radius: 3px;
                    cursor: pointer;
                    font-size: 14px;
                }

                @media print {
                    .print-button {
                        display: none;
                    }

                    body {
                        margin: 0;
                        padding: 0;
                    }
                }
            </style>
            <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
        </head>

        <body>
            <div class="label-container">
                <!-- Header sa podacima pošiljaoca -->
                <div class="header">
                    D Express doo, Zage Malivuk 1, Beograd
                    <div class="package-info">
                        <?php echo esc_html($package_index); ?>/<?php echo esc_html($package_count); ?>
                    </div>
                </div>

                <!-- Podaci pošiljaoca -->
                <div class="sender-info">
                    <strong>Pošiljalac:</strong><br>
                    <?php echo esc_html(get_option('dexpress_sender_name')); ?>,
                    <?php echo esc_html(get_option('dexpress_sender_address')); ?>
                    <?php echo esc_html(get_option('dexpress_sender_address_num')); ?>,
                    <?php
                    $town_id = get_option('dexpress_sender_town_id');
                    global $wpdb;
                    $town_name = $wpdb->get_var($wpdb->prepare(
                        "SELECT name FROM {$wpdb->prefix}dexpress_towns WHERE id = %d",
                        $town_id
                    ));
                    echo esc_html($town_name);
                    ?><br>
                    <?php echo esc_html(get_option('dexpress_sender_contact_phone')); ?>
                </div>

                <!-- Barcode sekcija -->
                <div class="barcode-container">
                    <svg class="barcode"></svg>
                    <script>
                        JsBarcode(".barcode", "<?php echo esc_js($tracking_number); ?>", {
                            format: "CODE128",
                            width: 1.5,
                            height: 50,
                            displayValue: false,
                            margin: 0
                        });
                    </script>
                    <div class="tracking-number"><?php echo esc_html($tracking_number); ?></div>
                </div>

                <!-- Podaci primaoca -->
                <div class="recipient-info">
                    <div class="recipient-title">Primalac:</div>
                    <div class="recipient-name"><?php echo esc_html($order_data['shipping_name']); ?></div>
                    <div class="recipient-address"><?php echo esc_html($order_data['shipping_address']); ?></div>
                    <div class="recipient-city"><?php echo esc_html($order_data['shipping_postcode'] . ' ' . $order_data['shipping_city']); ?></div>
                    <div class="recipient-phone"><?php echo esc_html($order_data['shipping_phone']); ?></div>
                </div>

                <!-- Dodatni podaci -->
                <div class="shipment-details">
                    <div class="detail-row">
                        <span class="detail-label">Referentni broj:</span>
                        <?php echo esc_html($reference_id); ?>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Uslugu plaća:</span>
                        <?php echo esc_html(dexpress_get_payment_by_text(get_option('dexpress_payment_by', '0'))); ?> -
                        <?php echo esc_html(dexpress_get_payment_type_text(get_option('dexpress_payment_type', '2'))); ?>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Povratna dokumentacija:</span>
                        <?php echo esc_html(dexpress_get_return_doc_text(get_option('dexpress_return_doc', '0'))); ?>
                    </div>

                    <?php if (!empty($cod_amount)): ?>
                        <div class="detail-row">
                            <span class="detail-label">Otkupnina:</span>
                            <?php echo esc_html($cod_amount); ?>
                        </div>
                    <?php endif; ?>

                    <div class="detail-row">
                        <span class="detail-label">Sadržaj:</span>
                        <?php echo esc_html(get_option('dexpress_default_content', 'Roba iz web prodavnice')); ?>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Masa:</span>
                        <?php echo esc_html(number_format($order_data['total_weight'], 2, ',', '.') . ' kg'); ?>
                    </div>
                </div>

                <!-- Footer sa vremenom štampe -->
                <div class="footer">
                    Vreme štampe: <?php echo esc_html($print_date); ?>
                </div>
            </div>

            <button class="print-button" onclick="window.print()"><?php _e('Štampaj nalepnicu', 'd-express-woo'); ?></button>
        </body>

        </html>
<?php
        return ob_get_clean();
    }

    /**
     * Pripremanje podataka o narudžbini za nalepnicu
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return array Podaci o narudžbini
     */
    private function prepare_order_data($order)
    {
        // Podaci o pošiljaocu
        $sender_name = get_option('dexpress_sender_name', '');
        $sender_address = get_option('dexpress_sender_address', '') . ' ' . get_option('dexpress_sender_address_num', '');
        $sender_town_id = get_option('dexpress_sender_town_id', '');

        // Dobijanje naziva grada pošiljaoca
        global $wpdb;
        $sender_city = '';

        if (!empty($sender_town_id)) {
            $sender_city = $wpdb->get_var($wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}dexpress_towns WHERE id = %d",
                $sender_town_id
            ));
        }

        // Izračunavanje ukupne težine narudžbine
        $total_weight = 0;
        // foreach ($order->get_items() as $item) {
        //     $product = $item->get_product();
        //     if ($product && $product->has_weight()) {
        //         $total_weight += floatval($product->get_weight()) * $item->get_quantity();
        //     }
        // }

        // Ako nema težine, postavimo neku podrazumevanu vrednost
        if ($total_weight <= 0) {
            $total_weight = 0.5; // 500g
        }

        // Podaci o pošiljci
        return array(
            'sender_name' => $sender_name,
            'sender_address' => $sender_address,
            'sender_city' => $sender_city,
            'shipping_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'shipping_address' => $order->get_shipping_address_1(),
            'shipping_city' => $order->get_shipping_city(),
            'shipping_postcode' => $order->get_shipping_postcode(),
            'shipping_phone' => $order->get_billing_phone(),
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'payment_method' => $order->get_payment_method(),
            'order_total' => $order->get_total(),
            'formatted_total' => $order->get_formatted_order_total(),
            'customer_note' => $order->get_customer_note(),
            'total_weight' => $total_weight
        );
    }
}
