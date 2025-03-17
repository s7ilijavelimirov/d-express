/**
 * D Express Checkout JavaScript
 */
(function ($) {
    'use strict';

    var DExpressCheckout = {
        init: function () {
            // Inicijalizacija autocomplete za ulice
            this.initStreetAutocomplete('billing');
            this.initStreetAutocomplete('shipping');

            // Praćenje promene metode dostave
            this.watchShippingMethod();

            // Praćenje "Dostavi na drugu adresu"
            $('#ship-to-different-address-checkbox').on('change', function () {
                setTimeout(function () {
                    DExpressCheckout.initStreetAutocomplete('shipping');
                }, 300);
            });
        },

        // Inicijalizacija autocomplete za ulicu
        initStreetAutocomplete: function (addressType) {
            var self = this;
            var $street = $('#' + addressType + '_street');
            var $streetId = $('#' + addressType + '_street_id');
            var $number = $('#' + addressType + '_number');
            var $city = $('#' + addressType + '_city');
            var $cityId = $('#' + addressType + '_city_id');
            var $postcode = $('#' + addressType + '_postcode');
            var $address1 = $('#' + addressType + '_address_1');

            // Inicijalizacija jQuery UI autocomplete
            if ($street.length) {
                // Inicijalizuj autocomplete za ulicu
                // U funkciji initStreetAutocomplete, modifikuj autocomplete deo:

                $street.autocomplete({
                    source: function (request, response) {
                        $.ajax({
                            url: dexpressCheckout.ajaxUrl,
                            dataType: 'json',
                            data: {
                                action: 'dexpress_search_streets',
                                term: request.term
                            },
                            success: function (data) {
                                response(data);
                            }
                        });
                    },
                    minLength: 2,
                    select: function (event, ui) {
                        // Postavi ID ulice
                        $streetId.val(ui.item.id);
                        // Postavi samo ime ulice (bez grada) u polje
                        $street.val(ui.item.value);

                        // Učitaj gradove za izabranu ulicu
                        self.loadCitiesForStreet(addressType, ui.item.id);

                        // Fokusiraj polje za kućni broj
                        setTimeout(function () {
                            $number.focus();
                        }, 100);

                        return false;
                    }
                }).autocomplete("instance")._renderItem = function (ul, item) {
                    // Prilagođeni rendering za prikazivanje ulice sa gradom
                    return $("<li>")
                        .append("<div>" + item.label + "</div>")
                        .appendTo(ul);
                };

                // Prati promene u polju za ulicu
                $street.on('input', function () {
                    // Ako je korisnik obrisao ili promenio ulicu, resetuj vrednosti
                    if ($street.val() === '') {
                        $streetId.val('');
                        self.resetCityField(addressType);
                    }
                });

                // Prati promene u broju ulice i ažuriraj adresu
                $number.on('input', function () {
                    self.updateAddressField(addressType);
                });
            }

            // Inicijalizuj select2 za grad
            if ($city.length) {
                $city.select2({
                    placeholder: dexpressCheckout.i18n.selectCity,
                    allowClear: true,
                    width: '100%'
                });

                // Prati promene u polju za grad
                $city.on('change', function () {
                    var selectedId = $(this).val();

                    // Postavi ID grada
                    $cityId.val(selectedId);

                    // Učitaj podatke o gradu ako je izabran
                    if (selectedId && selectedId !== 'other') {
                        self.loadCityData(addressType, selectedId);
                    } else if (selectedId === 'other') {
                        self.showOtherCityModal(addressType);
                    }

                    // Ažuriraj adresu
                    self.updateAddressField(addressType);
                });
            }
        },

        // Učitavanje gradova za izabranu ulicu
        loadCitiesForStreet: function (addressType, streetId) {
            var self = this;
            var $city = $('#' + addressType + '_city');

            // Prvo resetuj polje za grad
            this.resetCityField(addressType);

            // Postavi status učitavanja
            $city.html('<option value="">' + dexpressCheckout.i18n.firstEnterStreet + '</option>');
            $city.prop('disabled', true);

            // Učitaj gradove za izabranu ulicu
            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                dataType: 'json',
                data: {
                    action: 'dexpress_get_towns_for_street',
                    street_id: streetId
                },
                success: function (response) {
                    if (response.success && response.data.results) {
                        // Resetuj select
                        $city.empty();
                        $city.append('<option value="">' + dexpressCheckout.i18n.selectCity + '</option>');

                        // Dodaj opcije za gradove
                        $.each(response.data.results, function (i, city) {
                            $city.append('<option value="' + city.id + '" data-postcode="' + (city.postal_code || '') + '">' + city.text + '</option>');
                        });

                        // Omogući select
                        $city.prop('disabled', false);

                        // Reinicijalizuj Select2
                        if ($city.data('select2')) {
                            $city.select2('destroy');
                        }
                        $city.select2({
                            placeholder: dexpressCheckout.i18n.selectCity,
                            allowClear: true,
                            width: '100%'
                        });
                    }
                },
                error: function () {
                    self.resetCityField(addressType);
                }
            });
        },

        // Učitavanje podataka o gradu
        loadCityData: function (addressType, cityId) {
            var $postcode = $('#' + addressType + '_postcode');

            // Dobavi poštanski broj iz atributa
            var postcode = $('#' + addressType + '_city option[value="' + cityId + '"]').data('postcode') || '';

            // Postavi poštanski broj
            $postcode.val(postcode);
        },

        // Prikazivanje modala za izbor drugih gradova
        showOtherCityModal: function (addressType) {
            // Ovde bi išla implementacija modala za izbor grada koji nije na listi
            // Za jednostavnost u ovoj verziji ćemo samo resetovati polje
            alert(dexpressCheckout.i18n.otherCity);
            this.resetCityField(addressType);
        },

        // Resetovanje polja za grad
        resetCityField: function (addressType) {
            var $city = $('#' + addressType + '_city');
            var $cityId = $('#' + addressType + '_city_id');
            var $postcode = $('#' + addressType + '_postcode');

            // Resetuj polje za grad
            $city.empty().append('<option value="">' + dexpressCheckout.i18n.firstEnterStreet + '</option>');
            $cityId.val('');
            $postcode.val('');

            // Reinicijalizuj Select2
            if ($city.data('select2')) {
                $city.select2('destroy');
            }
            $city.select2({
                placeholder: dexpressCheckout.i18n.firstEnterStreet,
                allowClear: true,
                width: '100%'
            });

            // Onemogući polje
            $city.prop('disabled', true);
        },

        // Ažuriranje standardnog address_1 polja
        updateAddressField: function (addressType) {
            var $street = $('#' + addressType + '_street');
            var $number = $('#' + addressType + '_number');
            var $address1 = $('#' + addressType + '_address_1');

            // Kombinuj ulicu i broj
            var street = $street.val() || '';
            var number = $number.val() || '';

            if (street && number) {
                $address1.val(street + ' ' + number);
            } else {
                $address1.val(street);
            }
        },

        // Praćenje promene metode dostave
        watchShippingMethod: function () {
            var self = this;

            // Na svaku promenu checkout-a
            $(document.body).on('updated_checkout', function () {
                // Proveri da li je D Express dostava odabrana
                var isDExpressSelected = false;

                $('input[name^="shipping_method"]').each(function () {
                    if ($(this).is(':checked') && $(this).val().indexOf('dexpress') !== -1) {
                        isDExpressSelected = true;
                        return false; // break
                    }
                });

                // Prikaži/sakrij D Express dodatne opcije
                if (isDExpressSelected) {
                    $('.dexpress-options').show();
                } else {
                    $('.dexpress-options').hide();
                }

                // Reinicijalizuj autocomplete i select2
                self.initStreetAutocomplete('billing');

                if ($('#ship-to-different-address-checkbox').is(':checked')) {
                    self.initStreetAutocomplete('shipping');
                }
            });
        }
    };

    // Inicijalizacija na učitavanje DOM-a
    $(document).ready(function () {
        DExpressCheckout.init();
    });

})(jQuery);