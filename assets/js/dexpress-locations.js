/**
 * D Express Locations JavaScript
 * File: assets/js/dexpress-locations.js
 */

jQuery(document).ready(function ($) {
    console.log('DExpress Locations script loaded');

    // Spreči da form submit utiče na glavni form
    $('#dexpress-location-form').on('submit', function (e) {
        e.preventDefault();
        e.stopPropagation();
        console.log('Location form submitted');
        saveLocation();
        return false;
    });

    // Otvori modal za dodavanje nove lokacije
    $('#dexpress-add-location-btn').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        console.log('Add location clicked');
        openLocationModal();
        return false;
    });

    // Zatvori modal
    $(document).on('click', '.dexpress-modal-close, #dexpress-cancel-location', function (e) {
        e.preventDefault();
        closeLocationModal();
    });

    // Zatvori modal klikom van njega
    $('#dexpress-location-modal').on('click', function (e) {
        if (e.target === this) {
            closeLocationModal();
        }
    });

    // Uredi lokaciju
    $(document).on('click', '.dexpress-edit-location', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const locationId = $(this).data('location-id');
        console.log('Edit location:', locationId);
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

    /**
     * Otvori modal za dodavanje/editovanje
     */
    function openLocationModal(locationData = null) {
        console.log('Opening modal with data:', locationData);

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
            clearForm();
            $('#dexpress-save-location').text('Sačuvaj lokaciju');
        }

        $('#dexpress-location-modal').addClass('show').show();
    }

    /**
     * Očisti formu
     */
    function clearForm() {
        $('#location-id').val('');
        $('#location-name').val('');
        $('#location-address').val('');
        $('#location-address-num').val('');
        $('#location-town').val('');
        $('#location-contact-name').val('');
        $('#location-contact-phone').val('');
        $('#location-bank-account').val('');
        $('#location-is-default').prop('checked', false);
    }

    /**
     * Zatvori modal
     */
    function closeLocationModal() {
     $('#dexpress-location-modal').removeClass('show').hide();
        clearForm();
    }

    /**
     * Sačuvaj/ažuriraj lokaciju
     */
    function saveLocation() {
        const locationId = $('#location-id').val();
        const action = locationId ? 'update_location' : 'create_location';

        // Sakupi podatke
        var formData = {
            action: 'dexpress_' + action,
            nonce: dexpressAdmin.nonce,
            name: $('#location-name').val(),
            address: $('#location-address').val(),
            address_num: $('#location-address-num').val(),
            town_id: $('#location-town').val(),
            contact_name: $('#location-contact-name').val(),
            contact_phone: $('#location-contact-phone').val(),
            bank_account: $('#location-bank-account').val(),
            is_default: $('#location-is-default').is(':checked') ? 1 : 0
        };

        if (locationId) {
            formData.location_id = locationId;
        }

        console.log('Sending data:', formData);

        // Dodaj loading state
        $('#dexpress-save-location').prop('disabled', true).text('Čuvanje...');

        $.ajax({
            url: dexpressAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function (response) {
                console.log('AJAX response:', response);

                if (response.success) {
                    closeLocationModal();

                    // Refresh samo tab, ne celu stranicu
                    setTimeout(function () {
                        window.location.href = window.location.href;
                    }, 1000);
                } else {
                    alert('Greška: ' + (response.data || 'Nepoznata greška'));
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', xhr, status, error);
                alert('Greška pri komunikaciji sa serverom: ' + error);
            },
            complete: function () {
                $('#dexpress-save-location').prop('disabled', false);
                const locationId = $('#location-id').val();
                $('#dexpress-save-location').text(locationId ? 'Ažuriraj lokaciju' : 'Sačuvaj lokaciju');
            }
        });
    }

    /**
     * Edituj lokaciju
     */
    function editLocation(locationId) {
        console.log('Editing location:', locationId);

        $.ajax({
            url: dexpressAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dexpress_get_location',
                location_id: locationId,
                nonce: dexpressAdmin.nonce
            },
            success: function (response) {
                console.log('Get location response:', response);

                if (response.success) {
                    openLocationModal(response.data);
                } else {
                    alert('Greška pri učitavanju lokacije: ' + (response.data || 'Nepoznata greška'));
                }
            },
            error: function (xhr, status, error) {
                console.error('Get location error:', xhr, status, error);
                alert('Greška pri komunikaciji sa serverom: ' + error);
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
                    window.location.href = window.location.href;
                } else {
                    alert('Greška: ' + (response.data || 'Nepoznata greška'));
                }
            },
            error: function (xhr, status, error) {
                alert('Greška pri komunikaciji sa serverom: ' + error);
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
                    $('tr[data-location-id="' + locationId + '"]').fadeOut(function () {
                        $(this).remove();
                    });
                } else {
                    alert('Greška: ' + (response.data || 'Nepoznata greška'));
                }
            },
            error: function (xhr, status, error) {
                alert('Greška pri komunikaciji sa serverom: ' + error);
            }
        });
    }
});