<?php
defined('ABSPATH') || exit;
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('D Express Izveštaji', 'd-express-woo'); ?></h1>

    <div class="dexpress-reports-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="dexpress-reports">

            <div class="dexpress-date-range">
                <label for="date-range"><?php _e('Period:', 'd-express-woo'); ?></label>
                <input type="text" id="date-range" name="date_range" class="regular-text" />
            </div>

            <div class="dexpress-status-filter">
                <label for="status"><?php _e('Status:', 'd-express-woo'); ?></label>
                <select id="status" name="status">
                    <option value=""><?php _e('Svi statusi', 'd-express-woo'); ?></option>
                    <?php
                    // Dohvatanje svih statusa
                    global $wpdb;
                    $statuses = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}dexpress_statuses_index ORDER BY id");

                    foreach ($statuses as $status) {
                        echo '<option value="' . esc_attr($status->id) . '">' . esc_html($status->name) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <button type="submit" class="button button-primary"><?php _e('Filtriraj', 'd-express-woo'); ?></button>

            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('export' => 'csv'), admin_url('admin.php?page=dexpress-reports')), 'dexpress_export_csv')); ?>" class="button button-secondary"><?php _e('Izvezi CSV', 'd-express-woo'); ?></a>
        </form>
    </div>

    <div class="dexpress-reports-grid">
        <div class="dexpress-report-card">
            <h2><?php _e('Pošiljke po statusima', 'd-express-woo'); ?></h2>
            <canvas id="statusChart" height="250"></canvas>
        </div>

        <div class="dexpress-report-card">
            <h2><?php _e('Pošiljke po datumima', 'd-express-woo'); ?></h2>
            <canvas id="dateChart" height="250"></canvas>
        </div>
    </div>

    <div class="dexpress-reports-summary">
        <h2><?php _e('Sumarni podaci', 'd-express-woo'); ?></h2>

        <table class="widefat">
            <thead>
                <tr>
                    <th><?php _e('Status', 'd-express-woo'); ?></th>
                    <th><?php _e('Broj pošiljki', 'd-express-woo'); ?></th>
                    <th><?php _e('Procenat', 'd-express-woo'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $total_shipments = 0;
                $status_data = array();

                foreach ($status_stats as $stat) {
                    $total_shipments += $stat->count;
                    $status_data[$stat->status_code] = $stat->count;
                }

                if ($total_shipments > 0) {
                    foreach ($status_stats as $stat) {
                        $percentage = round(($stat->count / $total_shipments) * 100, 2);
                        echo '<tr>';
                        echo '<td>' . dexpress_get_status_name($stat->status_code) . '</td>';
                        echo '<td>' . $stat->count . '</td>';
                        echo '<td>' . $percentage . '%</td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr><td colspan="3">' . __('Nema podataka', 'd-express-woo') . '</td></tr>';
                }
                ?>
            </tbody>
            <tfoot>
                <tr>
                    <th><?php _e('Ukupno', 'd-express-woo'); ?></th>
                    <th><?php echo $total_shipments; ?></th>
                    <th>100%</th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<style>
    /* Dodajte ovo u vaš <style> tag */
    .dexpress-report-card {
        height: 350px !important;
        max-height: 350px !important;
        overflow: hidden !important;
    }

    .dexpress-report-card canvas,
    canvas.chartjs-render-monitor {
        height: 280px !important;
        max-height: 280px !important;
        min-height: 280px !important;
    }

    /* Specificiranje globalnih stilova za Chart.js */
    canvas {
        height: 280px !important;
    }

    .dexpress-reports-filters {
        margin: 20px 0;
        padding: 15px;
        background: #fff;
        border: 1px solid #e5e5e5;
        box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
    }

    .dexpress-reports-filters form {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .dexpress-date-range,
    .dexpress-status-filter {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .dexpress-reports-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 20px;
        margin: 20px 0;
    }

    .dexpress-report-card {
        background: #fff;
        border: 1px solid #e5e5e5;
        box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
        padding: 20px;
        position: relative;
        min-height: 350px;
        /* Fiksirajte minimalnu visinu */
        display: flex;
        flex-direction: column;
    }

    .dexpress-report-card h2 {
        margin-top: 0;
        margin-bottom: 15px;
    }

    .dexpress-report-card canvas {
        flex: 1;
        width: 100% !important;
        height: 280px !important;
        /* Fiksirajte visinu canvasa */
    }

    /* Poboljšanja za mobilni prikaz */
    @media screen and (max-width: 782px) {
        .dexpress-reports-grid {
            grid-template-columns: 1fr;
        }

        .dexpress-report-card {
            min-height: 300px;
        }
    }
</style>

<script type="text/javascript">
    jQuery(document).ready(function($) {
        // Inicijalizacija DateRangePicker-a
        $('#date-range').daterangepicker({
            startDate: moment().subtract(29, 'days'),
            endDate: moment(),
            ranges: {
                [dexpressReports.i18n.today]: [moment(), moment()],
                [dexpressReports.i18n.yesterday]: [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                [dexpressReports.i18n.last7Days]: [moment().subtract(6, 'days'), moment()],
                [dexpressReports.i18n.last30Days]: [moment().subtract(29, 'days'), moment()],
                [dexpressReports.i18n.thisMonth]: [moment().startOf('month'), moment().endOf('month')],
                [dexpressReports.i18n.lastMonth]: [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
            },
            locale: {
                applyLabel: dexpressReports.i18n.apply,
                cancelLabel: dexpressReports.i18n.cancel,
                customRangeLabel: dexpressReports.i18n.customRange
            }
        });

        // Inicijalizacija grafikona za statuse
        var statusCtx = document.getElementById('statusChart').getContext('2d');
        var statusChart = new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: [
                    <?php
                    foreach ($status_stats as $stat) {
                        echo "'" . esc_js(dexpress_get_status_name($stat->status_code)) . "',";
                    }
                    ?>
                ],
                datasets: [{
                    data: [
                        <?php
                        foreach ($status_stats as $stat) {
                            echo $stat->count . ',';
                        }
                        ?>
                    ],
                    backgroundColor: [
                        '#4e73df',
                        '#1cc88a',
                        '#36b9cc',
                        '#f6c23e',
                        '#e74a3b',
                        '#858796'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Ovo je važno!
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });

        // Inicijalizacija grafikona za datume
        var dateCtx = document.getElementById('dateChart').getContext('2d');
        var dateChart = new Chart(dateCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dates); ?>,
                datasets: [{
                    label: '<?php _e('Broj pošiljki', 'd-express-woo'); ?>',
                    data: <?php echo json_encode($counts); ?>,
                    backgroundColor: 'rgba(78, 115, 223, 0.05)',
                    borderColor: 'rgba(78, 115, 223, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: 'rgba(78, 115, 223, 1)',
                    pointBorderColor: '#fff',
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Ovo je važno!
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            drawBorder: false
                        },
                        ticks: {
                            precision: 0
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    });
</script>