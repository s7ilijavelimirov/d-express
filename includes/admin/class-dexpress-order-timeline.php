<?php

/**
 * Dodaje napredni timeline u order metabox
 */
function enhance_order_metabox_timeline($post_or_order)
{
    // Provera da li je prosleđen WP_Post ili WC_Order
    if (is_a($post_or_order, 'WP_Post')) {
        $order = wc_get_order($post_or_order->ID);
    } else {
        $order = $post_or_order;
    }

    if (!$order) {
        return;
    }

    // Dobavljanje podataka o pošiljci
    global $wpdb;
    $shipment = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
        $order->get_id()
    ));

    if (!$shipment) {
        return;
    }

    // Dobavljanje statusa pošiljke
    $statuses = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dexpress_statuses 
        WHERE (shipment_code = %s OR reference_id = %s) 
        ORDER BY status_date DESC",
        $shipment->shipment_id,
        $shipment->reference_id
    ));

    // Ako nema statusa, pokušaj dobaviti iz shipment tabele
    if (empty($statuses) && !empty($shipment->status_code)) {
        $statuses = [
            (object)[
                'status_id' => $shipment->status_code,
                'status_date' => $shipment->updated_at
            ]
        ];
    }

    // Ako nemamo statuse, prikaži samo osnovne informacije o pošiljci
    if (empty($statuses)) {
        return;
    }

    // Dodavanje CSS-a za timeline
    wp_add_inline_style('dexpress-admin-css', '
        .dexpress-timeline {
            margin: 20px 0;
            position: relative;
            max-width: 100%;
            padding-left: 30px;
        }
        
        .dexpress-timeline::before {
            content: "";
            position: absolute;
            top: 0;
            bottom: 0;
            left: 10px;
            width: 2px;
            background: #e5e5e5;
        }
        
        .dexpress-timeline-item {
            position: relative;
            margin-bottom: 15px;
        }
        
        .dexpress-timeline-dot {
            position: absolute;
            left: -30px;
            top: 2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 2px solid #fff;
            box-shadow: 0 0 0 2px #0073aa;
            background: white;
            z-index: 1;
        }
        
        .dexpress-timeline-dot.current {
            background: #0073aa;
        }
        
        .dexpress-timeline-dot.completed {
            background: #46b450;
            box-shadow: 0 0 0 2px #46b450;
        }
        
        .dexpress-timeline-dot.failed {
            background: #dc3232;
            box-shadow: 0 0 0 2px #dc3232;
        }
        
        .dexpress-timeline-content {
            background: #f8f8f8;
            padding: 10px 15px;
            border-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        
        .dexpress-timeline-date {
            font-size: 12px;
            color: #777;
            margin-bottom: 4px;
        }
        
        .dexpress-timeline-status {
            font-weight: 600;
        }
        
        .dexpress-timeline-item.current .dexpress-timeline-content {
            background: #f1f8fe;
            border-left: 3px solid #0073aa;
        }
        
        .dexpress-timeline-item.failed .dexpress-timeline-content {
            background: #fef7f7;
            border-left: 3px solid #dc3232;
        }
        
        .dexpress-timeline-item.completed .dexpress-timeline-content {
            background: #f7fef7;
            border-left: 3px solid #46b450;
        }
    ');

    // Ovaj niz mapira kodove statusa sa nazivima klasa za stilizaciju
    $status_classes = [
        '130' => 'completed', // Isporučeno
        '131' => 'failed',    // Neisporučeno
        // Možeš dodati ostale kodove statusa prema potrebi
    ];

    // Definisanje koraka dostave i njihovih kodova statusa
    $delivery_steps = [
        'created' => ['naziv' => 'Narudžbina kreirana', 'kod' => ''],
        'preparing' => ['naziv' => 'Priprema za slanje', 'kod' => ''],
        'pickup' => ['naziv' => 'Preuzeto od kurira', 'kod' => ''],
        'in_transit' => ['naziv' => 'U tranzitu', 'kod' => ''],
        'out_for_delivery' => ['naziv' => 'Na isporuci', 'kod' => ''],
        'delivered' => ['naziv' => 'Isporučeno primaocu', 'kod' => '130']
    ];

    // Ako je pošiljka isporučena ili neisporučena, sakrij korake nakon poslednjeg statusa
    $current_step = 'created';

    foreach ($statuses as $status) {
        if ($status->status_id == '130') { // Isporučeno
            $current_step = 'delivered';
            break;
        } elseif ($status->status_id == '131') { // Neisporučeno
            $current_step = 'out_for_delivery'; // Neisporuka se dešava na koraku isporuke
            break;
        }
        // Dodaj logiku za ostale statuse
    }

    // Prikazivanje timeline-a
    echo '<div class="dexpress-order-tracking">';
    echo '<h3>' . __('Praćenje pošiljke', 'd-express-woo') . '</h3>';

    // Prikazivanje tracking broja
    echo '<div class="dexpress-tracking-number">';
    echo '<strong>' . __('Tracking broj:', 'd-express-woo') . '</strong> ';
    if ($shipment->is_test) {
        echo esc_html($shipment->tracking_number) . ' <span class="description">(' . __('Test', 'd-express-woo') . ')</span>';
    } else {
        echo '<a href="https://www.dexpress.rs/rs/pracenje-posiljaka/' .
            esc_attr($shipment->tracking_number) . '" target="_blank" class="dexpress-tracking-link">' .
            esc_html($shipment->tracking_number) . '</a>';
    }
    echo '</div>';

    echo '<div class="dexpress-timeline">';

    // Sortiranje statusa po datumu (najnoviji prvo)
    usort($statuses, function ($a, $b) {
        return strtotime($b->status_date) - strtotime($a->status_date);
    });

    foreach ($statuses as $status) {
        $status_name = dexpress_get_status_name($status->status_id);
        $status_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($status->status_date));

        // Određivanje klase za stilizaciju (current, completed, failed)
        $item_class = '';
        $dot_class = '';

        if (isset($status_classes[$status->status_id])) {
            $item_class = $status_classes[$status->status_id];
            $dot_class = $status_classes[$status->status_id];
        } else {
            $item_class = 'current';
            $dot_class = 'current';
        }

        echo '<div class="dexpress-timeline-item ' . esc_attr($item_class) . '">';
        echo '<div class="dexpress-timeline-dot ' . esc_attr($dot_class) . '"></div>';
        echo '<div class="dexpress-timeline-content">';
        echo '<div class="dexpress-timeline-date">' . esc_html($status_date) . '</div>';
        echo '<div class="dexpress-timeline-status">' . esc_html($status_name) . '</div>';
        echo '</div></div>';
    }

    echo '</div>'; // End .dexpress-timeline

    // Dodaj dugme za preuzimanje nalepnice
    $nonce = wp_create_nonce('dexpress-download-label');
    $label_url = admin_url('admin-ajax.php?action=dexpress_download_label&shipment_id=' . $shipment->id . '&nonce=' . $nonce);

    echo '<div class="dexpress-actions" style="margin-top: 15px;">';
    echo '<a href="' . esc_url($label_url) . '" class="button button-primary" target="_blank">';
    echo '<span class="dashicons dashicons-printer" style="margin: 4px 5px 0 0;"></span> ' . __('Preuzmi nalepnicu', 'd-express-woo') . '</a>';

    // Dugme za osvežavanje statusa
    echo ' <a href="#" class="button dexpress-refresh-status" data-id="' . esc_attr($shipment->id) . '" data-nonce="' . wp_create_nonce('dexpress-refresh-status') . '">';
    echo '<span class="dashicons dashicons-update" style="margin: 4px 5px 0 0;"></span> ' . __('Osveži status', 'd-express-woo') . '</a>';
    echo '</div>';

    // JavaScript za osvežavanje statusa
?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.dexpress-refresh-status').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                var originalText = button.html();

                button.html('<span class="dashicons dashicons-update" style="margin: 4px 5px 0 0; animation: dexpress-spin 1s linear infinite;"></span> <?php _e('Osvežavanje...', 'd-express-woo'); ?>');
                button.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dexpress_refresh_shipment_status',
                        shipment_id: button.data('id'),
                        nonce: button.data('nonce')
                    },
                    success: function(response) {
                        if (response.success) {
                            // Osvežavanje stranice da bi se prikazali ažurirani statusi
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php _e('Došlo je do greške prilikom osvežavanja statusa.', 'd-express-woo'); ?>');
                            button.html(originalText);
                            button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('<?php _e('Došlo je do greške prilikom osvežavanja statusa.', 'd-express-woo'); ?>');
                        button.html(originalText);
                        button.prop('disabled', false);
                    }
                });
            });
        });

        // Dodavanje animacije za ikonu osvežavanja
        jQuery('head').append('<style>@keyframes dexpress-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>');
    </script>
<?php

    echo '</div>'; // End .dexpress-order-tracking
}

/**
 * AJAX handler za osvežavanje statusa pošiljke
 */
function dexpress_refresh_shipment_status_ajax()
{
    // Provera nonce-a
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dexpress-refresh-status')) {
        wp_send_json_error(['message' => __('Sigurnosna provera nije uspela.', 'd-express-woo')]);
    }

    // Provera dozvola
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Nemate dozvolu za ovu akciju.', 'd-express-woo')]);
    }

    // Provera ID-a pošiljke
    if (!isset($_POST['shipment_id']) || empty($_POST['shipment_id'])) {
        wp_send_json_error(['message' => __('ID pošiljke je obavezan.', 'd-express-woo')]);
    }

    $shipment_id = intval($_POST['shipment_id']);

    // Kreiranje instance D_Express_Shipment_Service
    $shipment_service = new D_Express_Shipment_Service();

    // Sinhronizacija statusa
    $result = $shipment_service->sync_shipment_status($shipment_id);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    } else {
        wp_send_json_success(['message' => __('Status pošiljke je uspešno ažuriran.', 'd-express-woo')]);
    }
}
add_action('wp_ajax_dexpress_refresh_shipment_status', 'dexpress_refresh_shipment_status_ajax');

/**
 * Registracija funkcije za prikaz timeline-a u order metabox-u
 */
function register_dexpress_timeline_in_metabox()
{
    // Nadjenij render_order_metabox metod u D_Express_Admin klasi
    add_action('dexpress_after_order_metabox', 'enhance_order_metabox_timeline');
}
add_action('init', 'register_dexpress_timeline_in_metabox');
