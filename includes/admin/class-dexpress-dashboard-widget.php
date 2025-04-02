<?php

/**
 * D Express Dashboard Widget
 */

defined('ABSPATH') || exit;

class D_Express_Dashboard_Widget
{

    public function init()
    {
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
    }

    public function add_dashboard_widget()
    {
        wp_add_dashboard_widget(
            'dexpress_dashboard_widget',
            __('D Express Status Pošiljki', 'd-express-woo'),
            array($this, 'dashboard_widget_callback')
        );
    }

    public function dashboard_widget_callback()
    {
        global $wpdb;

        // Dobijanje svih statusa grupisanih
        $all_statuses = dexpress_get_all_status_codes();

        // Formiranje upita za statuse isporuke
        $delivered_status_ids = array();
        $failed_status_ids = array();
        foreach ($all_statuses as $id => $status_info) {
            if ($status_info['group'] === 'delivered') {
                $delivered_status_ids[] = "'" . esc_sql($id) . "'";
            } elseif ($status_info['group'] === 'failed') {
                $failed_status_ids[] = "'" . esc_sql($id) . "'";
            }
        }

        // Sastavljanje upita
        $delivered_status_in = !empty($delivered_status_ids) ? implode(',', $delivered_status_ids) : "'130'"; // Fallback
        $failed_status_in = !empty($failed_status_ids) ? implode(',', $failed_status_ids) : "'131'"; // Fallback

        // Dobijanje statistike pošiljki
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_shipments");
        $delivered = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_shipments WHERE status_code IN ({$delivered_status_in})");
        $failed = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_shipments WHERE status_code IN ({$failed_status_in})");
        $in_transit = $total - $delivered - $failed;

        // Prikaz statistike
        echo '<div class="dexpress-dashboard-stats">';
        echo '<div class="dexpress-stat"><span class="dexpress-stat-count">' . $total . '</span> ' . __('Ukupno pošiljki', 'd-express-woo') . '</div>';
        echo '<div class="dexpress-stat"><span class="dexpress-stat-count">' . $delivered . '</span> ' . __('Isporučeno', 'd-express-woo') . '</div>';
        echo '<div class="dexpress-stat"><span class="dexpress-stat-count">' . $in_transit . '</span> ' . __('U tranzitu', 'd-express-woo') . '</div>';
        echo '<div class="dexpress-stat"><span class="dexpress-stat-count">' . $failed . '</span> ' . __('Neisporučeno', 'd-express-woo') . '</div>';
        echo '</div>';

        // Lista najnovijih pošiljki
        $recent_shipments = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}dexpress_shipments ORDER BY created_at DESC LIMIT 5");

        if ($recent_shipments) {
            echo '<h3>' . __('Najnovije pošiljke', 'd-express-woo') . '</h3>';
            echo '<ul class="dexpress-recent-shipments">';
            foreach ($recent_shipments as $shipment) {
                $order = wc_get_order($shipment->order_id);
                $order_text = $order ? '#' . $order->get_order_number() : '#' . $shipment->order_id;

                echo '<li>';
                echo '<strong>' . $shipment->tracking_number . '</strong> ';
                echo '(' . $order_text . ') - ';
                echo dexpress_get_status_name($shipment->status_code);
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '<p><a href="' . admin_url('admin.php?page=dexpress-shipments') . '" class="button">' . __('Prikaži sve pošiljke', 'd-express-woo') . '</a></p>';

        // Dodajte CSS za stilizovanje widgeta
        echo '<style>
            .dexpress-dashboard-stats {
                display: flex;
                flex-wrap: wrap;
                margin-bottom: 15px;
            }
            .dexpress-stat {
                flex: 1 0 45%;
                padding: 8px;
                margin: 5px;
                background: #f8f8f8;
                border-radius: 3px;
                text-align: center;
            }
            .dexpress-stat-count {
                display: block;
                font-size: 24px;
                font-weight: bold;
                color: #0073aa;
            }
            .dexpress-recent-shipments {
                margin: 0;
                padding: 0;
            }
            .dexpress-recent-shipments li {
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }
            .dexpress-recent-shipments li:last-child {
                border-bottom: none;
            }
        </style>';
    }
}

// Inicijalizacija widgeta
add_action('init', function () {
    $widget = new D_Express_Dashboard_Widget();
    $widget->init();
});
