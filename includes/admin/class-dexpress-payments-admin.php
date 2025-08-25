<?php
defined('ABSPATH') || exit;

class D_Express_Payments_Admin
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu'], 20);
        add_action('wp_ajax_dexpress_import_payments', [$this, 'ajax_import_payments']);
    }

    public function add_admin_menu()
    {
        add_submenu_page(
            'dexpress-settings',  // ← OVO je ispravno iz fajla koji si poslao
            'D Express Plaćanja',
            'Plaćanja',
            'manage_woocommerce',
            'dexpress-payments',
            [$this, 'render_payments_page']
        );
    }
    public function render_payments_page()
    {
        // Obradi POST zahtev
        if ($_POST && isset($_POST['payment_reference']) && wp_verify_nonce($_POST['nonce'], 'dexpress_import_payments')) {
            $this->handle_import_request();
        }

        echo '<div class="wrap">';
        echo '<h1>D Express Plaćanja</h1>';

        $this->render_import_form();
        $this->render_test_simulation_section();
        $this->render_payments_list();

        echo '</div>';
    }
    /**
     * Obrađuje import zahtev
     */
    private function handle_import_request()
    {
        $reference = sanitize_text_field($_POST['payment_reference']);

        if (empty($reference)) {
            echo '<div class="notice notice-error"><p>Referenca je obavezna</p></div>';
            return;
        }

        $service = new D_Express_Payments_Service();
        $result = $service->import_payments_by_reference($reference);

        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>Greška: ' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Uspešno importovano ' . $result . ' plaćanja za referencu: ' . esc_html($reference) . '</p></div>';
        }
    }

    private function render_payments_list()
    {
        global $wpdb;
        $payments = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}dexpress_payments 
            ORDER BY payment_date DESC, imported_at DESC 
            LIMIT 50
        ");

        if (!empty($payments)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>Reference</th><th>Order ID</th><th>Iznos</th><th>Datum</th>';
            echo '</tr></thead><tbody>';

            foreach ($payments as $payment) {
                echo '<tr>';
                echo '<td>' . esc_html($payment->payment_reference) . '</td>';
                echo '<td><a href="' . admin_url('post.php?post=' . $payment->reference_id . '&action=edit') . '">' . esc_html($payment->reference_id) . '</a></td>';
                echo '<td>' . number_format($payment->buyout_amount / 100, 2) . ' RSD</td>';
                echo '<td>' . esc_html($payment->payment_date) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }
    /**
     * Renderuje formu za import
     */
    private function render_import_form()
    {
        echo '<div class="postbox" style="margin-top: 20px;">';
        echo '<div class="postbox-header"><h2>Import plaćanja iz bankovnog izvoda</h2></div>';
        echo '<div class="inside">';

        echo '<form method="post" style="padding: 10px;">';
        wp_nonce_field('dexpress_import_payments', 'nonce');
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="payment_reference">Referenca sa izvoda</label></th>';
        echo '<td>';
        echo '<input type="text" id="payment_reference" name="payment_reference" placeholder="DEXPR-20250125-001" class="regular-text" required>';
        echo '<p class="description">Unesite referencu koju ste dobili u bankovnom izvodu od D Express-a</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        echo '<p class="submit">';
        echo '<input type="submit" value="Importuj plaćanja" class="button-primary">';
        echo '</p>';
        echo '</form>';

        echo '</div>';
        echo '</div>';
    }

    /**
     * Test simulacija sekcija
     */
    private function render_test_simulation_section()
    {
        if (!dexpress_is_test_mode()) {
            return;
        }

        global $wpdb;
        $cod_count = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_shipments 
        WHERE buyout_in_para > 0 
        AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");

        echo '<div class="postbox" style="margin-top: 20px; border-left: 4px solid #ffb900;">';
        echo '<div class="postbox-header"><h2>Test Mode - Simulacija</h2></div>';
        echo '<div class="inside" style="padding: 15px;">';

        echo '<p><strong>Simulacija koristi tvoje stvarne COD pošiljke (' . $cod_count . ' u poslednjih 30 dana):</strong></p>';
        echo '<ul>';
        echo '<li><code>TEST-20250125-001</code> - simulira 3 najnovije COD pošiljke</li>';
        echo '<li><code>TEST-20250125-002</code> - simulira 1 najnoviju COD pošiljku</li>';
        echo '<li><code>ALL-TEST</code> - simulira sve COD pošiljke (max 5)</li>';
        echo '<li><code>EMPTY-TEST</code> - simulira prazan odgovor</li>';
        echo '</ul>';

        if ($cod_count == 0) {
            echo '<p style="color: #d63384;"><strong>Upozorenje:</strong> Nema COD pošiljki za simulaciju. Kreiraj pošiljku sa pouzećem prvo.</p>';
        }

        echo '</div></div>';
    }
    public function ajax_import_payments()
    {
        check_ajax_referer('dexpress_admin_nonce', 'nonce');

        $reference = sanitize_text_field($_POST['payment_reference']);
        $service = new D_Express_Payments_Service();
        $result = $service->import_payments_by_reference($reference);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success("Importovano $result plaćanja");
        }
    }
}
