<?php

/**
 * ENHANCED D Express Shipments List
 * 
 * Klasa za prikazivanje liste pošiljki sa bulk operacijama za daily workflow
 */

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class D_Express_Shipments_List extends WP_List_Table
{

    public function __construct()
    {
        parent::__construct([
            'singular' => 'shipment',
            'plural'   => 'shipments',
            'ajax'     => false
        ]);
    }

    public function prepare_items()
    {
        global $wpdb;

        $per_page = 50; // Povećano za bolje bulk operacije
        $current_page = $this->get_pagenum();
        $total_items = $this->record_count();

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);

        $this->_column_headers = [
            $this->get_columns(),
            $this->get_hidden_columns(),
            $this->get_sortable_columns(),
            'shipment_id'
        ];

        $this->items = $this->get_shipments($per_page, $current_page);
    }

    /**
     * ENHANCED: Dobavljanje pošiljki sa detaljnim podacima za bulk operacije
     */
    private function get_shipments($per_page, $page_number)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_shipments';
        $packages_table = $wpdb->prefix . 'dexpress_packages';

        // Date filtering
        $date_filter = $this->get_date_filter_condition();

        // Status filtering  
        $status_filter = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : '';
        $status_condition = '';
        if (!empty($status_filter) && $status_filter !== 'all') {
            $status_condition = $wpdb->prepare("AND s.status_code = %s", $status_filter);
        }

        // Search filtering
        $search = isset($_REQUEST['s']) ? trim(sanitize_text_field($_REQUEST['s'])) : '';
        $search_condition = '';
        if (!empty($search)) {
            $search_condition = $wpdb->prepare(
                "AND (s.reference_id LIKE %s OR p.package_code LIKE %s OR s.id LIKE %s OR s.order_id LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        // Ordering
        $orderby = !empty($_REQUEST['orderby']) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'created_at';
        $order = !empty($_REQUEST['order']) ? sanitize_key($_REQUEST['order']) : 'DESC';

        // Pagination
        $offset = ($page_number - 1) * $per_page;

        // FIXED SQL - koristi towns tabelu za postal_code
        $sql = "SELECT 
                s.id as id,
                s.id as shipment_id,
                s.reference_id,
                s.order_id,
                s.status_code,
                s.created_at,
                s.is_test,
                s.total_mass,
                s.sender_location_id,
                o.post_status as order_status,
                sl.name as location_name,
                sl.address as location_address,
                t.postal_code as location_postal,
                COUNT(p.id) as package_count,
                GROUP_CONCAT(p.package_code ORDER BY p.package_index SEPARATOR '|') as tracking_codes,
                SUM(p.mass) as total_package_mass,
                MIN(p.package_code) as main_tracking_code
            FROM $table_name s
            LEFT JOIN {$wpdb->posts} o ON s.order_id = o.ID
            LEFT JOIN $packages_table p ON s.id = p.shipment_id
            LEFT JOIN {$wpdb->prefix}dexpress_sender_locations sl ON s.sender_location_id = sl.id
            LEFT JOIN {$wpdb->prefix}dexpress_towns t ON sl.town_id = t.id
            WHERE 1=1 $date_filter $search_condition $status_condition
            GROUP BY s.id
            ORDER BY s.$orderby $order
            LIMIT $offset, $per_page";

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Date filter condition generator
     */
    private function get_date_filter_condition()
    {
        global $wpdb;

        $date_filter = isset($_REQUEST['date_filter']) ? sanitize_key($_REQUEST['date_filter']) : '';

        switch ($date_filter) {
            case 'today':
                return "AND DATE(s.created_at) = CURDATE()";
            case 'yesterday':
                return "AND DATE(s.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            case 'last_3_days':
                return "AND DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)";
            case 'this_week':
                return "AND YEARWEEK(s.created_at) = YEARWEEK(CURDATE())";
            case 'unprinted':
                // Pošiljke koje nisu štampane
                return "AND s.order_id NOT IN (
                    SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_dexpress_label_printed' AND meta_value = 'yes'
                )";
            default:
                return '';
        }
    }

    private function record_count()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_shipments';
        $packages_table = $wpdb->prefix . 'dexpress_packages';

        $date_filter = $this->get_date_filter_condition();
        $status_filter = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : '';
        $status_condition = '';
        if (!empty($status_filter) && $status_filter !== 'all') {
            $status_condition = $wpdb->prepare("AND s.status_code = %s", $status_filter);
        }

        $search = isset($_REQUEST['s']) ? trim(sanitize_text_field($_REQUEST['s'])) : '';
        $search_condition = '';
        if (!empty($search)) {
            $search_condition = $wpdb->prepare(
                "AND (s.reference_id LIKE %s OR p.package_code LIKE %s OR s.id LIKE %s OR s.order_id LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        $sql = "SELECT COUNT(DISTINCT s.id) 
            FROM $table_name s
            LEFT JOIN $packages_table p ON s.id = p.shipment_id
            LEFT JOIN {$wpdb->prefix}dexpress_sender_locations sl ON s.sender_location_id = sl.id
            LEFT JOIN {$wpdb->prefix}dexpress_towns t ON sl.town_id = t.id
            WHERE 1=1 $date_filter $search_condition $status_condition";

        return $wpdb->get_var($sql);
    }

    public function get_columns()
    {
        return [
            'cb'               => '<input type="checkbox" />',
            'shipment_id'      => __('ID / Tracking', 'd-express-woo'),
            'order_info'       => __('Narudžbina', 'd-express-woo'),
            'packages_info'    => __('Paketi & Masa', 'd-express-woo'),
            'location_info'    => __('Lokacija', 'd-express-woo'),
            'status_code'      => __('Status', 'd-express-woo'),
            'created_at'       => __('Datum', 'd-express-woo'),
            'actions'          => __('Akcije', 'd-express-woo'),
        ];
    }

    public function get_hidden_columns()
    {
        return [];
    }

    public function get_sortable_columns()
    {
        return [
            'shipment_id'     => ['id', false],
            'order_info'      => ['order_id', false],
            'created_at'      => ['created_at', true],
            'packages_info'   => ['package_count', false],
        ];
    }

    public function get_bulk_actions()
    {
        return [
            'bulk_print' => __('Štampaj nalepnice', 'd-express-woo'),
            'mark_printed' => __('Označi kao štampano', 'd-express-woo'),
            'delete' => __('Obriši', 'd-express-woo'),
        ];
    }

    public function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="shipment[]" value="%s" />',
            $item['id']
        );
    }

    /**
     * ENHANCED: Shipment ID kolona sa glavnim tracking kodom
     */
    public function column_shipment_id($item)
    {
        $main_tracking = !empty($item['main_tracking_code']) ? $item['main_tracking_code'] : '-';
        $is_test = (bool) $item['is_test'];

        $title = sprintf(
            '<strong>Pošiljka #%s</strong><br>',
            $item['shipment_id']
        );

        if ($is_test) {
            $tracking_display = sprintf(
                '<span class="dexpress-tracking" title="Test mod">%s <small>(TEST)</small></span>',
                esc_html($main_tracking)
            );
        } else {
            $tracking_display = sprintf(
                '<a href="https://www.dexpress.rs/rs/pracenje-posiljaka/%s" target="_blank" class="dexpress-tracking">%s</a>',
                esc_attr($main_tracking),
                esc_html($main_tracking)
            );
        }

        $actions = [
            'view' => sprintf(
                '<a href="%s">Pregled</a>',
                admin_url(sprintf('post.php?post=%s&action=edit', $item['order_id']))
            ),
            'print' => sprintf(
                '<a href="#" onclick="printSingleShipment(%d); return false;">Štampaj</a>',
                $item['id']
            ),
        ];

        return $title . $tracking_display . $this->row_actions($actions);
    }

    /**
     * ENHANCED: Order info kolona
     */
    public function column_order_info($item)
    {
        $order = wc_get_order($item['order_id']);

        if (!$order) {
            return sprintf('#%s <small>(Obrisana)</small>', $item['order_id']);
        }

        $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $total = $order->get_total();

        $output = sprintf(
            '<a href="%s"><strong>#%s</strong></a><br>',
            admin_url(sprintf('post.php?post=%s&action=edit', $item['order_id'])),
            $order->get_order_number()
        );

        $output .= sprintf(
            '<small>%s<br>%s RSD</small>',
            esc_html($customer_name),
            number_format($total, 2, ',', '.')
        );

        // Status badge
        $status_name = wc_get_order_status_name($item['order_status']);
        $output .= sprintf(
            '<br><span class="order-status order-status-%s">%s</span>',
            $item['order_status'],
            $status_name
        );

        return $output;
    }

    /**
     * ENHANCED: Packages & mass info kolona
     */
    public function column_packages_info($item)
    {
        $package_count = intval($item['package_count']);
        $total_mass = intval($item['total_package_mass'] ?: $item['total_mass']);

        $output = sprintf(
            '<strong>%d %s</strong><br>',
            $package_count,
            $package_count == 1 ? 'paket' : 'paketa'
        );

        $output .= sprintf(
            '<small>%s kg</small>',
            number_format($total_mass / 1000, 2, ',', '.')
        );

        // Tracking codes ako ih ima više
        if (!empty($item['tracking_codes']) && $package_count > 1) {
            $codes = explode('|', $item['tracking_codes']);
            $output .= sprintf(
                '<br><small title="%s">%d tracking kodova</small>',
                esc_attr(implode(', ', $codes)),
                count($codes)
            );
        }

        return $output;
    }

    /**
     * ENHANCED: Location info kolona
     */
    public function column_location_info($item)
    {
        if (empty($item['location_name'])) {
            return '<small>Nepoznata lokacija</small>';
        }

        $output = sprintf('<strong>%s</strong><br>', esc_html($item['location_name']));

        if (!empty($item['location_address'])) {
            $output .= sprintf('<small>%s</small>', esc_html($item['location_address']));

            if (!empty($item['location_postal'])) {
                $output .= sprintf('<br><small>%s</small>', esc_html($item['location_postal']));
            }
        }

        return $output;
    }

    /**
     * Status kolona sa boljim prikazom
     */
    public function column_status_code($item)
    {
        $status_code = $item['status_code'];
        if (empty($status_code)) {
            return '<span class="dexpress-status-badge dexpress-status-pending">U obradi</span>';
        }

        $status_name = dexpress_get_status_name($status_code);
        $status_class = dexpress_get_status_css_class($status_code);

        return sprintf(
            '<span class="dexpress-status-badge %s" title="Status kod: %s">%s</span>',
            $status_class,
            $status_code,
            $status_name
        );
    }

    /**
     * Datum kolona sa relativnim vremenom
     */
    public function column_created_at($item)
    {
        $date = new DateTime($item['created_at']);
        $now = new DateTime();
        $diff = $now->diff($date);

        $relative = '';
        if ($diff->days == 0) {
            $relative = 'Danas';
        } elseif ($diff->days == 1) {
            $relative = 'Juče';
        } else {
            $relative = $diff->days . ' dana';
        }

        return sprintf(
            '<strong>%s</strong><br><small>%s (%s)</small>',
            $date->format('d.m.Y'),
            $date->format('H:i'),
            $relative
        );
    }

    /**
     * Actions kolona sa bulk operacijama
     */
    public function column_actions($item)
    {
        $actions = [];

        // Print pojedinačno
        $actions[] = sprintf(
            '<a href="#" onclick="printSingleShipment(%d); return false;" class="button button-small">Štampaj</a>',
            $item['id']
        );

        // Označi kao štampano/neštampano
        global $wpdb;
        $is_printed = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_dexpress_label_printed'",
            $item['order_id']
        )) === 'yes';

        if (!$is_printed) {
            $actions[] = sprintf(
                '<a href="#" onclick="markAsPrinted(%d); return false;" class="button button-small">Označи штампано</a>',
                $item['id']
            );
        } else {
            $actions[] = '<small>✓ Štampano</small>';
        }

        return implode('<br>', $actions);
    }

    /**
     * ENHANCED: Date & bulk action filters
     */
    public function extra_tablenav($which)
    {
        if ($which === 'top') {
            echo '<div class="alignleft actions">';

            // Date filter dropdown
            $current_date_filter = isset($_REQUEST['date_filter']) ? sanitize_key($_REQUEST['date_filter']) : '';
            echo '<select name="date_filter">';
            echo '<option value=""' . selected($current_date_filter, '', false) . '>Svi datumi</option>';
            echo '<option value="today"' . selected($current_date_filter, 'today', false) . '>Danas</option>';
            echo '<option value="yesterday"' . selected($current_date_filter, 'yesterday', false) . '>Juče</option>';
            echo '<option value="last_3_days"' . selected($current_date_filter, 'last_3_days', false) . '>Poslednja 3 dana</option>';
            echo '<option value="this_week"' . selected($current_date_filter, 'this_week', false) . '>Ova nedelja</option>';
            echo '<option value="unprinted"' . selected($current_date_filter, 'unprinted', false) . '>Neštampano</option>';
            echo '</select>';

            // Status filter dropdown  
            $current_status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : '';
            echo '<select name="status">';
            echo '<option value="all"' . selected($current_status, 'all', false) . '>Svi statusi</option>';

            global $wpdb;
            $statuses = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}dexpress_statuses_index ORDER BY id");
            foreach ($statuses as $status) {
                echo '<option value="' . esc_attr($status->id) . '"' . selected($current_status, $status->id, false) . '>';
                echo esc_html($status->name);
                echo '</option>';
            }
            echo '</select>';

            submit_button(__('Filter', 'd-express-woo'), 'button', 'filter_action', false);

            echo '</div>';

            // QUICK ACTION BUTTONS
            echo '<div class="alignright actions">';
            echo '<button type="button" id="print-today-btn" class="button button-primary">Štampaj sve današnje</button> ';
            echo '<button type="button" id="print-unprinted-btn" class="button">Štampaj neštampano</button> ';
            echo '<button type="button" id="select-all-btn" class="button">Odaberi sve</button>';
            echo '</div>';
        }
    }

    public function no_items()
    {
        _e('Nema pronađenih pošiljki. Proverite filtere.', 'd-express-woo');
    }
}

/**
 * ENHANCED: Render function with enhanced JavaScript
 */
function dexpress_render_enhanced_shipments_page()
{
    $shipments_list = new D_Express_Shipments_List();
    $shipments_list->prepare_items();

    // Statistics
    global $wpdb;
    $today_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_shipments WHERE DATE(created_at) = CURDATE()");
    $unprinted_count = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_shipments s 
        WHERE s.order_id NOT IN (
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_dexpress_label_printed' AND meta_value = 'yes'
        )
    ");

?>
    <div class="wrap">
        <h1 class="wp-heading-inline">D Express Pošiljke</h1>

        <!-- Quick stats -->
        <div class="dexpress-quick-stats">
            <span class="stat-item">Danas: <strong><?php echo $today_count; ?></strong></span>
            <span class="stat-item">Neštampano: <strong><?php echo $unprinted_count; ?></strong></span>
        </div>

        <form method="post" id="dexpress-shipments-form">
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page']; ?>" />
            <?php $shipments_list->search_box('Pretraži pošiljke', 'dexpress_search'); ?>
            <?php $shipments_list->display(); ?>
        </form>

        <!-- Hidden form for bulk printing -->
        <form id="bulk_print_form" method="post" action="<?php echo admin_url('admin-ajax.php'); ?>" target="_blank">
            <input type="hidden" name="action" value="dexpress_bulk_print_labels">
            <input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce('dexpress-bulk-print'); ?>">
            <input type="hidden" name="shipment_ids" id="bulk_print_ids" value="">
        </form>
    </div>

    <style>
        .dexpress-quick-stats {
            margin: 10px 0 20px 0;
            padding: 10px;
            background: #f1f1f1;
            border-radius: 4px;
        }

        .stat-item {
            margin-right: 20px;
            font-size: 14px;
        }

        .dexpress-tracking {
            font-family: monospace;
            font-size: 12px;
        }

        .dexpress-status-badge {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }

        .dexpress-status-pending {
            background: #ffa500;
            color: white;
        }

        .dexpress-status-delivered {
            background: #46b450;
            color: white;
        }

        .dexpress-status-failed {
            background: #dc3232;
            color: white;
        }

        .dexpress-status-transit {
            background: #00a0d2;
            color: white;
        }
    </style>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Print today's shipments
            $('#print-today-btn').on('click', function() {
                if (confirm('Štampati sve današnje pošiljke?')) {
                    window.open('<?php echo admin_url('admin-ajax.php?action=dexpress_bulk_print_labels&date_filter=today&_wpnonce=' . wp_create_nonce('dexpress-bulk-print')); ?>', '_blank');
                }
            });

            // Print unprinted shipments  
            $('#print-unprinted-btn').on('click', function() {
                if (confirm('Štampati sve neštampane pošiljke?')) {
                    window.open('<?php echo admin_url('admin-ajax.php?action=dexpress_bulk_print_labels&date_filter=unprinted&_wpnonce=' . wp_create_nonce('dexpress-bulk-print')); ?>', '_blank');
                }
            });

            // Select all checkboxes
            $('#select-all-btn').on('click', function() {
                $('input[name="shipment[]"]').prop('checked', true);
            });

            // Handle bulk actions
            $('select[name="action"], select[name="action2"]').on('change', function() {
                if ($(this).val() === 'bulk_print') {
                    $(this).closest('form').on('submit', function(e) {
                        e.preventDefault();

                        var selectedIds = [];
                        $('input[name="shipment[]"]:checked').each(function() {
                            selectedIds.push($(this).val());
                        });

                        if (selectedIds.length === 0) {
                            alert('Molimo odaberite pošiljke za štampanje.');
                            return false;
                        }

                        $('#bulk_print_ids').val(selectedIds.join(','));
                        $('#bulk_print_form').submit();
                        return false;
                    });
                }
            });
        });

        // Global functions for individual actions
        function printSingleShipment(shipmentId) {
            var nonce = '<?php echo wp_create_nonce('dexpress-bulk-print'); ?>';
            window.open(ajaxurl + '?action=dexpress_bulk_print_labels&shipment_ids=' + shipmentId + '&_wpnonce=' + nonce, '_blank');
        }

        function markAsPrinted(shipmentId) {
            // AJAX call to mark as printed
            jQuery.post(ajaxurl, {
                action: 'dexpress_mark_printed',
                shipment_id: shipmentId,
                nonce: '<?php echo wp_create_nonce('dexpress_admin_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Greška: ' + response.data);
                }
            });
        }
    </script>
<?php
}
