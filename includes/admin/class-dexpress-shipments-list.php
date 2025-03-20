<?php

/**
 * D Express Shipments List
 * 
 * Klasa za prikazivanje liste pošiljki u admin panelu
 */

defined('ABSPATH') || exit;

// Provera da li WP_List_Table klasa postoji
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class D_Express_Shipments_List extends WP_List_Table
{

    /**
     * Konstruktor
     */
    public function __construct()
    {
        parent::__construct([
            'singular' => 'shipment',   // Jednina
            'plural'   => 'shipments',  // Množina
            'ajax'     => false         // Da li tabela podržava ajax
        ]);
    }

    /**
     * Priprema stavki za prikazivanje u tabeli
     */
    public function prepare_items()
    {
        global $wpdb;

        // Paginacija
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = $this->record_count();

        // Postavljanje podataka paginacije
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);

        // Definisanje kolona
        $this->_column_headers = [
            $this->get_columns(),
            $this->get_hidden_columns(),
            $this->get_sortable_columns(),
            'shipment_id'  // Primarna kolona
        ];

        // Dobavljanje stavki
        $this->items = $this->get_shipments($per_page, $current_page);
    }

    /**
     * Dobavljanje pošiljki iz baze
     */
    private function get_shipments($per_page, $page_number)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_shipments';

        // Definisanje ORDER BY
        $orderby = !empty($_REQUEST['orderby']) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'created_at';
        $order = !empty($_REQUEST['order']) ? sanitize_key($_REQUEST['order']) : 'DESC';

        // Definisanje WHERE uslova za pretragu
        $search = isset($_REQUEST['s']) ? trim(sanitize_text_field($_REQUEST['s'])) : '';
        $search_condition = '';

        if (!empty($search)) {
            $search_condition = $wpdb->prepare(
                "AND (shipment_id LIKE %s OR tracking_number LIKE %s OR reference_id LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        // Definisanje WHERE uslova za filter statusa
        $status_filter = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : '';
        $status_condition = '';

        if (!empty($status_filter) && $status_filter !== 'all') {
            $status_condition = $wpdb->prepare("AND status_code = %s", $status_filter);
        }

        // Definisanje LIMIT
        $offset = ($page_number - 1) * $per_page;
        $limit = "LIMIT $offset, $per_page";

        // SQL upit
        $sql = "SELECT s.*, o.post_status as order_status 
                FROM $table_name s
                LEFT JOIN {$wpdb->posts} o ON s.order_id = o.ID
                WHERE 1=1 $search_condition $status_condition
                ORDER BY $orderby $order
                $limit";

        // Izvršavanje upita
        $result = $wpdb->get_results($sql, ARRAY_A);

        return $result;
    }

    /**
     * Dobavljanje ukupnog broja pošiljki
     */
    private function record_count()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'dexpress_shipments';

        // Definisanje WHERE uslova za pretragu
        $search = isset($_REQUEST['s']) ? trim(sanitize_text_field($_REQUEST['s'])) : '';
        $search_condition = '';

        if (!empty($search)) {
            $search_condition = $wpdb->prepare(
                "AND (shipment_id LIKE %s OR tracking_number LIKE %s OR reference_id LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        // Definisanje WHERE uslova za filter statusa
        $status_filter = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : '';
        $status_condition = '';

        if (!empty($status_filter) && $status_filter !== 'all') {
            $status_condition = $wpdb->prepare("AND status_code = %s", $status_filter);
        }

        // SQL upit za brojanje
        $sql = "SELECT COUNT(*) FROM $table_name WHERE 1=1 $search_condition $status_condition";

        return $wpdb->get_var($sql);
    }

    /**
     * Definisanje kolona tabele
     */
    public function get_columns()
    {
        return [
            'cb'               => '<input type="checkbox" />',
            'shipment_id'      => __('ID Pošiljke', 'd-express-woo'),
            'tracking_number'  => __('Tracking broj', 'd-express-woo'),
            'order_id'         => __('Narudžbina', 'd-express-woo'),
            'status_code'      => __('Status', 'd-express-woo'),
            'created_at'       => __('Datum kreiranja', 'd-express-woo'),
            'is_test'          => __('Test', 'd-express-woo'),
        ];
    }

    /**
     * Definisanje skrivenih kolona
     */
    public function get_hidden_columns()
    {
        return [];
    }

    /**
     * Definisanje kolona koje se mogu sortirati
     */
    public function get_sortable_columns()
    {
        return [
            'shipment_id'     => ['shipment_id', false],
            'tracking_number' => ['tracking_number', false],
            'order_id'        => ['order_id', false],
            'status_code'     => ['status_code', false],
            'created_at'      => ['created_at', true],  // true označava da je ovo podrazumevana kolona za sortiranje
        ];
    }

    /**
     * Definisanje bulk akcija
     */
    public function get_bulk_actions()
    {
        return [
            'print' => __('Štampaj nalepnice', 'd-express-woo'),
            'delete' => __('Obriši', 'd-express-woo'),
        ];
    }

    /**
     * Renderovanje checkbox kolone
     */
    public function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="shipment[]" value="%s" />',
            $item['id']
        );
    }

    /**
     * Renderovanje kolone za ID pošiljke
     */
    public function column_shipment_id($item)
    {
        // Definisanje akcija za pojedinačnu pošiljku
        $actions = [
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                admin_url(sprintf('admin.php?page=dexpress-view-shipment&id=%s', $item['id'])),
                __('Pregled', 'd-express-woo')
            ),
            'print' => sprintf(
                '<a href="%s" target="_blank">%s</a>',
                admin_url(sprintf(
                    'admin-ajax.php?action=dexpress_download_label&shipment_id=%s&nonce=%s',
                    $item['id'],
                    wp_create_nonce('dexpress-download-label')
                )),
                __('Štampaj', 'd-express-woo')
            ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
                admin_url(sprintf(
                    'admin.php?page=dexpress-shipments&action=delete&shipment=%s&_wpnonce=%s',
                    $item['id'],
                    wp_create_nonce('dexpress_delete_shipment')
                )),
                __('Da li ste sigurni da želite da obrišete ovu pošiljku?', 'd-express-woo'),
                __('Obriši', 'd-express-woo')
            ),
        ];

        // Vraćanje kolone sa akcijama
        return sprintf(
            '<strong><a href="%s">%s</a></strong> %s',
            admin_url(sprintf('admin.php?page=dexpress-view-shipment&id=%s', $item['id'])),
            $item['shipment_id'],
            $this->row_actions($actions)
        );
    }

    /**
     * Renderovanje kolone za tracking broj
     */
    public function column_tracking_number($item)
    {
        $is_test = (bool) $item['is_test'];

        if ($is_test) {
            return sprintf(
                '<span class="dexpress-tracking-number">%s</span> <span class="dexpress-test-label">%s</span>',
                $item['tracking_number'],
                __('(Test)', 'd-express-woo')
            );
        } else {
            return sprintf(
                '<a href="https://www.dexpress.rs/rs/pracenje-posiljaka/%s" target="_blank" class="dexpress-tracking-number">%s</a>',
                $item['tracking_number'],
                $item['tracking_number']
            );
        }
    }

    /**
     * Renderovanje kolone za narudžbinu
     */
    public function column_order_id($item)
    {
        $order = wc_get_order($item['order_id']);

        if ($order) {
            return sprintf(
                '<a href="%s">#%s</a> <span class="order-status order-status-%s">%s</span>',
                admin_url(sprintf('post.php?post=%s&action=edit', $item['order_id'])),
                $order->get_order_number(),
                $item['order_status'],
                wc_get_order_status_name($item['order_status'])
            );
        } else {
            return sprintf('#%s', $item['order_id']);
        }
    }

    /**
     * Renderovanje kolone za status
     */
    public function column_status_code($item)
    {
        $status_code = $item['status_code'];

        $status_name = dexpress_get_status_name($status_code);

        $status_classes = [
            '130' => 'dexpress-status-delivered',  // Isporučeno
            '131' => 'dexpress-status-failed',     // Neisporučeno
        ];

        $class = isset($status_classes[$status_code]) ? $status_classes[$status_code] : 'dexpress-status-transit';
        
        return sprintf(
            '<span class="dexpress-status-badge %s">%s</span>',
            $class,
            $status_name
        );
    }

    /**
     * Renderovanje kolone za datum kreiranja
     */
    public function column_created_at($item)
    {
        $date = new DateTime($item['created_at']);

        return sprintf(
            '<span>%s</span><br><span class="dexpress-time">%s</span>',
            $date->format(get_option('date_format')),
            $date->format(get_option('time_format'))
        );
    }

    /**
     * Renderovanje kolone za test
     */
    public function column_is_test($item)
    {
        return $item['is_test'] ? __('Da', 'd-express-woo') : __('Ne', 'd-express-woo');
    }

    /**
     * Podrazumevani prikaz za kolone koje nisu posebno definisane
     */
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'reference_id':
                return $item[$column_name];
            default:
                return print_r($item, true); // Prikazujemo ceo niz za debugging
        }
    }

    /**
     * Prikaz filtera iznad tabele
     */
    public function extra_tablenav($which)
    {
        if ($which === 'top') {
            global $wpdb;

            // Definisanje filtera za statuse
            $table_name = $wpdb->prefix . 'dexpress_statuses_index';
            $statuses = $wpdb->get_results("SELECT id, name FROM $table_name ORDER BY id");

            $current_status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : '';
?>
            <div class="alignleft actions">
                <select name="status">
                    <option value="all" <?php selected($current_status, 'all'); ?>><?php _e('Svi statusi', 'd-express-woo'); ?></option>
                    <?php foreach ($statuses as $status) : ?>
                        <option value="<?php echo esc_attr($status->id); ?>" <?php selected($current_status, $status->id); ?>>
                            <?php echo esc_html($status->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php submit_button(__('Filter', 'd-express-woo'), 'button', 'filter_action', false); ?>
            </div>
    <?php
        }
    }

    /**
     * Prikaz kada nema podataka
     */
    public function no_items()
    {
        _e('Nema pronađenih pošiljki.', 'd-express-woo');
    }
}

/**
 * Funkcija za kreiranje instance liste pošiljki
 */
function dexpress_shipments_list()
{
    $shipments_list = new D_Express_Shipments_List();
    $shipments_list->prepare_items();

    // Prikazujemo formu za pretragu
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php _e('D Express Pošiljke', 'd-express-woo'); ?></h1>
        <a href="<?php echo admin_url('admin.php?page=dexpress-sync-shipments'); ?>" class="page-title-action"><?php _e('Sinhronizuj statuse', 'd-express-woo'); ?></a>

        <form method="post">
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page']; ?>" />
            <?php $shipments_list->search_box(__('Pretraži pošiljke', 'd-express-woo'), 'dexpress_search'); ?>
            <?php $shipments_list->display(); ?>
        </form>
    </div>
<?php
}

/**
 * Obrada bulk akcija
 */
function dexpress_process_bulk_actions()
{
    $shipments_list = new D_Express_Shipments_List();

    // Provera trenutne akcije
    $action = $shipments_list->current_action();

    if (!$action) {
        return;
    }

    // Provera da li postoje odabrane pošiljke
    if (!isset($_POST['shipment']) || empty($_POST['shipment'])) {
        return;
    }

    // Obrada akcije "print"
    if ($action === 'print') {
        $shipment_ids = array_map('intval', $_POST['shipment']);

        // Kreiraj nonce
        $nonce = wp_create_nonce('dexpress-bulk-print');

        // Redirektuj na stranicu za štampanje
        wp_redirect(add_query_arg([
            'page'         => 'dexpress-print-labels',
            'shipment_ids' => implode(',', $shipment_ids),
            '_wpnonce'     => $nonce
        ], admin_url('admin.php')));
        exit;
    }

    // Obrada akcije "delete"
    if ($action === 'delete') {
        // Provera nonce-a
        check_admin_referer('bulk-' . $shipments_list->_args['plural']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_shipments';
        $shipment_ids = array_map('intval', $_POST['shipment']);

        foreach ($shipment_ids as $id) {
            $wpdb->delete($table_name, ['id' => $id], ['%d']);
        }

        // Redirektuj na istu stranicu sa porukom
        wp_redirect(add_query_arg([
            'page'    => 'dexpress-shipments',
            'deleted' => count($shipment_ids)
        ], admin_url('admin.php')));
        exit;
    }
}
add_action('load-woocommerce_page_dexpress-shipments', 'dexpress_process_bulk_actions');

/**
 * Obrada pojedinačnih akcija
 */
function dexpress_process_single_actions()
{
    // Provera da li je akcija "delete"
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['shipment'])) {
        // Provera nonce-a
        check_admin_referer('dexpress_delete_shipment');

        $shipment_id = intval($_GET['shipment']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'dexpress_shipments';

        $wpdb->delete($table_name, ['id' => $shipment_id], ['%d']);

        // Redirektuj na istu stranicu sa porukom
        wp_redirect(add_query_arg([
            'page'    => 'dexpress-shipments',
            'deleted' => 1
        ], admin_url('admin.php')));
        exit;
    }
}
add_action('admin_init', 'dexpress_process_single_actions');
