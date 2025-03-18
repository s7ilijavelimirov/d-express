(function ($) {
    'use strict';

    var DExpressCheckout = {
        init: function () {
            this.initStreetAutocomplete('billing');
            this.initStreetAutocomplete('shipping');

            // Praćenje "Dostavi na drugu adresu" checkbox-a
            $('#ship-to-different-address-checkbox').on('change', function () {
                setTimeout(function () {
                    DExpressCheckout.initStreetAutocomplete('shipping');
                }, 300);
            });

            // Inicijalizacija validacije pre slanja forme
            this.initCheckoutValidation();

            // Pratimo promene ulice i broja da bi ažurirali standardni address_1 field
            this.setupAddressSync();
        },

        initStreetAutocomplete: function (addressType) {
            var self = this;
            var $street = $('#' + addressType + '_street');
            var $streetId = $('#' + addressType + '_street_id');
            var $city = $('#' + addressType + '_city');
            var $cityId = $('#' + addressType + '_city_id');
            var $postcode = $('#' + addressType + '_postcode');
            var $number = $('#' + addressType + '_number');

            if (!$street.length) return;

            $street.autocomplete({
                source: function (request, response) {
                    if (request.term.length < 2) return;

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
                            response(data.length ? data : [{ label: dexpressCheckout.i18n.noResults, value: request.term, id: 'custom' }]);
                        },
                        error: function () {
                            $street.removeClass('dexpress-loading');
                            response([]);
                        }
                    });
                },
                minLength: 2,
                delay: 300,
                select: function (event, ui) {
                    if (ui.item.id === 'custom') {
                        $streetId.val('');
                        self.resetCityFields(addressType);
                    } else {
                        $streetId.val(ui.item.id);
                        $street.val(ui.item.value);
                        self.fetchTownData(addressType, ui.item.town_id, ui.item.town_name, ui.item.postal_code);

                        // Fokusiramo polje za broj
                        setTimeout(function () {
                            $number.focus();
                        }, 100);
                    }
                    return false;
                }
            }).autocomplete("instance")._renderItem = function (ul, item) {
                return $("<li>").append("<div>" + item.label + "</div>").appendTo(ul);
            };

            $street.on('input', function () {
                if (!$street.val()) {
                    $streetId.val('');
                    self.resetCityFields(addressType);
                }
            });

            // Kućni broj validacija i sinhronizacija
            $number.on('input', function () {
                if ($(this).val().includes(' ')) {
                    $(this).addClass('dexpress-error');
                    if (!$(this).next('.dexpress-error-message').length) {
                        $('<div class="dexpress-error-message">' + dexpressCheckout.i18n.numberNoSpaces + '</div>').insertAfter($(this));
                    }
                } else {
                    $(this).removeClass('dexpress-error');
                    $(this).next('.dexpress-error-message').remove();
                }

                // Ažuriranje standardnog address_1 polja
                self.updateStandardAddressField(addressType);
            });
        },

        fetchTownData: function (addressType, townId, townName, postalCode) {
            var $city = $('#' + addressType + '_city');
            var $cityId = $('#' + addressType + '_city_id');
            var $postcode = $('#' + addressType + '_postcode');

            if (!townId) return;

            console.log("Setting town ID: " + townId); // Dodajte ovu liniju za debug

            // Postavljamo vrednosti
            $city.val(townName);
            $cityId.val(townId);
            $postcode.val(postalCode);

            // Ažuriramo standardna polja WooCommerce-a
            $('#' + addressType + '_city').val(townName);
            $('#' + addressType + '_postcode').val(postalCode);

            // Vizuelno označimo polja kao popunjena
            $city.addClass('dexpress-filled');
            $postcode.addClass('dexpress-filled');

            this.updateStandardAddressField(addressType);
        },
        resetCityFields: function (addressType) {
            var $city = $('#' + addressType + '_city');
            var $cityId = $('#' + addressType + '_city_id');
            var $postcode = $('#' + addressType + '_postcode');

            $city.val('').removeClass('dexpress-filled');
            $cityId.val('');
            $postcode.val('').removeClass('dexpress-filled');
        },

        updateStandardAddressField: function (addressType) {
            var $street = $('#' + addressType + '_street');
            var $number = $('#' + addressType + '_number');
            var $address1 = $('#' + addressType + '_address_1');

            // Kombinovana ulica i broj idu u address_1
            if ($street.val() && $number.val()) {
                $address1.val($street.val() + ' ' + $number.val());
            } else if ($street.val()) {
                $address1.val($street.val());
            }
        },

        setupAddressSync: function () {
            var self = this;

            // Pratiti promene u poljima i sinhronizovati ih sa standardnim poljima
            $('.dexpress-street, .dexpress-number, .dexpress-city').on('change', function () {
                var addressType = $(this).attr('id').startsWith('billing_') ? 'billing' : 'shipping';
                self.updateStandardAddressField(addressType);
            });
        },

        initCheckoutValidation: function () {
            $('form.checkout').on('checkout_place_order', function () {
                var addressType = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';

                var isValid = true;
                var $street = $('#' + addressType + '_street');
                var $streetId = $('#' + addressType + '_street_id');
                var $number = $('#' + addressType + '_number');
                var $city = $('#' + addressType + '_city');
                var $cityId = $('#' + addressType + '_city_id');

                if (!$street.val()) {
                    isValid = DExpressCheckout.showFieldError($street, dexpressCheckout.i18n.enterStreet);
                } else if (!$streetId.val()) {
                    isValid = DExpressCheckout.showFieldError($street, dexpressCheckout.i18n.selectStreet);
                }

                if (!$number.val()) {
                    isValid = DExpressCheckout.showFieldError($number, dexpressCheckout.i18n.enterNumber);
                } else if ($number.val().includes(' ')) {
                    isValid = DExpressCheckout.showFieldError($number, dexpressCheckout.i18n.numberNoSpaces);
                }

                if (!$city.val() || !$cityId.val()) {
                    isValid = DExpressCheckout.showFieldError($city, dexpressCheckout.i18n.selectCity);
                }

                if (!isValid) {
                    $('html, body').animate({
                        scrollTop: $('.dexpress-error').first().offset().top - 100
                    }, 500);
                    return false;
                }

                return true;
            });
        },

        showFieldError: function ($field, message) {
            $field.addClass('dexpress-error');
            if (!$field.next('.dexpress-error-message').length) {
                $('<div class="dexpress-error-message">' + message + '</div>').insertAfter($field);
            }
            if ($field.hasClass('select2-hidden-accessible')) {
                $field.next('.select2-container').addClass('dexpress-error');
            }
            return false;
        }
    };

    $(document).ready(function () {
        DExpressCheckout.init();
    });

})(jQuery);