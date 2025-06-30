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

    /**
     * Renderovanje sadržaja metabox-a
     */
    private function render_metabox_content($order, $has_dexpress, $is_dispenser, $shipment, $locations, $selected_location_id)
    {
?>
        <div class="dexpress-order-metabox">
            <?php if (!$has_dexpress): ?>
                <p><?php _e('Ova narudžbina ne koristi D Express dostavu.', 'd-express-woo'); ?></p>

            <?php elseif ($shipment): ?>
                <!-- POŠILJKA POSTOJI - PRIKAŽI TRACKING INFO -->
                <div class="dexpress-tracking-info">
                    <!-- Status -->
                    <div class="dexpress-tracking-status">
                        <?php $this->render_shipment_status($shipment); ?>
                    </div>

                    <!-- Tracking broj -->
                    <p>
                        <strong><?php _e('Tracking broj:', 'd-express-woo'); ?></strong><br>
                        <?php $this->render_tracking_number($shipment); ?>
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

                    <!-- Dugmad za akcije -->
                    <div class="dexpress-actions" style="margin-top: 15px;">
                        <button type="button" class="button dexpress-get-label"
                            data-shipment-id="<?php echo esc_attr($shipment->id); ?>">
                            <?php _e('Preuzmi nalepnicu', 'd-express-woo'); ?>
                        </button>
                    </div>
                </div>

            <?php else: ?>
                <!-- KREIRANJE NOVE POŠILJKE -->
                <div class="dexpress-create-shipment-section">
                    <?php if (!empty($locations)): ?>
                        <!-- Izbor lokacije -->
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
                </div>
            <?php endif; ?>
        </div>

        <!-- JavaScript za metabox -->
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Kreiranje pošiljke
                $('.dexpress-create-shipment-btn').on('click', function() {
                    var btn = $(this);
                    var order_id = btn.data('order-id');
                    var response_div = btn.siblings('.dexpress-response');
                    var sender_location_id = $('#dexpress-sender-location').val();

                    if (!sender_location_id) {
                        alert('<?php echo esc_js(__('Molimo izaberite lokaciju pošaljioce.', 'd-express-woo')); ?>');
                        return;
                    }

                    btn.prop('disabled', true).text('<?php echo esc_js(__('Kreiranje...', 'd-express-woo')); ?>');
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
                            if (response.success) {
                                response_div.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                response_div.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                                btn.prop('disabled', false).text('<?php echo esc_js(__('Kreiraj D Express pošiljku', 'd-express-woo')); ?>');
                            }
                        },
                        error: function() {
                            response_div.html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Došlo je do greške.', 'd-express-woo')); ?></p></div>');
                            btn.prop('disabled', false).text('<?php echo esc_js(__('Kreiraj D Express pošiljku', 'd-express-woo')); ?>');
                        }
                    });
                });

                // Čuvanje izabrane lokacije
                $('#dexpress-sender-location').on('change', function() {
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

                // Preuzimanje nalepnice
                $('.dexpress-get-label').on('click', function() {
                    var shipment_id = $(this).data('shipment-id');

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
