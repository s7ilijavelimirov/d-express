<?php

/**
 * D Express Order Metabox Handler - Improved Version
 * 
 * Klasa za upravljanje metabox-om na WooCommerce order stranicama
 */

defined('ABSPATH') || exit;

class D_Express_Order_Metabox
{
    /**
     * Konstruktor
     */
    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_metabox_assets'));
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_weight_data'), 10, 1);
        add_action('woocommerce_update_order', array($this, 'save_weight_data'), 10, 1);

        // NOVI AJAX callbacks za poboljšano iskustvo
        add_action('wp_ajax_dexpress_update_item_weight', array($this, 'ajax_update_item_weight'));
        add_action('wp_ajax_dexpress_create_package_simple', array($this, 'ajax_create_package_simple'));
    }

    /**
     * Dodavanje metabox-a na stranici narudžbine
     */
    public function add_order_metabox()
    {
        // Klasičan način
        add_meta_box(
            'dexpress_order_metabox',
            __('D Express Pošiljka', 'd-express-woo'),
            array($this, 'render_order_metabox'),
            'shop_order',
            'side',
            'default'
        );

        // HPOS način
        if ($this->is_hpos_enabled()) {
            add_meta_box(
                'dexpress_order_metabox',
                __('D Express Pošiljka', 'd-express-woo'),
                array($this, 'render_order_metabox'),
                wc_get_page_screen_id('shop-order'),
                'side',
                'default'
            );
        }
    }

    /**
     * Renderovanje metabox-a
     */
    public function render_order_metabox($post_or_order)
    {
        $order = $this->get_order_from_param($post_or_order);
        if (!$order) {
            echo '<p>' . __('Narudžbina nije pronađena.', 'd-express-woo') . '</p>';
            return;
        }

        $order_id = $order->get_id();

        // Sigurnosni nonce
        wp_nonce_field('dexpress_meta_box', 'dexpress_meta_box_nonce');

        // Proverava da li narudžbina koristi D Express
        $uses_dexpress = $this->order_uses_dexpress($order);
        $is_dispenser = $this->is_dispenser_delivery($order);

        if (!$uses_dexpress) {
            echo '<p>' . __('Ova narudžbina ne koristi D Express dostavu.', 'd-express-woo') . '</p>';
            return;
        }

        // Dobij postojeće pošiljke
        $shipments = $this->get_order_shipments($order_id);

        // Dobij lokacije
        $locations = $this->get_sender_locations();
        $selected_location_id = $this->get_selected_location($order_id);

        // Renderuj sadržaj
        $this->render_metabox_content($order, $shipments, $locations, $selected_location_id, $is_dispenser);
    }

    /**
     * Generiše content za split shipment (fallback za label generator)
     */
    // public function generate_shipment_content_for_split($order, $shipment)
    // {
    //     // Fallback content generisanje kada nema package-specific content
    //     return function_exists('dexpress_generate_shipment_content')
    //         ? dexpress_generate_shipment_content($order)
    //         : 'Razni proizvodi';
    // }

    /**
     * Renderovanje glavnog sadržaja metabox-a - MODERN UX VERSION
     */
    private function render_metabox_content($order, $shipments, $locations, $selected_location_id, $is_dispenser)
    {
        $order_id = $order->get_id();
?>
        <div class="dexpress-order-metabox" data-order-id="<?php echo $order_id; ?>">

            <?php if (!empty($shipments)): ?>
                <!-- Postojeće pošiljke - MODERN DESIGN -->
                <?php $this->render_existing_shipments_modern($shipments); ?>
            <?php else: ?>

                <!-- MODERN WORKFLOW STEPS -->
                <div class="dexpress-workflow">

                    <!-- Step 1: Package Type Selection -->
                    <div class="dexpress-step dexpress-step-active" id="dexpress-step-selection" data-step="1">
                        <div class="dexpress-step-header">
                            <h3>Odaberite tip paketa</h3>
                            <p>Koliko paketa želite da kreirate za ovu narudžbinu?</p>
                        </div>

                        <div class="dexpress-package-options">
                            <div class="dexpress-package-option" id="dexpress-select-single">
                                <div class="dexpress-option-icon">
                                    <?php $icon_url = DEXPRESS_WOO_PLUGIN_URL . 'assets/images/package.svg'; ?>
                                    <img src="<?php echo esc_url($icon_url); ?>">
                                </div>
                                <h4>Jedan paket</h4>
                                <p>Svi artikli jednom paketu</p>
                            </div>

                            <div class="dexpress-package-option" id="dexpress-select-multiple">
                                <div class="dexpress-option-icon">
                                    <?php $icon_url = DEXPRESS_WOO_PLUGIN_URL . 'assets/images/packages.svg'; ?>
                                    <img src="<?php echo esc_url($icon_url); ?>">
                                </div>
                                <h4>Više paketa</h4>
                                <p>Podelite artikle u različite pakete <br>i sa vise adresa</p>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Configuration -->
                    <div class="dexpress-step" id="dexpress-step-config" data-step="2" style="display: none;">
                        <div class="dexpress-step-header">
                            <div class="dexpress-step-nav">
                                <button type="button" class="dexpress-btn-back" id="dexpress-back-to-selection">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="15,18 9,12 15,6" />
                                    </svg>
                                    Nazad
                                </button>
                            </div>
                            <h3 id="dexpress-config-title">Konfiguracija paketa</h3>
                        </div>

                        <!-- Weight Management Section -->
                        <div class="dexpress-card">
                            <div class="dexpress-card-header">
                                <h4>Težine proizvoda</h4>
                                <button type="button" class="dexpress-btn-secondary dexpress-btn-sm" id="dexpress-toggle-weights">
                                    Uredi težine
                                </button>
                            </div>
                            <div class="dexpress-card-body">
                                <?php $this->render_weight_table_modern($order); ?>
                            </div>
                        </div>

                        <!-- Location & Settings -->
                        <div class="dexpress-card">
                            <div class="dexpress-card-header">
                                <h4>Magacin i opcije</h4>
                            </div>
                            <div class="dexpress-card-body">
                                <?php $this->render_settings_form_modern($order, $locations, $selected_location_id, $is_dispenser); ?>
                            </div>
                        </div>

                        <!-- Single Package Config -->
                        <div class="dexpress-single-config" id="dexpress-single-config" style="display: none;">
                            <div class="dexpress-card">
                                <div class="dexpress-card-header">
                                    <h4>Sadržaj paketa</h4>
                                </div>
                                <div class="dexpress-card-body">
                                    <div class="dexpress-form-group">
                                        <label for="dexpress_content">Opis sadržaja:</label>
                                        <input type="text" name="dexpress_content" id="dexpress_content"
                                            maxlength="50" placeholder="Auto-generisan sadržaj...">
                                        <small class="dexpress-form-help">
                                            Automatski generisan na osnovu vaših postavki
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Multiple Packages Config -->
                        <div class="dexpress-multiple-config" id="dexpress-multiple-config" style="display: none;">
                            <div class="dexpress-card">
                                <div class="dexpress-card-header">
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <h4>Podela u pakete</h4>
                                        <div class="dexpress-package-counter">
                                            <label for="dexpress-split-count">Broj paketa:</label>
                                            <input type="number" id="dexpress-split-count" min="2" max="20" value="2">
                                            <button type="button" id="dexpress-generate-splits" class="dexpress-btn-primary dexpress-btn-sm">
                                                Generiši
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="dexpress-card-body">
                                    <div id="dexpress-splits-container" class="dexpress-splits-container"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="dexpress-step-actions">
                            <button type="button" id="dexpress-create-shipment" class="dexpress-btn-primary dexpress-btn-lg">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                                </svg>
                                <span id="dexpress-create-text">Kreiraj paket</span>
                            </button>
                        </div>
                    </div>

                </div>

                <!-- Response Messages -->
                <div id="dexpress-response" class="dexpress-response"></div>

            <?php endif; ?>

        </div>

        <?php $this->render_javascript($locations, $order); ?>
    <?php
    }
    /**
     * Simplified JavaScript rendering - only data passing
     */
    private function render_javascript($locations, $order)
    {
        $order_items = $this->prepare_order_items_for_js($order);
    ?>
        <script type="text/javascript">
            // Pass data to JavaScript modules
            window.dexpressOrderData = {
                orderItems: <?php echo json_encode($order_items); ?>,
                locations: <?php echo json_encode($locations); ?>,
                orderId: <?php echo $order->get_id(); ?>
            };

            // Merge with localized data if it exists
            if (window.dexpressMetabox) {
                window.dexpressMetabox = Object.assign(window.dexpressMetabox, window.dexpressOrderData);
            }
        </script>
    <?php
    }
    /**
     * Modern weight table rendering
     */
    private function render_weight_table_modern($order)
    {
    ?>
        <div class="dexpress-weight-section">
            <table class="dexpress-weight-table">
                <thead>
                    <tr>
                        <th>Proizvod</th>
                        <th>Kol.</th>
                        <th>Težina/kom</th>
                        <th>Ukupno</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_weight = 0;
                    foreach ($order->get_items() as $item_id => $item):
                        if (!($item instanceof WC_Order_Item_Product)) continue;

                        $product = $item->get_product();
                        $quantity = $item->get_quantity();
                        $weight = $this->get_item_weight($order->get_id(), $item_id, $product);
                        $item_total = $weight * $quantity;
                        $total_weight += $item_total;
                    ?>
                        <tr data-item-id="<?php echo $item_id; ?>">
                            <td>
                                <div class="dexpress-product-info">
                                    <strong><?php echo esc_html(mb_substr($item->get_name(), 0, 30)); ?></strong>
                                    <?php if ($product && $product->get_sku()): ?>
                                        <small>SKU: <?php echo esc_html($product->get_sku()); ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><span class="dexpress-quantity"><?php echo $quantity; ?>x</span></td>
                            <td>
                                <div class="dexpress-weight-control">
                                    <span class="weight-display"><?php echo number_format($weight, 2); ?> kg</span>
                                    <input type="number" class="weight-input dexpress-input"
                                        value="<?php echo esc_attr($weight); ?>"
                                        step="0.01" min="0.01" max="50"
                                        data-item-id="<?php echo $item_id; ?>"
                                        data-quantity="<?php echo $quantity; ?>"
                                        style="display: none;">
                                </div>
                            </td>
                            <td>
                                <strong class="item-total-weight"><?php echo number_format($item_total, 2); ?> kg</strong>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="dexpress-total-row">
                        <td colspan="3"><strong>UKUPNO:</strong></td>
                        <td><strong><span id="total-order-weight"><?php echo number_format($total_weight, 2); ?></span> kg</strong></td>
                    </tr>
                </tfoot>
            </table>

            <div id="weight-edit-controls" class="dexpress-weight-controls" style="display: none;">
                <button type="button" class="dexpress-btn-primary dexpress-btn-sm" id="save-weight-changes">
                    Sačuvaj izmene
                </button>
                <button type="button" class="dexpress-btn-secondary dexpress-btn-sm" id="cancel-weight-changes">
                    Otkaži
                </button>
                <div id="weight-save-status" class="dexpress-status-message"></div>
            </div>
        </div>
    <?php
    }
    /**
     * Modern settings form
     */
    private function render_settings_form_modern($order, $locations, $selected_location_id, $is_dispenser)
    {
    ?>
        <div class="dexpress-settings-grid">
            <!-- Lokacija -->
            <div class="dexpress-form-group">
                <label for="dexpress_sender_location_id">Lokacija pošiljaoca:</label>
                <select name="dexpress_sender_location_id" id="dexpress_sender_location_id" class="dexpress-select" required>
                    <option value="">Izaberite lokaciju...</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo esc_attr($location->id); ?>" <?php selected($selected_location_id, $location->id); ?>>
                            <?php echo esc_html($location->name . ' - ' . $location->address); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Opcije -->
            <div class="dexpress-form-group">
                <div class="dexpress-checkbox-group">
                    <label class="dexpress-checkbox">
                        <input type="checkbox" name="dexpress_return_doc" value="1">
                        <span class="dexpress-checkbox-mark"></span>
                        Povratni dokumenti
                    </label>

                    <?php if ($is_dispenser): ?>
                        <div class="dexpress-form-group" style="margin-top: 15px;">
                            <label for="dexpress_dispenser_id">Paketomat ID:</label>
                            <input type="number" name="dexpress_dispenser_id" id="dexpress_dispenser_id"
                                class="dexpress-input" placeholder="ID paketomata...">
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php
    }
    /**
     * Modern existing shipments display
     */
    private function render_existing_shipments_modern($shipments)
    {
    ?>
        <div class="dexpress-existing-shipments">
            <div class="dexpress-step-header">
                <h3>Kreiranje paketi</h3>
                <p>Pošiljke su već kreirane za ovu narudžbinu</p>
            </div>

            <div class="dexpress-shipments-grid">
                <?php foreach ($shipments as $shipment): ?>
                    <?php $this->render_shipment_card_modern($shipment); ?>
                <?php endforeach; ?>
            </div>

            <div class="dexpress-shipment-actions">
                <?php if (count($shipments) === 1): ?>
                    <button type="button" class="dexpress-btn-primary dexpress-bulk-download-labels"
                        data-shipment-ids="<?php echo esc_attr($shipment->id); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6,9 6,2 18,2 18,9" />
                            <path d="M6,18H4a2,2,0,0,1-2-2V11a2,2,0,0,1,2-2H20a2,2,0,0,1,2,2v5a2,2,0,0,1-2,2H18" />
                            <line x1="6" y1="14" x2="6.01" y2="14" />
                            <line x1="18" y1="14" x2="18.01" y2="14" />
                        </svg>
                        Štampaj nalepnicu
                    </button>
                <?php else: ?>
                    <?php $shipment_ids = array_column($shipments, 'id'); ?>
                    <button type="button" class="dexpress-btn-primary dexpress-bulk-download-labels"
                        data-shipment-ids="<?php echo esc_attr(implode(',', $shipment_ids)); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6,9 6,2 18,2 18,9" />
                            <path d="M6,18H4a2,2,0,0,1-2-2V11a2,2,0,0,1,2-2H20a2,2,0,0,1,2,2v5a2,2,0,0,1-2,2H18" />
                            <line x1="6" y1="14" x2="6.01" y2="14" />
                            <line x1="18" y1="14" x2="18.01" y2="14" />
                        </svg>
                        Štampaj sve nalepnice (<?php echo count($shipments); ?>)
                    </button>
                <?php endif; ?>
            </div>
        </div>
    <?php
    }
    /**
     * Helper metode
     */
    private function get_order_from_param($post_or_order)
    {
        if (is_a($post_or_order, 'WP_Post')) {
            return wc_get_order($post_or_order->ID);
        }
        return $post_or_order;
    }

    private function is_hpos_enabled()
    {
        return class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') &&
            wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled();
    }

    private function order_uses_dexpress($order)
    {
        $shipping_methods = $order->get_shipping_methods();
        foreach ($shipping_methods as $shipping_method) {
            if (in_array($shipping_method->get_method_id(), ['dexpress', 'dexpress_dispenser'])) {
                return true;
            }
        }
        return false;
    }

    private function is_dispenser_delivery($order)
    {
        $shipping_methods = $order->get_shipping_methods();
        foreach ($shipping_methods as $shipping_method) {
            if ($shipping_method->get_method_id() === 'dexpress_dispenser') {
                return true;
            }
        }
        return false;
    }

    private function get_order_shipments($order_id)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d ORDER BY created_at ASC",
            $order_id
        ));
    }

    private function get_shipment_packages($shipment_id)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d ORDER BY package_index ASC",
            $shipment_id
        ));
    }

    private function get_sender_locations()
    {
        if (class_exists('D_Express_Sender_Locations')) {
            $service = new D_Express_Sender_Locations();
            return $service->get_all_locations();
        }
        return [];
    }

    private function get_selected_location($order_id)
    {
        $selected = get_post_meta($order_id, '_dexpress_selected_sender_location_id', true);
        if (empty($selected)) {
            $selected = get_option('dexpress_default_sender_location_id');
        }
        return $selected;
    }

    private function get_location_info($location_id)
    {
        if (!$location_id || !class_exists('D_Express_Sender_Locations')) {
            return '';
        }

        $service = D_Express_Sender_Locations::get_instance();
        $location = $service->get_location($location_id);
        return $location ? ' - ' . $location->name : '';
    }

    private function get_item_weight($order_id, $item_id, $product)
    {
        // Prvo pokušaj da dobiješ custom weight
        $custom_weight = get_post_meta($order_id, '_dexpress_item_weight_' . $item_id, true);
        if (!empty($custom_weight)) {
            return floatval($custom_weight);
        }

        // Inače koristi product weight
        if ($product && $product->has_weight()) {
            return floatval($product->get_weight());
        }

        return 0.1; // Default minimum weight
    }

    private function prepare_order_items_for_js($order)
    {
        $items = [];
        foreach ($order->get_items() as $item_id => $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            $weight = $this->get_item_weight($order->get_id(), $item_id, $product);

            $items[] = [
                'id' => $item_id,
                'name' => mb_substr($item->get_name(), 0, 40),
                'quantity' => $item->get_quantity(),
                'weight' => $weight
            ];
        }
        return $items;
    }

    private function get_default_content($order)
    {
        $product_names = [];
        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }
            $name = $item->get_name();
            if (strlen($name) > 20) {
                $name = substr($name, 0, 20) . '...';
            }
            $product_names[] = $name;
        }

        return !empty($product_names) ? implode(', ', array_slice($product_names, 0, 2)) : 'Razni proizvodi';
    }

    /**
     * Čuvanje weight podataka
     */
    public function save_weight_data($order_id)
    {
        if (
            !isset($_POST['dexpress_meta_box_nonce']) ||
            !wp_verify_nonce($_POST['dexpress_meta_box_nonce'], 'dexpress_meta_box')
        ) {
            return;
        }

        if (isset($_POST['dexpress_item_weight']) && is_array($_POST['dexpress_item_weight'])) {
            foreach ($_POST['dexpress_item_weight'] as $item_id => $weight) {
                $weight = floatval($weight);
                if ($weight > 0) {
                    update_post_meta($order_id, '_dexpress_item_weight_' . $item_id, $weight);
                } else {
                    delete_post_meta($order_id, '_dexpress_item_weight_' . $item_id);
                }
            }
        }
    }

    /**
     * NOVO: AJAX: Ažuriranje težine artikla
     */
    public function ajax_update_item_weight()
    {
        check_ajax_referer('dexpress_meta_box', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Nemate dozvolu');
        }

        $order_id = intval($_POST['order_id']);
        $item_id = intval($_POST['item_id']);
        $new_weight = floatval($_POST['weight']);

        if ($order_id <= 0 || $item_id <= 0 || $new_weight <= 0) {
            wp_send_json_error('Nevalidni podaci');
        }

        // Sačuvaj novu težinu
        update_post_meta($order_id, '_dexpress_item_weight_' . $item_id, $new_weight);

        // Izračunaj ukupnu težinu
        $order = wc_get_order($order_id);
        $total_weight = 0;

        foreach ($order->get_items() as $check_item_id => $item) {
            if (!($item instanceof WC_Order_Item_Product)) continue;

            $product = $item->get_product();
            $weight = $this->get_item_weight($order_id, $check_item_id, $product);
            $total_weight += ($weight * $item->get_quantity());
        }

        wp_send_json_success([
            'item_total' => number_format($new_weight * $order->get_item($item_id)->get_quantity(), 2),
            'order_total' => number_format($total_weight, 2),
            'message' => 'Težina ažurirana'
        ]);
    }

    /**
     * NOVO: AJAX: Kreiranje jednostavnog paketa
     */
    public function ajax_create_package_simple()
    {
        check_ajax_referer('dexpress_meta_box', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Nemate dozvolu');
        }

        $order_id = intval($_POST['order_id']);
        $location_id = intval($_POST['location_id']);
        $selected_items = isset($_POST['selected_items']) ? $_POST['selected_items'] : array();
        $custom_weight = isset($_POST['custom_weight']) ? floatval($_POST['custom_weight']) : 0;
        $custom_content = isset($_POST['content']) ? sanitize_text_field($_POST['content']) : '';

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Narudžbina nije pronađena');
        }

        if (empty($selected_items)) {
            wp_send_json_error('Morate odabrati barem jedan proizvod');
        }

        if (!$location_id) {
            wp_send_json_error('Morate odabrati lokaciju');
        }

        try {
            // Koristimo postojeći shipment service
            $shipment_service = new D_Express_Shipment_Service();

            // Ako je custom weight postavljena, sačuvaj je privremeno
            if ($custom_weight > 0) {
                update_post_meta($order_id, '_temp_custom_package_weight', $custom_weight);
            }

            $result = $shipment_service->create_shipment($order, $location_id, null, $custom_content);

            // Ukloni privremenu težinu
            delete_post_meta($order_id, '_temp_custom_package_weight');

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }

            wp_send_json_success([
                'message' => 'Paket uspešno kreiran',
                'shipment_id' => $result['shipment_id'],
                'tracking_number' => $result['tracking_number']
            ]);
        } catch (Exception $e) {
            wp_send_json_error('Greška: ' . $e->getMessage());
        }
    }
    /**
     * Modern shipment card
     */
    private function render_shipment_card_modern($shipment)
    {
        $packages = $this->get_shipment_packages($shipment->id);
        $location_info = $this->get_location_info($shipment->sender_location_id);
    ?>
        <div class="dexpress-shipment-card">
            <div class="dexpress-card-header">
                <div class="dexpress-shipment-info">
                    <h4><?php echo esc_html($shipment->reference_id ?: 'Shipment #' . $shipment->id); ?></h4>
                    <?php if ($location_info): ?>
                        <small><?php echo esc_html($location_info); ?></small>
                    <?php endif; ?>
                </div>
                <div class="dexpress-shipment-status">
                    <span class="dexpress-status-badge">
                        <?php echo esc_html($shipment->status_description ?: 'U obradi'); ?>
                    </span>
                </div>
            </div>

            <div class="dexpress-card-body">
                <?php if (!empty($packages)): ?>
                    <div class="dexpress-packages-info">
                        <strong><?php echo count($packages); ?> paket(a):</strong>
                        <div class="dexpress-package-codes">
                            <?php foreach (array_slice($packages, 0, 3) as $package): ?>
                                <code><?php echo esc_html($package->package_code); ?></code>
                            <?php endforeach; ?>
                            <?php if (count($packages) > 3): ?>
                                <span>+<?php echo count($packages) - 3; ?> više</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="dexpress-shipment-meta">
                    <small>
                        Kreiran: <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($shipment->created_at))); ?>
                    </small>
                </div>
            </div>
        </div>
<?php
    }
    /**
     * Učitavanje assets-a - UPDATED VERSION
     */
    public function enqueue_metabox_assets($hook)
    {
        if (!in_array($hook, ['post.php', 'post-new.php', 'woocommerce_page_wc-orders'])) {
            return;
        }

        global $post;
        if ($post && $post->post_type !== 'shop_order') {
            return;
        }

        // CSS
        wp_enqueue_style(
            'dexpress-metabox-css',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-admin.css',
            array(),
            DEXPRESS_WOO_VERSION
        );

        // JavaScript
        wp_enqueue_script(
            'dexpress-order-metabox-js',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-order-metabox.js',
            array('jquery'),
            DEXPRESS_WOO_VERSION,
            true
        );

        // Localize script sa svim podacima
        wp_localize_script('dexpress-order-metabox-js', 'dexpressMetabox', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces' => [
                'admin' => wp_create_nonce('dexpress_admin_nonce'),
                'metabox' => wp_create_nonce('dexpress_meta_box'),
                'downloadLabel' => wp_create_nonce('dexpress-download-label'),
                'bulkPrint' => wp_create_nonce('dexpress-bulk-print'),
                'refreshStatus' => wp_create_nonce('dexpress-refresh-status')
            ],
            'strings' => [
                'confirmDelete' => __('Da li ste sigurni da želite da obrišete ovu pošiljku?', 'd-express-woo'),
                'confirmRemovePackage' => __('Ukloniti ovaj paket?', 'd-express-woo'),
                'selectLocation' => __('Morate izabrati lokaciju!', 'd-express-woo'),
                'selectAtLeastOnePackage' => __('Morate definisati barem jedan paket!', 'd-express-woo'),
                'weightsUpdated' => __('Težine ažurirane', 'd-express-woo'),
                'communicationError' => __('Greška u komunikaciji sa serverom', 'd-express-woo'),
                'processing' => __('Obrađujem...', 'd-express-woo'),
                'saved' => __('Sačuvano', 'd-express-woo'),
                'error' => __('Greška', 'd-express-woo')
            ],
            'config' => [
                'maxWeight' => 34000, // grama
                'maxPackages' => 20,
                'maxContentLength' => 50
            ]
        ]);
    }
}
