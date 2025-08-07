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
            
            .barcode-container {
                text-align: center;
                padding-top:8px;
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
     * Izračunava COD iznos za shipment (bez dostave)
     */
    private function calculate_split_cod_amount($order, $shipment)
    {
        // Ako je split shipment, kalkuliši samo za artikle iz tog split-a
        if (!empty($shipment->split_index) && !empty($shipment->total_splits) && $shipment->total_splits > 1) {
            $shipment_splits = get_post_meta($order->get_id(), '_dexpress_shipment_splits', true);

            if (!empty($shipment_splits) && isset($shipment_splits[$shipment->split_index - 1])) {
                $split_data = $shipment_splits[$shipment->split_index - 1];
                $selected_items = isset($split_data['items']) ? $split_data['items'] : [];

                if (!empty($selected_items)) {
                    $split_value = 0;

                    foreach ($order->get_items() as $item_id => $item) {
                        if (in_array($item_id, $selected_items)) {
                            if (!($item instanceof WC_Order_Item_Product)) {
                                continue;
                            }
                            $split_value += ($item->get_total() + $item->get_total_tax());
                        }
                    }

                    return $split_value > 0 ? $split_value : 0;
                }
            }
        }

        // Fallback: Izračunaj vrednost svih proizvoda (bez dostave)
        $total_items_value = 0;
        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }
            $total_items_value += ($item->get_total() + $item->get_total_tax());
        }

        return $total_items_value;
    }

    /**
     * AJAX akcija za masovno štampanje nalepnica - REFAKTORISANA
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
        $content = '';
        $shipment_index = 1;

        // Generisanje nalepnica
        foreach ($shipments as $shipment) {
            $order = wc_get_order($shipment->order_id);

            if ($order) {
                ob_start();
                $this->generate_compact_label($shipment, $order, $shipment_index, $total_shipments);
                $content .= ob_get_clean();
                $shipment_index++;

                // Označimo kao odštampanu
                update_post_meta($order->get_id(), '_dexpress_label_printed', 'yes');
                update_post_meta($order->get_id(), '_dexpress_label_printed_date', current_time('mysql'));
            }
        }
        echo $this->generate_html_wrapper($content, 'D Express nalepnice za štampanje', $total_shipments);
        exit;
    }

    /**
     * Dobavlja broj paketa za pošiljku
     */
    private function get_package_count($shipment)
    {
        global $wpdb;

        // PRVO: Proveri da li postoje paketi u packages tabeli
        $package_count_from_db = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d",
            $shipment->id
        ));

        if ($package_count_from_db && intval($package_count_from_db) > 0) {
            return intval($package_count_from_db);
        }

        // FALLBACK: Proveri package_list kolonu u shipments tabeli
        if (!empty($shipment->package_list)) {
            $package_list = maybe_unserialize($shipment->package_list);
            if (is_array($package_list)) {
                return count($package_list);
            }
        }

        // POSLEDNJI FALLBACK: Default 1 paket
        return 1;
    }

    /**
     * Generiše kompaktnu nalepnicu - OPTIMIZOVANA
     */
    public function generate_compact_label($shipment, $order, $package_index = 1, $package_count = 1)
    {
        // Pripremanje podataka za nalepnicu
        $order_data = $this->prepare_order_data($order, $shipment);

        $address_type = $order->has_shipping_address() ? 'shipping' : 'billing';
        $address_desc = get_post_meta($order->get_id(), "_{$address_type}_address_desc", true);

        // Tracking broj
        $tracking_number = !empty($shipment->tracking_number)
            ? $shipment->tracking_number
            : 'TT' . str_pad(rand(1, 999999), 10, '0', STR_PAD_LEFT);

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

        // Broj paketa iz shipment-a
        $total_packages = $this->get_package_count($shipment);

?>
        <div class="label-container">
            <!-- Header sa podacima D Express filijale -->
            <div class="header">
                D Express doo, Zage Malivuk 1, Beograd
                <div class="package-info">
                    <?php echo esc_html($package_index); ?>/<?php echo esc_html($total_packages); ?>
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
                <svg class="barcode-<?php echo esc_attr($tracking_number); ?>"></svg>
                <script>
                    JsBarcode(".barcode-<?php echo esc_js($tracking_number); ?>", "<?php echo esc_js($tracking_number); ?>", {
                        format: "CODE128",
                        width: 3.3,
                        height: 80,
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
                        <?php echo esc_html($shipment_content); ?>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Masa:</span>
                        <?php
                        $shipment_weight = $this->calculate_shipment_weight($order, $shipment);
                        if ($shipment_weight == (int)$shipment_weight) {
                            echo esc_html(number_format($shipment_weight, 0, ',', '.') . ' kg');
                        } else {
                            echo esc_html(number_format($shipment_weight, 1, ',', '.') . ' kg');
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
        $content = '';

        // Generiši nalepnice za svaki paket
        for ($i = 1; $i <= $package_count; $i++) {
            ob_start();
            $this->generate_compact_label($shipment, $order, $i, $package_count);
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
