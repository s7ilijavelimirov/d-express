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

            // Inicijalizacija načina dostave (adresa ili paketomat)
            this.initDeliveryTypeSelection();

            // Provera validacije pre slanja forme
            this.initCheckoutValidation();
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
                $street.autocomplete({
                    source: function (request, response) {
                        if (request.term.length < 2) {
                            return;
                        }

                        $.ajax({
                            url: dexpressCheckout.ajaxUrl,
                            dataType: 'json',
                            data: {
                                action: 'dexpress_search_streets',
                                term: request.term,
                                nonce: dexpressCheckout.nonce
                            },
                            beforeSend: function () {
                                $street.addClass('dexpress-loading');
                            },
                            success: function (data) {
                                $street.removeClass('dexpress-loading');
                                if (data.length === 0) {
                                    response([{
                                        label: dexpressCheckout.i18n.noResults,
                                        value: request.term,
                                        id: 'custom'
                                    }]);
                                } else {
                                    response(data);
                                }
                            },
                            error: function () {
                                $street.removeClass('dexpress-loading');
                                response([{
                                    label: dexpressCheckout.i18n.noResults,
                                    value: request.term,
                                    id: 'custom'
                                }]);
                            }
                        });
                    },
                    minLength: 2,
                    delay: 500,
                    select: function (event, ui) {
                        // Postavi ID ulice
                        $streetId.val(ui.item.id);

                        // Ako je korisnički unos
                        if (ui.item.id === 'custom') {
                            // Samo sačuvaj unesenu vrednost
                            $street.val(ui.item.value);

                            // Prikaži modal za odabir grada
                            self.showAllCitiesModal(addressType);
                        } else {
                            // Postavi samo ime ulice (bez grada) u polje
                            $street.val(ui.item.value);

                            // Učitaj gradove za izabranu ulicu
                            self.loadCitiesForStreet(addressType, ui.item.id);
                        }

                        // Fokusiraj polje za kućni broj
                        setTimeout(function () {
                            $number.focus();
                        }, 100);

                        return false;
                    }
                }).autocomplete("instance")._renderItem = function (ul, item) {
                    // Prilagođeni rendering za prikazivanje ulice sa gradom
                    var $item = $("<li>").append("<div>" + item.label + "</div>");

                    // Dodaj klasu za stavku 'Nema rezultata'
                    if (item.id === 'custom') {
                        $item.addClass('ui-state-disabled');
                    }

                    return $item.appendTo(ul);
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

                    // Validacija kućnog broja - ne sme sadržati razmake
                    var value = $(this).val();
                    if (value.indexOf(' ') !== -1) {
                        $(this).addClass('dexpress-error');
                        if (!$(this).next('.dexpress-error-message').length) {
                            $('<div class="dexpress-error-message">' + dexpressCheckout.i18n.numberNoSpaces + '</div>').insertAfter($(this));
                        }
                    } else {
                        $(this).removeClass('dexpress-error');
                        $(this).next('.dexpress-error-message').remove();
                    }
                });
            }

            // Inicijalizuj select2 za grad
            if ($city.length) {
                $city.select2({
                    placeholder: dexpressCheckout.i18n.selectCity,
                    allowClear: true,
                    width: '100%',
                    language: {
                        noResults: function () {
                            return dexpressCheckout.i18n.noResults;
                        },
                        searching: function () {
                            return dexpressCheckout.i18n.searching;
                        }
                    }
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
                        self.showAllCitiesModal(addressType);
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

            // Dodaj indikator učitavanja
            var $wrapper = $city.parent();
            $wrapper.addClass('dexpress-loading');

            // Učitaj gradove za izabranu ulicu
            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                dataType: 'json',
                data: {
                    action: 'dexpress_get_towns_for_street',
                    street_id: streetId,
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    $wrapper.removeClass('dexpress-loading');

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
                            width: '100%',
                            language: {
                                noResults: function () {
                                    return dexpressCheckout.i18n.noResults;
                                },
                                searching: function () {
                                    return dexpressCheckout.i18n.searching;
                                }
                            }
                        });
                    } else {
                        self.resetCityField(addressType);
                    }
                },
                error: function () {
                    $wrapper.removeClass('dexpress-loading');
                    self.resetCityField(addressType);
                }
            });
        },

        // Učitavanje svih gradova (za modal)
        loadAllCities: function (callback) {
            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                dataType: 'json',
                data: {
                    action: 'dexpress_get_all_towns',
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    if (response.success && response.data.results) {
                        callback(response.data.results);
                    } else {
                        callback([]);
                    }
                },
                error: function () {
                    callback([]);
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
        showAllCitiesModal: function (addressType) {
            var self = this;
            var $cityId = $('#' + addressType + '_city_id');
            var $city = $('#' + addressType + '_city');
            var $postcode = $('#' + addressType + '_postcode');

            // Prvo proveriti da li već postoji modal
            var $modal = $('#dexpress-city-modal');
            if ($modal.length === 0) {
                // Kreiranje modala ako ne postoji
                $modal = $('<div id="dexpress-city-modal" class="dexpress-modal">' +
                    '<div class="dexpress-modal-content">' +
                    '<span class="dexpress-modal-close">&times;</span>' +
                    '<h3>' + dexpressCheckout.i18n.selectCity + '</h3>' +
                    '<select id="dexpress-modal-city-select" style="width:100%;"></select>' +
                    '<div class="dexpress-modal-actions" style="margin-top:15px;text-align:right;">' +
                    '<button type="button" class="button button-primary dexpress-modal-confirm">' + dexpressCheckout.i18n.confirm + '</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>');
                $('body').append($modal);

                // Inicijalizacija select2 u modalu
                var $modalSelect = $('#dexpress-modal-city-select');
                $modalSelect.select2({
                    placeholder: dexpressCheckout.i18n.selectCity,
                    allowClear: true,
                    width: '100%',
                    language: {
                        noResults: function () {
                            return dexpressCheckout.i18n.noResults;
                        },
                        searching: function () {
                            return dexpressCheckout.i18n.searching;
                        }
                    }
                });

                // Zatvaranje modala
                $('.dexpress-modal-close').on('click', function () {
                    $modal.hide();
                });

                // Klik izvan modala
                $(window).on('click', function (event) {
                    if ($(event.target).is($modal)) {
                        $modal.hide();
                    }
                });

                // Potvrda odabira
                $('.dexpress-modal-confirm').on('click', function () {
                    var selectedId = $modalSelect.val();
                    var selectedText = $modalSelect.find('option:selected').text();
                    var selectedPostcode = $modalSelect.find('option:selected').data('postcode') || '';

                    if (selectedId) {
                        // Ažuriranje polja za grad
                        $cityId.val(selectedId);

                        // Ažuriranje select-a za grad
                        if ($city.find('option[value="' + selectedId + '"]').length === 0) {
                            $city.append('<option value="' + selectedId + '" data-postcode="' + selectedPostcode + '">' + selectedText + '</option>');
                        }
                        $city.val(selectedId).trigger('change');

                        // Postavljanje poštanskog broja
                        $postcode.val(selectedPostcode);
                    }

                    $modal.hide();
                });
            }

            // Učitavanje svih gradova
            var $modalSelect = $('#dexpress-modal-city-select');
            $modalSelect.empty().append('<option value="">' + dexpressCheckout.i18n.selectCity + '</option>');

            $modal.find('.dexpress-modal-content').addClass('dexpress-loading');

            this.loadAllCities(function (cities) {
                $modal.find('.dexpress-modal-content').removeClass('dexpress-loading');

                // Dodavanje gradova u select
                $.each(cities, function (i, city) {
                    $modalSelect.append('<option value="' + city.id + '" data-postcode="' + (city.postal_code || '') + '">' + city.text + '</option>');
                });

                // Resetovanje i prikazivanje
                $modalSelect.val('').trigger('change');
                $modal.show();
            });
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
                width: '100%',
                language: {
                    noResults: function () {
                        return dexpressCheckout.i18n.noResults;
                    },
                    searching: function () {
                        return dexpressCheckout.i18n.searching;
                    }
                }
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

        // Inicijalizacija načina dostave
        initDeliveryTypeSelection: function () {
            var self = this;

            // Prati promene u načinu dostave
            $('input[name="dexpress_delivery_type"]').on('change', function () {
                var deliveryType = $('input[name="dexpress_delivery_type"]:checked').val();

                if (deliveryType === 'address') {
                    // Prikaži polja za adresu, sakrij polja za pickup
                    $('.woocommerce-shipping-fields').show();
                    $('.dexpress-pickup-wrapper').hide();
                } else {
                    // Sakrij polja za adresu, prikaži polja za pickup
                    $('.woocommerce-shipping-fields').hide();
                    $('.dexpress-pickup-wrapper').show();

                    // Inicijalizuj select2 za gradove
                    if ($('#dexpress_pickup_town').length) {
                        $('#dexpress_pickup_town').select2({
                            placeholder: dexpressCheckout.i18n.selectCity,
                            allowClear: true,
                            width: '100%',
                            language: {
                                noResults: function () {
                                    return dexpressCheckout.i18n.noResults;
                                },
                                searching: function () {
                                    return dexpressCheckout.i18n.searching;
                                }
                            }
                        });

                        // Kada se promeni grad, učitaj lokacije
                        $('#dexpress_pickup_town').on('change', function () {
                            var townId = $(this).val();
                            if (townId) {
                                self.loadPickupLocations(deliveryType, townId);
                            } else {
                                self.resetPickupLocationField();
                            }
                        });
                    }

                    if ($('#dexpress_pickup_location').length) {
                        $('#dexpress_pickup_location').select2({
                            placeholder: dexpressCheckout.i18n.selectLocation,
                            allowClear: true,
                            width: '100%'
                        });
                    }
                }
            });

            // Okidanje događaja za inicijalno stanje
            $('input[name="dexpress_delivery_type"]:checked').trigger('change');
        },

        // Učitavanje lokacija za preuzimanje
        loadPickupLocations: function (type, townId) {
            var self = this;
            var $location = $('#dexpress_pickup_location');

            // Resetuj polje za lokaciju
            this.resetPickupLocationField();

            // Dodaj indikator učitavanja
            var $wrapper = $location.parent();
            $wrapper.addClass('dexpress-loading');

            // Učitaj lokacije za odabrani grad
            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                dataType: 'json',
                data: {
                    action: 'dexpress_get_pickup_locations',
                    town_id: townId,
                    type: type === 'shop' ? 'shop' : 'dispenser',
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    $wrapper.removeClass('dexpress-loading');

                    if (response.success && response.data.results) {
                        // Resetuj select
                        $location.empty();
                        $location.append('<option value="">' + dexpressCheckout.i18n.selectLocation + '</option>');

                        // Dodaj opcije za lokacije
                        $.each(response.data.results, function (i, location) {
                            $location.append('<option value="' + location.id + '">' + location.text + '</option>');
                        });

                        // Omogući select
                        $location.prop('disabled', false);

                        // Reinicijalizuj Select2
                        if ($location.data('select2')) {
                            $location.select2('destroy');
                        }
                        $location.select2({
                            placeholder: dexpressCheckout.i18n.selectLocation,
                            allowClear: true,
                            width: '100%'
                        });
                    } else {
                        self.resetPickupLocationField();
                    }
                },
                error: function () {
                    $wrapper.removeClass('dexpress-loading');
                    self.resetPickupLocationField();
                }
            });
        },

        // Resetovanje polja za lokaciju
        resetPickupLocationField: function () {
            var $location = $('#dexpress_pickup_location');

            // Resetuj polje za lokaciju
            $location.empty().append('<option value="">' + dexpressCheckout.i18n.firstSelectTown + '</option>');

            // Reinicijalizuj Select2
            if ($location.data('select2')) {
                $location.select2('destroy');
            }
            $location.select2({
                placeholder: dexpressCheckout.i18n.firstSelectTown,
                allowClear: true,
                width: '100%'
            });

            // Onemogući polje
            $location.prop('disabled', true);
        },

        // Inicijalizacija validacije checkout-a
        initCheckoutValidation: function () {
            var self = this;

            // Validacija pre slanja forme
            $('form.checkout').on('checkout_place_order', function () {
                // Proveri da li je D Express dostava odabrana
                var isDExpressSelected = false;

                $('input[name^="shipping_method"]').each(function () {
                    if ($(this).is(':checked') && $(this).val().indexOf('dexpress') !== -1) {
                        isDExpressSelected = true;
                        return false; // break
                    }
                });

                if (!isDExpressSelected) {
                    return true; // Nije D Express dostava, nastavi normalno
                }

                // Proveri tip dostave
                var deliveryType = $('input[name="dexpress_delivery_type"]:checked').val();

                if (deliveryType === 'address') {
                    // Validacija dostave na adresu
                    return self.validateAddressDelivery();
                } else {
                    // Validacija preuzimanja
                    return self.validatePickupDelivery();
                }
            });
        },

        // Validacija dostave na adresu
        validateAddressDelivery: function () {
            var isValid = true;
            var addressType = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';

            // Provera ulice
            var $street = $('#' + addressType + '_street');
            var $streetId = $('#' + addressType + '_street_id');

            if ($street.val() === '') {
                this.showError($street, dexpressCheckout.i18n.enterStreet);
                isValid = false;
            } else if ($streetId.val() === '') {
                this.showError($street, dexpressCheckout.i18n.selectStreet);
                isValid = false;
            }

            // Provera kućnog broja
            var $number = $('#' + addressType + '_number');

            if ($number.val() === '') {
                this.showError($number, dexpressCheckout.i18n.enterNumber);
                isValid = false;
            } else if ($number.val().indexOf(' ') !== -1) {
                this.showError($number, dexpressCheckout.i18n.numberNoSpaces);
                isValid = false;
            }

            // Provera grada
            var $city = $('#' + addressType + '_city');
            var $cityId = $('#' + addressType + '_city_id');

            if ($city.val() === '') {
                this.showError($city, dexpressCheckout.i18n.selectCity);
                isValid = false;
            } else if ($cityId.val() === '') {
                this.showError($city, dexpressCheckout.i18n.selectCity);
                isValid = false;
            }

            if (!isValid) {
                $('html, body').animate({
                    scrollTop: $('.dexpress-error').first().offset().top - 100
                }, 500);
            }

            return isValid;
        },

        // Validacija preuzimanja
        validatePickupDelivery: function () {
            var isValid = true;

            // Provera grada
            var $town = $('#dexpress_pickup_town');

            if ($town.val() === '') {
                this.showError($town, dexpressCheckout.i18n.selectTown);
                isValid = false;
            }

            // Provera lokacije
            var $location = $('#dexpress_pickup_location');

            if ($location.val() === '') {
                this.showError($location, dexpressCheckout.i18n.selectLocation);
                isValid = false;
            }

            if (!isValid) {
                $('html, body').animate({
                    scrollTop: $('.dexpress-error').first().offset().top - 100
                }, 500);
            }

            return isValid;
        },

        // Prikazivanje greške za polje
        showError: function ($field, message) {
            // Dodaj klasu greške
            $field.addClass('dexpress-error');

            // Dodaj poruku o grešci ako ne postoji
            if (!$field.next('.dexpress-error-message').length) {
                $('<div class="dexpress-error-message">' + message + '</div>').insertAfter($field);
            } else {
                $field.next('.dexpress-error-message').text(message);
            }

            // Ako je select2, dodaj klasu i na kontejner
            if ($field.data('select2')) {
                $field.next('.select2-container').addClass('dexpress-error');
            }
        }
    };

    // Inicijalizacija na učitavanje DOM-a
    $(document).ready(function () {
        DExpressCheckout.init();
    });

})(jQuery);