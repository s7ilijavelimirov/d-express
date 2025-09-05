<?php

/**
 * D Express Label Generator - Refaktorisana verzija
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
     * Uklanja duplirani postcode iz display_name
     */
    private function clean_town_name($town_name, $postal_code)
    {
        if (empty($town_name) || empty($postal_code)) {
            return $town_name;
        }

        // Ukloni " - postcode" sa kraja
        $pattern = '/ - ' . preg_quote($postal_code, '/') . '$/';
        return preg_replace($pattern, '', $town_name);
    }

    /**
     * Generiše jedinstveni CSS za sve nalepnice
     */
    private function get_label_css()
    {
        return '
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
                size: A4;
                margin: 0.5cm;
            }
            
            /* Kontrolni deo koji se ne štampa */
            .no-print {
                text-align: center;
                max-width: 600px;
                margin: 20px auto;
            }
            
            .print-button {
                background-color: #f80400;
                color: white;
                border: none;
                padding: 12px 24px;
                font-size: 16px;
                cursor: pointer;
                border-radius: 4px;
                margin-top: 15px;
            }
            
            .print-button:hover {
                background-color: #f80400a9;
            }
            
            .shipment-count {
                font-size: 16px;
                margin: 10px 0 20px;
            }
            
            /* Kontejner za štampanje */
            .print-container {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                max-width: 50cm;
                margin: 0 auto;
            }
            
            /* Pojedinačna nalepnica */
            .label-container {
                width: 13.22917cm;
                height: 14cm;
                margin: 0.2cm;
                border: 1px solid #000;
                page-break-inside: avoid;
                background-color: #fff;
                position: relative;
                padding: 0.2cm 0.4cm;
                box-sizing: border-box;
            }
            
            /* Elementi nalepnice */
            .header {
                padding: 3px;
                font-size: 12px;
                text-align: center;
            }
            
            .package-info {
                position: absolute;
                top: 12px;
                right: 12px;
                font-weight: bold;
                font-size: 1.6rem;
            }
            /* Osnovni stil za sve kvržice */
            .corner {
            position: absolute;
            width: 20px;   /* veličina kvržice */
            height: 20px;
            }

            /* Gornji levi */
            .corner.top-left {
            top: 0;
            left: 0;
            border-top: 3px solid black;
            border-left: 3px solid black;
            }

            /* Gornji desni */
            .corner.top-right {
            top: 0;
            right: 0;
            border-top: 3px solid black;
            border-right: 3px solid black;
            }

            /* Donji levi */
            .corner.bottom-left {
            bottom: 0;
            left: 0;
            border-bottom: 3px solid black;
            border-left: 3px solid black;
            }

            /* Donji desni */
            .corner.bottom-right {
            bottom: 0;
            right: 0;
            border-bottom: 3px solid black;
            border-right: 3px solid black;
            }
            .barcode-container {
                position: relative;
                text-align: center;
                padding-top:8px;
                border-radius: 4px;
                width:auto;
            }
           

            .tracking-number {
                font-size: 12px;
                font-weight: bold;
                text-align: center;
                margin-top: 3px;
            }
            
            .sender-info {
                padding: 8px 0px;
                line-height:1.4;
                font-size: 13px;
                border-bottom: 1px solid #eee;
            }
            
            .recipient-info {
                padding: 8px;
                text-align: center;
            }
            
            .recipient-title {
                margin-bottom: 2px;
                font-size: 0.8rem;
                font-weight:bold;
            }
            
            .recipient-name {
                font-size: 1.4rem;
                font-weight: bold;
            }
            
            .recipient-address {
                font-size: 1.4rem;
                font-weight: bold;
                margin-top: 2px;
            }
            
            .recipient-city {
                 font-size: 1.4rem;
                font-weight: bold;
                margin-top: 2px;
            }
            
            .recipient-phone {
               font-size: 1.4rem;
                font-weight: bold;
                margin-top: 2px;
            }
            .shipment-row {
                padding: 8px 0px;
                display:flex;
            }
            .shipment-details {
                font-size: 0.8rem;
                font-weight:500;
            }
            .shipment-details.one{
                flex:2;
            }
            .shipment-details.two{
                flex:1;
            }
            .detail-row {
                margin-bottom: 2px;
            }
            
            .detail-label {
                font-weight: bold;
                display: inline-block;
               
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
        </style>';
    }

    /**
     * Generiše HTML wrapper za sve nalepnice
     */
    private function generate_html_wrapper($content, $title = 'D Express - Nalepnice', $shipment_count = null)
    {
        $count_html = '';
        if ($shipment_count) {
            $count_html = '<p class="shipment-count">' .
                sprintf(__('Ukupno %d nalepnica za štampanje.', 'd-express-woo'), $shipment_count) .
                '</p>';
        }

        return '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . esc_html($title) . '</title>
            ' . $this->get_label_css() . '
            <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
        </head>
        <body>
            <div class="no-print">
                <img src="' . plugin_dir_url(__FILE__) . '../../assets/images/Dexpress-logo.jpg" alt="Logo" height="100" class="dexpress-settings-logo">
                <h1>' . esc_html($title) . '</h1>
                ' . $count_html . '
                <button onclick="window.print()" class="print-button">' . __('Štampaj nalepnice', 'd-express-woo') . '</button>
            </div>
            
            <div class="print-container">
                ' . $content . '
            </div>
        </body>
        </html>';
    }
    /**
     * Izračunava COD iznos za shipment
     */
    private function calculate_split_cod_amount($order, $shipment)
    {
        if ($order->get_payment_method() !== 'cod') {
            return 0;
        }

        // Za split shipment sa više pošiljki
        if (!empty($shipment->split_index) && !empty($shipment->total_splits) && $shipment->total_splits > 1) {
            // Prvi split dobija ceo COD, ostali 0
            if ($shipment->split_index == 1) {
                return $order->get_total(); // Cela narudžba
            } else {
                return 0; // Ostali splitovi bez COD-a
            }
        }

        // Za obične shipments (bez split-a)
        return $order->get_total();
    }

    /**
     * AJAX akcija za masovno štampanje nalepnica - ENHANCED
     */
    public function ajax_bulk_print_labels()
    {
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'dexpress-bulk-print')) {
            wp_die(__('Sigurnosna provera nije uspela.', 'd-express-woo'));
        }

        // Provera dozvola
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nemate dozvolu za ovu akciju.', 'd-express-woo'));
        }

        global $wpdb;
        $shipment_ids = [];

        // NOVO: Proverava da li se koristi date_filter umesto direktnih shipment_ids
        if (isset($_REQUEST['date_filter']) && !empty($_REQUEST['date_filter'])) {
            $date_filter = sanitize_key($_REQUEST['date_filter']);

            // Generiši shipment_ids na osnovu date filter-a
            switch ($date_filter) {
                case 'today':
                    $condition = "AND DATE(s.created_at) = CURDATE()";
                    break;
                case 'yesterday':
                    $condition = "AND DATE(s.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
                    break;
                case 'last_3_days':
                    $condition = "AND DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)";
                    break;
                case 'unprinted':
                    $condition = "AND s.order_id NOT IN (
                    SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_dexpress_label_printed' AND meta_value = 'yes'
                )";
                    break;
                default:
                    $condition = "";
            }

            $shipment_ids = $wpdb->get_col(
                "SELECT s.id FROM {$wpdb->prefix}dexpress_shipments s WHERE 1=1 $condition ORDER BY s.created_at DESC"
            );

            if (empty($shipment_ids)) {
                wp_die(__('Nema pošiljki za štampanje sa odabranim filterom.', 'd-express-woo'));
            }
        } else {
            // Postojeća logika za direktne shipment_ids
            if (!isset($_REQUEST['shipment_ids']) || empty($_REQUEST['shipment_ids'])) {
                wp_die(__('Nisu odabrane pošiljke za štampanje.', 'd-express-woo'));
            }

            // KONVERTUJ PIPE U COMMA
            $ids_string = str_replace('|', ',', sanitize_text_field($_REQUEST['shipment_ids']));
            $shipment_ids = explode(',', $ids_string);
            $shipment_ids = array_map('intval', $shipment_ids);

            if (empty($shipment_ids)) {
                wp_die(__('Nisu odabrane pošiljke za štampanje.', 'd-express-woo'));
            }
        }

        // Ostatak funkcije ostaje isti...
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
        $content = '';

        foreach ($shipments as $shipment) {
            $order = wc_get_order($shipment->order_id);

            if ($order) {
                $packages = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d ORDER BY package_index ASC",
                    $shipment->id
                ));

                if (!empty($packages)) {
                    foreach ($packages as $package) {
                        ob_start();
                        $this->generate_compact_label($shipment, $order, $package);
                        $content .= ob_get_clean();
                    }
                } else {
                    ob_start();
                    $this->generate_compact_label($shipment, $order, null);
                    $content .= ob_get_clean();
                }

                // Označimo kao odštampanu
                update_post_meta($order->get_id(), '_dexpress_label_printed', 'yes');
                update_post_meta($order->get_id(), '_dexpress_label_printed_date', current_time('mysql'));
            }
        }

        $total_labels = substr_count($content, 'class="label-container"');
        echo $this->generate_html_wrapper($content, 'D Express nalepnice za štampanje', $total_labels);
        exit;
    }

    /**
     * Dobavlja broj paketa za pošiljku
     */
    private function get_package_count($shipment)
    {
        global $wpdb;

        // Jednostavno: broji pakete u packages tabeli
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d",
            $shipment->id
        ));

        return $count ? intval($count) : 1; // Fallback na 1
    }

    /**
     * Generiše kompaktnu nalepnicu - OPTIMIZOVANA
     */
    public function generate_compact_label($shipment, $order, $package = null)
    {
        // Pripremanje podataka za nalepnicu
        $order_data = $this->prepare_order_data($order, $shipment);

        $address_type = $order->has_shipping_address() ? 'shipping' : 'billing';
        $address_desc = get_post_meta($order->get_id(), "_{$address_type}_address_desc", true);

        // Tracking broj

        // ✅ ISPRAVKA: Koristi package_code iz package objekta ili fallback
        if ($package && !empty($package->package_code)) {
            $tracking_number = $package->package_code;
            // Proveri da li ova pošiljka ima više paketa ili je odvojena pošiljka
            if ($package->total_packages > 1) {
                $package_display = $package->package_index . '/' . $package->total_packages;
            } else {
                // Odvojena pošiljka - uvek 1/1
                $package_display = '1/1';
            }
        } else {
            // Fallback logika
            $tracking_number = !empty($shipment->tracking_number)
                ? $shipment->tracking_number
                : (!empty($shipment->package_code) ? $shipment->package_code : 'TT' . str_pad(rand(1, 999999), 10, '0', STR_PAD_LEFT));
            $package_display = '1/1';
        }
        // Format otkupnine
        $cod_amount = '';
        if ($order_data['payment_method'] === 'cod') {
            $split_cod = $this->calculate_split_cod_amount($order, $shipment);
            $cod_amount = number_format($split_cod, 2, ',', '.') . ' RSD';
        }

        // Referentni broj
        $reference_id = $this->generate_reference_id($order, $shipment);

        // Datum i vreme štampe
        $print_date = date_i18n('d.m.Y H:i:s');

        // Sender lokacija sa logikom
        $sender_location = $this->get_sender_location($shipment, $order);

        // Generiši sadržaj pošiljke
        $shipment_content = $this->generate_shipment_content_for_split($order, $shipment);


?>
        <div class="label-container">
            <!-- Header sa podacima D Express filijale -->
            <div class="header">
                D Express doo, Zage Malivuk 1, Beograd
                <div class="package-info">
                    <?php echo esc_html($package_display); ?>
                </div>
            </div>

            <!-- Podaci pošiljaoca -->
            <div class="sender-info">
                <strong>Pošiljalac:</strong><br>
                <?php if ($sender_location): ?>
                    <?php
                    $clean_town_name = $this->clean_town_name($sender_location->town_name, $sender_location->town_postal_code);
                    ?>
                    <?php echo esc_html($sender_location->name); ?>,
                    <?php echo esc_html($sender_location->address . ' ' . $sender_location->address_num); ?>,
                    <?php echo esc_html($sender_location->town_postal_code . ' ' . $clean_town_name); ?><br>
                    <?php echo esc_html($sender_location->contact_phone); ?>
                <?php else: ?>
                    <em>Podaci o pošiljaocu nisu dostupni</em>
                <?php endif; ?>
            </div>

            <!-- Barcode sekcija -->
            <div class="barcode-container">
                <div class="corner top-left"></div>
                <div class="corner top-right"></div>
                <div class="corner bottom-left"></div>
                <div class="corner bottom-right"></div>
                <svg class="barcode-<?php echo esc_attr($tracking_number); ?>"></svg>
                <script>
                    JsBarcode(".barcode-<?php echo esc_js($tracking_number); ?>", "<?php echo esc_js($tracking_number); ?>", {
                        format: "CODE128",
                        width: 3.5,
                        height: 90,
                        displayValue: true,
                        margin: 1,
                        fontSize: 26
                    });
                </script>
                <div class="tracking-number" style="display:none;"><?php echo esc_html($tracking_number); ?></div>
            </div>

            <!-- Podaci primaoca -->
            <div class="recipient-info">
                <div class="recipient-title">Primalac:</div>
                <div class="recipient-name"><?php echo esc_html($order_data['shipping_name']); ?></div>
                <div class="recipient-address"><?php echo esc_html($order_data['shipping_address']); ?></div>
                <div class="recipient-city"><?php echo esc_html($order_data['shipping_postcode'] . ' ' . $order_data['shipping_city']); ?></div>
                <div class="recipient-phone"><?php echo esc_html($order_data['shipping_phone']); ?></div>
            </div>
            <div class="shipment-row">
                <!-- Dodatni podaci -->
                <div class="shipment-details one">
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
                        <?php
                        if ($package && !empty($package->content)) {
                            $package_content = $package->content;
                        } else {
                            // Fallback na shipment content
                            $package_content = $this->generate_shipment_content_for_split($order, $shipment);
                        }
                        echo esc_html($package_content);
                        ?>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Masa:</span>
                        <?php
                        // ✅ Koristi masu konkretnog paketa umesto ukupne pošiljke
                        if ($package && isset($package->mass)) {
                            $package_weight_kg = $package->mass / 1000; // Iz grama u kg
                            echo esc_html(number_format($package_weight_kg, ($package_weight_kg == (int)$package_weight_kg) ? 0 : 1, ',', '.') . ' kg');
                        } else {
                            // Fallback za stare pošiljke
                            $shipment_weight = $this->calculate_shipment_weight($order, $shipment);
                            echo esc_html(number_format($shipment_weight, ($shipment_weight == (int)$shipment_weight) ? 0 : 1, ',', '.') . ' kg');
                        }
                        ?>
                    </div>
                </div>
                <div class="shipment-details two">
                    <span class="detail-label">Napomena:</span><br>
                    <?php
                    echo (isset($order_data['shipping_address_desc']) && !empty($order_data['shipping_address_desc']))
                        ? esc_html($order_data['shipping_address_desc'])
                        : '/';
                    ?>
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
     * Dobija sender lokaciju sa logikom
     */
    private function get_sender_location($shipment, $order)
    {
        $sender_service = D_Express_Sender_Locations::get_instance();

        if (!empty($shipment->sender_location_id)) {
            return $sender_service->get_location($shipment->sender_location_id);
        } else {
            $used_location_id = get_post_meta($order->get_id(), '_dexpress_used_sender_location_id', true);
            if ($used_location_id) {
                return $sender_service->get_location($used_location_id);
            } else {
                return $sender_service->get_default_location();
            }
        }
    }

    /**
     * Generiše referencični broj za shipment
     */
    private function generate_reference_id($order, $shipment)
    {
        if (!empty($shipment->reference_id)) {
            return $shipment->reference_id;
        }

        if (!empty($shipment->split_index) && !empty($shipment->total_splits) && $shipment->total_splits > 1) {
            return $order->get_id() . '-' . $shipment->split_index;
        }

        return 'ORDER-' . $order->get_id();
    }

    /**
     * Izračunava težinu za specifičan shipment
     */
    private function calculate_shipment_weight($order, $shipment)
    {
        // Ako je split shipment, kalkuliši težinu samo za artikle koji pripadaju ovom split-u
        if (!empty($shipment->split_index) && !empty($shipment->total_splits) && $shipment->total_splits > 1) {
            $shipment_splits = get_post_meta($order->get_id(), '_dexpress_shipment_splits', true);

            if (!empty($shipment_splits) && isset($shipment_splits[$shipment->split_index - 1])) {
                $split_data = $shipment_splits[$shipment->split_index - 1];
                $selected_items = isset($split_data['items']) ? $split_data['items'] : [];

                if (!empty($selected_items)) {
                    $total_weight = 0;

                    foreach ($order->get_items() as $item_id => $item) {
                        if (in_array($item_id, $selected_items)) {
                            if (!($item instanceof WC_Order_Item_Product)) {
                                continue;
                            }

                            $product = $item->get_product();
                            $quantity = $item->get_quantity();

                            if ($product && $product->has_weight()) {
                                $total_weight += floatval($product->get_weight()) * $quantity;
                            }
                        }
                    }

                    return $total_weight > 0 ? $total_weight : 0.5;
                }
            }
        }

        // Fallback: koristi težinu cele narudžbine
        $total_weight = 0;
        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if ($product && $product instanceof WC_Product && $product->has_weight()) {
                $total_weight += floatval($product->get_weight()) * $item->get_quantity();
            }
        }

        return $total_weight > 0 ? $total_weight : 0.5;
    }

    /**
     * Generiše sadržaj pošiljke za split shipment
     */
    private function generate_shipment_content_for_split($order, $shipment)
    {
        // Ako je split shipment, koristi samo artikle koji pripadaju ovom split-u
        if (!empty($shipment->split_index) && !empty($shipment->total_splits) && $shipment->total_splits > 1) {
            $shipment_splits = get_post_meta($order->get_id(), '_dexpress_shipment_splits', true);

            if (!empty($shipment_splits) && isset($shipment_splits[$shipment->split_index - 1])) {
                $split_data = $shipment_splits[$shipment->split_index - 1];
                $selected_items = isset($split_data['items']) ? $split_data['items'] : [];

                if (!empty($selected_items)) {
                    $content_parts = [];

                    foreach ($order->get_items() as $item_id => $item) {
                        if (in_array($item_id, $selected_items)) {
                            if (!($item instanceof WC_Order_Item_Product)) {
                                continue;
                            }

                            $product = $item->get_product();
                            $quantity = $item->get_quantity();

                            if ($product) {
                                $content_parts[] = $quantity . 'x ' . $product->get_name();
                            }
                        }
                    }

                    return !empty($content_parts) ? implode(', ', $content_parts) : '';
                }
            }
        }

        // Fallback: koristi postojeću funkciju za celu narudžbinu
        if (function_exists('dexpress_generate_shipment_content')) {
            return dexpress_generate_shipment_content($order);
        }

        // Final fallback
        $content_parts = [];
        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if ($product) {
                $content_parts[] = $item->get_quantity() . 'x ' . $product->get_name();
            }
        }

        return !empty($content_parts) ? implode(', ', $content_parts) : 'Proizvodi';
    }

    /**
     * Pripremanje podataka o narudžbini za nalepnicu - OPTIMIZOVANO
     */
    private function prepare_order_data($order, $shipment = null)
    {
        // Sender podaci
        $sender_location = $this->get_sender_location($shipment, $order);

        // Tip adrese
        $address_type = $order->has_shipping_address() ? 'shipping' : 'billing';

        // Formatirana adresa
        $shipping_address = $this->format_dexpress_address($order, $address_type);

        // Napomena
        $address_desc = get_post_meta($order->get_id(), "_{$address_type}_address_desc", true);
        if (empty($address_desc) && $address_type === 'shipping') {
            $address_desc = get_post_meta($order->get_id(), "_billing_address_desc", true);
        }

        // Težina
        if ($shipment) {
            $total_weight = $this->calculate_shipment_weight($order, $shipment);
        } else {
            $total_weight = 0;
            foreach ($order->get_items() as $item) {
                if (!($item instanceof WC_Order_Item_Product)) continue;

                $product = $item->get_product();
                if ($product && $product->has_weight()) {
                    $total_weight += floatval($product->get_weight()) * $item->get_quantity();
                }
            }

            if ($total_weight <= 0) $total_weight = 0.5;
        }

        return array(
            'sender_name' => $sender_location ? $sender_location->name : '',
            'sender_address' => $sender_location ? ($sender_location->address . ' ' . $sender_location->address_num) : '',
            'sender_city' => $sender_location ? $sender_location->town_name : '',
            'shipping_name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()) ?:
                trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'shipping_address' => $shipping_address,
            'shipping_address_desc' => $address_desc,
            'shipping_city' => $order->get_shipping_city() ?: $order->get_billing_city(),
            'shipping_postcode' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
            'shipping_phone' => $order->get_billing_phone(),
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'payment_method' => $order->get_payment_method(),
            'order_total' => $order->get_total(),
            'customer_note' => $order->get_customer_note(),
            'total_weight' => $total_weight
        );
    }

    /**
     * Formatiranje D Express adrese
     */
    private function format_dexpress_address($order, $address_type)
    {
        $street = get_post_meta($order->get_id(), "_{$address_type}_street", true);
        $number = get_post_meta($order->get_id(), "_{$address_type}_number", true);

        if (!empty($street) && !empty($number)) {
            return $street . ' ' . $number;
        }

        if ($address_type === 'shipping') {
            return $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        } else {
            return $order->get_billing_address_1();
        }
    }

    /**
     * AJAX akcija za preuzimanje nalepnice - REFAKTORISANA
     */
    public function ajax_download_label()
    {
        $nonce = $_GET['nonce'] ?? '';
        $valid_nonces = [
            'dexpress_admin_nonce',
            'dexpress-bulk-print',
            'dexpress-download-label'
        ];

        $nonce_valid = false;
        foreach ($valid_nonces as $nonce_name) {
            if (wp_verify_nonce($nonce, $nonce_name)) {
                $nonce_valid = true;
                break;
            }
        }

        if (!$nonce_valid || !current_user_can('manage_woocommerce')) {
            wp_die(__('Sigurnosna provera nije uspela1.', 'd-express-woo'));
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
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE id = %d",
            intval($shipment_id)
        ));
        if (!$shipment) {
            wp_die(__('Pošiljka nije pronađena.', 'd-express-woo'));
        }

        $order = wc_get_order($shipment->order_id);
        if (!$order) {
            wp_die(__('Narudžbina nije pronađena.', 'd-express-woo'));
        }

        global $wpdb;
        $packages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d ORDER BY package_index ASC",
            $shipment->id
        ));

        $content = '';
        if (!empty($packages)) {
            // Generiši nalepnicu za svaki paket sa pravilnim package_code
            foreach ($packages as $package) {
                ob_start();
                $this->generate_compact_label($shipment, $order, $package);
                $content .= ob_get_clean();
            }
        } else {
            // Fallback - ako nema paketa u bazi
            ob_start();
            $this->generate_compact_label($shipment, $order, null);
            $content .= ob_get_clean();
        }

        echo $this->generate_html_wrapper($content, 'D Express - Nalepnica');

        // Označimo kao odštampano
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
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress_admin_nonce')) {
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

    /**
     * Backup funkcija za kompatibilnost sa starim kodom
     * SADA KORISTI NOVI WRAPPER
     */
    public function generate_dexpress_label_html($shipment, $is_bulk = false)
    {
        $order = wc_get_order($shipment->order_id);

        if (!$order) {
            return '<p>' . __('Narudžbina nije pronađena.', 'd-express-woo') . '</p>';
        }

        $package_count = $this->get_package_count($shipment);
        $content = '';

        // Generiši nalepnice
        for ($i = 1; $i <= $package_count; $i++) {
            ob_start();
            $this->generate_compact_label($shipment, $order, $i, $package_count);
            $content .= ob_get_clean();
        }

        // Ako je bulk, vrati samo content, inače kompletan HTML
        if ($is_bulk) {
            return $content;
        } else {
            return $this->generate_html_wrapper($content, 'D Express - Nalepnica');
        }
    }
}
