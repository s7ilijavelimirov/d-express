<?php

/**
 * REDESIGNED D Express Shipments List - Grouped by Orders
 * 
 * Klasa za prikazivanje liste pošiljki grupovano po narudžbinama
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
            'singular' => 'order_shipment',
            'plural'   => 'order_shipments',
            'ajax'     => false
        ]);
    }

    public function prepare_items()
    {
        global $wpdb;

        $per_page = 25;
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
            'order_id'
        ];

        $this->items = $this->get_order_shipments($per_page, $current_page);
    }

    /**
     * Dobavljanje narudžbina sa pošiljkama - GRUPOVANO PO ORDER-U
     */
    private function get_order_shipments($per_page, $page_number)
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
                "AND (s.order_id LIKE %s OR o.post_excerpt LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        // Ordering
        $orderby = !empty($_REQUEST['orderby']) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'created_at';
        $order = !empty($_REQUEST['order']) ? sanitize_key($_REQUEST['order']) : 'DESC';

        // Pagination
        $offset = ($page_number - 1) * $per_page;

        // SQL Query - GRUPOVANO PO ORDER_ID
        $sql = "SELECT 
                s.order_id,
                o.post_status as order_status,
                MAX(s.created_at) as latest_shipment_date,
                COUNT(DISTINCT s.id) as total_shipments,
                COUNT(DISTINCT p.id) as total_packages,
                SUM(DISTINCT s.total_mass) as total_mass,
                GROUP_CONCAT(DISTINCT s.id ORDER BY s.created_at SEPARATOR '|') as shipment_ids,
                GROUP_CONCAT(DISTINCT p.package_code ORDER BY s.created_at, p.package_index SEPARATOR '|') as all_tracking_codes,
                GROUP_CONCAT(DISTINCT s.status_code ORDER BY s.created_at SEPARATOR '|') as all_statuses,
                GROUP_CONCAT(DISTINCT sl.name ORDER BY s.created_at SEPARATOR '|') as all_locations,
                MAX(s.is_test) as has_test_shipments,
                MIN(p.package_code) as main_tracking_code
            FROM $table_name s
            LEFT JOIN {$wpdb->posts} o ON s.order_id = o.ID
            LEFT JOIN $packages_table p ON s.id = p.shipment_id
            LEFT JOIN {$wpdb->prefix}dexpress_sender_locations sl ON s.sender_location_id = sl.id
            LEFT JOIN {$wpdb->prefix}dexpress_towns t ON sl.town_id = t.id
            WHERE 1=1 $date_filter $search_condition $status_condition
            GROUP BY s.order_id
            ORDER BY MAX(s.$orderby) $order
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
                "AND (s.order_id LIKE %s OR o.post_excerpt LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        // COUNT DISTINCT ORDER_ID
        $sql = "SELECT COUNT(DISTINCT s.order_id) 
            FROM $table_name s
            LEFT JOIN {$wpdb->posts} o ON s.order_id = o.ID
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
            'order_info'       => __('Narudžbina', 'd-express-woo'),
            'shipments_summary' => __('Pošiljke & Paketi', 'd-express-woo'),
            'tracking_codes'   => __('Tracking kodovi', 'd-express-woo'),
            'location_info'    => __('Lokacije', 'd-express-woo'),
            'status_summary'   => __('Status', 'd-express-woo'),
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
            'order_info'       => ['order_id', false],
            'created_at'       => ['created_at', true],
            'shipments_summary' => ['total_shipments', false],
        ];
    }

    public function get_bulk_actions()
    {
        return [
            'bulk_print_all' => __('Štampaj sve nalepnice', 'd-express-woo'),
            'mark_all_printed' => __('Označi sve kao štampano', 'd-express-woo'),
            'delete_all' => __('Obriši sve pošiljke', 'd-express-woo'),
        ];
    }

    public function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="order[]" value="%s" data-shipments="%s" />',
            $item['order_id'],
            esc_attr($item['shipment_ids'])
        );
    }

    /**
     * Order info kolona
     */
    public function column_order_info($item)
    {
        $order = wc_get_order($item['order_id']);

        if (!$order) {
            return sprintf('<div class="order-deleted">#%s <small>(Obrisana)</small></div>', $item['order_id']);
        }

        $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $total = $order->get_total();

        $output = '<div class="order-info-wrapper">';
        
        $output .= sprintf(
            '<div class="order-number"><a href="%s">#%s</a></div>',
            admin_url(sprintf('post.php?post=%s&action=edit', $item['order_id'])),
            $order->get_order_number()
        );

        if (!empty($customer_name)) {
            $output .= sprintf('<div class="customer-name">%s</div>', esc_html($customer_name));
        }

        if ($total > 0) {
            $output .= sprintf('<div class="order-total">%s RSD</div>', number_format($total, 2, ',', '.'));
        }

        // Order status badge
        $status_name = wc_get_order_status_name($item['order_status']);
        $output .= sprintf('<div class="order-status order-status-%s">%s</div>', $item['order_status'], $status_name);

        // Test mode indicator
        if ($item['has_test_shipments']) {
            $output .= '<div class="test-mode-badge">TEST</div>';
        }

        $output .= '</div>';

        $actions = [
            'view_order' => sprintf(
                '<a href="%s">Pregled</a>',
                admin_url(sprintf('post.php?post=%s&action=edit', $item['order_id']))
            )
        ];

        return $output . $this->row_actions($actions);
    }

    /**
     * Shipments summary kolona
     */
    public function column_shipments_summary($item)
    {
        $shipment_count = intval($item['total_shipments']);
        $package_count = intval($item['total_packages']);
        $total_mass = intval($item['total_mass']);

        $output = '<div class="shipments-summary">';
        $output .= sprintf('<div class="shipment-count">%d %s</div>', 
            $shipment_count,
            $shipment_count == 1 ? 'pošiljka' : 'pošiljki'
        );
        $output .= sprintf('<div class="package-count">%d %s</div>', 
            $package_count,
            $package_count == 1 ? 'paket' : 'paketa'
        );
        $output .= sprintf('<div class="total-mass">%s kg</div>', 
            number_format($total_mass / 1000, 2, ',', '.')
        );
        $output .= '</div>';

        return $output;
    }

    /**
     * Tracking codes kolona
     */
    public function column_tracking_codes($item)
    {
        if (empty($item['all_tracking_codes'])) {
            return '<div class="no-tracking">-</div>';
        }

        $tracking_codes = array_filter(explode('|', $item['all_tracking_codes']));
        $is_test = (bool) $item['has_test_shipments'];
        
        $output = '<div class="tracking-codes-list">';
        
        $display_codes = array_slice($tracking_codes, 0, 3);
        
        foreach ($display_codes as $code) {
            if ($is_test) {
                $output .= sprintf('<div class="tracking-code test">%s <span class="test-label">(TEST)</span></div>', esc_html($code));
            } else {
                $output .= sprintf(
                    '<a href="https://www.dexpress.rs/rs/pracenje-posiljaka/%s" target="_blank" class="tracking-code">%s</a>',
                    esc_attr($code),
                    esc_html($code)
                );
            }
        }
        
        $remaining = count($tracking_codes) - 3;
        if ($remaining > 0) {
            $output .= sprintf('<div class="tracking-more">+%d više</div>', $remaining);
        }
        
        $output .= '</div>';

        return $output;
    }

    /**
     * Location info kolona - POBOLJŠANA
     */
    public function column_location_info($item)
    {
        if (empty($item['all_locations'])) {
            return '<div class="no-location">Nepoznata</div>';
        }

        $locations = array_filter(array_unique(explode('|', $item['all_locations'])));
        
        $output = '<div class="dexpress-locations-list">';
        foreach ($locations as $i => $location) {
            if ($i === 0) {
                $output .= sprintf('<div class="primary-location">%s</div>', esc_html($location));
            } else {
                $output .= sprintf('<div class="secondary-location">%s</div>', esc_html($location));
            }
        }
        $output .= '</div>';
        
        return $output;
    }

    /**
     * Status summary kolona - POBOLJŠANA
     */
    public function column_status_summary($item)
    {
        if (empty($item['all_statuses'])) {
            return '<div class="dexpress-status-badge dexpress-status-pending">U obradi</div>';
        }

        $statuses = array_filter(explode('|', $item['all_statuses']));
        $unique_statuses = array_unique($statuses);

        // Ako su svi statusi isti
        if (count($unique_statuses) === 1) {
            $status_code = $unique_statuses[0];
            $status_name = dexpress_get_status_name($status_code);
            $status_class = dexpress_get_status_css_class($status_code);

            return sprintf('<div class="dexpress-status-badge %s">%s</div>', $status_class, $status_name);
        } else {
            // Različiti statusi - prikaži najčešći + info da su različiti
            $status_counts = array_count_values($statuses);
            arsort($status_counts);
            $most_common = key($status_counts);
            
            $status_name = dexpress_get_status_name($most_common);
            $status_class = dexpress_get_status_css_class($most_common);

            $output = '<div class="status-mixed">';
            $output .= sprintf('<div class="dexpress-status-badge %s">%s</div>', $status_class, $status_name);
            $output .= '<div class="mixed-status-note">Mešoviti statusi</div>';
            $output .= '</div>';
            
            return $output;
        }
    }

    /**
     * Datum kolona
     */
    public function column_created_at($item)
    {
        $date = new DateTime($item['latest_shipment_date']);
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

        $output = '<div class="date-wrapper">';
        $output .= sprintf('<div class="date-main">%s</div>', $date->format('d.m.Y'));
        $output .= sprintf('<div class="date-details">%s (%s)</div>', $date->format('H:i'), $relative);
        $output .= '</div>';

        return $output;
    }

    /**
     * Actions kolona
     */
    public function column_actions($item)
    {
        $output = '<div class="actions-wrapper">';

        // Print all shipments za ovu narudžbinu
        $output .= sprintf(
            '<a href="#" onclick="printOrderShipments(%d, \'%s\'); return false;" class="button button-primary button-small dex-btn-print">Štampaj sve</a>',
            $item['order_id'],
            esc_js($item['shipment_ids'])
        );

        // Mark as printed
        global $wpdb;
        $is_printed = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_dexpress_label_printed'",
            $item['order_id']
        )) === 'yes';

        if (!$is_printed) {
            $output .= sprintf(
                '<a href="#" onclick="markOrderAsPrinted(%d); return false;" class="button button-small dex-btn-mark">Označи štampano</a>',
                $item['order_id']
            );
        } else {
            $output .= '<div class="printed-status">✓ Štampano</div>';
        }

        $output .= '</div>';

        return $output;
    }

    /**
     * Date & bulk action filters
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
            echo '<div class="alignright actions dex-quick-actions">';
            echo '<button type="button" id="print-today-btn" class="button button-primary">Štampaj sve današnje</button> ';
            echo '<button type="button" id="print-unprinted-btn" class="button">Štampaj neštampano</button> ';
            echo '<button type="button" id="select-all-btn" class="button">Odaberi sve</button>';
            echo '</div>';
        }
    }

    public function no_items()
    {
        _e('Nema pronađenih narudžbina sa pošiljkama. Proverite filtere.', 'd-express-woo');
    }
}

/**
 * Render function 
 */
function dexpress_render_enhanced_shipments_page()
{
    $shipments_list = new D_Express_Shipments_List();
    $shipments_list->prepare_items();

    // Statistics - po narudžbinama
    global $wpdb;
    $today_orders = $wpdb->get_var("SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}dexpress_shipments WHERE DATE(created_at) = CURDATE()");
    $unprinted_orders = $wpdb->get_var("
        SELECT COUNT(DISTINCT s.order_id) FROM {$wpdb->prefix}dexpress_shipments s 
        WHERE s.order_id NOT IN (
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_dexpress_label_printed' AND meta_value = 'yes'
        )
    ");

?>
    <div class="wrap dexpress-shipments-wrap">
        <h1 class="wp-heading-inline">D Express - Narudžbine sa pošiljkama</h1>

        <!-- Quick stats -->
        <div class="dexpress-quick-stats">
            <div class="stats-row">
                <div class="stat-card stat-today">
                    <div class="stat-icon">📦</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $today_orders; ?></div>
                        <div class="stat-label">Danas narudžbina</div>
                    </div>
                </div>

                <div class="stat-card stat-unprinted">
                    <div class="stat-icon">🖨️</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $unprinted_orders; ?></div>
                        <div class="stat-label">Neštampano</div>
                    </div>
                </div>

                <?php
                // Dodatne statistike
                $total_orders = $wpdb->get_var("SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}dexpress_shipments");
                $total_packages = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dexpress_packages");
                ?>

                <div class="stat-card stat-total">
                    <div class="stat-icon">📋</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_orders; ?></div>
                        <div class="stat-label">Ukupno narudžbina</div>
                    </div>
                </div>

                <div class="stat-card stat-packages">
                    <div class="stat-icon">📄</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $total_packages; ?></div>
                        <div class="stat-label">Ukupno paketa</div>
                    </div>
                </div>
            </div>

            <!-- Quick actions row -->
            <div class="stats-actions">
                <a href="?page=dexpress-shipments&date_filter=today" class="stats-btn stats-btn-primary">
                    Prikaži današnje
                </a>
                <a href="?page=dexpress-shipments&date_filter=unprinted" class="stats-btn stats-btn-secondary">
                    Prikaži neštampano
                </a>
            </div>
        </div>

        <form method="post" id="dexpress-shipments-form" class="dexpress-table-form">
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page']; ?>" />
            <?php $shipments_list->search_box('Pretraži narudžbine', 'dexpress_search'); ?>
            <div class="dexpress-table-wrapper">
                <?php $shipments_list->display(); ?>
            </div>
        </form>

        <!-- Hidden form for bulk printing -->
        <form id="bulk_print_form" method="post" action="<?php echo admin_url('admin-ajax.php'); ?>" target="_blank">
            <input type="hidden" name="action" value="dexpress_bulk_print_labels">
            <input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce('dexpress-bulk-print'); ?>">
            <input type="hidden" name="shipment_ids" id="bulk_print_ids" value="">
        </form>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Print today's orders
            $('#print-today-btn').on('click', function() {
                if (confirm('Štampati sve pošiljke za današnje narudžbine?')) {
                    window.open('<?php echo admin_url('admin-ajax.php?action=dexpress_bulk_print_labels&date_filter=today&_wpnonce=' . wp_create_nonce('dexpress-bulk-print')); ?>', '_blank');
                }
            });

            // Print unprinted orders
            $('#print-unprinted-btn').on('click', function() {
                if (confirm('Štampati sve neštampane pošiljke?')) {
                    window.open('<?php echo admin_url('admin-ajax.php?action=dexpress_bulk_print_labels&date_filter=unprinted&_wpnonce=' . wp_create_nonce('dexpress-bulk-print')); ?>', '_blank');
                }
            });

            // Select all checkboxes
            $('#select-all-btn').on('click', function() {
                $('input[name="order[]"]').prop('checked', true);
            });

            // Handle bulk actions
            $('select[name="action"], select[name="action2"]').on('change', function() {
                var action = $(this).val();
                if (action === 'bulk_print_all') {
                    $(this).closest('form').on('submit', function(e) {
                        e.preventDefault();

                        var allShipmentIds = [];
                        $('input[name="order[]"]:checked').each(function() {
                            var shipmentIds = $(this).data('shipments').toString().split('|');
                            allShipmentIds = allShipmentIds.concat(shipmentIds);
                        });

                        if (allShipmentIds.length === 0) {
                            alert('Molimo odaberite narudžbine za štampanje.');
                            return false;
                        }

                        $('#bulk_print_ids').val(allShipmentIds.join(','));
                        $('#bulk_print_form').submit();
                        return false;
                    });
                }
            });
        });

        // Global functions
        function printOrderShipments(orderId, shipmentIds) {
            var nonce = '<?php echo wp_create_nonce('dexpress-bulk-print'); ?>';
            window.open(ajaxurl + '?action=dexpress_bulk_print_labels&shipment_ids=' + shipmentIds + '&_wpnonce=' + nonce, '_blank');
        }

        function markOrderAsPrinted(orderId) {
            jQuery.post(ajaxurl, {
                action: 'dexpress_mark_printed',
                order_id: orderId,
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