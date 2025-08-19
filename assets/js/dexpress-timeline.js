jQuery(document).ready(function ($) {
    'use strict';

    // Refresh status functionality
    $('.dexpress-refresh-status').on('click', function (e) {
        e.preventDefault();

        var button = $(this);
        var shipmentId = button.data('shipment-id');
        var originalText = button.html();

        button.html('<span class="dashicons dashicons-update-alt rotating"></span> Osvežavanje...');
        button.prop('disabled', true);
        button.addClass('disabled');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'dexpress_refresh_shipment_status',
                shipment_id: shipmentId,
                nonce: button.data('nonce')
            },
            success: function (response) {
                if (response.success) {
                    showNotification('success', response.data.message || 'Status uspešno ažuriran.');
                    setTimeout(function () {
                        location.reload();
                    }, 1500);
                } else {
                    button.html(originalText);
                    button.prop('disabled', false);
                    button.removeClass('disabled');
                    showNotification('error', response.data.message || 'Došlo je do greške.');
                }
            },
            error: function () {
                button.html(originalText);
                button.prop('disabled', false);
                button.removeClass('disabled');
                showNotification('error', 'Greška u komunikaciji sa serverom');
            }
        });
    });

    // Label download handlers
    $('.dexpress-get-single-label').on('click', function (e) {
        e.preventDefault();
        var shipmentId = $(this).data('shipment-id');
        var nonce = 'f326d5e081';

        if (!shipmentId) {
            alert('Greška: Nije definisan ID pošiljke');
            return;
        }

        var url = ajaxurl + '?action=dexpress_download_label&shipment_id=' + shipmentId + '&nonce=' + nonce;
        window.open(url, '_blank');
    });

    $('.dexpress-bulk-download-labels').on('click', function (e) {
        e.preventDefault();
        var shipmentIds = $(this).data('shipment-ids');
        var nonce = 'fd0aa7aa93';

        if (!shipmentIds) {
            alert('Greška: Nisu definisani ID-jevi pošiljki');
            return;
        }

        var url = ajaxurl + '?action=dexpress_bulk_print_labels&shipment_ids=' + shipmentIds + '&_wpnonce=' + nonce;
        window.open(url, '_blank');
    });

    // Spinner animation
    $('<style>').text(`
        .dashicons.rotating {
            animation: dashicons-spin 1s infinite linear;
        }
        @keyframes dashicons-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    `).appendTo('head');

    // Notification function
    function showNotification(type, message) {
        var noticeClass = (type === 'error') ? 'notice-error' : 'notice-success';
        var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p><button type="button" class="notice-dismiss"></button></div>');

        if ($('.dexpress-multiple-shipments-container').length) {
            $('.dexpress-multiple-shipments-container').before(notice);
        } else {
            $('.dexpress-order-metabox').prepend(notice);
        }

        notice.find('.notice-dismiss').on('click', function () {
            notice.fadeOut(400, function () {
                $(this).remove();
            });
        });

        setTimeout(function () {
            notice.fadeOut(400, function () {
                $(this).remove();
            });
        }, 5000);
    }
});