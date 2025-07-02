(function ($) {
    'use strict';
    function validateAddressDesc(value) {
        if (!value) return true; // Polje nije obavezno

        // Validacija prema obrascu 
        const pattern = /^([\-a-zžćčđšA-ZĐŠĆŽČ:,._0-9]+\.?)( [\-a-zžćčđšA-ZĐŠĆŽČ:,._0-9]+\.?)*$/u;

        // Provera dužine i obrasca
        return value.length <= 150 && pattern.test(value);
    }
    var DExpressCheckout = {
        init: function () {
            if (!$('#billing_first_name').length) {
                return; // Ne inicijalizujemo na stranicama koje nisu checkout
            }
            this.initStreetAutocomplete('billing');
            this.initStreetAutocomplete('shipping');
            this.initPhoneFormatter();
            this.watchShippingMethod();

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
                // Osnovna validacija za razmake (može biti proširena)
                if ($(this).val().includes(' ')) {
                    $(this).addClass('dexpress-error');
                    if (!$(this).next('.dexpress-error-message').length) {
                        $('<div class="dexpress-error-message">' + dexpressCheckout.i18n.numberNoSpaces + '</div>').insertAfter($(this));
                    }
                } else {
                    // D Express API validacija za broj
                    var addrNumberPattern = /^((bb|BB|b\.b\.|B\.B\.)(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*|(\d(-\d){0,1}[a-zžćčđšA-ZĐŠĆŽČ_0-9]{0,2})+(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*)$/;

                    if (!addrNumberPattern.test($(this).val())) {
                        $(this).addClass('dexpress-error');
                        if (!$(this).next('.dexpress-error-message').length) {
                            $('<div class="dexpress-error-message">Format: broj, bb, ili broj/broj</div>').insertAfter($(this));
                        }
                    } else {
                        $(this).removeClass('dexpress-error');
                        $(this).next('.dexpress-error-message').remove();
                    }
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

        initPhoneFormatter: function () {
            var self = this;
            var $phone = $('#billing_phone');

            if (!$phone.length) return;

            // Postavi početnu vrednost
            if (!$phone.val()) {
                $phone.val('+381 ');
            }

            // ✅ DODAJ HELPER TEXT ispod polja
            if (!$phone.next('.dexpress-phone-hint').length) {
                $('<div class="dexpress-phone-hint" style="font-size: 12px; color: #666; margin-top: 3px;">Primer: +381 60 123 4567 (mobilni) ili +381 11 123 456 (fiksni)</div>')
                    .insertAfter($phone);
            }

            $phone.on('input', function () {
                var value = $(this).val();
                var numbersOnly = value.replace(/[^0-9]/g, '');

                // ✅ AUTOMATSKI UKLONI POČETNU NULU
                if (numbersOnly.startsWith('3810')) {
                    numbersOnly = '381' + numbersOnly.substring(4); // ukloni 0 nakon 381
                }

                // Dodaj 381 ako ne postoji
                if (!numbersOnly.startsWith('381')) {
                    numbersOnly = '381' + numbersOnly;
                }

                // Formatiranje za prikaz
                var formatted = self.formatPhoneDisplay(numbersOnly);
                $(this).val(formatted);

                // Ažuriraj hidden field za API
                self.updateApiPhone(numbersOnly);
            });

            // Focus event
            $phone.on('focus', function () {
                if ($(this).val() === '+381') {
                    $(this).val('+381 ');
                }
                // Postavi kursor nakon prefiksa
                setTimeout(function () {
                    var input = $phone[0];
                    if (input.setSelectionRange) {
                        var cursorPos = Math.max(5, input.value.length);
                        input.setSelectionRange(cursorPos, cursorPos);
                    }
                }, 10);
            });

            // Validacija
            $phone.on('blur', function () {
                self.validatePhoneField($(this));
            });
        },
        formatPhoneDisplay: function (numbersOnly) {
            if (numbersOnly.length <= 3) {
                return '+381 ';
            }

            var localPart = numbersOnly.substring(3);

            if (localPart.length <= 2) {
                return '+381 ' + localPart;
            } else if (localPart.length <= 5) {
                return '+381 ' + localPart.substring(0, 2) + ' ' + localPart.substring(2);
            } else {
                return '+381 ' + localPart.substring(0, 2) + ' ' +
                    localPart.substring(2, 5) + ' ' + localPart.substring(5);
            }
        },
        // Nova funkcija validacije
        validatePhoneField: function ($phone) {
            if (!$phone.length) return false;

            var value = $phone.val();
            if (!value) return false;

            // API format validacija (bez +)
            var apiPhone = $('#dexpress_phone_api').val();
            var pattern = /^(381[1-9][0-9]{7,8}|38167[0-9]{6,8})$/;

            if (!apiPhone || !pattern.test(apiPhone)) {
                $phone.addClass('woocommerce-invalid');

                if (!$phone.parent().find('.phone-validation-error').length) {
                    $('<div class="phone-validation-error woocommerce-error">Telefon mora biti u formatu +381 XX XXX XXXX</div>')
                        .insertAfter($phone);
                }
                return false;
            } else {
                $phone.removeClass('woocommerce-invalid');
                $phone.parent().find('.phone-validation-error').remove();
                return true;
            }
        },
        updateApiPhone: function (numbersOnly) {
            // Ukloni postojeći hidden field
            $('#dexpress_phone_api').remove();

            if (numbersOnly && numbersOnly.length >= 10) {
                // Dodaj hidden field za API (bez +)
                $('<input type="hidden" id="dexpress_phone_api" name="dexpress_phone_api" />')
                    .val(numbersOnly)
                    .insertAfter('#billing_phone');
            }
        },
        // Funkcija koja proverava da li je izabrana D-Express dostava
        isDExpressSelected: function () {
            var isDExpressShipping = false;
            $('.shipping_method:checked, .shipping_method:hidden').each(function () {
                if ($(this).val() && $(this).val().indexOf('dexpress') !== -1) {
                    isDExpressShipping = true;
                    return false;
                }
            });
            return isDExpressShipping;
        },

        watchShippingMethod: function () {
            var self = this;

            // Inicijalno proveri
            self.updatePhoneRequirement();

            // Prati promene
            $(document.body).on('updated_checkout', function () {
                self.updatePhoneRequirement();
            });

            // Prati promene metode dostave
            $(document).on('change', 'input.shipping_method', function () {
                self.updatePhoneRequirement();
            });
        },

        // Ažuriramo oznaku obaveznog polja za telefon na osnovu izbora dostave
        updatePhoneRequirement: function () {
            var isDExpressShipping = this.isDExpressSelected();
            var $phoneLabel = $('label[for="billing_phone"]');
            var $phoneField = $('#billing_phone');

            if (isDExpressShipping) {
                if ($phoneLabel.find('.optional').length) {
                    $phoneLabel.find('.optional').remove();
                    if ($phoneLabel.find('.required').length === 0) {
                        $phoneLabel.append('<abbr class="required" title="required">*</abbr>');
                    }
                }
                $phoneField.attr('required', 'required');
            } else {
                if ($phoneLabel.find('.required').length) {
                    $phoneLabel.find('.required').remove();
                    if ($phoneLabel.find('.optional').length === 0) {
                        $phoneLabel.append('<span class="optional">(optional)</span>');
                    }
                }
                $phoneField.removeAttr('required');

                // Ukloni grešku ako D-Express nije izabran
                $phoneField.removeClass('woocommerce-invalid');
                $phoneField.parent().find('.woocommerce-error').remove();
            }
        },
        initCheckoutValidation: function () {
            var self = this;
            // Validacija pri unosu za polje address_desc
            $(document).on('blur', 'textarea[name$="_address_desc"], input[name$="_address_desc"]', function () {
                const value = $(this).val().trim();
                if (value && !validateAddressDesc(value)) {
                    self.showFieldError(
                        $(this),
                        dexpressCheckout.i18n.invalidAddressDesc || 'Neispravan format dodatnih informacija o adresi. Dozvoljeni su samo slova, brojevi, razmaci i znakovi: , . : - _'
                    );
                } else {
                    $(this).removeClass('dexpress-error woocommerce-invalid');
                    $(this).next('.dexpress-error-message').remove();
                }
            });
            // Validacija prilikom podnošenja forme
            $(document).on('checkout_place_order', function () {
                let isValid = true;
                const addressType = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';
                var $phone = $('#billing_phone');
                var phoneValue = $phone.val().trim();

                // Validacija dodatnih informacija o adresi
                const $addressDesc = $('#' + addressType + '_address_desc');
                if ($addressDesc.length && $addressDesc.val().trim() && !validateAddressDesc($addressDesc.val().trim())) {
                    isValid = false;
                    self.showFieldError(
                        $addressDesc,
                        dexpressCheckout.i18n.invalidAddressDesc || 'Neispravan format dodatnih informacija o adresi. Dozvoljeni su samo slova, brojevi, razmaci i znakovi: , . : - _'
                    );
                }
                if ($phone.length && phoneValue) {
                    if (!$('#dexpress_formatted_phone').length) {
                        $('<input type="hidden" id="dexpress_formatted_phone" name="dexpress_formatted_phone" />')
                            .insertAfter($phone);
                    }
                    $('#dexpress_formatted_phone').val('+381' + phoneValue);
                    console.log('Phone ready for submission: +381' + phoneValue);
                }
                // Samo validiraj D-Express polja ako je D-Express dostava izabrana
                if (self.isDExpressSelected()) {
                    var $street = $('#' + addressType + '_street');
                    var $streetId = $('#' + addressType + '_street_id');
                    var $number = $('#' + addressType + '_number');
                    var $city = $('#' + addressType + '_city');
                    var $cityId = $('#' + addressType + '_city_id');

                    if ($phone.length && phoneValue) {
                        if (!$('#dexpress_formatted_phone').length) {
                            $('<input type="hidden" id="dexpress_formatted_phone" name="dexpress_formatted_phone" />')
                                .insertAfter($phone);
                        }
                        $('#dexpress_formatted_phone').val('+381' + phoneValue);
                        console.log('Phone ready for submission: +381' + phoneValue);
                    }
                    // Validacija ulice
                    if (!$street.val()) {
                        isValid = self.showFieldError($street, dexpressCheckout.i18n.enterStreet);
                    } else if (!$streetId.val()) {
                        isValid = self.showFieldError($street, dexpressCheckout.i18n.selectStreet);
                    }

                    // Validacija kućnog broja
                    if (!$number.val()) {
                        isValid = self.showFieldError($number, dexpressCheckout.i18n.enterNumber);
                    } else if ($number.val().includes(' ')) {
                        isValid = self.showFieldError($number, dexpressCheckout.i18n.numberNoSpaces);
                    } else {
                        // Validacija prema D Express API formatu
                        var addrNumberPattern = /^((bb|BB|b\.b\.|B\.B\.)(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*|(\d(-\d){0,1}[a-zžćčđšA-ZĐŠĆŽČ_0-9]{0,2})+(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*)$/;
                        if (!addrNumberPattern.test($number.val())) {
                            isValid = self.showFieldError($number, 'Format kućnog broja nije validan. Npr: 12, bb, 15a, 23/4');
                        }
                    }

                    // Validacija grada
                    if (!$city.val() || !$cityId.val()) {
                        isValid = self.showFieldError($city, dexpressCheckout.i18n.selectCity);
                    }

                    if (!isValid) {
                        $('html, body').animate({
                            scrollTop: $('.dexpress-error, .woocommerce-invalid').first().offset().top - 100
                        }, 500);
                        return false; // Ovo zaustavlja checkout proces
                    }
                }
                return true;
            });
        },
        showFieldError: function ($field, message) {
            $field.addClass('dexpress-error woocommerce-invalid woocommerce-invalid-required-field');

            // Ako je telefonsko polje, koristi WooCommerce stil greške
            if ($field.attr('id') === 'billing_phone') {
                if ($field.parent().find('.woocommerce-error').length === 0) {
                    $('<div class="woocommerce-error">' + message + '</div>')
                        .insertAfter($field);
                }
            } else {
                // Za druga polja koristi naš stil
                if (!$field.next('.dexpress-error-message').length) {
                    $('<div class="dexpress-error-message">' + message + '</div>').insertAfter($field);
                } else {
                    $field.next('.dexpress-error-message').text(message);
                }
            }

            return false;
        }
    };

    $(document).ready(function () {
        DExpressCheckout.init();
    });

})(jQuery);

// dexpress-validation.js
jQuery(function ($) {
    'use strict';

    // Ručno dodajemo validaciju za naša D Express polja
    $(document.body).on('checkout_error', function () {
        // Provera da li je izabran D Express
        let isDExpress = false;
        $('input.shipping_method:checked, input.shipping_method:hidden').each(function () {
            if ($(this).val() && $(this).val().indexOf('dexpress') !== -1) {
                isDExpress = true;
                return false;
            }
        });

        if (!isDExpress) return;

        // Tip adrese (billing ili shipping)
        const addressType = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';

        // Lista polja za validaciju
        const fields = [
            addressType + '_street',
            addressType + '_number',
            addressType + '_city'
        ];

        // Prolazak kroz poruke o greškama i povezivanje sa poljima
        $('.woocommerce-error li').each(function () {
            let errorText = $(this).text().trim();

            // Povezujemo poruke sa odgovarajućim poljima
            fields.forEach(function (fieldId) {
                const $field = $('#' + fieldId);
                const fieldLabel = $field.closest('.form-row').find('label').text().trim();

                if (errorText.includes(fieldLabel) ||
                    errorText.includes('Ulica') ||
                    errorText.includes('Kućni broj') ||
                    errorText.includes('Grad')) {

                    // Dodajemo klase za invalidaciju
                    $field.addClass('woocommerce-invalid woocommerce-invalid-required-field');

                    // Dodajemo data-id atribut na li element za kompatibilnost
                    $(this).attr('data-id', fieldId);
                }
            });
        });
    });
});