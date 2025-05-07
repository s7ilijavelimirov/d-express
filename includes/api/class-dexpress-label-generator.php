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

        add_action('wp_ajax_dexpress_bulk_print_labels', array($this, 'ajax_bulk_print_labels'));
    }
    /**
     * AJAX akcija za masovno štampanje nalepnica
     */
    /**
     * AJAX akcija za masovno štampanje nalepnica
     */
    public function ajax_bulk_print_labels()
    {
        // Provera nonce-a
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'dexpress-bulk-print')) {
            wp_die(__('Sigurnosna provera nije uspela.', 'd-express-woo'));
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za ovu akciju.', 'd-express-woo'));
        }

        // Provera ID-jeva pošiljki
        if (!isset($_REQUEST['shipment_ids']) || empty($_REQUEST['shipment_ids'])) {
            wp_die(__('Nisu odabrane pošiljke za štampanje.', 'd-express-woo'));
        }

        $shipment_ids = explode(',', sanitize_text_field($_REQUEST['shipment_ids']));
        $shipment_ids = array_map('intval', $shipment_ids);

        if (empty($shipment_ids)) {
            wp_die(__('Nisu odabrane pošiljke za štampanje.', 'd-express-woo'));
        }

        // Dobavljanje podataka o pošiljkama
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($shipment_ids), '%d'));

        $shipments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id IN ($placeholders)",
                $shipment_ids
            )
        );

        if (empty($shipments)) {
            wp_die(__('Pošiljke nisu pronađene.', 'd-express-woo'));
        }
        $total_shipments = count($shipments);
        // Početak HTML-a za nalepnice
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>D Express - Nalepnice</title>
            <style>
                /* Osnovna podešavanja */
            body {
                font-family: Arial, sans-serif;
                margin: 0;
                padding: 0;
                background-color: #f4f4f4;
            }
            
            /* Stilovi za štampanje */
            @page {
                size: A4; /* Standardni A4 format */
                margin: 0.5cm; /* Manji margin za više nalepnica */
            }
            
            /* Kontejner za štampanje */
            .print-container {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-around;
                max-width: 21cm; /* A4 širina */
                margin: 0 auto;
            }
            
            /* Pojedinačna nalepnica */
            .label-container {
                width: 10cm; /* Oko pola A4 papira širine */
                height: 14cm; /* Oko trećine A4 papira visine */
                margin: 0.2cm;
                border: 1px solid #000;
                page-break-inside: avoid; /* Sprečava prelom nalepnica */
                background-color: #fff;
                position: relative;
                padding: 0.2cm;
                box-sizing: border-box;
            }
            
            /* Kontrolni deo koji se ne štampa */
            .no-print {
                text-align: center;
                background-color: #f8f8f8;
                padding: 20px;
                border-radius: 5px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                max-width: 600px;
                margin: 20px auto;
            }
            
            .print-button {
                background-color: #2271b1;
                color: white;
                border: none;
                padding: 12px 24px;
                font-size: 16px;
                cursor: pointer;
                border-radius: 4px;
                margin-top: 15px;
            }
            
            .print-button:hover {
                background-color: #135e96;
            }
            
            .shipment-count {
                font-size: 16px;
                margin: 10px 0 20px;
            }
            
            /* Elementi nalepnice */
            .header {
                padding: 3px;
                font-size: 8px;
                text-align: left;
                border-bottom: 1px solid #eee;
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
                padding: 5px 0;
            }
            
            .barcode {
                height: 40px;
                margin: 0 auto;
            }
            
            .tracking-number {
                font-size: 12px;
                font-weight: bold;
                text-align: center;
                margin-top: 3px;
            }
            
            .sender-info {
                padding: 3px;
                font-size: 9px;
                border-bottom: 1px solid #eee;
            }
            
            .recipient-info {
                padding: 3px;
                text-align: center;
            }
            
            .recipient-title {
                margin-bottom: 2px;
                font-size: 10px;
            }
            
            .recipient-name {
                font-size: 14px;
                font-weight: bold;
            }
            
            .recipient-address {
                font-size: 12px;
                font-weight: bold;
                margin-top: 2px;
            }
            
            .recipient-city {
                font-size: 12px;
                font-weight: bold;
                margin-top: 2px;
            }
            
            .recipient-phone {
                font-size: 12px;
                font-weight: bold;
                margin-top: 2px;
            }
            
            .shipment-details {
                padding: 3px;
                font-size: 8px;
            }
            
            .detail-row {
                margin-bottom: 2px;
            }
            
            .detail-label {
                font-weight: normal;
                display: inline-block;
                width: 100px;
            }
            
            .footer {
                padding: 3px;
                width: 100%;
                box-sizing: border-box;
                text-align: center;
                font-size: 8px;
                border-top: 1px solid #eee;
                margin-top: 5px;
            }
            
            @media print {
                body {
                    background-color: white;
                }
                .no-print {
                    display: none;
                }
                .print-container {
                    width: 100%;
                }
            }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    </head>
    <body>
        <div class="no-print">
            <h1>' . __('D Express nalepnice za štampanje', 'd-express-woo') . '</h1>
            <p class="shipment-count">' . sprintf(__('Ukupno %d nalepnica za štampanje.', 'd-express-woo'), count($shipments)) . '</p>
            <button onclick="window.print()" class="print-button">' . __('Štampaj sve nalepnice', 'd-express-woo') . '</button>
        </div>
        
        <div class="print-container">';

        // Generisanje nalepnica za svaku pošiljku
        $shipment_index = 1; // Brojač pošiljki

        foreach ($shipments as $shipment) {
            $order = wc_get_order($shipment->order_id);

            if ($order) {
                $this->generate_compact_label($shipment, $order, $shipment_index, $total_shipments);
                $shipment_index++; // Povećavamo indeks za sledeću pošiljku

                // Označimo svaku kao odštampanu
                update_post_meta($order->get_id(), '_dexpress_label_printed', 'yes');
                update_post_meta($order->get_id(), '_dexpress_label_printed_date', current_time('mysql'));
            }
        }

        echo '</div></body></html>';
        exit;
    }
    /**
     * Dobavlja broj paketa za pošiljku
     * 
     * @param object $shipment Podaci o pošiljci
     * @return int Broj paketa
     */
    private function get_package_count($shipment)
    {
        // Ako postoji PackageList u podacima pošiljke, koristimo njegovu dužinu
        if (!empty($shipment->package_list)) {
            $package_list = maybe_unserialize($shipment->package_list);
            if (is_array($package_list)) {
                return count($package_list);
            }
        }

        // Ako nemamo podatke o paketima, pretpostavljamo da je jedan paket
        return 1;
    }
    /**
     * Generiše kompaktnu nalepnicu za višestruko štampanje
     * 
     * @param object $shipment Podaci o pošiljci
     * @param WC_Order $order WooCommerce narudžbina
     * @param int $package_index Indeks trenutnog paketa (opciono)
     * @param int $package_count Ukupan broj paketa (opciono)
     */
    public function generate_compact_label($shipment, $order, $package_index = 1, $package_count = 1)
    {
        // Pripremanje podataka za nalepnicu
        $order_data = $this->prepare_order_data($order);

        $address_type = $order->has_shipping_address() ? 'shipping' : 'billing';
        $address_desc = get_post_meta($order->get_id(), "_{$address_type}_address_desc", true);

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

        // Town name za pošiljaoca
        $town_id = get_option('dexpress_sender_town_id');
        global $wpdb;
        $town_name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}dexpress_towns WHERE id = %d",
            $town_id
        ));

        // Debug info
        $debug_info = "Generating label for order: " . $order->get_id() . "\n";
        $debug_info .= "Address description: " . print_r($order_data['shipping_address_desc'], true) . "\n";
        $debug_info .= "Package index/count: " . $package_index . "/" . $package_count . "\n";
        error_log($debug_info);

        // HTML za nalepnicu
?>
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
                <?php echo esc_html($town_name); ?><br>
                <?php echo esc_html(get_option('dexpress_sender_contact_phone')); ?>
            </div>

            <!-- Barcode sekcija -->
            <div class="barcode-container">
                <svg class="barcode-<?php echo esc_attr($tracking_number); ?>"></svg>
                <script>
                    JsBarcode(".barcode-<?php echo esc_js($tracking_number); ?>", "<?php echo esc_js($tracking_number); ?>", {
                        format: "CODE128",
                        width: 2.4,
                        height: 70,
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
                    <span class="detail-label">Dokumentacija:</span>
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
                    <?php
                    // Prikazujemo težinu bez decimalnih mesta ako je ceo broj
                    if ($order_data['total_weight'] == (int)$order_data['total_weight']) {
                        echo esc_html(number_format($order_data['total_weight'], 0, ',', '.') . ' kg');
                    } else {
                        echo esc_html(number_format($order_data['total_weight'], 1, ',', '.') . ' kg');
                    }
                    ?>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Napomena:</span>
                    <?php echo !empty($order_data['shipping_address_desc']) ? esc_html($order_data['shipping_address_desc']) : ''; ?>
                </div>
            </div>

            <!-- Footer sa vremenom štampe -->
            <div class="footer">
                Vreme štampe: <?php echo esc_html($print_date); ?>
            </div>
        </div>
    <?php
    }
    /**
     * AJAX akcija za preuzimanje nalepnice
     */
    // U includes/api/class-dexpress-label-generator.php, izmeni ajax_download_label funkciju:

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

        $shipment_id = sanitize_text_field($_GET['shipment_id']);

        // Dobijanje podataka o pošiljci
        global $wpdb;
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d OR shipment_id = %s OR tracking_number = %s",
            intval($shipment_id),
            $shipment_id,
            $shipment_id
        ));

        if (!$shipment) {
            wp_die(__('Pošiljka nije pronađena.', 'd-express-woo'));
        }

        $order = wc_get_order($shipment->order_id);
        if (!$order) {
            wp_die(__('Narudžbina nije pronađena.', 'd-express-woo'));
        }

        $package_count = $this->get_package_count($shipment);

        // Generišemo HTML za štampanje (stilovi, skripte, itd.)
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>D Express - Nalepnica</title>
            <style>
                /* Osnovna podešavanja */
                body {
                    font-family: Arial, sans-serif;
                    margin: 0;
                    padding: 0;
                    background-color: #f4f4f4;
                }
                
                /* Stilovi za štampanje */
                @page {
                    size: A4; /* Standardni A4 format */
                    margin: 0.5cm; /* Manji margin za više nalepnica */
                }
                
                /* Kontejner za štampanje */
                .print-container {
                    display: flex;
                    flex-wrap: wrap;
                    justify-content: space-around;
                    max-width: 21cm; /* A4 širina */
                    margin: 0 auto;
                }
                
                /* Pojedinačna nalepnica */
                .label-container {
                    width: 10cm; /* Oko pola A4 papira širine */
                    height: 14cm; /* Oko trećine A4 papira visine */
                    margin: 0.2cm;
                    border: 1px solid #000;
                    page-break-inside: avoid; /* Sprečava prelom nalepnica */
                    background-color: #fff;
                    position: relative;
                    padding: 0.2cm;
                    box-sizing: border-box;
                }
                
                /* Kontrolni deo koji se ne štampa */
                .no-print {
                    text-align: center;
                    background-color: #f8f8f8;
                    padding: 20px;
                    border-radius: 5px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                    max-width: 600px;
                    margin: 20px auto;
                }
                
                .print-button {
                    background-color: #2271b1;
                    color: white;
                    border: none;
                    padding: 12px 24px;
                    font-size: 16px;
                    cursor: pointer;
                    border-radius: 4px;
                    margin-top: 15px;
                }
                
                .print-button:hover {
                    background-color: #135e96;
                }
                
                .shipment-count {
                    font-size: 16px;
                    margin: 10px 0 20px;
                }
                
                /* Elementi nalepnice */
                .header {
                    padding: 3px;
                    font-weight:600;
                    font-size: 8px;
                    text-align: center;
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
                    padding: 5px 0;
                }
                
                .barcode {
                    height: 40px;
                    margin: 0 auto;
                }
                
                .tracking-number {
                    font-size: 12px;
                    font-weight: bold;
                    text-align: center;
                    margin-top: 3px;
                }
                
                .sender-info {
                    padding: 3px;
                    font-size: 9px;
                    border-bottom: 1px solid #eee;
                }
                
                .recipient-info {
                    padding: 3px;
                    text-align: center;
                }
                
                .recipient-title {
                    margin-bottom: 2px;
                    font-size: 10px;
                }
                
                .recipient-name {
                    font-size: 14px;
                    font-weight: bold;
                }
                
                .recipient-address {
                    font-size: 12px;
                    font-weight: bold;
                    margin-top: 2px;
                }
                
                .recipient-city {
                    font-size: 12px;
                    font-weight: bold;
                    margin-top: 2px;
                }
                
                .recipient-phone {
                    font-size: 12px;
                    font-weight: bold;
                    margin-top: 2px;
                }
                
                .shipment-details {
                    padding: 3px;
                    font-size: 8px;
                }
                
                .detail-row {
                    margin-bottom: 2px;
                }
                
                .detail-label {
                    font-weight: normal;
                    display: inline-block;
                    width: 100px;
                }
                
                .footer {
                    padding: 3px;
                    width: 100%;
                    box-sizing: border-box;
                    text-align: center;
                    font-size: 8px;
                    border-top: 1px solid #eee;
                    margin-top: 5px;
                }
                
                @media print {
                    body {
                        background-color: white;
                    }
                    .no-print {
                        display: none;
                    }
                    .print-container {
                        width: 100%;
                    }
                }
            </style>
            <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
        </head>
        <body>
            <div class="no-print">
                <h1>' . __('D Express nalepnica za štampanje', 'd-express-woo') . '</h1>
                <button onclick="window.print()" class="print-button">' . __('Štampaj nalepnicu', 'd-express-woo') . '</button>
            </div>
        
        <div class="print-container">';

        // Prikaz nalepnice koristeći istu funkciju kao za bulk štampanje
        for ($i = 1; $i <= $package_count; $i++) {
            $this->generate_compact_label($shipment, $order, $i, $package_count);
        }

        echo '</div></body></html>';

        // Označimo kao odštampano nakon što je HTML generisan
        update_post_meta($order->get_id(), '_dexpress_label_printed', 'yes');
        update_post_meta($order->get_id(), '_dexpress_label_printed_date', current_time('mysql'));

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

    public function generate_dexpress_label_html($shipment, $is_bulk = false)
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
                    <?php if (!empty($address_desc)): ?>
                        <div class="recipient-address-desc"><?php echo esc_html($address_desc); ?></div>
                    <?php endif; ?>
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
                        <?php
                        // Prikazujemo težinu bez decimalnih mesta ako je ceo broj
                        if ($order_data['total_weight'] == (int)$order_data['total_weight']) {
                            echo esc_html(number_format($order_data['total_weight'], 0, ',', '.') . ' kg');
                        } else {
                            echo esc_html(number_format($order_data['total_weight'], 1, ',', '.') . ' kg');
                        }
                        ?>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Napomena:</span>
                        <?php echo !empty($order_data['shipping_address_desc']) ? esc_html($order_data['shipping_address_desc']) : ''; ?>
                    </div>
                </div>

                <!-- Footer sa vremenom štampe -->
                <div class="footer">
                    Vreme štampe: <?php echo esc_html($print_date); ?>
                </div>
            </div>

            <?php if (!$is_bulk): ?>
                <button class="print-button" onclick="window.print()"><?php _e('Štampaj nalepnicu', 'd-express-woo'); ?></button>
            <?php endif; ?>
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
        if (class_exists('D_Express_Validator')) {
            $weight_grams = D_Express_Validator::calculate_order_weight($order);
            $total_weight = $weight_grams / 1000; // Konverzija iz grama u kg za prikaz
        } else {
            // Alternativno, ako klasa nije dostupna, izračunaj ovde
            $total_weight = 0;
            foreach ($order->get_items() as $item) {
                // Provera da li je $item instanca WC_Order_Item_Product
                if (!($item instanceof WC_Order_Item_Product)) {
                    continue;
                }

                $product = $item->get_product();
                if ($product && $product instanceof WC_Product && $product->has_weight()) {
                    $total_weight += floatval($product->get_weight()) * $item->get_quantity();
                }
            }

            // Ako nema težine, postavimo neku podrazumevanu vrednost
            if ($total_weight <= 0) {
                $total_weight = 0.5; // 500g = 0.5kg
            }
        }

        // Odredi tip adrese (billing ili shipping)
        $address_type = $order->has_shipping_address() ? 'shipping' : 'billing';

        // Prvo pokušaj da dobiješ napomenu iz odgovarajućeg tipa adrese
        $address_desc = get_post_meta($order->get_id(), "_{$address_type}_address_desc", true);

        // Ako je napomena prazna, a koristimo shipping adresu, probaj i billing
        if (empty($address_desc) && $address_type === 'shipping') {
            $address_desc = get_post_meta($order->get_id(), "_billing_address_desc", true);
        }

        // Podaci o pošiljci
        return array(
            'sender_name' => $sender_name,
            'sender_address' => $sender_address,
            'sender_city' => $sender_city,
            'shipping_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'shipping_address' => $order->get_shipping_address_1(),
            'shipping_address_desc' => $address_desc, // Dodali smo napomenu ovde
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
