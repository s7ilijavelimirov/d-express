jQuery(document).ready(function($) {
    'use strict';
    
    $('.dexpress-refresh-status').on('click', function(e) {
        e.preventDefault(); // Sprečavamo podrazumevano ponašanje
        
        var button = $(this);
        var shipmentId = button.data('shipment-id'); // Ispravljeno - koristi shipment-id umesto id
        var originalText = button.html();
        
        // Vizuelni feedback
        button.html('<span class="dashicons dashicons-update-alt rotating"></span> ' + dexpressTimelineL10n.refreshing);
        button.prop('disabled', true);
        button.addClass('disabled');
        
        // AJAX poziv
        $.ajax({
            url: dexpressTimelineL10n.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'dexpress_refresh_shipment_status', // Proveri da li ovo odgovara PHP hooku
                shipment_id: shipmentId,
                nonce: dexpressTimelineL10n.nonce // Koristi nonce iz lokalizovanih podataka
            },
            success: function(response) {
                if (response.success) {
                    // Prikazujemo poruku o uspehu
                    showNotification('success', response.data.message || dexpressTimelineL10n.successMessage);
                    
                    // Osvežavanje stranice nakon uspešnog ažuriranja
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    // Prikazivanje greške
                    button.html(originalText);
                    button.prop('disabled', false);
                    button.removeClass('disabled');
                    
                    showNotification('error', response.data.message || dexpressTimelineL10n.errorMessage);
                    
                    console.error('AJAX Error:', response);
                }
            },
            error: function(xhr, status, error) {
                // Prikazivanje greške
                button.html(originalText);
                button.prop('disabled', false);
                button.removeClass('disabled');
                
                showNotification('error', dexpressTimelineL10n.errorMessage);
                
                console.error('AJAX Error:', xhr.responseText, status, error);
            }
        });
    });
    
    // Dodajemo CSS animaciju za spinner
    $('<style>').text(`
        .dashicons.rotating {
            animation: dashicons-spin 1s infinite linear;
        }
        @keyframes dashicons-spin {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }
    `).appendTo('head');
    
    // Funkcija za prikazivanje notifikacija
    function showNotification(type, message) {
        var noticeClass = (type === 'error') ? 'notice-error' : 'notice-success';
        var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p><button type="button" class="notice-dismiss"></button></div>');
        
        // Dodaj u DOM
        $('.dexpress-timeline-container').before(notice);
        
        // Funkcionalnost za zatvaranje obaveštenja
        notice.find('.notice-dismiss').on('click', function() {
            notice.fadeOut(400, function() {
                $(this).remove();
            });
        });
        
        // Automatsko sklanjanje nakon 5 sekundi
        setTimeout(function() {
            notice.fadeOut(400, function() {
                $(this).remove();
            });
        }, 5000);
    }
});