<?php

/**
 * D Express Column Renderer
 * 
 * Odgovoran za renderovanje sadržaja custom kolona u order listama
 */

defined('ABSPATH') || exit;

class D_Express_Column_Renderer
{
    /**
     * Konstruktor
     */
    public function __construct()
    {
        // Nema hook-ova, samo rendering metode
    }

    /**
     * Renderuje tracking kolonu
     */
    public function render_tracking_column($order_id)
    {
        global $wpdb;

        // Dobijamo podatke o pošiljci iz baze
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));

        if ($shipment) {
            // Prikazujemo tracking broj i status ako postoji
            echo '<a href="https://www.dexpress.rs/rs/pracenje-posiljaka/' . esc_attr($shipment->tracking_number) . '" 
            target="_blank" class="dexpress-tracking-number">' .
                esc_html($shipment->tracking_number) . '</a>';

            if (!empty($shipment->status_code)) {
                $status_class = dexpress_get_status_css_class($shipment->status_code);
                $status_name = dexpress_get_status_name($shipment->status_code);

                echo '<br><span class="dexpress-status-badge ' . $status_class . '">' .
                    esc_html($status_name) . '</span>';
            }
        } else {
            echo '<span class="dexpress-no-shipment">-</span>';
        }
    }

    /**
     * Renderuje label printed kolonu
     */
    public function render_label_printed_column($order_id)
    {
        global $wpdb;

        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));

        if (!$shipment) {
            $this->render_no_shipment_icon();
            return;
        }

        $is_printed = get_post_meta($order_id, '_dexpress_label_printed', true);

        if ($is_printed === 'yes') {
            $this->render_label_printed_icon();
        } else {
            $this->render_label_not_printed_icon();
        }
    }

    /**
     * Renderuje shipment status kolonu
     */
    public function render_shipment_status_column($order_id)
    {
        global $wpdb;
        
        $shipment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dexpress_shipments WHERE order_id = %d",
            $order_id
        ));

        if (!$shipment) {
            echo '<span class="dexpress-no-shipment">-</span>';
            return;
        }

        // Koristi centralizovane funkcije za dobijanje statusa
        $status_code = $shipment->status_code;
        $status_name = dexpress_get_status_name($status_code);
        $css_class = dexpress_get_status_css_class($status_code);
        $icon = dexpress_get_status_icon($status_code);

        // Za test režim, dodaj [TEST] oznaku
        if ($shipment->is_test) {
            $status_name .= ' [TEST]';
        }

        // Prikazivanje statusa sa odgovarajućom ikonom i stilom
        echo '<div class="' . esc_attr($css_class) . '" style="display:flex; align-items:center; justify-content:center; flex-direction:column;">';
        echo '<span class="dashicons ' . esc_attr($icon) . '" style="margin-bottom:3px; font-size:20px;"></span>';
        echo '<span style="font-size:12px; text-align:center; line-height:1.2;">' . esc_html($status_name) . '</span>';

        // Dodaj indikator testa ako je test pošiljka
        if ($shipment->is_test) {
            echo '<span class="dexpress-test-badge" style="background:#f8f9fa; color:#6c757d; font-size:10px; padding:1px 4px; border-radius:3px; margin-top:2px;">TEST</span>';
        }

        echo '</div>';
    }

    /**
     * Render helper: Nema pošiljke ikonica
     */
    private function render_no_shipment_icon()
    {
        echo '<div class="dexpress-no-shipment" style="text-align: center; color: #999;">';
        echo '<span class="dashicons dashicons-minus" style="font-size: 24px; width: 24px; height: 24px; margin: 0 auto;"></span>';
        echo '<div style="font-size: 12px; margin-top: 4px;">' . esc_html__('Nema pošiljke', 'd-express-woo') . '</div>';
        echo '</div>';
    }

    /**
     * Render helper: Label printed ikonica
     */
    private function render_label_printed_icon()
    {
        echo '<div class="dexpress-label-printed" style="text-align: center;">';
        echo '<span class="dashicons dashicons-yes-alt" style="color: #5cb85c; font-size: 28px; width: 28px; height: 28px; margin: 0 auto;"></span>';
        echo '<div style="font-size: 12px; margin-top: 4px;">' . esc_html__('Odštampano', 'd-express-woo') . '</div>';
        echo '</div>';
    }

    /**
     * Render helper: Label not printed ikonica
     */
    private function render_label_not_printed_icon()
    {
        echo '<div class="dexpress-label-not-printed" style="text-align: center;">';
        echo '<span class="dashicons dashicons-no-alt" style="color: red; font-size: 28px; width: 28px; height: 28px; margin: 0 auto;"></span>';
        echo '<div style="font-size: 12px; margin-top: 4px;">' . esc_html__('Nije štampano', 'd-express-woo') . '</div>';
        echo '</div>';
    }

    /**
     * Helper: Format last update time
     */
    private function format_last_update_time($option_name)
    {
        $timestamp = get_option($option_name, 0);
        if (!$timestamp) {
            return 'Nikad';
        }

        $time_diff = time() - $timestamp;

        if ($time_diff < HOUR_IN_SECONDS) {
            return 'Pre ' . round($time_diff / 60) . ' minuta';
        } elseif ($time_diff < DAY_IN_SECONDS) {
            return 'Pre ' . round($time_diff / HOUR_IN_SECONDS) . ' sati';
        } else {
            return date('d.m.Y H:i', $timestamp);
        }
    }
}