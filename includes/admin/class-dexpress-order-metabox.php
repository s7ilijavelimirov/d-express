<?php

/**
 * D Express Order Metabox Handler
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
        // Za klasični način čuvanja porudžbina (post_type)
        add_meta_box(
            'dexpress_order_metabox',
            __('D Express Pošiljka', 'd-express-woo'),
            array($this, 'render_order_metabox'),
            'shop_order',
            'side',
            'default'
        );

        // Za HPOS način čuvanja porudžbina (ako je omogućen)
        if (
            class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') &&
            wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
        ) {
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
        // Provera da li je prosleđen WP_Post ili WC_Order
        if (is_a($post_or_order, 'WP_Post')) {
            $order = wc_get_order($post_or_order->ID);
            $order_id = $post_or_order->ID;
        } else {
            $order = $post_or_order;
            $order_id = $order->get_id();
        }

        if (!$order) {
            echo '<p>' . __('Narudžbina nije pronađena.', 'd-express-woo') . '</p>';
            return;
        }

        // Nonce field za sigurnost
        wp_nonce_field('dexpress_meta_box', 'dexpress_meta_box_nonce');

        // Proveri da li narudžbina koristi D Express dostavu
        $has_dexpress = $this->order_uses_dexpress($order);
        $is_dispenser = $this->is_dispenser_delivery($order);

        // Dobij podatke o pošiljci
        $shipment = $this->get_order_shipment($order_id);

        // Dobij lokacije
        $sender_locations_service = new D_Express_Sender_Locations();
        $locations = $sender_locations_service->get_all_locations();

        // Dobij trenutno izabranu lokaciju
        $selected_location_id = get_post_meta($order_id, '_dexpress_selected_sender_location_id', true);
        if (empty($selected_location_id)) {
            $selected_location_id = get_option('dexpress_default_sender_location_id');
        }

        // Pozovi view fajl sa svim potrebnim podacima
        $this->render_metabox_content($order, $has_dexpress, $is_dispenser, $shipment, $locations, $selected_location_id);
    }
    private function get_order_shipments($order_id)
    {
        // Osiguraj da je order_id integer
        $order_id = is_object($order_id) ? $order_id->get_id() : intval($order_id);

        global $wpdb;

        // ✅ IZMENA: Dobij shipments sa package info
        $shipments = $wpdb->get_results($wpdb->prepare("
        SELECT s.*, 
               COUNT(p.id) as package_count,
               GROUP_CONCAT(p.package_code ORDER BY p.package_index ASC) as package_codes
        FROM {$wpdb->prefix}dexpress_shipments s
        LEFT JOIN {$wpdb->prefix}dexpress_packages p ON s.id = p.shipment_id
        WHERE s.order_id = %d
        GROUP BY s.id
        ORDER BY s.created_at ASC
    ", $order_id));

        return $shipments;
    }

    /**
     * ZAMENI render_metabox_content FUNKCIJU SA OVOM (sa split functionality)
     */
    private function render_metabox_content($order, $has_dexpress, $is_dispenser, $shipment, $locations, $selected_location_id)
    {
        // Postojeći kod za računanje težine...
        $calculated_weight = $this->calculate_initial_weight($order);
        $custom_weight = get_post_meta($order->get_id(), '_dexpress_custom_weight', true);
        $display_weight = !empty($custom_weight) ? floatval($custom_weight) : $calculated_weight;

        $order_id = is_object($order) ? $order->get_id() : intval($order);
        $shipments = $this->get_order_shipments_with_packages($order_id);
        $shipment_splits = get_post_meta($order->get_id(), '_dexpress_shipment_splits', true) ?: [];

?>
        <div class="dexpress-order-metabox">
            <?php if (!$has_dexpress): ?>
                <p><?php _e('Ova narudžbina ne koristi D Express dostavu.', 'd-express-woo'); ?></p>
            <?php elseif (!empty($shipments)): ?>

                <!-- ✅ PRIKAZ POSTOJEĆIH POŠILJKI -->
                <div class="dexpress-existing-shipments">
                    <h4><?php _e('Postojeće pošiljke', 'd-express-woo'); ?></h4>

                    <?php foreach ($shipments as $shipment): ?>
                        <?php
                        global $wpdb;
                        $packages = $wpdb->get_results($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}dexpress_packages WHERE shipment_id = %d ORDER BY package_index ASC",
                            $shipment->id
                        ));

                        $package_count = count($packages);

                        if ($package_count === 1) {
                            $main_title = $packages[0]->package_code;
                            $subtitle = '';
                        } else if ($package_count > 1) {
                            $main_title = sprintf(__('Shipment #%s', 'd-express-woo'), $shipment->id);
                            $package_codes = array_map(function ($pkg) {
                                return $pkg->package_code;
                            }, $packages);
                            $subtitle = sprintf(__('%d paketa: %s', 'd-express-woo'), $package_count, implode(', ', $package_codes));
                        } else {
                            $main_title = $shipment->tracking_number ?: sprintf(__('Shipment #%s', 'd-express-woo'), $shipment->id);
                            $subtitle = __('Nema paketa u bazi', 'd-express-woo');
                        }

                        $location_info = '';
                        if ($shipment->sender_location_id) {
                            $locations_service = D_Express_Sender_Locations::get_instance();
                            $location = $locations_service->get_location($shipment->sender_location_id);
                            if ($location) {
                                $location_info = ' - ' . $location->name;
                            }
                        }
                        ?>

                        <div class="dexpress-shipment-item" style="padding: 10px; margin-bottom: 10px; border: 1px solid #ddd; background: #f9f9f9;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <div style="flex: 1;">
                                    <div>
                                        <strong><?php echo esc_html($main_title); ?></strong>
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
                                    <span class="dexpress-status-badge dexpress-status-delivered">
                                        <?php echo esc_html($shipment->status_description ?: 'U obradi'); ?>
                                    </span>
                                </div>
                            </div>

                            <div style="margin-top: 8px; font-size: 12px; color: #666;">
                                <?php _e('Kreirana:', 'd-express-woo'); ?>
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at))); ?>
                            </div>
                        </div>

                    <?php endforeach; ?>

                    <!-- Label dugmad -->
                    <div style="margin-top: 15px; text-align: center;">
                        <?php if (count($shipments) === 1): ?>
                            <button type="button" class="button button-primary dexpress-get-single-label"
                                data-shipment-id="<?php echo esc_attr($shipments[0]->id); ?>">
                                <?php _e('Štampaj nalepnicu', 'd-express-woo'); ?>
                            </button>
                        <?php else: ?>
                            <?php foreach ($shipments as $index => $ship): ?>
                                <button type="button" class="button button-secondary dexpress-get-single-label"
                                    data-shipment-id="<?php echo esc_attr($ship->id); ?>"
                                    style="margin: 2px;">
                                    <?php printf(__('Nalepnica %s', 'd-express-woo'), $ship->tracking_number); ?>
                                </button>
                            <?php endforeach; ?>

                            <?php $shipment_ids = array_map(function ($s) {
                                return $s->id;
                            }, $shipments); ?>
                            <br><br>
                            <button type="button" class="button button-primary dexpress-bulk-download-labels"
                                data-shipment-ids="<?php echo esc_attr(implode(',', $shipment_ids)); ?>">
                                <?php printf(__('Štampaj sve (%d nalepnica)', 'd-express-woo'), count($shipments)); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <hr style="margin: 15px 0;">

            <?php else: ?>
                <!-- ✅ KREIRANJE NOVE POŠILJKE - NEDOSTAJUĆI DEO! -->
                <?php $this->render_order_items_with_weights($order); ?>

                <div class="dexpress-create-section" id="dexpress-create-section">
                    <h4><?php _e('Kreiranje D Express pošiljke', 'd-express-woo'); ?></h4>

                    <!-- Lokacija pošiljaoca -->
                    <div style="margin-bottom: 15px;">
                        <label for="dexpress_sender_location_id" style="display: block; font-weight: bold; margin-bottom: 5px;">
                            <?php _e('Lokacija pošiljaoca:', 'd-express-woo'); ?>
                        </label>
                        <select name="dexpress_sender_location_id" id="dexpress_sender_location_id" style="width: 100%;" required>
                            <option value=""><?php _e('Izaberite lokaciju...', 'd-express-woo'); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo esc_attr($location->id); ?>"
                                    <?php selected($selected_location_id, $location->id); ?>>
                                    <?php echo esc_html($location->name . ' - ' . $location->address); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Sadržaj pošiljke -->
                    <div style="margin-bottom: 15px;">
                        <label for="dexpress_content" style="display: block; font-weight: bold; margin-bottom: 5px;">
                            <?php _e('Sadržaj pošiljke:', 'd-express-woo'); ?>
                        </label>
                        <input type="text" name="dexpress_content" id="dexpress_content"
                            value="<?php echo esc_attr($this->get_default_content($order)); ?>"
                            style="width: 100%;" maxlength="50"
                            placeholder="<?php _e('Opisati sadržaj pošiljke...', 'd-express-woo'); ?>">
                    </div>

                    <!-- Povratni dokumenti -->
                    <div style="margin-bottom: 15px;">
                        <label>
                            <input type="checkbox" name="dexpress_return_doc" value="1">
                            <?php _e('Povratni dokumenti', 'd-express-woo'); ?>
                        </label>
                        <p class="description"><?php _e('Kurir čeka da primalac potpiše i odmah vraća dokumente.', 'd-express-woo'); ?></p>
                    </div>

                    <!-- Paketomat (ako je dispenser dostava) -->
                    <?php if ($is_dispenser): ?>
                        <div style="margin-bottom: 15px;">
                            <label for="dexpress_dispenser_id" style="display: block; font-weight: bold; margin-bottom: 5px;">
                                <?php _e('Paketomat ID:', 'd-express-woo'); ?>
                            </label>
                            <input type="number" name="dexpress_dispenser_id" id="dexpress_dispenser_id"
                                value="" style="width: 100%;"
                                placeholder="<?php _e('Unesite ID paketomata...', 'd-express-woo'); ?>">
                            <p class="description"><?php _e('ID paketomata za dostavu (obavezno za paketomat dostavu).', 'd-express-woo'); ?></p>
                        </div>
                    <?php endif; ?>

                    <!-- Dugmad za kreiranje -->
                    <div style="margin-top: 20px;">
                        <button type="button" id="dexpress-create-single-shipment"
                            class="button button-primary"
                            data-order-id="<?php echo esc_attr($order_id); ?>">
                            <?php _e('Kreiraj pošiljku', 'd-express-woo'); ?>
                        </button>

                        <button type="button" id="dexpress-toggle-split-mode"
                            class="button button-secondary" style="margin-left: 10px;">
                            <?php _e('Podeli na više pošiljki', 'd-express-woo'); ?>
                        </button>
                    </div>
                </div>

                <!-- Split mode sekcija -->
                <div class="dexpress-split-section" id="dexpress-split-section" style="display: none;">
                    <h4><?php _e('Podela na više pošiljki', 'd-express-woo'); ?></h4>

                    <div style="margin-bottom: 15px;">
                        <label for="dexpress-split-count"><?php _e('Broj pošiljki:', 'd-express-woo'); ?></label>
                        <select id="dexpress-split-count" style="margin-left: 10px;">
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                        </select>
                        <button type="button" id="dexpress-generate-splits" class="button" style="margin-left: 10px;">
                            <?php _e('Generiši pakete', 'd-express-woo'); ?>
                        </button>
                    </div>

                    <div id="dexpress-splits-container"></div>

                    <div style="margin-top: 15px;">
                        <button type="button" id="dexpress-create-all-shipments"
                            class="button button-primary"
                            data-order-id="<?php echo esc_attr($order_id); ?>">
                            <?php _e('Kreiraj sve pošiljke', 'd-express-woo'); ?>
                        </button>

                        <button type="button" id="dexpress-back-to-single" class="button" style="margin-left: 10px;">
                            <?php _e('Nazad na jednostruku', 'd-express-woo'); ?>
                        </button>
                    </div>
                </div>

            <?php endif; ?>

            <div id="dexpress-response" style="margin-top: 15px;"></div>
        </div>

        <!-- JavaScript -->
        <script type="text/javascript">
            jQuery(document).ready(function($) {

                // Ažuriranje ukupne težine kada se menja weight input
                $('.dexpress-item-weight-input').on('input', function() {
                    var itemId = $(this).data('item-id');
                    var quantity = $(this).data('quantity');
                    var weight = parseFloat($(this).val()) || 0;
                    var totalWeight = weight * quantity;

                    // Ažuriraj prikaz ukupne težine za item
                    $('.dexpress-total-item-weight[data-item-id="' + itemId + '"]').text(totalWeight.toFixed(2));

                    // Ažuriraj grand total
                    updateGrandTotal();
                });

                function updateGrandTotal() {
                    var grandTotal = 0;
                    $('.dexpress-item-weight-input').each(function() {
                        var weight = parseFloat($(this).val()) || 0;
                        var quantity = $(this).data('quantity');
                        grandTotal += weight * quantity;
                    });

                    $('#dexpress-grand-total-weight').text(grandTotal.toFixed(2));
                }

                // SINGLE SHIPMENT - poziva postojeći AJAX handler
                $('#dexpress-create-single-shipment').on('click', function() {
                    var btn = $(this);
                    var orderId = btn.data('order-id');
                    var locationId = $('select[name="dexpress_sender_location_id"]').val();
                    var content = $('input[name="dexpress_content"]').val();
                    var returnDoc = $('input[name="dexpress_return_doc"]:checked').length > 0 ? 1 : 0;
                    var dispenserId = $('input[name="dexpress_dispenser_id"]').val();

                    if (!locationId) {
                        alert('Morate izabrati lokaciju pošaljioca!');
                        return;
                    }

                    btn.prop('disabled', true).text('Kreiranje...');
                    $('#dexpress-response').html('<div class="notice notice-info"><p>Kreiranje pošiljke...</p></div>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'dexpress_create_shipment',
                            order_id: orderId,
                            sender_location_id: locationId,
                            content: content,
                            return_doc: returnDoc,
                            dispenser_id: dispenserId,
                            nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                        },
                        success: function(response) {
                            btn.prop('disabled', false).text('<?php _e('Kreiraj pošiljku', 'd-express-woo'); ?>');

                            if (response.success) {
                                $('#dexpress-response').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                                // Reload stranicu posle kratke pauze
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                $('#dexpress-response').html('<div class="notice notice-error"><p>Greška: ' + response.data.message + '</p></div>');
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('<?php _e('Kreiraj pošiljku', 'd-express-woo'); ?>');
                            $('#dexpress-response').html('<div class="notice notice-error"><p>Greška u komunikaciji sa serverom</p></div>');
                        }
                    });
                });

                // MULTIPLE SHIPMENTS - poziva postojeći AJAX handler
                $('#dexpress-create-all-shipments').on('click', function() {
                    var btn = $(this);
                    var orderId = btn.data('order-id');
                    var splits = [];

                    // Prikupi podatke iz svih split forma
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

                    if (splits.length === 0) {
                        alert('Morate definisati barem jednu pošiljku sa lokacijom i artiklima!');
                        return;
                    }

                    btn.prop('disabled', true).text('Kreiranje svih pošiljki...');
                    $('#dexpress-response').html('<div class="notice notice-info"><p>Kreiranje ' + splits.length + ' pošiljki...</p></div>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'dexpress_create_multiple_shipments',
                            order_id: orderId,
                            splits: splits,
                            nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                        },
                        success: function(response) {
                            btn.prop('disabled', false).text('<?php _e('Kreiraj sve pošiljke', 'd-express-woo'); ?>');

                            if (response.success) {
                                var message = response.data.message;
                                if (response.data.shipments && response.data.shipments.length > 0) {
                                    message += '<br><strong>Kreirane pošiljke:</strong><ul>';
                                    response.data.shipments.forEach(function(shipment) {
                                        message += '<li>' + shipment.tracking_number + ' - ' + shipment.location_name + '</li>';
                                    });
                                    message += '</ul>';
                                }
                                $('#dexpress-response').html('<div class="notice notice-success"><p>' + message + '</p></div>');

                                // Reload stranicu posle kratke pauze
                                setTimeout(function() {
                                    location.reload();
                                }, 3000);
                            } else {
                                $('#dexpress-response').html('<div class="notice notice-error"><p>Greška: ' + response.data.message + '</p></div>');
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('<?php _e('Kreiraj sve pošiljke', 'd-express-woo'); ?>');
                            $('#dexpress-response').html('<div class="notice notice-error"><p>Greška u komunikaciji sa serverom</p></div>');
                        }
                    });
                });

                // LABEL DOWNLOAD HANDLERS
                $('.dexpress-get-single-label').on('click', function(e) {
                    e.preventDefault();
                    var shipmentId = $(this).data('shipment-id');
                    var nonce = '<?php echo wp_create_nonce('dexpress-download-label'); ?>';

                    window.open(
                        ajaxurl + '?action=dexpress_download_label&shipment_id=' + shipmentId + '&nonce=' + nonce,
                        '_blank'
                    );
                });

                $('.dexpress-bulk-download-labels').on('click', function(e) {
                    e.preventDefault();
                    var shipmentIds = $(this).data('shipment-ids');
                    var nonce = '<?php echo wp_create_nonce('dexpress-bulk-print'); ?>';

                    window.open(
                        ajaxurl + '?action=dexpress_bulk_print_labels&shipment_ids=' + shipmentIds + '&_wpnonce=' + nonce,
                        '_blank'
                    );
                });

                // Toggle split mode
                $('#dexpress-toggle-split-mode').on('click', function() {
                    $('#dexpress-create-section').hide();
                    $('#dexpress-split-section').show();
                    loadOrderItems();
                });

                // Back to single shipment
                $('#dexpress-back-to-single').on('click', function() {
                    $('#dexpress-split-section').hide();
                    $('#dexpress-create-section').show();
                    clearAllSplits();
                });

                // Generate multiple splits at once
                $('#dexpress-generate-splits').on('click', function() {
                    var count = parseInt($('#dexpress-split-count').val()) || 2;
                    if (count < 2) count = 2;
                    if (count > 10) count = 10;

                    clearAllSplits();

                    for (var i = 1; i <= count; i++) {
                        addSplitForm(i);
                    }

                    // Scroll to first split
                    if ($('.dexpress-split-form').length > 0) {
                        $('.dexpress-split-form').first()[0].scrollIntoView({
                            behavior: 'smooth'
                        });
                    }
                });

                // Clear all splits
                $('#dexpress-clear-splits').on('click', function() {
                    if (confirm('Da li ste sigurni da želite da obrišete sve pakete?')) {
                        clearAllSplits();
                    }
                });

                // Add split functionality (single)
                $('#dexpress-add-split').on('click', function() {
                    var nextIndex = $('.dexpress-split-form').length + 1;
                    addSplitForm(nextIndex);
                });

                function clearAllSplits() {
                    $('#dexpress-splits-container').empty();
                }

                // Load order items for splitting
                function loadOrderItems() {
                    var orderItems = [];
                    $('.dexpress-item-weight-input').each(function() {
                        var itemId = $(this).data('item-id');
                        var itemName = $(this).closest('tr').find('td:first strong').text();
                        var quantity = $(this).data('quantity');
                        var weight = parseFloat($(this).val()) || 0;

                        orderItems.push({
                            id: itemId,
                            name: itemName,
                            quantity: quantity,
                            weight: weight
                        });
                    });

                    window.dexpressOrderItems = orderItems;
                }

                // Add split form
                function addSplitForm(splitIndex) {
                    if (!splitIndex) {
                        splitIndex = $('.dexpress-split-form').length + 1;
                    }

                    var locations = <?php echo json_encode($locations); ?>;
                    var orderItems = window.dexpressOrderItems || [];

                    var splitHtml = '<div class="dexpress-split-form" data-split-index="' + splitIndex + '" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; background: #f9f9f9;">';
                    splitHtml += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
                    splitHtml += '<h5 style="margin: 0;">Paket #' + splitIndex + '</h5>';
                    splitHtml += '<button type="button" class="button button-small dexpress-remove-split">Ukloni</button>';
                    splitHtml += '</div>';

                    // Location selector
                    splitHtml += '<div style="margin-bottom: 15px;">';
                    splitHtml += '<label style="display: block; font-weight: bold; margin-bottom: 5px;">Lokacija pošaljioca:</label>';
                    splitHtml += '<select name="split_locations[]" class="split-location-select" style="width: 100%;" required>';
                    splitHtml += '<option value="">Izaberite lokaciju...</option>';
                    locations.forEach(function(location) {
                        splitHtml += '<option value="' + location.id + '">' + location.name + ' - ' + location.address + '</option>';
                    });
                    splitHtml += '</select>';
                    splitHtml += '</div>';

                    // Items selector
                    splitHtml += '<div style="margin-bottom: 15px;">';
                    splitHtml += '<label style="display: block; font-weight: bold; margin-bottom: 5px;">Artikli za ovaj paket:</label>';
                    splitHtml += '<div style="margin-bottom: 8px;">';
                    splitHtml += '<button type="button" class="button button-small select-all-items" data-split="' + splitIndex + '">Izaberi sve</button> ';
                    splitHtml += '<button type="button" class="button button-small deselect-all-items" data-split="' + splitIndex + '">Poništi sve</button>';
                    splitHtml += '</div>';

                    splitHtml += '<div class="items-container" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">';

                    if (orderItems.length === 0) {
                        splitHtml += '<p style="color: #666;">Nema dostupnih artikala</p>';
                    } else {
                        orderItems.forEach(function(item) {
                            for (var i = 1; i <= item.quantity; i++) {
                                splitHtml += '<label style="display: block; margin-bottom: 5px; padding: 5px; cursor: pointer;" class="item-checkbox-label">';
                                splitHtml += '<input type="checkbox" name="split_items_' + splitIndex + '[]" value="' + item.id + '" style="margin-right: 8px;">';
                                splitHtml += '<strong>' + item.name + '</strong> ';
                                splitHtml += '(' + item.weight + 'kg) ';
                                splitHtml += '- komad ' + i;
                                splitHtml += '</label>';
                            }
                        });
                    }

                    splitHtml += '</div>';
                    splitHtml += '</div>';

                    // Weight summary
                    splitHtml += '<div style="background: #f0f0f0; padding: 10px; font-size: 13px;">';
                    splitHtml += '<strong>Težina paketa: <span class="split-total-weight" data-split="' + splitIndex + '">0.00 kg</span></strong>';
                    splitHtml += '</div>';

                    splitHtml += '</div>';

                    $('#dexpress-splits-container').append(splitHtml);

                    // Add event listeners for this split
                    attachSplitEventListeners(splitIndex);
                }

                // Attach event listeners for split
                function attachSplitEventListeners(splitIndex) {
                    // Select/deselect all
                    $('.select-all-items[data-split="' + splitIndex + '"]').on('click', function() {
                        $('input[name="split_items_' + splitIndex + '[]"]').prop('checked', true);
                        updateSplitWeight(splitIndex);
                    });

                    $('.deselect-all-items[data-split="' + splitIndex + '"]').on('click', function() {
                        $('input[name="split_items_' + splitIndex + '[]"]').prop('checked', false);
                        updateSplitWeight(splitIndex);
                    });

                    // Update weight when items change
                    $('input[name="split_items_' + splitIndex + '[]"]').on('change', function() {
                        updateSplitWeight(splitIndex);
                    });

                    // Hover effect for labels
                    $('.dexpress-split-form[data-split-index="' + splitIndex + '"] .item-checkbox-label').hover(
                        function() {
                            $(this).css('background-color', '#f0f8ff');
                        },
                        function() {
                            $(this).css('background-color', 'transparent');
                        }
                    );
                }

                // Update weight for specific split
                function updateSplitWeight(splitIndex) {
                    var totalWeight = 0;
                    var orderItems = window.dexpressOrderItems || [];

                    $('input[name="split_items_' + splitIndex + '[]"]:checked').each(function() {
                        var itemId = $(this).val();
                        var item = orderItems.find(function(item) {
                            return item.id == itemId;
                        });
                        if (item) {
                            totalWeight += parseFloat(item.weight) || 0;
                        }
                    });

                    $('.split-total-weight[data-split="' + splitIndex + '"]').text(totalWeight.toFixed(2) + ' kg');
                }

                // Remove split form
                $(document).on('click', '.dexpress-remove-split', function() {
                    if (confirm('Da li ste sigurni da želite da uklonite ovaj paket?')) {
                        $(this).closest('.dexpress-split-form').remove();
                        // Re-number splits
                        $('.dexpress-split-form').each(function(index) {
                            var newIndex = index + 1;
                            $(this).attr('data-split-index', newIndex);
                            $(this).find('h5').html('Paket #' + newIndex);

                            // Update form elements
                            $(this).find('input[name^="split_items_"]').attr('name', 'split_items_' + newIndex + '[]');
                            $(this).find('.select-all-items, .deselect-all-items').attr('data-split', newIndex);
                            $(this).find('.split-total-weight').attr('data-split', newIndex);
                        });
                    }
                });

            });
        </script>

<?php
    }
    /**
     * ✅ NOVA METODA: Dobija shipments sa package informacijama
     */
    private function get_order_shipments_with_packages($order_id)
    {
        global $wpdb;

        // Dobij sve shipments za ovaj order
        $shipments = $wpdb->get_results($wpdb->prepare("
        SELECT s.*
        FROM {$wpdb->prefix}dexpress_shipments s
        WHERE s.order_id = %d
        ORDER BY s.created_at ASC
    ", $order_id));

        return $shipments;
    }
    /**
     * Renderovanje statusa pošiljke
     */
    private function render_shipment_status($shipment)
    {
        if ($shipment->status_code) {
            $status_class = dexpress_get_status_css_class($shipment->status_code);
            echo '<span class="dexpress-status-badge ' . esc_attr($status_class) . '">';
            echo esc_html(dexpress_get_status_name($shipment->status_code));
            echo '</span>';
        } else {
            echo '<span class="dexpress-status-badge status-created">';
            echo __('Pošiljka kreirana', 'd-express-woo');
            echo '</span>';
        }
    }

    /**
     * Renderovanje tracking broja
     */
    private function render_tracking_number($shipment)
    {
        if ($shipment->is_test) {
            echo '<span class="dexpress-tracking-number">' . esc_html($shipment->tracking_number) . '</span>';
            echo ' <span class="description">' . __('(Test mode)', 'd-express-woo') . '</span>';
        } else {
            echo '<a href="https://www.dexpress.rs/rs/pracenje-posiljaka/' . esc_attr($shipment->tracking_number) . '"';
            echo ' target="_blank" class="dexpress-tracking-number">';
            echo esc_html($shipment->tracking_number);
            echo '</a>';
        }
    }

    /**
     * Proverava da li narudžbina koristi D Express
     */
    private function order_uses_dexpress($order)
    {
        $shipping_methods = $order->get_shipping_methods();
        foreach ($shipping_methods as $shipping_method) {
            $method_id = $shipping_method->get_method_id();
            if (in_array($method_id, ['dexpress', 'dexpress_dispenser'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Proverava da li je dispenser dostava
     */
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

    /**
     * Dobija pošiljku za narudžbinu
     */
    private function get_order_shipment($order_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));
    }
    private function calculate_initial_weight($order)
    {
        $weight_kg = 0;

        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if ($product && $product->has_weight()) {
                $weight_kg += floatval($product->get_weight()) * $item->get_quantity();
            }
        }

        return max(0.1, $weight_kg); // Minimum 0.1kg
    }
    /**
     * Renderovanje sekcije sa artiklima i njihovim težinama
     */
    private function render_order_items_with_weights($order)
    {
        echo '<div class="dexpress-items-weight-section" style="margin-bottom: 20px;">';
        echo '<h4>' . __('Proizvodi i težine', 'd-express-woo') . '</h4>';

        echo '<div class="dexpress-items-table">';
        echo '<table style="width: 100%; border-collapse: collapse; font-size: 12px;">';
        echo '<thead>';
        echo '<tr style="background: #f5f5f5;">';
        echo '<th style="padding: 6px; text-align: left; border: 1px solid #ddd;">' . __('Proizvod', 'd-express-woo') . '</th>';
        echo '<th style="padding: 6px; text-align: center; border: 1px solid #ddd;">' . __('Kol.', 'd-express-woo') . '</th>';
        echo '<th style="padding: 6px; text-align: center; border: 1px solid #ddd;">' . __('Težina (kg)', 'd-express-woo') . '</th>';
        echo '<th style="padding: 6px; text-align: center; border: 1px solid #ddd;">' . __('Ukupno', 'd-express-woo') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        $total_calculated_weight = 0;

        foreach ($order->get_items() as $item_id => $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            $quantity = $item->get_quantity();
            $product_weight = 0;

            if ($product && $product->has_weight()) {
                $product_weight = floatval($product->get_weight());
            }

            // Dobij custom weight za ovaj item ako postoji
            $custom_item_weight = get_post_meta($order->get_id(), '_dexpress_item_weight_' . $item_id, true);
            $display_item_weight = !empty($custom_item_weight) ? floatval($custom_item_weight) : $product_weight;

            $total_item_weight = $display_item_weight * $quantity;
            $total_calculated_weight += $total_item_weight;

            echo '<tr>';
            echo '<td style="padding: 6px; border: 1px solid #ddd;">';
            echo '<strong>' . esc_html(mb_substr($item->get_name(), 0, 30)) . '</strong>';
            if ($product && $product->get_sku()) {
                echo '<br><small style="color: #666;">SKU: ' . esc_html($product->get_sku()) . '</small>';
            }
            echo '</td>';

            echo '<td style="padding: 6px; text-align: center; border: 1px solid #ddd;">';
            echo '<strong>' . esc_html($quantity) . '</strong>';
            echo '</td>';

            echo '<td style="padding: 6px; text-align: center; border: 1px solid #ddd;">';
            if ($product_weight > 0) {
                echo '<span style="color: #999; font-size: 11px;">' . number_format($product_weight, 2) . '</span><br>';
            }
            echo '<input type="number" name="dexpress_item_weight[' . $item_id . ']" ';
            echo 'value="' . esc_attr($display_item_weight) . '" ';
            echo 'step="0.01" min="0" style="width: 60px; text-align: center; font-size: 11px;" ';
            echo 'class="dexpress-item-weight-input" data-item-id="' . $item_id . '" data-quantity="' . $quantity . '">';
            echo '</td>';

            echo '<td style="padding: 6px; text-align: center; border: 1px solid #ddd;">';
            echo '<strong><span class="dexpress-total-item-weight" data-item-id="' . $item_id . '">';
            echo number_format($total_item_weight, 2) . '</span> kg</strong>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '<tfoot>';
        echo '<tr style="background: #e8f4f8; font-weight: bold;">';
        echo '<td colspan="3" style="padding: 8px; border: 1px solid #ddd; text-align: right;">';
        echo __('UKUPNA TEŽINA:', 'd-express-woo');
        echo '</td>';
        echo '<td style="padding: 8px; text-align: center; border: 1px solid #ddd; color: #2563eb;">';
        echo '<span id="dexpress-grand-total-weight">' . number_format($total_calculated_weight, 2) . '</span> kg';
        echo '</td>';
        echo '</tr>';
        echo '</tfoot>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
    }
    /**
     * Čuvanje weight podataka
     */
    public function save_weight_data($order_id)
    {
        // Provera nonce-a
        if (!isset($_POST['dexpress_meta_box_nonce']) || !wp_verify_nonce($_POST['dexpress_meta_box_nonce'], 'dexpress_meta_box')) {
            return;
        }

        // Čuvanje custom weight za svaki item
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
     * Helper funkcije
     */
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

        if (!empty($product_names)) {
            return implode(', ', array_slice($product_names, 0, 2));
        }

        return 'Razni proizvodi';
    }

    private function is_cod_order($order)
    {
        $payment_method = $order->get_payment_method();
        return in_array($payment_method, ['cod', 'cash_on_delivery']);
    }
    /**
     * Učitavanje assets-a za metabox
     */
    public function enqueue_metabox_assets($hook)
    {
        // Učitaj samo na order stranicama
        if (!in_array($hook, ['post.php', 'post-new.php', 'woocommerce_page_wc-orders'])) {
            return;
        }

        // Proverava da li je WooCommerce order
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
