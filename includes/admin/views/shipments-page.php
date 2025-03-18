<?php

/**
 * D Express Shipments Page
 * 
 * Template za stranicu sa pošiljkama u admin panelu
 */

defined('ABSPATH') || exit;

// Prikaz poruka o uspešnim akcijama
if (isset($_GET['deleted']) && !empty($_GET['deleted'])) {
    $count = intval($_GET['deleted']);
    $message = sprintf(
        _n(
            'Pošiljka je uspešno obrisana.',
            '%d pošiljki je uspešno obrisano.',
            $count,
            'd-express-woo'
        ),
        $count
    );

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
}

if (isset($_GET['synced']) && !empty($_GET['synced'])) {
    $count = intval($_GET['synced']);
    $message = sprintf(
        _n(
            'Status %d pošiljke je uspešno ažuriran.',
            'Statusi %d pošiljki su uspešno ažurirani.',
            $count,
            'd-express-woo'
        ),
        $count
    );

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
}

// Kreiranje i prikaz liste pošiljki
$shipments_list = new D_Express_Shipments_List();
$shipments_list->prepare_items();
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('D Express Pošiljke', 'd-express-woo'); ?></h1>

    <a href="<?php echo admin_url('admin.php?page=dexpress-sync-shipments'); ?>" class="page-title-action"><?php _e('Sinhronizuj statuse', 'd-express-woo'); ?></a>

    <hr class="wp-header-end">

    <form id="dexpress-shipments-filter" method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />

        <?php $shipments_list->search_box(__('Pretraži pošiljke', 'd-express-woo'), 'dexpress-search'); ?>
        <?php $shipments_list->display(); ?>
    </form>
</div>

<script type="text/javascript">
    jQuery(document).ready(function($) {
        // Bulk print akcija
        $('.dexpress-print-bulk').on('click', function(e) {
            e.preventDefault();

            var selectedShipments = [];
            $('input[name="shipment[]"]:checked').each(function() {
                selectedShipments.push($(this).val());
            });

            if (selectedShipments.length === 0) {
                alert('<?php _e('Molimo odaberite pošiljke za štampanje.', 'd-express-woo'); ?>');
                return;
            }

            var printUrl = '<?php echo admin_url('admin.php?page=dexpress-print-labels&shipment_ids='); ?>' + selectedShipments.join(',') + '&_wpnonce=<?php echo wp_create_nonce('dexpress-bulk-print'); ?>';
            window.open(printUrl, '_blank');
        });

        // Bulk delete akcija
        $('.dexpress-delete-bulk').on('click', function(e) {
            e.preventDefault();

            var selectedShipments = [];
            $('input[name="shipment[]"]:checked').each(function() {
                selectedShipments.push($(this).val());
            });

            if (selectedShipments.length === 0) {
                alert('<?php _e('Molimo odaberite pošiljke za brisanje.', 'd-express-woo'); ?>');
                return;
            }

            if (confirm('<?php _e('Da li ste sigurni da želite da obrišete odabrane pošiljke?', 'd-express-woo'); ?>')) {
                $('#dexpress-shipments-filter').append('<input type="hidden" name="action" value="delete" />');
                $('#dexpress-shipments-filter').submit();
            }
        });
    });
</script>