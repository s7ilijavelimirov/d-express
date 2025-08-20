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
     * Renderovanje glavnog sadržaja metabox-a
     */
    private function render_metabox_content($order, $shipments, $locations, $selected_location_id, $is_dispenser)
    {
        $order_id = $order->get_id();
?>
        <div class="dexpress-order-metabox">

            <?php if (!empty($shipments)): ?>
                <!-- Postojeće pošiljke -->
                <?php $this->render_existing_shipments($shipments); ?>
                <hr style="margin: 15px 0;">
            <?php endif; ?>

            <!-- Kreiranje nove pošiljke -->
            <div class="dexpress-create-section" id="dexpress-create-section">
                <h4><?php _e('Kreiranje D Express pošiljke', 'd-express-woo'); ?></h4>

                <!-- Artikli sa težinама -->
                <?php $this->render_order_items($order); ?>

                <!-- Forma za kreiranje -->
                <?php $this->render_shipment_form($order, $locations, $selected_location_id, $is_dispenser); ?>

                <!-- Dugmad -->
                <div style="margin-top: 20px;">
                    <button type="button" id="dexpress-create-single-shipment"
                        class="button button-primary" data-order-id="<?php echo esc_attr($order_id); ?>">
                        <?php _e('Kreiraj jedan paket', 'd-express-woo'); ?>
                    </button>

                    <button type="button" id="dexpress-toggle-split-mode"
                        class="button button-secondary" style="margin-left: 10px;">
                        <?php _e('Podeli na više paketa', 'd-express-woo'); ?>
                    </button>
                </div>
            </div>

            <!-- Sekcija za podelu na više paketa -->
            <div class="dexpress-split-section" id="dexpress-split-section" style="display: none;">
                <h4><?php _e('Podela na više paketa', 'd-express-woo'); ?></h4>

                <div style="margin-bottom: 15px;">
                    <label for="dexpress-split-count"><?php _e('Broj paketa:', 'd-express-woo'); ?></label>
                    <input type="number" id="dexpress-split-count" min="2" max="20" value="2" style="width: 60px; margin-left: 10px;">
                    <button type="button" id="dexpress-generate-splits" class="button" style="margin-left: 10px;">
                        <?php _e('Generiši pakete', 'd-express-woo'); ?>
                    </button>
                </div>

                <div id="dexpress-splits-container"></div>

                <div style="margin-top: 15px;">
                    <button type="button" id="dexpress-create-all-shipments"
                        class="button button-primary" data-order-id="<?php echo esc_attr($order_id); ?>">
                        <?php _e('Kreiraj sve pakete', 'd-express-woo'); ?>
                    </button>

                    <button type="button" id="dexpress-back-to-single" class="button" style="margin-left: 10px;">
                        <?php _e('Nazad', 'd-express-woo'); ?>
                    </button>
                </div>
            </div>

            <div id="dexpress-response" style="margin-top: 15px;"></div>
        </div>

        <?php $this->render_javascript($locations, $order); ?>
    <?php
    }

    /**
     * Renderovanje postojećih pošiljki
     */
    private function render_existing_shipments($shipments)
    {
    ?>
        <div class="dexpress-existing-shipments">
            <h4><?php _e('Postojeći paketi', 'd-express-woo'); ?></h4>

            <?php foreach ($shipments as $shipment): ?>
                <?php $this->render_single_shipment($shipment); ?>
            <?php endforeach; ?>

            <!-- Dugmad za nalepnice -->
            <div style="margin-top: 15px; text-align: center;">
                <?php if (count($shipments) === 1): ?>
                    <button type="button" class="button button-primary dexpress-get-single-label"
                        data-shipment-id="<?php echo esc_attr($shipments[0]->id); ?>">
                        <?php _e('Štampaj nalepnicu', 'd-express-woo'); ?>
                    </button>
                <?php else: ?>
                    <?php $shipment_ids = array_column($shipments, 'id'); ?>
                    <button type="button" class="button button-primary dexpress-bulk-download-labels"
                        data-shipment-ids="<?php echo esc_attr(implode(',', $shipment_ids)); ?>">
                        <?php printf(__('Štampaj sve (%d nalepnica)', 'd-express-woo'), count($shipments)); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    <?php
    }

    /**
     * Renderovanje jedne pošiljke
     */
    private function render_single_shipment($shipment)
    {
        $packages = $this->get_shipment_packages($shipment->id);
        $package_count = count($packages);

        if ($package_count === 1) {
            $title = $packages[0]->package_code;
            $subtitle = '';
        } elseif ($package_count > 1) {
            $title = sprintf(__('Shipment #%s', 'd-express-woo'), $shipment->id);
            $subtitle = sprintf(__('%d paketa', 'd-express-woo'), $package_count);
        } else {
            $title = $shipment->tracking_number ?: sprintf(__('Shipment #%s', 'd-express-woo'), $shipment->id);
            $subtitle = '';
        }

        $location_info = $this->get_location_info($shipment->sender_location_id);
    ?>
        <div class="dexpress-shipment-item" style="padding: 10px; margin-bottom: 10px; border: 1px solid #ddd; background: #f9f9f9;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="flex: 1;">
                    <div>
                        <strong><?php echo esc_html($title); ?></strong>
                        <?php if ($location_info): ?>
                            <span style="color: #666; font-size: 12px;"><?php echo esc_html($location_info); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($subtitle): ?>
                        <div style="margin-top: 3px; font-size: 12px; color: #0073aa;">
                            <?php echo esc_html($subtitle); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="text-align: right;">
                    <span class="dexpress-status-badge">
                        <?php echo esc_html($shipment->status_description ?: 'U obradi'); ?>
                    </span>
                </div>
            </div>

            <div style="margin-top: 8px; font-size: 12px; color: #666;">
                <?php _e('Kreiran:', 'd-express-woo'); ?>
                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($shipment->created_at))); ?>
            </div>
        </div>
    <?php
    }

    /**
     * Renderovanje artikala sa težinама
     */
    private function render_order_items($order)
    {
    ?>
        <div class="dexpress-items-section" style="margin-bottom: 20px;">
            <h5><?php _e('Proizvodi i težine', 'd-express-woo'); ?></h5>

            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th style="padding: 6px; text-align: left; border: 1px solid #ddd;"><?php _e('Proizvod', 'd-express-woo'); ?></th>
                        <th style="padding: 6px; text-align: center; border: 1px solid #ddd;"><?php _e('Kol.', 'd-express-woo'); ?></th>
                        <th style="padding: 6px; text-align: center; border: 1px solid #ddd;"><?php _e('Težina (kg)', 'd-express-woo'); ?></th>
                        <th style="padding: 6px; text-align: center; border: 1px solid #ddd;"><?php _e('Ukupno', 'd-express-woo'); ?></th>
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
                        <tr>
                            <td style="padding: 6px; border: 1px solid #ddd;">
                                <strong><?php echo esc_html(mb_substr($item->get_name(), 0, 30)); ?></strong>
                                <?php if ($product && $product->get_sku()): ?>
                                    <br><small style="color: #666;">SKU: <?php echo esc_html($product->get_sku()); ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 6px; text-align: center; border: 1px solid #ddd;">
                                <strong><?php echo esc_html($quantity); ?></strong>
                            </td>
                            <td style="padding: 6px; text-align: center; border: 1px solid #ddd;">
                                <input type="number" name="dexpress_item_weight[<?php echo $item_id; ?>]"
                                    value="<?php echo esc_attr($weight); ?>"
                                    step="0.01" min="0" style="width: 60px; text-align: center;"
                                    class="dexpress-item-weight-input"
                                    data-item-id="<?php echo $item_id; ?>"
                                    data-quantity="<?php echo $quantity; ?>">
                            </td>
                            <td style="padding: 6px; text-align: center; border: 1px solid #ddd;">
                                <strong><span class="dexpress-total-item-weight" data-item-id="<?php echo $item_id; ?>">
                                        <?php echo number_format($item_total, 2); ?>
                                    </span> kg</strong>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #e8f4f8; font-weight: bold;">
                        <td colspan="3" style="padding: 8px; border: 1px solid #ddd; text-align: right;">
                            <?php _e('UKUPNA TEŽINA:', 'd-express-woo'); ?>
                        </td>
                        <td style="padding: 8px; text-align: center; border: 1px solid #ddd; color: #2563eb;">
                            <span id="dexpress-grand-total-weight"><?php echo number_format($total_weight, 2); ?></span> kg
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php
    }

    /**
     * Renderovanje forme za kreiranje pošiljke
     */
    private function render_shipment_form($order, $locations, $selected_location_id, $is_dispenser)
    {
    ?>
        <!-- Lokacija pošiljaoca -->
        <div style="margin-bottom: 15px;">
            <label for="dexpress_sender_location_id" style="display: block; font-weight: bold; margin-bottom: 5px;">
                <?php _e('Lokacija pošiljaoca:', 'd-express-woo'); ?>
            </label>
            <select name="dexpress_sender_location_id" id="dexpress_sender_location_id" style="width: 100%;" required>
                <option value=""><?php _e('Izaberite lokaciju...', 'd-express-woo'); ?></option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?php echo esc_attr($location->id); ?>" <?php selected($selected_location_id, $location->id); ?>>
                        <?php echo esc_html($location->name . ' - ' . $location->address); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Sadržaj pošiljke -->
        <!-- Sadržaj pošiljke -->
        <div style="margin-bottom: 15px;">
            <label for="dexpress_content" style="display: block; font-weight: bold; margin-bottom: 5px;">
                <?php _e('Sadržaj pošiljke:', 'd-express-woo'); ?>
            </label>

            <?php
            $content_type = $this->get_content_type_setting();
            $auto_content = $this->generate_content_by_type($order, $content_type);
            ?>

            <input type="text" name="dexpress_content" id="dexpress_content"
                value="<?php echo esc_attr($auto_content); ?>"
                style="width: 100%;" maxlength="50"
                placeholder="<?php _e('Opisati sadržaj pošiljke...', 'd-express-woo'); ?>">

            <p class="description" style="margin-top: 5px; font-size: 11px; color: #666;">
                <?php _e('Auto-generisano na osnovu:', 'd-express-woo'); ?>
                <strong><?php
                        switch ($content_type) {
                            case 'category':
                                echo 'Kategorije proizvoda';
                                break;
                            case 'name':
                                echo 'Nazivi proizvoda';
                                break;
                            case 'custom':
                                echo 'Prilagođeni tekst';
                                break;
                        }
                        ?></strong>
            </p>
        </div>

        <!-- Povratni dokumenti -->
        <div style="margin-bottom: 15px;">
            <label>
                <input type="checkbox" name="dexpress_return_doc" value="1">
                <?php _e('Povratni dokumenti', 'd-express-woo'); ?>
            </label>
        </div>

        <?php if ($is_dispenser): ?>
            <!-- Paketomat ID -->
            <div style="margin-bottom: 15px;">
                <label for="dexpress_dispenser_id" style="display: block; font-weight: bold; margin-bottom: 5px;">
                    <?php _e('Paketomat ID:', 'd-express-woo'); ?>
                </label>
                <input type="number" name="dexpress_dispenser_id" id="dexpress_dispenser_id"
                    style="width: 100%;" placeholder="<?php _e('Unesite ID paketomata...', 'd-express-woo'); ?>">
            </div>
        <?php endif; ?>
    <?php
    }
    public function get_content_type_setting()
    {
        return get_option('dexpress_content_type', 'category');
    }

    public function generate_content_by_type($order, $content_type, $selected_items = null)
    {
        switch ($content_type) {
            case 'category':
                return $this->get_categories_content($order, $selected_items);
            case 'name':
                return $this->get_names_content($order, $selected_items);
            case 'custom':
                return get_option('dexpress_custom_content_text', 'Razni proizvodi');
            default:
                return 'Razni proizvodi';
        }
    }

    public function get_categories_content($order, $selected_items = null)
    {
        $categories = [];
        foreach ($order->get_items() as $item_id => $item) {
            if ($selected_items && !in_array($item_id, $selected_items)) continue;

            $product = $item->get_product();
            if ($product) {
                $terms = get_the_terms($product->get_id(), 'product_cat');
                if ($terms && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $categories[] = $term->name;
                    }
                }
            }
        }
        return !empty($categories) ? implode(', ', array_unique($categories)) : 'Razni proizvodi';
    }

    public function get_names_content($order, $selected_items = null)
    {
        $names = [];
        foreach ($order->get_items() as $item_id => $item) {
            if ($selected_items && !in_array($item_id, $selected_items)) continue;

            $name = $item->get_name();
            if (strlen($name) > 15) {
                $name = substr($name, 0, 15) . '...';
            }
            $names[] = $name;
        }
        return !empty($names) ? implode(', ', array_slice($names, 0, 3)) : 'Razni proizvodi';
    }
    /**
     * Renderovanje JavaScript-a
     */
    private function render_javascript($locations, $order)
    {
        $order_items = $this->prepare_order_items_for_js($order);
    ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var orderItems = <?php echo json_encode($order_items); ?>;
                var locations = <?php echo json_encode($locations); ?>;

                // Ažuriranje težine
                $('.dexpress-item-weight-input').on('input', function() {
                    updateWeights();
                });

                function updateWeights() {
                    var grandTotal = 0;
                    $('.dexpress-item-weight-input').each(function() {
                        var weight = parseFloat($(this).val()) || 0;
                        var quantity = $(this).data('quantity');
                        var itemId = $(this).data('item-id');
                        var totalWeight = weight * quantity;

                        $('.dexpress-total-item-weight[data-item-id="' + itemId + '"]').text(totalWeight.toFixed(2));
                        grandTotal += totalWeight;
                    });
                    $('#dexpress-grand-total-weight').text(grandTotal.toFixed(2));
                }

                // Kreiranje jedne pošiljke
                $('#dexpress-create-single-shipment').on('click', function() {
                    createSingleShipment();
                });

                // Toggle split mode
                $('#dexpress-toggle-split-mode').on('click', function() {
                    $('#dexpress-create-section').hide();
                    $('#dexpress-split-section').show();
                });

                // Nazad na jednostruku
                $('#dexpress-back-to-single').on('click', function() {
                    $('#dexpress-split-section').hide();
                    $('#dexpress-create-section').show();
                    $('#dexpress-splits-container').empty();
                });

                // Generisanje paketa
                $('#dexpress-generate-splits').on('click', function() {
                    var count = parseInt($('#dexpress-split-count').val()) || 2;
                    if (count < 2) count = 2;
                    if (count > 20) count = 20;

                    generateSplitForms(count);
                });

                // Kreiranje svih paketa
                $('#dexpress-create-all-shipments').on('click', function() {
                    createMultipleShipments();
                });

                // Label download handlers
                $('.dexpress-get-single-label').on('click', function(e) {
                    e.preventDefault();
                    var shipmentId = $(this).data('shipment-id');
                    downloadLabel(shipmentId);
                });

                $('.dexpress-bulk-download-labels').on('click', function(e) {
                    e.preventDefault();
                    var shipmentIds = $(this).data('shipment-ids');
                    downloadBulkLabels(shipmentIds);
                });

                function createSingleShipment() {
                    var data = getShipmentFormData();
                    if (!validateShipmentData(data)) return;

                    sendAjaxRequest('dexpress_create_shipment', data, function(response) {
                        if (response.success) {
                            showSuccess(response.data.message);
                            // ✅ DODAJ OVU LINIJU:
                            $('#dexpress-create-section').hide();
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            showError(response.data.message);
                        }
                    });
                }

                function createMultipleShipments() {
                    var splits = collectSplitData();
                    if (splits.length === 0) {
                        alert('Morate definisati barem jedan paket!');
                        return;
                    }

                    var data = {
                        order_id: $('#dexpress-create-single-shipment').data('order-id'),
                        splits: splits
                    };

                    sendAjaxRequest('dexpress_create_multiple_shipments', data, function(response) {
                        if (response.success) {
                            showSuccess(response.data.message);
                            setTimeout(() => location.reload(), 3000);
                        } else {
                            showError(response.data.message);
                        }
                    });
                }

                function generateSplitForms(count) {
                    $('#dexpress-splits-container').empty();

                    for (var i = 1; i <= count; i++) {
                        addSplitForm(i);
                    }
                }

                function addSplitForm(index) {
                    var html = buildSplitFormHTML(index);
                    $('#dexpress-splits-container').append(html);
                    attachSplitEventListeners(index);
                }

                function buildSplitFormHTML(index) {
                    var html = '<div class="dexpress-split-form" data-split-index="' + index + '" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; background: #f9f9f9;">';

                    // Header
                    html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
                    html += '<h5 style="margin: 0;">Paket #' + index + '</h5>';
                    html += '<button type="button" class="button button-small dexpress-remove-split">Ukloni</button>';
                    html += '</div>';

                    // Location selector
                    html += '<div style="margin-bottom: 15px;">';
                    html += '<label style="display: block; font-weight: bold; margin-bottom: 5px;">Lokacija:</label>';
                    html += '<select name="split_locations[]" class="split-location-select" style="width: 100%;" required>';
                    html += '<option value="">Izaberite lokaciju...</option>';
                    locations.forEach(function(location) {
                        html += '<option value="' + location.id + '">' + location.name + ' - ' + location.address + '</option>';
                    });
                    html += '</select></div>';

                    // Content input za svaki paket - dodaj posle location selector-a
                    html += '<div style="margin-bottom: 15px;">';
                    html += '<label style="display: block; font-weight: bold; margin-bottom: 5px;">Sadržaj paketa:</label>';
                    html += '<input type="text" name="split_content[]" class="split-content-input" ';
                    html += 'style="width: 100%;" maxlength="50" placeholder="Automatski će se generisati...">';
                    html += '<p style="margin-top: 3px; font-size: 11px; color: #666;">Automatski se ažurira na osnovu izabranih artikala</p>';
                    html += '</div>';
                    // Items selector
                    html += '<div style="margin-bottom: 15px;">';
                    html += '<label style="display: block; font-weight: bold; margin-bottom: 5px;">Artikli:</label>';
                    html += '<div style="margin-bottom: 8px;">';
                    html += '<button type="button" class="button button-small select-all-items" data-split="' + index + '">Sve</button> ';
                    html += '<button type="button" class="button button-small deselect-all-items" data-split="' + index + '">Ništa</button>';
                    html += '</div>';

                    html += '<div class="items-container" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">';
                    orderItems.forEach(function(item) {
                        for (var i = 1; i <= item.quantity; i++) {
                            html += '<label style="display: block; margin-bottom: 5px; padding: 5px; cursor: pointer;">';
                            html += '<input type="checkbox" name="split_items_' + index + '[]" value="' + item.id + '" style="margin-right: 8px;">';
                            html += '<strong>' + item.name + '</strong> (' + item.weight + 'kg) - komad ' + i;
                            html += '</label>';
                        }
                    });
                    html += '</div></div>';

                    // Weight summary
                    html += '<div style="background: #f0f0f0; padding: 10px; font-size: 13px;">';
                    html += '<strong>Težina paketa: <span class="split-total-weight" data-split="' + index + '">0.00 kg</span></strong>';
                    html += '</div></div>';

                    return html;
                }

                function attachSplitEventListeners(index) {
                    // Select/deselect all
                    $('.select-all-items[data-split="' + index + '"]').on('click', function() {
                        $('input[name="split_items_' + index + '[]"]').prop('checked', true);
                        updateSplitWeight(index);
                    });

                    $('.deselect-all-items[data-split="' + index + '"]').on('click', function() {
                        $('input[name="split_items_' + index + '[]"]').prop('checked', false);
                        updateSplitWeight(index);
                    });

                    // Update weight on change
                    $('input[name="split_items_' + index + '[]"]').on('change', function() {
                        updateSplitWeight(index);
                    });
                }

                function updateSplitWeight(index) {
                    var totalWeight = 0;
                    var selectedItemIds = [];

                    $('input[name="split_items_' + index + '[]"]:checked').each(function() {
                        var itemId = $(this).val();
                        selectedItemIds.push(itemId);
                        var item = orderItems.find(function(item) {
                            return item.id == itemId;
                        });
                        if (item) {
                            totalWeight += parseFloat(item.weight) || 0;
                        }
                    });

                    $('.split-total-weight[data-split="' + index + '"]').text(totalWeight.toFixed(2) + ' kg');

                    // ✅ DODAJ: Auto-update sadržaja
                    if (selectedItemIds.length > 0) {
                        generateSplitContent(index, selectedItemIds);
                    } else {
                        $('.dexpress-split-form[data-split-index="' + index + '"] .split-content-input').val('');
                    }
                }

                // Remove split form
                $(document).on('click', '.dexpress-remove-split', function() {
                    if (confirm('Ukloniti ovaj paket?')) {
                        $(this).closest('.dexpress-split-form').remove();
                        renumberSplitForms();
                    }
                });

                function renumberSplitForms() {
                    $('.dexpress-split-form').each(function(index) {
                        var newIndex = index + 1;
                        $(this).attr('data-split-index', newIndex);
                        $(this).find('h5').html('Paket #' + newIndex);
                        $(this).find('input[name^="split_items_"]').attr('name', 'split_items_' + newIndex + '[]');
                        $(this).find('.select-all-items, .deselect-all-items').attr('data-split', newIndex);
                        $(this).find('.split-total-weight').attr('data-split', newIndex);
                    });
                }

                function getShipmentFormData() {
                    return {
                        order_id: $('#dexpress-create-single-shipment').data('order-id'),
                        sender_location_id: $('select[name="dexpress_sender_location_id"]').val(),
                        content: $('input[name="dexpress_content"]').val(),
                        return_doc: $('input[name="dexpress_return_doc"]:checked').length > 0 ? 1 : 0,
                        dispenser_id: $('input[name="dexpress_dispenser_id"]').val()
                    };
                }

                function generateSplitContent(splitIndex, selectedItemIds) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'dexpress_generate_split_content',
                            order_id: $('#dexpress-create-single-shipment').data('order-id'),
                            selected_items: selectedItemIds,
                            nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $('.dexpress-split-form[data-split-index="' + splitIndex + '"] .split-content-input').val(response.data.content);
                            }
                        }
                    });
                }

                function collectSplitData() {
                    var splits = [];
                    $('.dexpress-split-form').each(function(index) {
                        var splitIndex = index + 1;
                        var locationId = $(this).find('select[name="split_locations[]"]').val();
                        var selectedItems = [];

                        $(this).find('input[name="split_items_' + splitIndex + '[]"]:checked').each(function() {
                            selectedItems.push($(this).val());
                        });

                        if (locationId && selectedItems.length > 0) {
                            splits.push({
                                location_id: locationId,
                                items: selectedItems
                            });
                        }
                    });
                    return splits;
                }

                function validateShipmentData(data) {
                    if (!data.sender_location_id) {
                        alert('Morate izabrati lokaciju!');
                        return false;
                    }
                    return true;
                }

                function sendAjaxRequest(action, data, callback) {
                    data.action = action;
                    data.nonce = '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>';

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: data,
                        success: callback,
                        error: function() {
                            showError('Greška u komunikaciji sa serverom');
                        }
                    });
                }

                function downloadLabel(shipmentId) {
                    var nonce = '<?php echo wp_create_nonce('dexpress-download-label'); ?>';
                    window.open(ajaxurl + '?action=dexpress_download_label&shipment_id=' + shipmentId + '&nonce=' + nonce, '_blank');
                }

                function downloadBulkLabels(shipmentIds) {
                    var nonce = '<?php echo wp_create_nonce('dexpress-bulk-print'); ?>';
                    window.open(ajaxurl + '?action=dexpress_bulk_print_labels&shipment_ids=' + shipmentIds + '&_wpnonce=' + nonce, '_blank');
                }

                function showSuccess(message) {
                    $('#dexpress-response').html('<div class="notice notice-success"><p>' + message + '</p></div>');
                }

                function showError(message) {
                    $('#dexpress-response').html('<div class="notice notice-error"><p>Greška: ' + message + '</p></div>');
                }
            });
        </script>
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
     * Učitavanje assets-a
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

        wp_enqueue_style(
            'dexpress-metabox-css',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/css/dexpress-admin.css',
            array(),
            DEXPRESS_WOO_VERSION
        );
    }
}
