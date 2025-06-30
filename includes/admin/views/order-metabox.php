<?php

/**
 * D Express Order Metabox
 * 
 * Prikazuje opcije i podatke o D Express pošiljci na stranici narudžbine
 */

defined('ABSPATH') || exit;

// Sigurnosna provera
wp_nonce_field('dexpress_meta_box', 'dexpress_meta_box_nonce');
?>

<div class="dexpress-order-metabox">
    <?php if (!$has_dexpress): ?>
        <p><?php _e('Ova narudžbina ne koristi D Express dostavu.', 'd-express-woo'); ?></p>

    <?php elseif ($shipment): ?>
        <!-- AKO POŠILJKA POSTOJI - PRIKAŽI INFO -->
        <div class="dexpress-tracking-info">
            <div class="dexpress-tracking-status">
                <?php
                if ($shipment->status_code) {
                    echo esc_html(dexpress_get_status_name($shipment->status_code));
                } else {
                    _e('Pošiljka kreirana', 'd-express-woo');
                }
                ?>
            </div>

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

            <p>
                <strong><?php _e('Reference ID:', 'd-express-woo'); ?></strong><br>
                <?php echo esc_html($shipment->reference_id); ?>
            </p>

            <p>
                <strong><?php _e('Datum kreiranja:', 'd-express-woo'); ?></strong><br>
                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at))); ?>
            </p>

            <!-- PRIKAŽI KORIŠĆENU LOKACIJU -->
            <?php
            $used_location_id = get_post_meta($order->get_id(), '_dexpress_used_sender_location_id', true);
            if ($used_location_id) {
                $sender_locations_service = new D_Express_Sender_Locations();
                $used_location = $sender_locations_service->get_location($used_location_id);
                if ($used_location) {
                    echo '<p><strong>' . __('Poslano sa lokacije:', 'd-express-woo') . '</strong><br>';
                    echo esc_html($used_location->name . ' - ' . $used_location->address);
                    echo '</p>';
                }
            }
            ?>

            <div class="dexpress-actions">
                <button type="button" class="button dexpress-get-label" data-shipment-id="<?php echo esc_attr($shipment->id); ?>">
                    <?php _e('Preuzmi nalepnicu', 'd-express-woo'); ?>
                </button>

                <button type="button" class="button dexpress-refresh-status" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php _e('Osveži status', 'd-express-woo'); ?>
                </button>
            </div>
        </div>

    <?php else: ?>
        <!-- AKO POŠILJKA NE POSTOJI - PRIKAŽI SAMO DROPDOWN ZA LOKACIJU -->
        <?php if (!$is_paid): ?>
            <div class="notice notice-warning inline" style="margin: 0 0 10px;">
                <p><?php _e('Narudžbina nije plaćena. Preporučujemo da sačekate da kupac izvrši plaćanje pre kreiranja pošiljke.', 'd-express-woo'); ?></p>
            </div>
        <?php endif; ?>

        <p><?php _e('Još uvek nema pošiljke za ovu narudžbinu.', 'd-express-woo'); ?></p>

        <?php
        $sender_service = D_Express_Sender_Locations::get_instance();
        $locations = $sender_service->get_all_locations();

        // Dohvati trenutno sačuvanu lokaciju za ovu narudžbinu
        $order_id = $order->get_id();
        $saved_location_id = get_post_meta($order_id, '_dexpress_selected_sender_location_id', true);

        // Ako nema sačuvane, koristi glavnu
        if (empty($saved_location_id)) {
            $saved_location_id = get_option('dexpress_default_sender_location_id');
        }
        ?>

        <?php if (!empty($locations)): ?>
            <div class="dexpress-location-selection" style="margin-bottom: 15px;">
                <label for="sender-location-select-metabox"><strong><?php _e('Pošalji sa lokacije/prodavnice:', 'd-express-woo'); ?></strong></label><br>
                <select id="sender-location-select-metabox" style="width: 100%; margin-top: 5px;">
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo esc_attr($location->id); ?>"
                            <?php selected($location->id, $saved_location_id); ?>>
                            <?php echo esc_html($location->name); ?> (<?php echo esc_html($location->town_name); ?>)
                            <?php if ($location->is_default): ?> - [Glavna]<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e('Izaberite lokaciju/prodavnicu sa koje će se poslati pošiljka.', 'd-express-woo'); ?></p>
            </div>
        <?php else: ?>
            <div class="notice notice-error inline" style="margin: 0 0 10px;">
                <p><?php _e('Nema konfigurisan nijednu lokaciju pošaljioce. ', 'd-express-woo'); ?>
                    <a href="<?php echo admin_url('admin.php?page=dexpress-settings&tab=sender'); ?>"><?php _e('Dodaj lokaciju', 'd-express-woo'); ?></a>
                </p>
            </div>
        <?php endif; ?>

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

<?php if ($shipment): ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // ✅ Kreiranje pošiljke SA LOKACIJOM
            $('.dexpress-create-shipment-btn').on('click', function() {
                var btn = $(this);
                var order_id = btn.data('order-id');
                var sender_location_id = $('#sender-location-select-metabox').val(); // ← KLJUČNO!
                var response_div = btn.siblings('.dexpress-response');

                btn.prop('disabled', true).text('<?php _e('Kreiranje...', 'd-express-woo'); ?>');
                response_div.html('');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dexpress_create_shipment',
                        order_id: order_id,
                        sender_location_id: sender_location_id, // ← POŠALJEMO LOKACIJU
                        nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        btn.prop('disabled', false).text('<?php _e('Kreiraj D Express pošiljku', 'd-express-woo'); ?>');

                        if (response.success) {
                            response_div.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                            // Refresh strane nakon 2 sekunde
                            setTimeout(function() {
                                window.location.reload();
                            }, 2000);
                        } else {
                            response_div.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('<?php _e('Kreiraj D Express pošiljku', 'd-express-woo'); ?>');
                        response_div.html('<div class="notice notice-error inline"><p><?php _e('Došlo je do greške. Pokušajte ponovo.', 'd-express-woo'); ?></p></div>');
                    }
                });
            });

            // Preuzimanje nalepnice
            $('.dexpress-get-label').on('click', function() {
                var btn = $(this);
                var shipment_id = btn.data('shipment-id');

                btn.prop('disabled', true).text('<?php _e('Generisanje...', 'd-express-woo'); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dexpress_generate_label',
                        shipment_id: shipment_id,
                        nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        btn.prop('disabled', false).text('<?php _e('Preuzmi nalepnicu', 'd-express-woo'); ?>');
                        if (response && response.success && response.data && response.data.url) {
                            var newWindow = window.open('about:blank', '_blank');
                            if (newWindow) {
                                newWindow.location.href = response.data.url;
                            } else {
                                alert('<?php _e('Molimo omogućite pop-up prozore za ovu stranicu.', 'd-express-woo'); ?>');
                            }
                        } else {
                            alert('<?php _e('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'); ?>');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('<?php _e('Preuzmi nalepnicu', 'd-express-woo'); ?>');
                        alert('<?php _e('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'); ?>');
                    }
                });
            });

            // Osvežavanje statusa
            $('.dexpress-refresh-status').on('click', function() {
                var btn = $(this);
                var order_id = btn.data('order-id');

                btn.prop('disabled', true).text('<?php _e('Osvežavanje...', 'd-express-woo'); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dexpress_refresh_shipment_status',
                        order_id: order_id,
                        nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        btn.prop('disabled', false).text('<?php _e('Osveži status', 'd-express-woo'); ?>');
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message);
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('<?php _e('Osveži status', 'd-express-woo'); ?>');
                        alert('<?php _e('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'); ?>');
                    }
                });
            });

            // ✅ Čuvanje izabrane lokacije kada se promeni dropdown
            $('#sender-location-select-metabox').on('change', function() {
                var location_id = $(this).val();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dexpress_save_order_meta',
                        order_id: <?php echo $order->get_id(); ?>,
                        field_name: 'dexpress_selected_sender_location_id',
                        field_value: location_id,
                        nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                    }
                });
            });
        });
    </script>
<?php endif; ?>