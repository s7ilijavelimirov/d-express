<?php

/**
 * D Express Tracking Widget
 *
 * Template za prikaz tracking widget-a
 *
 * @package D_Express_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
// Dobijanje tracking broja iz shortcode atributa ili GET parametra
$tracking_number = '';

if (!empty($atts['tracking_number'])) {
    $tracking_number = sanitize_text_field($atts['tracking_number']);
} elseif (!empty($atts['order_id'])) {
    // Dobijanje tracking broja iz order ID-a
    global $wpdb;
    $shipment = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
        intval($atts['order_id'])
    ));

    if ($shipment) {
        $tracking_number = $shipment->tracking_number;
    }
} elseif (isset($_GET['tracking_number'])) {
    $tracking_number = sanitize_text_field($_GET['tracking_number']);
}
?>

<div class="dexpress-tracking-widget">
    <h2><?php _e('Praćenje D Express pošiljke', 'd-express-woo'); ?></h2>

    <form class="dexpress-tracking-form" method="get" action="<?php echo esc_url(remove_query_arg('tracking_number')); ?>">
        <p><?php _e('Unesite broj za praćenje pošiljke:', 'd-express-woo'); ?></p>

        <div class="form-row">
            <input type="text" name="tracking_number" value="<?php echo esc_attr($tracking_number); ?>" class="input-text" placeholder="<?php echo esc_attr__('npr. DE123456789', 'd-express-woo'); ?>" />
            <button type="submit" class="button"><?php _e('Prati', 'd-express-woo'); ?></button>
        </div>
    </form>

    <?php if (!empty($tracking_number)): ?>
        <div class="dexpress-tracking-results" data-tracking="<?php echo esc_attr($tracking_number); ?>">
            <div class="dexpress-tracking-loading">
                <p><?php _e('Učitavanje informacija o pošiljci...', 'd-express-woo'); ?></p>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var trackingNumber = $('.dexpress-tracking-results').data('tracking');

                if (trackingNumber) {
                    $.ajax({
                        url: dexpressFrontend.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'dexpress_track_shipment',
                            tracking_number: trackingNumber,
                            nonce: dexpressFrontend.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                var data = response.data;
                                var html = '<div class="dexpress-tracking-info">';

                                html += '<h3>' + '<?php _e('Informacije o pošiljci', 'd-express-woo'); ?>' + '</h3>';
                                html += '<table class="dexpress-tracking-details">';
                                html += '<tr><th>' + '<?php _e('Tracking broj:', 'd-express-woo'); ?>' + '</th><td>' + data.tracking_number + '</td></tr>';
                                html += '<tr><th>' + '<?php _e('Referenca:', 'd-express-woo'); ?>' + '</th><td>' + data.reference_id + '</td></tr>';
                                html += '<tr><th>' + '<?php _e('Status:', 'd-express-woo'); ?>' + '</th><td>' + data.status + '</td></tr>';
                                html += '<tr><th>' + '<?php _e('Kreirana:', 'd-express-woo'); ?>' + '</th><td>' + data.created_at + '</td></tr>';
                                html += '</table>';

                                if (data.statuses && data.statuses.length > 0) {
                                    html += '<h3>' + '<?php _e('Istorija statusa', 'd-express-woo'); ?>' + '</h3>';
                                    html += '<table class="dexpress-tracking-statuses">';
                                    html += '<tr><th>' + '<?php _e('Datum', 'd-express-woo'); ?>' + '</th><th>' + '<?php _e('Status', 'd-express-woo'); ?>' + '</th></tr>';

                                    $.each(data.statuses, function(i, status) {
                                        html += '<tr>';
                                        html += '<td>' + status.date + '</td>';
                                        html += '<td>' + status.status + '</td>';
                                        html += '</tr>';
                                    });

                                    html += '</table>';
                                } else {
                                    html += '<p>' + '<?php _e('Još uvek nema informacija o statusu ove pošiljke.', 'd-express-woo'); ?>' + '</p>';
                                }

                                html += '<p><a href="https://www.dexpress.rs/rs/pracenje-posiljaka/' + data.tracking_number + '" class="button" target="_blank"><?php _e('Prati na D Express sajtu', 'd-express-woo'); ?></a></p>';

                                html += '</div>';

                                $('.dexpress-tracking-results').html(html);
                            } else {
                                $('.dexpress-tracking-results').html('<div class="woocommerce-message woocommerce-message--error">' + response.data.message + '</div>');
                            }
                        },
                        error: function() {
                            $('.dexpress-tracking-results').html('<div class="woocommerce-message woocommerce-message--error"><?php _e('Došlo je do greške. Molimo pokušajte ponovo.', 'd-express-woo'); ?></div>');
                        }
                    });
                }
            });
        </script>
    <?php endif; ?>
</div>