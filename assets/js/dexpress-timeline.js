jQuery(document).ready(function ($) {
    'use strict';

    // Refresh status functionality - SAMO OVO OSTAVI
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