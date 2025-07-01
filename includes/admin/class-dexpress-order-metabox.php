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
        $db = new D_Express_DB();
        return $db->get_shipments_by_order_id($order_id);
    }
    /**
     * Renderovanje sadržaja metabox-a
     */
    private function render_metabox_content($order, $has_dexpress, $is_dispenser, $shipment, $locations, $selected_location_id)
    {
        // Proveri da li postoje već kreirane pošiljke (ažurirano za multiple)
        $shipments = $this->get_order_shipments($order->get_id());
        $shipment_splits = get_post_meta($order->get_id(), '_dexpress_shipment_splits', true) ?: [];

?>
        <div class="dexpress-order-metabox">
            <?php if (!$has_dexpress): ?>
                <p><?php _e('Ova narudžbina ne koristi D Express dostavu.', 'd-express-woo'); ?></p>

            <?php elseif (!empty($shipments)): ?>
                <!-- PRIKAZ POSTOJEĆIH POŠILJKI -->
                <div class="dexpress-existing-shipments">
                    <h4><?php _e('D Express pošiljke', 'd-express-woo'); ?></h4>

                    <!-- UNIVERSAL DOWNLOAD BUTTON SEKCIJA -->
                    <div class="dexpress-download-actions" style="margin-bottom: 20px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">
                        <?php if (count($shipments) > 1): ?>
                            <button type="button" class="button button-primary dexpress-bulk-download-labels"
                                data-shipment-ids="<?php echo esc_attr(implode(',', array_column($shipments, 'id'))); ?>">
                                <?php printf(__('Preuzmi sve nalepnice (%d)', 'd-express-woo'), count($shipments)); ?>
                            </button>
                        <?php else: ?>
                            <button type="button" class="button button-primary dexpress-get-single-label"
                                data-shipment-id="<?php echo esc_attr($shipments[0]->id); ?>">
                                <?php _e('Preuzmi nalepnicu', 'd-express-woo'); ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- DETALJI POŠILJKI -->
                    <?php foreach ($shipments as $index => $shipment): ?>
                        <div style="margin-bottom: 15px; padding: 10px; border: 1px solid #ddd;">
                            <?php if (count($shipments) > 1): ?>
                                <h5><?php printf(__('Pošiljka %d od %d', 'd-express-woo'), $index + 1, count($shipments)); ?></h5>
                            <?php else: ?>
                                <h5><?php _e('D Express pošiljka', 'd-express-woo'); ?></h5>
                            <?php endif; ?>

                            <div class="dexpress-tracking-status">
                                <?php $this->render_shipment_status($shipment); ?>
                            </div>

                            <p>
                                <strong><?php _e('Tracking broj:', 'd-express-woo'); ?></strong><br>
                                <?php $this->render_tracking_number($shipment); ?>
                            </p>

                            <?php if ($shipment->sender_location_id): ?>
                                <p>
                                    <strong><?php _e('Lokacija:', 'd-express-woo'); ?></strong><br>
                                    <?php
                                    $location = array_filter($locations, function ($loc) use ($shipment) {
                                        return $loc->id == $shipment->sender_location_id;
                                    });
                                    echo $location ? reset($location)->name : 'N/A';
                                    ?>
                                </p>
                            <?php endif; ?>

                            <div style="margin-top: 10px;">
                                <button type="button" class="button button-link-delete dexpress-delete-shipment"
                                    data-shipment-id="<?php echo esc_attr($shipment->id); ?>"
                                    data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                                    style="color: #a00;">
                                    <?php _e('Obriši pošiljku', 'd-express-woo'); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <!-- KREIRANJE NOVIH POŠILJKI -->
                <?php if ($order->get_item_count() > 1 && empty($shipment)): ?>
                    <div style="margin-bottom: 15px;">
                        <label>
                            <input type="checkbox" id="dexpress-enable-split">
                            <strong><?php _e('Podeli na više pošiljki', 'd-express-woo'); ?></strong>
                        </label>
                        <p class="description"><?php _e('Omogućava kreiranje više pošiljki iz jedne narudžbine sa različitim lokacijama.', 'd-express-woo'); ?></p>
                    </div>

                    <div id="dexpress-split-section" style="display:none;">
                        <div id="dexpress-splits-container">
                            <!-- Dinamički se dodaju split forme -->
                        </div>

                        <button type="button" class="button" id="dexpress-add-split">
                            <?php _e('Dodaj pošiljku', 'd-express-woo'); ?>
                        </button>

                        <hr style="margin: 15px 0;">

                        <button type="button" class="button button-primary" id="dexpress-create-all-shipments"
                            data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                            <?php _e('Kreiraj sve pošiljke', 'd-express-woo'); ?>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Postojeći kod za kreiranje pojedinačne pošiljke -->
                <div id="dexpress-single-shipment">
                    <?php if (!empty($locations)): ?>
                        <p>
                            <label for="dexpress-sender-location">
                                <strong><?php _e('Lokacija pošaljioce:', 'd-express-woo'); ?></strong>
                            </label>
                            <select id="dexpress-sender-location" style="width: 100%; margin-top: 5px;">
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo esc_attr($location->id); ?>"
                                        <?php selected($selected_location_id, $location->id); ?>>
                                        <?php echo esc_html($location->name); ?>
                                        <?php if ($location->is_default): ?>
                                            <?php _e('(Glavna)', 'd-express-woo'); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                    <?php endif; ?>

                    <div class="dexpress-create-shipment" style="margin-top: 15px;">
                        <button type="button" class="button button-primary dexpress-create-shipment-btn"
                            data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                            <?php if (empty($locations)) echo 'disabled'; ?>>
                            <?php _e('Kreiraj D Express pošiljku', 'd-express-woo'); ?>
                        </button>

                        <div class="dexpress-response" style="margin-top: 10px;"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Postojeći JS kod za kreiranje pojedinačne pošiljke
                $('.dexpress-create-shipment-btn').on('click', function(e) {
                    e.preventDefault();

                    var btn = $(this);
                    var orderId = btn.data('order-id');
                    var locationId = $('#dexpress-sender-location').val();

                    btn.prop('disabled', true).text('<?php _e('Kreiranje...', 'd-express-woo'); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'dexpress_create_shipment',
                            order_id: orderId,
                            sender_location_id: locationId,
                            nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $('.dexpress-response').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                $('.dexpress-response').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                                btn.prop('disabled', false).text('<?php _e('Kreiraj D Express pošiljku', 'd-express-woo'); ?>');
                            }
                        },
                        error: function() {
                            $('.dexpress-response').html('<div class="notice notice-error"><p><?php _e('Greška pri komunikaciji sa serverom.', 'd-express-woo'); ?></p></div>');
                            btn.prop('disabled', false).text('<?php _e('Kreiraj D Express pošiljku', 'd-express-woo'); ?>');
                        }
                    });
                });

                // JS za bulk download nalepnica (MULTIPLE shipments)
                $('.dexpress-bulk-download-labels').on('click', function(e) {
                    e.preventDefault();

                    var shipmentIds = $(this).data('shipment-ids');
                    var nonce = '<?php echo wp_create_nonce('dexpress-bulk-print'); ?>';

                    // Otvori novi tab sa nalepnicama
                    var url = ajaxurl + '?action=dexpress_bulk_print_labels&shipment_ids=' + shipmentIds + '&_wpnonce=' + nonce;
                    window.open(url, '_blank');
                });

                // JS za single download nalepnice 
                $('.dexpress-get-single-label').on('click', function(e) {
                    e.preventDefault();

                    var shipmentId = $(this).data('shipment-id');
                    window.open(ajaxurl + '?action=dexpress_download_label&shipment_id=' + shipmentId + '&nonce=<?php echo wp_create_nonce('dexpress-download-label'); ?>', '_blank');
                });

                // NOVI JS za multiple shipments
                $('#dexpress-enable-split').on('change', function() {
                    if ($(this).is(':checked') && confirm('<?php _e('Da li ste sigurni da želite da podelite ovu narudžbinu na više pošiljki?', 'd-express-woo'); ?>')) {
                        $('#dexpress-split-section').show();
                        $('#dexpress-single-shipment').hide();
                        if ($('#dexpress-splits-container').children().length === 0) {
                            $('#dexpress-add-split').click();
                        }
                    } else {
                        $(this).prop('checked', false);
                        $('#dexpress-split-section').hide();
                        $('#dexpress-single-shipment').show();
                    }
                });

                var splitIndex = 0;
                var orderItems = <?php echo json_encode(array_values(array_map(function ($item) {
                                        return array(
                                            'id' => $item->get_id(),
                                            'name' => $item->get_name(),
                                            'quantity' => $item->get_quantity()
                                        );
                                    }, $order->get_items()))); ?>;
                var locations = <?php echo json_encode(array_map(function ($loc) {
                                    return array(
                                        'id' => $loc->id,
                                        'name' => $loc->name
                                    );
                                }, $locations)); ?>;

                // NOVA FUNKCIJA: Validira i ažurira dostupnost artikala
                function updateItemAvailability() {
                    var assignedItems = [];

                    // Prikupi sve već dodeljene artikle
                    $('.dexpress-split-form .item-checkbox:checked').each(function() {
                        assignedItems.push($(this).data('item-id'));
                    });

                    // Ažuriraj stanje svih checkbox-ova
                    $('.dexpress-split-form .item-checkbox').each(function() {
                        var itemId = $(this).data('item-id');
                        var isAssigned = assignedItems.indexOf(itemId) !== -1 && !$(this).is(':checked');

                        if (isAssigned) {
                            // Artikal je već dodeljen u drugoj pošiljci
                            $(this).prop('disabled', true);
                            $(this).closest('label').css({
                                'opacity': '0.5',
                                'text-decoration': 'line-through'
                            }).attr('title', 'Artikal je već dodeljen drugoj pošiljci');
                        } else {
                            // Artikal je dostupan
                            $(this).prop('disabled', false);
                            $(this).closest('label').css({
                                'opacity': '1',
                                'text-decoration': 'none'
                            }).removeAttr('title');
                        }
                    });


                }

                function renumberSplits() {
                    $('.dexpress-split-form').each(function(index) {
                        $(this).find('h5').first().contents().filter(function() {
                            return this.nodeType === 3; // Text node
                        }).first().replaceWith('📍 Pošiljka ' + (index + 1) + ' ');
                    });
                }
                $('#dexpress-add-split').on('click', function() {
                    var html = '<div class="dexpress-split-form" data-index="' + splitIndex + '" style="margin-bottom: 15px; padding: 15px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;">';
                    html += '<h5 style="margin: 0 0 10px 0;">📍 Pošiljka ' + (splitIndex + 1);
                    html += ' <button type="button" class="button-link dexpress-remove-split" style="float: right; color: #a00; text-decoration: none;">✕ Ukloni</button></h5>';

                    // Lokacija
                    html += '<p><strong>Lokacija pošaljioca:</strong></p>';
                    html += '<select class="split-location" style="width: 100%; margin-bottom: 15px; padding: 5px;">';
                    for (var i = 0; i < locations.length; i++) {
                        html += '<option value="' + locations[i].id + '">' + locations[i].name + '</option>';
                    }
                    html += '</select>';

                    // Artikli sa boljim dizajnom
                    html += '<div class="split-items">';
                    html += '<p><strong>Artikli za ovu pošiljku:</strong></p>';
                    html += '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: white; border-radius: 3px;">';

                    if (orderItems.length > 0) {
                        for (var j = 0; j < orderItems.length; j++) {
                            var item = orderItems[j];
                            html += '<label style="display: block; margin: 8px 0; padding: 5px; cursor: pointer;" class="item-label">';
                            html += '<input type="checkbox" class="item-checkbox" data-item-id="' + item.id + '" style="margin-right: 8px;"> ';
                            html += '<strong>' + item.name + '</strong> <span style="color: #666;">(x' + item.quantity + ')</span>';
                            html += '</label>';
                        }
                    } else {
                        html += '<p style="color: #666; font-style: italic;">Nema artikala u narudžbini</p>';
                    }
                    html += '</div>';
                    html += '</div>';

                    html += '</div>';

                    $('#dexpress-splits-container').append(html);
                    splitIndex++;

                    // Odmah ažuriraj dostupnost nakon dodavanja
                    updateItemAvailability();
                });

                // Event listener za promene u checkbox-ovima
                $(document).on('change', '.item-checkbox', function() {
                    updateItemAvailability();
                });

                // Uklanjanje split forme
                $(document).on('click', '.dexpress-remove-split', function() {
                    if (confirm('<?php _e('Da li ste sigurni da želite da uklonite ovu pošiljku?', 'd-express-woo'); ?>')) {
                        $(this).closest('.dexpress-split-form').remove();
                        updateItemAvailability(); // Ažuriraj dostupnost nakon uklanjanja
                    }
                });

                // JS za brisanje pošiljke
                $('.dexpress-delete-shipment').on('click', function(e) {
                    e.preventDefault();

                    if (!confirm('<?php _e('Da li ste sigurni da želite da obrišete ovu pošiljku?', 'd-express-woo'); ?>')) {
                        return;
                    }

                    var btn = $(this);
                    var shipmentId = btn.data('shipment-id');
                    var orderId = btn.data('order-id');

                    btn.prop('disabled', true).text('<?php _e('Brisanje...', 'd-express-woo'); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'dexpress_delete_shipment',
                            shipment_id: shipmentId,
                            order_id: orderId,
                            nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert(response.data.message);
                                location.reload();
                            } else {
                                alert('<?php _e('Greška:', 'd-express-woo'); ?> ' + response.data.message);
                                btn.prop('disabled', false).text('<?php _e('Obriši', 'd-express-woo'); ?>');
                            }
                        },
                        error: function() {
                            alert('<?php _e('Greška pri komunikaciji sa serverom', 'd-express-woo'); ?>');
                            btn.prop('disabled', false).text('<?php _e('Obriši', 'd-express-woo'); ?>');
                        }
                    });
                });

                // POBOLJŠANO kreiranje svih pošiljki sa detaljnijim validacijama
                $('#dexpress-create-all-shipments').on('click', function() {
                    var splits = [];
                    var hasErrors = false;
                    var errorMessages = [];

                    $('.dexpress-split-form').each(function(index) {
                        var locationId = $(this).find('.split-location').val();
                        var locationName = $(this).find('.split-location option:selected').text();
                        var items = [];

                        $(this).find('.item-checkbox:checked').each(function() {
                            items.push($(this).data('item-id'));
                        });

                        if (!locationId) {
                            errorMessages.push('Pošiljka ' + (index + 1) + ': Lokacija nije izabrana');
                            hasErrors = true;
                        }

                        if (items.length === 0) {
                            errorMessages.push('Pošiljka ' + (index + 1) + ' (' + locationName + '): Nema izabranih artikala');
                            hasErrors = true;
                        }

                        if (!hasErrors) {
                            splits.push({
                                location_id: locationId,
                                items: items
                            });
                        }
                    });

                    if (hasErrors) {
                        alert('Greške u konfiguraciji:\n\n' + errorMessages.join('\n'));
                        return;
                    }

                    if (splits.length === 0) {
                        alert('<?php _e('Morate kreirati najmanje jednu pošiljku', 'd-express-woo'); ?>');
                        return;
                    }

                    // Proveri da li su svi artikli dodeljeni
                    var assignedItems = [];
                    var unassignedItems = [];

                    for (var i = 0; i < splits.length; i++) {
                        for (var j = 0; j < splits[i].items.length; j++) {
                            assignedItems.push(splits[i].items[j]);
                        }
                    }

                    for (var k = 0; k < orderItems.length; k++) {
                        if (assignedItems.indexOf(orderItems[k].id) === -1) {
                            unassignedItems.push(orderItems[k].name);
                        }
                    }

                    if (unassignedItems.length > 0) {
                        alert('❌ Sledeći artikli nisu dodeljeni nijednoj pošiljci:\n\n' + unassignedItems.join('\n') + '\n\nMolimo dodelite sve artikle pre kreiranja pošiljki.');
                        return;
                    }

                    // Proveri duplikate (dodatna sigurnost)
                    var duplicateCheck = {};
                    var duplicates = [];

                    for (var i = 0; i < assignedItems.length; i++) {
                        var itemId = assignedItems[i];
                        if (duplicateCheck[itemId]) {
                            duplicates.push(itemId);
                        } else {
                            duplicateCheck[itemId] = true;
                        }
                    }

                    if (duplicates.length > 0) {
                        alert('❌ Greška: Neki artikli su dodeljeni više pešiljki. Molimo proverite konfiguraciju.');
                        return;
                    }

                    var confirmMessage = '✅ Konfiguraciya je ispravna!\n\n';
                    confirmMessage += 'Kreiraće se ' + splits.length + ' pošiljki sa ukupno ' + assignedItems.length + ' artikl(a).\n\n';
                    confirmMessage += 'Da li želite da nastavite?';

                    if (!confirm(confirmMessage)) {
                        return;
                    }

                    var btn = $(this);
                    btn.prop('disabled', true).text('<?php _e('Kreiranje...', 'd-express-woo'); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'dexpress_create_multiple_shipments',
                            order_id: btn.data('order-id'),
                            splits: splits,
                            nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('✅ ' + response.data.message);
                                location.reload();
                            } else {
                                alert('❌ <?php _e('Greška:', 'd-express-woo'); ?> ' + response.data.message);
                                btn.prop('disabled', false).text('<?php _e('Kreiraj sve pošiljke', 'd-express-woo'); ?>');
                            }
                        },
                        error: function() {
                            alert('❌ <?php _e('Greška pri komunikaciji sa serverom', 'd-express-woo'); ?>');
                            btn.prop('disabled', false).text('<?php _e('Kreiraj sve pošiljke', 'd-express-woo'); ?>');
                        }
                    });
                });

                // Stil za hover efekat na label-ima
                $(document).on('mouseenter', '.item-label', function() {
                    if (!$(this).find('.item-checkbox').prop('disabled')) {
                        $(this).css('background-color', '#f0f8ff');
                    }
                }).on('mouseleave', '.item-label', function() {
                    $(this).css('background-color', 'transparent');
                });
            });
        </script>
<?php
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
