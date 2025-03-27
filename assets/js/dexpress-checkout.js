(function ($) {
    'use strict';

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
            $(document).ready(function () {
                var $phone = $('#billing_phone');
                if ($phone.length) {
                    var val = $phone.val();
                    if (!val || val === '') {
                        $phone.val('+381');
                    } else if (val.indexOf('+381') !== 0) {
                        if (val.indexOf('381') === 0) {
                            $phone.val('+' + val);
                        } else if (val.indexOf('0') === 0) {
                            $phone.val('+381' + val.substring(1));
                        } else {
                            $phone.val('+381' + val);
                        }
                    }
                }
            });
            var self = this;
            var $phone = $('#billing_phone');

            // Provera da li element postoji
            if (!$phone.length) {
                return;
            }

            // Dodaj pomoćni tekst ispod polja
            if (!$phone.next('.phone-format-hint').length) {
                $('<span class="phone-format-hint">Format: +381(0) XX XXX XXXX (mobilni ili fiksni broj)</span>')
                    .insertAfter($phone);
            }

            // Inicijalno formatiranje - ako nema prefiks, dodaj ga
            var currentValue = $phone.val();
            if (currentValue && currentValue.indexOf('+381') !== 0) {
                // Ako već ima prefiks 381 bez +, dodaj samo +
                if (currentValue.indexOf('381') === 0) {
                    $phone.val('+' + currentValue);
                }
                // Ako ima vodeću nulu, zameni je sa +381
                else if (currentValue.indexOf('0') === 0) {
                    $phone.val('+381' + currentValue.substring(1));
                }
                // Inače, dodaj +381
                else {
                    $phone.val('+381' + currentValue);
                }
            }

            // Fokus na kraj polja
            $phone.on('focus', function () {
                // Prebaci kursor na kraj
                var val = this.value;
                this.value = '';
                this.value = val;
            });

            // Kada korisnik kucka
            $phone.on('input', function () {
                var input = $(this);
                var value = input.val();

                // Ako korisnik obriše prefiks, vrati ga
                if (!value.startsWith('+381')) {
                    var cursorPos = this.selectionStart;
                    var beforeCursor = value.substring(0, cursorPos);
                    var afterCursor = value.substring(cursorPos);

                    // Ako postoji deo prefiksa, dočekaj ga
                    if ('+381'.startsWith(beforeCursor)) {
                        // Ne radi ništa, dopusti korisniku da ga dovrši
                    } else {
                        // Vrati prefiks
                        input.val('+381' + value);

                        // Postavi kursor nakon prefiksa
                        setTimeout(function () {
                            input[0].setSelectionRange(4 + value.length, 4 + value.length);
                        }, 0);
                    }
                }
            });

            // Validacija pri gubitku fokusa
            $phone.on('blur', function () {
                self.validatePhoneField($(this));
            });
        },

        // Nova funkcija validacije
        validatePhoneField: function ($phone) {
            if (!$phone.length) return false;

            var value = $phone.val();
            if (!value) return false;

            // Validacija prema D Express API formatu
            var pattern = /^\+381[1-9][0-9]{7,8}$/;

            if (!pattern.test(value)) {
                $phone.addClass('woocommerce-invalid');

                if ($phone.parent().find('.phone-validation-error').length === 0) {
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

            // Validacija prilikom podnošenja forme
            $(document).on('checkout_place_order', function () {
                var addressType = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';
                var isValid = true;
                var $phone = $('#billing_phone');
                var phoneValue = $phone.val().trim();

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
                        return false;
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
jQuery(function($) {
    'use strict';
    
    // Ručno dodajemo validaciju za naša D Express polja
    $(document.body).on('checkout_error', function() {
        // Provera da li je izabran D Express
        let isDExpress = false;
        $('input.shipping_method:checked, input.shipping_method:hidden').each(function() {
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
        $('.woocommerce-error li').each(function() {
            let errorText = $(this).text().trim();
            
            // Povezujemo poruke sa odgovarajućim poljima
            fields.forEach(function(fieldId) {
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