<?php

/**
 * D Express Order Metabox - KOMPLETNA VERZIJA
 * includes/admin/views/order-metabox.php
 */

defined('ABSPATH') || exit;

// Sigurnosna provera
wp_nonce_field('dexpress_meta_box', 'dexpress_meta_box_nonce');
?>

<div class="dexpress-order-metabox">
    <?php if (!$has_dexpress): ?>
        <p><?php _e('Ova narudžbina ne koristi D Express dostavu.', 'd-express-woo'); ?></p>

    <?php elseif ($shipment): ?>
        <!-- POŠILJKA POSTOJI - PRIKAŽI TRACKING INFO -->
        <div class="dexpress-tracking-info">
            <!-- Status -->
            <div class="dexpress-tracking-status">
                <?php
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
                ?>
            </div>

            <!-- Tracking broj -->
            <p>
                <strong><?php _e('Tracking broj:', 'd-express-woo'); ?></strong><br>
                <?php if ($shipment->is_test): ?>
                    <span class="dexpress-tracking-number"><?php echo esc_html($shipment->tracking_number); ?></span>
                    <span class="description"><?php _e('(Test mode)', 'd-express-woo'); ?></span>
                <?php else: ?>
                    <a href="https://www.dexpress.rs/rs/pracenje-posiljaka/<?php echo esc_attr($shipment->tracking_number); ?>"
                        target="_blank" class="dexpress-tracking-number">
                        <?php echo esc_html($shipment->tracking_number); ?>
                    </a>
                <?php endif; ?>
            </p>

            <!-- Reference ID -->
            <p>
                <strong><?php _e('Reference ID:', 'd-express-woo'); ?></strong><br>
                <?php echo esc_html($shipment->reference_id); ?>
            </p>

            <!-- Datum kreiranja -->
            <p>
                <strong><?php _e('Datum kreiranja:', 'd-express-woo'); ?></strong><br>
                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at))); ?>
            </p>

            <!-- Korišćena lokacija -->
            <?php
            $used_location_id = get_post_meta($order->get_id(), '_dexpress_used_sender_location_id', true);
            if ($used_location_id) {
                $sender_locations_service = new D_Express_Sender_Locations();
                $used_location = $sender_locations_service->get_location($used_location_id);
                if ($used_location) {
                    echo '<p><strong>' . __('Poslano sa lokacije:', 'd-express-woo') . '</strong><br>';
                    echo esc_html($used_location->name . ' - ' . $used_location->address . ', ' . $used_location->town_name);
                    echo '</p>';
                }
            }
            ?>

            <!-- Akcije -->
            <div class="dexpress-actions" style="margin-top: 15px;">
                <button type="button" class="button dexpress-get-label" data-shipment-id="<?php echo esc_attr($shipment->id); ?>">
                    <?php _e('Preuzmi nalepnicu', 'd-express-woo'); ?>
                </button>

                <button type="button" class="button dexpress-refresh-status" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php _e('Osveži status', 'd-express-woo'); ?>
                </button>
            </div>
        </div>

    <?php else: ?>
        <!-- NEMA POŠILJKE - KREIRANJE -->

        <!-- Upozorenje ako nije plaćeno -->
        <?php if (!$is_paid): ?>
            <div class="notice notice-warning inline" style="margin: 0 0 10px;">
                <p><?php _e('Narudžbina nije plaćena. Preporučujemo da sačekate da kupac izvrši plaćanje pre kreiranja pošiljke.', 'd-express-woo'); ?></p>
            </div>
        <?php endif; ?>

        <p><?php _e('Još uvek nema pošiljke za ovu narudžbinu.', 'd-express-woo'); ?></p>

        <!-- Izbor lokacije pošaljioce -->
        <?php if (!empty($locations)): ?>
            <div class="dexpress-location-selection" style="margin-bottom: 15px;">
                <label for="sender-location-select-metabox">
                    <strong><?php _e('Pošalji sa lokacije/prodavnice:', 'd-express-woo'); ?></strong>
                </label><br>
                <select id="sender-location-select-metabox" style="width: 100%; margin-top: 5px;">
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo esc_attr($location->id); ?>"
                            <?php selected($location->id, $selected_location_id); ?>>
                            <?php echo esc_html($location->name); ?> (<?php echo esc_html($location->town_name); ?>)
                            <?php if ($location->is_default): ?> - [Glavna]<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php _e('Izaberite lokaciju/prodavnicu sa koje će se poslati pošiljka.', 'd-express-woo'); ?>
                </p>
            </div>
        <?php else: ?>
            <div class="notice notice-error inline" style="margin: 0 0 10px;">
                <p>
                    <?php _e('Nema konfigurisan nijednu lokaciju pošaljioce. ', 'd-express-woo'); ?>
                    <a href="<?php echo admin_url('admin.php?page=dexpress-settings&tab=sender'); ?>">
                        <?php _e('Dodaj lokaciju', 'd-express-woo'); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>

        <!-- Dugme za kreiranje -->
        <div class="dexpress-create-shipment" style="margin-top: 15px;">
            <button type="button" class="button button-primary dexpress-create-shipment-btn"
                data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                <?php if (empty($locations)) echo 'disabled'; ?>>
                <?php _e('Kreiraj D Express pošiljku', 'd-express-woo'); ?>
            </button>

            <div class="dexpress-response" style="margin-top: 10px;"></div>
        </div>

    <?php endif; ?>
</div>

<!-- SAMO JEDAN JAVASCRIPT BLOK -->
<script type="text/javascript">
    jQuery(document).ready(function($) {

        // Kreiranje pošiljke
        $('.dexpress-create-shipment-btn').on('click', function() {
            var btn = $(this);
            var order_id = btn.data('order-id');
            var sender_location_id = $('#sender-location-select-metabox').val();
            var response_div = $('.dexpress-response');

            if (!sender_location_id) {
                alert('Molimo izaberite lokaciju pošaljioce.');
                return;
            }

            btn.prop('disabled', true).text('Kreiranje...');
            response_div.html('');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dexpress_create_shipment',
                    order_id: order_id,
                    sender_location_id: sender_location_id,
                    nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                },
                success: function(response) {
                    btn.prop('disabled', false).text('Kreiraj D Express pošiljku');

                    if (response.success) {
                        response_div.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        response_div.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Kreiraj D Express pošiljku');
                    response_div.html('<div class="notice notice-error inline"><p>Greška pri komunikaciji sa serverom.</p></div>');
                }
            });
        });

        // Čuvanje izabrane lokacije
        $('#sender-location-select-metabox').on('change', function() {
            var order_id = $('.dexpress-create-shipment-btn').data('order-id');
            var location_id = $(this).val();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dexpress_save_order_meta',
                    order_id: order_id,
                    field_name: 'dexpress_selected_sender_location_id',
                    field_value: location_id,
                    nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                }
            });
        });

        // Preuzimanje nalepnice
        // Preuzimanje nalepnice - ISPRAVKA!
        $('.dexpress-get-label').on('click', function() {
            var shipment_id = $(this).data('shipment-id');

            // Pozovi AJAX da generiše URL, zatim otvori u novom prozoru
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dexpress_get_label',
                    shipment_id: shipment_id,
                    nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success && response.data.url) {
                        window.open(response.data.url, '_blank');
                    } else {
                        alert('Greška: ' + (response.data.message || 'Nepoznata greška'));
                    }
                },
                error: function() {
                    alert('Došlo je do greške. Pokušajte ponovo.');
                }
            });
        });

        // Osvežavanje statusa
        $('.dexpress-refresh-status').on('click', function() {
            var btn = $(this);
            var order_id = btn.data('order-id');

            btn.prop('disabled', true).text('Osvežavanje...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dexpress_refresh_shipment_status',
                    order_id: order_id,
                    nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                },
                success: function(response) {
                    btn.prop('disabled', false).text('Osveži status');
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Greška: ' + response.data.message);
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Osveži status');
                    alert('Došlo je do greške. Pokušajte ponovo.');
                }
            });
        });
    });
</script>