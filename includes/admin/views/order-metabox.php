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
                <strong><?php _e('ID pošiljke:', 'd-express-woo'); ?></strong><br>
                <?php echo esc_html($shipment->shipment_id); ?>
            </p>

            <p>
                <strong><?php _e('Reference ID:', 'd-express-woo'); ?></strong><br>
                <?php echo esc_html($shipment->reference_id); ?>
            </p>

            <p>
                <strong><?php _e('Datum kreiranja:', 'd-express-woo'); ?></strong><br>
                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($shipment->created_at))); ?>
            </p>

            <div class="dexpress-actions">
                <button type="button" class="button dexpress-get-label" data-shipment-id="<?php echo esc_attr($shipment->id); ?>">
                    <?php _e('Preuzmi nalepnicu', 'd-express-woo'); ?>
                </button>
            </div>

            <?php if ($shipment->shipment_data && !empty($shipment->shipment_data)): ?>
                <div class="dexpress-shipment-data" style="margin-top: 10px; display: none;">
                    <h4><?php _e('Podaci o pošiljci:', 'd-express-woo'); ?></h4>
                    <pre style="white-space: pre-wrap; font-size: 11px; background: #f5f5f5; padding: 5px;">
                        <?php echo esc_html($shipment->shipment_data); ?>
                    </pre>
                </div>
                <p class="description">
                    <a href="#" class="dexpress-toggle-data"><?php _e('Prikaži tehničke podatke', 'd-express-woo'); ?></a>
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if (!$is_paid): ?>
            <div class="notice notice-warning inline" style="margin: 0 0 10px;">
                <p><?php _e('Narudžbina nije plaćena. Preporučujemo da sačekate da kupac izvrši plaćanje pre kreiranja pošiljke.', 'd-express-woo'); ?></p>
            </div>
        <?php endif; ?>

        <p><?php _e('Još uvek nema pošiljke za ovu narudžbinu1.', 'd-express-woo'); ?></p>

        <div class="dexpress-shipment-options">
            <h4><?php _e('Opcije pošiljke:', 'd-express-woo'); ?></h4>

            <p>
                <label for="dexpress_shipment_type"><?php _e('Tip pošiljke:', 'd-express-woo'); ?></label><br>
                <select id="dexpress_shipment_type" name="dexpress_shipment_type" class="widefat">
                    <?php foreach (dexpress_get_shipment_types() as $type_id => $type_name): ?>
                        <option value="<?php echo esc_attr($type_id); ?>" <?php selected(get_post_meta($order->get_id(), '_dexpress_shipment_type', true), $type_id); ?>>
                            <?php echo esc_html($type_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="dexpress_payment_by"><?php _e('Ko plaća dostavu:', 'd-express-woo'); ?></label><br>
                <select id="dexpress_payment_by" name="dexpress_payment_by" class="widefat">
                    <?php foreach (dexpress_get_payment_by_options() as $option_id => $option_name): ?>
                        <option value="<?php echo esc_attr($option_id); ?>" <?php selected(get_post_meta($order->get_id(), '_dexpress_payment_by', true), $option_id); ?>>
                            <?php echo esc_html($option_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="dexpress_payment_type"><?php _e('Način plaćanja dostave:', 'd-express-woo'); ?></label><br>
                <select id="dexpress_payment_type" name="dexpress_payment_type" class="widefat">
                    <?php foreach (dexpress_get_payment_type_options() as $type_id => $type_name): ?>
                        <option value="<?php echo esc_attr($type_id); ?>" <?php selected(get_post_meta($order->get_id(), '_dexpress_payment_type', true), $type_id); ?>>
                            <?php echo esc_html($type_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="dexpress_return_doc"><?php _e('Povraćaj dokumenata:', 'd-express-woo'); ?></label><br>
                <select id="dexpress_return_doc" name="dexpress_return_doc" class="widefat">
                    <?php foreach (dexpress_get_return_doc_options() as $option_id => $option_name): ?>
                        <option value="<?php echo esc_attr($option_id); ?>" <?php selected(get_post_meta($order->get_id(), '_dexpress_return_doc', true), $option_id); ?>>
                            <?php echo esc_html($option_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="dexpress_content"><?php _e('Sadržaj pošiljke:', 'd-express-woo'); ?></label><br>
                <input type="text" id="dexpress_content" name="dexpress_content" class="widefat"
                    value="<?php echo esc_attr(get_post_meta($order->get_id(), '_dexpress_content', true) ?: dexpress_generate_shipment_content($order)); ?>">
            </p>
        </div>

        <?php
        $sender_service = D_Express_Sender_Locations::get_instance();
        $locations = $sender_service->get_all_locations();
        ?>

        <?php if (!empty($locations)): ?>
            <div class="dexpress-location-selection" style="margin-bottom: 15px;">
                <label for="sender-location-select-metabox"><strong>Pošalji sa lokacije:</strong></label><br>
                <select id="sender-location-select-metabox" style="width: 100%; margin-top: 5px;">
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo esc_attr($location->id); ?>" <?php selected($location->is_default, 1); ?>>
                            <?php echo esc_html($location->name); ?> (<?php echo esc_html($location->town_name); ?>)
                            <?php if ($location->is_default): ?> - [Glavna]<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="dexpress-create-shipment" style="margin-top: 15px;">
            <button type="button" class="button button-primary dexpress-create-shipment-btn" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                <?php _e('Kreiraj D Express pošiljku', 'd-express-woo'); ?>
            </button>
            <div class="dexpress-response" style="margin-top: 10px;"></div>
        </div>
        <script>
            jQuery(document).ready(function($) {
                $('.dexpress-create-shipment-btn').on('click', function() {
                    var btn = $(this);
                    var order_id = btn.data('order-id');
                    var response_div = btn.siblings('.dexpress-response');

                    btn.prop('disabled', true).text('<?php _e('Kreiranje...', 'd-express-woo'); ?>');
                    response_div.html('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'dexpress_create_shipment',
                            order_id: order_id,
                            sender_location_id: $('#sender-location-select-metabox').val(),
                            nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
                        },
                        success: function(response) {
                            btn.prop('disabled', false).text('<?php _e('Kreiraj D Express pošiljku', 'd-express-woo'); ?>');

                            if (response.success) {
                                response_div.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');

                                // Ažuriranje stranice nakon uspešnog kreiranja
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                response_div.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('<?php _e('Kreiraj D Express pošiljku', 'd-express-woo'); ?>');
                            response_div.html('<div class="notice notice-error inline"><p><?php _e('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'); ?></p></div>');
                        }
                    });
                });
            });
        </script>
    <?php endif; ?>
</div>

<?php if ($shipment): ?>
    <script>
        jQuery(document).ready(function($) {
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
                    timeout: 30000, // Povećan timeout
                    success: function(response) {
                        btn.prop('disabled', false).text('<?php _e('Preuzmi nalepnicu', 'd-express-woo'); ?>');

                        if (response && response.success && response.data && response.data.url) {
                            // Otvaramo prozor pre nego što browser može da blokira pop-up
                            var newWindow = window.open('about:blank', '_blank');

                            // Potvrdimo da je prozor otvoren
                            if (newWindow) {
                                // Postavljamo lokaciju prozora
                                newWindow.location.href = response.data.url;
                            } else {
                                alert('<?php _e('Molimo omogućite pop-up prozore za ovu stranicu.', 'd-express-woo'); ?>');
                            }
                        } else {
                            alert(response && response.data && response.data.message ?
                                response.data.message :
                                '<?php _e('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'); ?>');
                        }
                    },
                    error: function(xhr, status, error) {
                        btn.prop('disabled', false).text('<?php _e('Preuzmi nalepnicu', 'd-express-woo'); ?>');

                        console.error('Error details:', status, error, xhr.responseText);
                        alert('<?php _e('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'); ?>');
                    }
                });
            });

            $('.dexpress-toggle-data').on('click', function(e) {
                e.preventDefault();
                $('.dexpress-shipment-data').toggle();

                if ($('.dexpress-shipment-data').is(':visible')) {
                    $(this).text('<?php _e('Sakrij tehničke podatke', 'd-express-woo'); ?>');
                } else {
                    $(this).text('<?php _e('Prikaži tehničke podatke', 'd-express-woo'); ?>');
                }
            });
        });
    </script>
<?php endif; ?>