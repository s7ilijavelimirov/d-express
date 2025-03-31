<?php

/**
 * D Express Reports
 */

defined('ABSPATH') || exit;

class D_Express_Reports
{

    public function init()
    {
        // Dodajemo enqueue_scripts akciju
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Registrujemo AJAX handler za učitavanje podataka izveštaja, ako je potrebno
        add_action('wp_ajax_dexpress_get_report_data', array($this, 'ajax_get_report_data'));
    }

    public function add_reports_page()
    {
        // Promenite parent slug na 'dexpress' umesto 'woocommerce'
        add_submenu_page(
            'dexpress',  // Izmenjeno da odgovara novoj strukturi menija
            __('D Express Izveštaji', 'd-express-woo'),
            __('Izveštaji', 'd-express-woo'),
            'manage_woocommerce',
            'dexpress-reports',
            array($this, 'render_reports_page')
        );
    }

    public function enqueue_scripts($hook)
    {
        // Hook se promenio zbog nove strukture menija
        if (
            $hook != 'woocommerce_page_dexpress-reports' &&
            $hook != 'd-express_page_dexpress-reports' &&
            $hook != 'dexpress_page_dexpress-reports'
        ) {
            return;
        }

        // Učitavanje Chart.js biblioteke
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js',
            array(),
            '3.7.1',
            true
        );

        // Učitavanje DateRangePicker
        wp_enqueue_script(
            'moment',
            'https://cdn.jsdelivr.net/npm/moment@2.29.1/moment.min.js',
            array(),
            '2.29.1',
            true
        );

        wp_enqueue_script(
            'daterangepicker',
            'https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.min.js',
            array('jquery', 'moment'),
            '3.1.0',
            true
        );

        wp_enqueue_style(
            'daterangepicker',
            'https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.css',
            array(),
            '3.1.0'
        );

        // Naša custom skripta za izveštaje
        wp_enqueue_script(
            'dexpress-reports',
            DEXPRESS_WOO_PLUGIN_URL . 'assets/js/dexpress-reports.js',
            array('jquery', 'chartjs', 'daterangepicker'),
            DEXPRESS_WOO_VERSION,
            true
        );

        // Ajax URL i nonce
        wp_localize_script('dexpress-reports', 'dexpressReports', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dexpress-reports-nonce'),
            'i18n' => array(
                'apply' => __('Primeni', 'd-express-woo'),
                'cancel' => __('Otkaži', 'd-express-woo'),
                'customRange' => __('Prilagođeni period', 'd-express-woo'),
                'today' => __('Danas', 'd-express-woo'),
                'yesterday' => __('Juče', 'd-express-woo'),
                'last7Days' => __('Poslednjih 7 dana', 'd-express-woo'),
                'last30Days' => __('Poslednjih 30 dana', 'd-express-woo'),
                'thisMonth' => __('Ovaj mesec', 'd-express-woo'),
                'lastMonth' => __('Prošli mesec', 'd-express-woo')
            )
        ));
    }

    public function render_reports_page()
    {
        $this->handle_export();

        global $wpdb;

        // Statistika po statusima
        $status_stats = $wpdb->get_results("
            SELECT status_code, COUNT(*) as count
            FROM {$wpdb->prefix}dexpress_shipments
            GROUP BY status_code
        ");

        // Statistika po datumima (poslednjih 30 dana)
        $date_stats = $wpdb->get_results("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM {$wpdb->prefix}dexpress_shipments
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");

        // Pripremanje podataka za grafikon
        $dates = array();
        $counts = array();

        foreach ($date_stats as $stat) {
            $dates[] = $stat->date;
            $counts[] = $stat->count;
        }

        // Prikaz stranice
        include DEXPRESS_WOO_PLUGIN_DIR . 'includes/admin/views/reports-page.php';
    }

    private function handle_export()
    {
        if (isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'dexpress_export_csv')) {
            $this->export_shipments_csv();
        }
    }

    public function export_shipments_csv()
    {
        global $wpdb;

        // Filteri za export
        $from_date = isset($_GET['from_date']) ? sanitize_text_field($_GET['from_date']) : '';
        $to_date = isset($_GET['to_date']) ? sanitize_text_field($_GET['to_date']) : '';
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        // Pripremanje uslova
        $where = "WHERE 1=1";

        if ($from_date && $to_date) {
            $where .= $wpdb->prepare(" AND created_at BETWEEN %s AND %s", $from_date . ' 00:00:00', $to_date . ' 23:59:59');
        }

        if ($status) {
            $where .= $wpdb->prepare(" AND status_code = %s", $status);
        }

        // Dobavljanje pošiljki
        $shipments = $wpdb->get_results("
            SELECT s.*, o.post_status as order_status 
            FROM {$wpdb->prefix}dexpress_shipments s
            LEFT JOIN {$wpdb->posts} o ON s.order_id = o.ID
            $where
            ORDER BY created_at DESC
        ");

        // Postavljanje headera za CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=dexpress-shipments.csv');

        $output = fopen('php://output', 'w');

        // BOM za UTF-8
        fputs($output, "\xEF\xBB\xBF");

        // CSV zaglavlje
        fputcsv($output, array(
            __('ID pošiljke', 'd-express-woo'),
            __('Tracking broj', 'd-express-woo'),
            __('Referenca', 'd-express-woo'),
            __('ID Narudžbine', 'd-express-woo'),
            __('Status narudžbine', 'd-express-woo'),
            __('Status pošiljke', 'd-express-woo'),
            __('Datum kreiranja', 'd-express-woo'),
            __('Test', 'd-express-woo')
        ));

        // Podaci
        foreach ($shipments as $shipment) {
            fputcsv($output, array(
                $shipment->shipment_id,
                $shipment->tracking_number,
                $shipment->reference_id,
                $shipment->order_id,
                isset($shipment->order_status) ? wc_get_order_status_name($shipment->order_status) : '',
                dexpress_get_status_name($shipment->status_code),
                $shipment->created_at,
                $shipment->is_test ? __('Da', 'd-express-woo') : __('Ne', 'd-express-woo')
            ));
        }

        fclose($output);
        exit;
    }
}

// Inicijalizacija izveštaja
add_action('init', function () {
    $reports = new D_Express_Reports();
    $reports->init();
});
