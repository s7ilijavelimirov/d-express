/**
 * D Express Locations JavaScript - Finalna verzija
 * File: assets/js/dexpress-locations.js
 */

jQuery(document).ready(function ($) {
    // Proveri da li je dexpressAdmin objekat dostupan
    if (typeof dexpressAdmin === 'undefined') {
        return;
    }

    // Proveri da li smo na sender tab-u
    if ($('#tab-sender').length === 0) {
        return;
    }

    // Flag za praćenje da li je modal aktivan
    let isModalActive = false;

    // Event listeneri
    initializeEventListeners();

    function initializeEventListeners() {
        // Spreči da form submit utiče na glavni form
        $(document).on('submit', '#dexpress-location-form', function (e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            saveLocation();
            return false;
        });

        // Otvori modal za dodavanje nove lokacije
        $(document).on('click', '#dexpress-add-location-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openLocationModal();
            return false;
        });

        // Save button u modalu
        $(document).on('click', '#dexpress-save-location', function (e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            saveLocation();
            return false;
        });

        // Zatvori modal
        $(document).on('click', '.dexpress-modal-close, #dexpress-cancel-location', function (e) {
            e.preventDefault();
            closeLocationModal();
        });

        // Zatvori modal klikom van njega
        $(document).on('click', '#dexpress-location-modal', function (e) {
            if (e.target === this) {
                closeLocationModal();
            }
        });

        // Uredi lokaciju
        $(document).on('click', '.dexpress-edit-location', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const locationId = $(this).data('location-id');
            editLocation(locationId);
            return false;
        });

        // Postavi kao glavnu
        $(document).on('click', '.dexpress-set-default', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const locationId = $(this).data('location-id');
            setAsDefault(locationId);
            return false;
        });

        // Obriši lokaciju
        $(document).on('click', '.dexpress-delete-location', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const locationId = $(this).data('location-id');
            deleteLocation(locationId);
            return false;
        });

        // Blokiraj sve event-e iz modal forme da ne idu u glavnu formu
        $(document).on('input change keyup keydown', '#dexpress-location-form', function (e) {
            e.stopPropagation();
            e.stopImmediatePropagation();
        });

        $(document).on('input change keyup keydown', '#dexpress-location-form input, #dexpress-location-form select, #dexpress-location-form textarea', function (e) {
            e.stopPropagation();
            e.stopImmediatePropagation();
        });
    }

    /**
     * Otvori modal za dodavanje/editovanje
     */
    function openLocationModal(locationData = null) {
        isModalActive = true;

        // Sačuvaj trenutno stanje glavne forme
        disableMainFormChangeDetection();

        const $form = $('#dexpress-location-form');
        if (!$form.length) {
            return;
        }

        // Reset form
        if ($form[0] && typeof $form[0].reset === 'function') {
            $form[0].reset();
        }

        $('#location-id').val('');

        if (locationData) {
            // Editovanje postojeće lokacije
            $('#dexpress-modal-title').text('Uredi lokaciju');
            $('#location-id').val(locationData.id);
            $('#location-name').val(locationData.name);
            $('#location-address').val(locationData.address);
            $('#location-address-num').val(locationData.address_num);
            $('#location-town').val(locationData.town_id);
            $('#location-contact-name').val(locationData.contact_name);
            $('#location-contact-phone').val(locationData.contact_phone);
            $('#location-bank-account').val(locationData.bank_account || '');
            $('#location-is-default').prop('checked', locationData.is_default == 1);
            $('#dexpress-save-location').text('Ažuriraj lokaciju');
        } else {
            // Dodavanje nove lokacije
            $('#dexpress-modal-title').text('Dodaj novu lokaciju');
            $('#dexpress-save-location').text('Sačuvaj lokaciju');
        }

        // Prikaži modal
        $('#dexpress-location-modal').addClass('active').show();
        $('body').addClass('modal-open');

        // Focus na prvi input
        setTimeout(function () {
            $('#location-name').focus();
        }, 100);
    }

    /**
     * Zatvori modal
     */
    function closeLocationModal() {
        const $modal = $('#dexpress-location-modal');
        const $form = $('#dexpress-location-form');

        isModalActive = false;

        if ($modal.length) {
            $modal.removeClass('active').hide();
        }

        $('body').removeClass('modal-open');

        // Safe form reset
        if ($form.length && $form[0] && typeof $form[0].reset === 'function') {
            $form[0].reset();
        }

        // Reaktiviraj glavnu formu
        enableMainFormChangeDetection();
    }

    /**
     * Sačuvaj lokaciju (kreiranje ili ažuriranje)
     */
    function saveLocation() {
        // Validacija
        const requiredFields = ['location-name', 'location-address', 'location-address-num', 'location-town', 'location-contact-name', 'location-contact-phone'];
        let isValid = true;

        requiredFields.forEach(function (fieldId) {
            const $field = $('#' + fieldId);
            if ($field.length && !$field.val().trim()) {
                $field.addClass('error');
                isValid = false;
            } else if ($field.length) {
                $field.removeClass('error');
            }
        });

        if (!isValid) {
            alert('Molimo popunite sva obavezna polja');
            return;
        }

        const locationId = $('#location-id').val();
        const action = locationId ? 'dexpress_update_location' : 'dexpress_create_location';

        const formData = {
            action: action,
            nonce: dexpressAdmin.nonce,
            name: $('#location-name').val().trim(),
            address: $('#location-address').val().trim(),
            address_num: $('#location-address-num').val().trim(),
            town_id: $('#location-town').val(),
            contact_name: $('#location-contact-name').val().trim(),
            contact_phone: $('#location-contact-phone').val().trim(),
            bank_account: $('#location-bank-account').val().trim(),
            is_default: $('#location-is-default').is(':checked') ? 1 : 0
        };

        if (locationId) {
            formData.location_id = locationId;
        }

        // Dodaj loading state na modal
        showModalLoading('Čuvanje...');

        $.ajax({
            url: dexpressAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            timeout: 30000,
            success: function (response) {
                if (response.success) {
                    // Promeni loading poruku
                    showModalLoading('Uspešno sačuvano! Osvežavanje stranice...');

                    // Ukloni sve change detection event-ove
                    removeAllChangeDetection();

                    // Kratka pauza da korisnik vidi poruku, pa zatim reload
                    setTimeout(function () {
                        $(window).off('beforeunload');
                        window.onbeforeunload = null;
                        window.location.reload();
                    }, 500);
                } else {
                    hideModalLoading();
                    const errorMessage = response.data || 'Nepoznata greška';
                    showNotice('error', 'Greška: ' + errorMessage);
                }
            },
            error: function (xhr, status, error) {
                hideModalLoading();
                let errorMessage = 'Greška pri komunikaciji sa serverom';

                if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMessage += ': ' + xhr.responseJSON.data;
                } else if (error) {
                    errorMessage += ': ' + error;
                }

                showNotice('error', errorMessage);
            }
        });
    }

    /**
     * Edituj lokaciju
     */
    function editLocation(locationId) {
        $.ajax({
            url: dexpressAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dexpress_get_location',
                location_id: locationId,
                nonce: dexpressAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    openLocationModal(response.data);
                } else {
                    showNotice('error', 'Greška pri učitavanju lokacije: ' + (response.data || 'Nepoznata greška'));
                }
            },
            error: function (xhr, status, error) {
                showNotice('error', 'Greška pri komunikaciji sa serverom: ' + error);
            }
        });
    }

    /**
     * Postavi kao glavnu lokaciju
     */
    function setAsDefault(locationId) {
        if (!confirm('Da li ste sigurni da želite da postavite ovu lokaciju kao glavnu?')) {
            return;
        }

        // Prikaži loading overlay na celoj stranici
        showPageLoading('Postavljanje kao glavna lokacija...');

        $.ajax({
            url: dexpressAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dexpress_set_default_location',
                location_id: locationId,
                nonce: dexpressAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    showPageLoading('Uspešno! Osvežavanje stranice...');

                    setTimeout(function () {
                        removeAllChangeDetection();
                        $(window).off('beforeunload');
                        window.onbeforeunload = null;
                        window.location.reload();
                    }, 500);
                } else {
                    hidePageLoading();
                    showNotice('error', 'Greška: ' + (response.data || 'Nepoznata greška'));
                }
            },
            error: function (xhr, status, error) {
                hidePageLoading();
                showNotice('error', 'Greška pri komunikaciji sa serverom: ' + error);
            }
        });
    }

    /**
     * Obriši lokaciju
     */
    function deleteLocation(locationId) {
        if (!confirm('Da li ste sigurni da želite da obrišete ovu lokaciju? Ova akcija se ne može opozvati.')) {
            return;
        }

        $.ajax({
            url: dexpressAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dexpress_delete_location',
                location_id: locationId,
                nonce: dexpressAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Ukloni red iz tabele sa animacijom
                    $('tr[data-location-id="' + locationId + '"]').fadeOut(500, function () {
                        $(this).remove();

                        // Proveri da li je tabela prazna
                        if ($('.dexpress-locations-list tbody tr').length === 0) {
                            $('.dexpress-locations-list').html('<p>Nema kreiranih lokacija.</p>');
                        }
                    });

                    showNotice('success', response.data.message || 'Lokacija je uspešno obrisana');
                } else {
                    showNotice('error', 'Greška: ' + (response.data || 'Nepoznata greška'));
                }
            },
            error: function (xhr, status, error) {
                showNotice('error', 'Greška pri komunikaciji sa serverom: ' + error);
            }
        });
    }

    /**
     * Onemogući change detection za glavnu formu
     */
    function disableMainFormChangeDetection() {
        // Ukloni WordPress autosave
        if (window.wp && window.wp.autosave) {
            window.wp.autosave.suspend();
        }

        // Ukloni beforeunload event-ove
        $(window).off('beforeunload.edit-post');
        $(window).off('beforeunload');

        // Ukloni WordPress form change detection
        $('.dexpress-settings-form').removeClass('changed');

        // Ukloni sve input change event-ove sa glavne forme
        $('.dexpress-settings-form').off('input.dirty-form change.dirty-form');

        // Override onbeforeunload
        window.onbeforeunload = null;
    }

    /**
     * Omogući change detection za glavnu formu
     */
    function enableMainFormChangeDetection() {
        // Ako nije modal aktivan, reaktiviraj
        if (!isModalActive && window.wp && window.wp.autosave) {
            window.wp.autosave.resume();
        }
    }

    /**
     * Ukloni SVE change detection event-ove
     */
    function removeAllChangeDetection() {
        // Ukloni sve WordPress event-ove
        $(window).off('beforeunload');
        $(document).off('input.dirty-form change.dirty-form');
        $('.dexpress-settings-form').off('input change');

        // Override sve onbeforeunload funkcije
        window.onbeforeunload = null;

        // Ukloni WordPress autosave
        if (window.wp && window.wp.autosave) {
            window.wp.autosave.suspend();
        }

        // Ukloni bilo koje dirty flagove
        $('.dexpress-settings-form').removeClass('changed dirty');
    }

    /**
     * Prikaži loading overlay na modal-u
     */
    function showModalLoading(message) {
        const loadingHtml = `
            <div id="dexpress-modal-loading" style="
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(255, 255, 255, 0.9);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 999999;
                flex-direction: column;
            ">
                <div class="spinner" style="
                    border: 4px solid #f3f3f3;
                    border-top: 4px solid #0073aa;
                    border-radius: 50%;
                    width: 40px;
                    height: 40px;
                    animation: spin 1s linear infinite;
                    margin-bottom: 15px;
                "></div>
                <div style="font-weight: 600; color: #0073aa;">${message}</div>
            </div>
            <style>
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            </style>
        `;

        // Ukloni postojeći loading
        $('#dexpress-modal-loading').remove();

        // Dodaj loading overlay u modal
        $('#dexpress-location-modal .dexpress-modal-content').css('position', 'relative').append(loadingHtml);
    }

    /**
     * Ukloni loading overlay sa modal-a
     */
    function hideModalLoading() {
        $('#dexpress-modal-loading').remove();
    }

    /**
     * Prikaži loading overlay na celoj stranici
     */
    function showPageLoading(message) {
        const loadingHtml = `
            <div id="dexpress-page-loading" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 999999;
                flex-direction: column;
            ">
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    text-align: center;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div class="spinner" style="
                        border: 4px solid #f3f3f3;
                        border-top: 4px solid #0073aa;
                        border-radius: 50%;
                        width: 40px;
                        height: 40px;
                        animation: spin 1s linear infinite;
                        margin: 0 auto 15px;
                    "></div>
                    <div style="font-weight: 600; color: #333; font-size: 16px;">${message}</div>
                </div>
            </div>
        `;

        // Ukloni postojeći loading
        $('#dexpress-page-loading').remove();

        // Dodaj loading overlay na body
        $('body').append(loadingHtml);
    }

    /**
     * Ukloni loading overlay sa stranice
     */
    function hidePageLoading() {
        $('#dexpress-page-loading').remove();
    }

    /**
     * Prikaži notice poruku
     */
    function showNotice(type, message) {
        const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        const noticeHtml = `
            <div class="notice ${noticeClass} is-dismissible">
                <p>${message}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </div>
        `;

        // Ukloni postojeće notice poruke
        $('.notice.is-dismissible').remove();

        // Dodaj novu notice poruku
        $('.wrap h1').after(noticeHtml);

        // Dodaj funkcionalnost dismiss button-a
        $('.notice-dismiss').on('click', function () {
            $(this).parent().fadeOut();
        });

        // Auto-hide success poruke nakon 3 sekundi
        if (type === 'success') {
            setTimeout(function () {
                $('.notice-success').fadeOut();
            }, 3000);
        }
    }
});