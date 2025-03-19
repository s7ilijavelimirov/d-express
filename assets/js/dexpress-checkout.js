(function ($) {
    'use strict';

    var DExpressCheckout = {
        init: function () {
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
            var $phone = $('#billing_phone');
            
            // Dodaj pomoćni tekst za telefon ako već ne postoji
            if ($phone.length && !$phone.parent().find('.phone-format-hint').length) {
                $('<span class="phone-format-hint">Format: +381 ili 0 na početku</span>')
                    .insertAfter($phone);
            }
            
            // Formatiranje pri napuštanju polja
            $phone.on('blur', function() {
                var currentValue = $(this).val().trim();
                if (currentValue) {
                    // Formatiranje telefona u preporučeni format
                    var phoneDigits = currentValue.replace(/\D/g, '');
                    
                    // Ako počinje sa 0, zameni sa +381
                    if (phoneDigits.startsWith('0')) {
                        $(this).val('+381' + phoneDigits.substring(1));
                    }
                    // Ako ne počinje sa +381 ili 381, dodaj +381
                    else if (!currentValue.startsWith('+381') && !phoneDigits.startsWith('381')) {
                        $(this).val('+381' + phoneDigits);
                    }
                    // Ako počinje sa 381 bez +, dodaj +
                    else if (phoneDigits.startsWith('381') && !currentValue.startsWith('+')) {
                        $(this).val('+' + phoneDigits);
                    }
                }
            });
            
            // Ukloni grešku kad korisnik počne da kuca
            $phone.on('input', function() {
                $(this).removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                $(this).parent().find('.woocommerce-error').remove();
            });
        },
        
        // Funkcija koja proverava da li je izabrana D-Express dostava
        isDExpressSelected: function() {
            var isDExpressShipping = false;
            $('.shipping_method:checked, .shipping_method:hidden').each(function() {
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
                // Dodaj oznaku obaveznog polja
                if ($phoneLabel.find('.optional').length) {
                    $phoneLabel.find('.optional').remove();
                    if ($phoneLabel.find('.required').length === 0) {
                        $phoneLabel.append('<abbr class="required" title="required">*</abbr>');
                    }
                }
                $phoneField.attr('required', 'required');
            } else {
                // Vrati na opciono
                if ($phoneLabel.find('.required').length) {
                    $phoneLabel.find('.required').remove();
                    if ($phoneLabel.find('.optional').length === 0) {
                        $phoneLabel.append('<span class="optional">(optional)</span>');
                    }
                }
                $phoneField.removeAttr('required');
                
                // Ukloni grešku ako D-Express nije izabran
                $phoneField.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                $phoneField.parent().find('.woocommerce-error').remove();
            }
        },
        
        initCheckoutValidation: function () {
            var self = this;
            
            // Validacija prilikom podnošenja forme
            $(document).on('checkout_place_order', function () {
                var addressType = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';
                var isValid = true;
                
                // Samo validiraj D-Express polja ako je D-Express dostava izabrana
                if (self.isDExpressSelected()) {
                    var $street = $('#' + addressType + '_street');
                    var $streetId = $('#' + addressType + '_street_id');
                    var $number = $('#' + addressType + '_number');
                    var $city = $('#' + addressType + '_city');
                    var $cityId = $('#' + addressType + '_city_id');
                    var $phone = $('#billing_phone');
                    
                    // Validacija telefona
                    var phoneValue = $phone.val().trim();
                    if (!phoneValue) {
                        isValid = self.showFieldError($phone, 'Telefon je obavezan za D Express dostavu');
                    } else {
                        // Formatiranje telefona za API
                        var phoneDigits = phoneValue.replace(/\D/g, '');
                        
                        // Ako počinje sa 0, zameniti sa 381
                        if (phoneDigits.startsWith('0')) {
                            phoneDigits = '381' + phoneDigits.substring(1);
                        } 
                        // Ako je sa +381, ukloniti +
                        else if (phoneValue.startsWith('+381')) {
                            phoneDigits = '381' + phoneDigits.substring(4);
                        }
                        // Ako ne počinje sa 381, dodaj ga
                        else if (!phoneDigits.startsWith('381')) {
                            phoneDigits = '381' + phoneDigits;
                        }
                        
                        // Validacija API formata
                        if (!/^(381[1-9][0-9]{7,8})$/.test(phoneDigits)) {
                            isValid = self.showFieldError($phone, 'Telefon mora počinjati sa +381 ili 0 i imati 8-9 cifara nakon pozivnog broja');
                        } else {
                            // Kreiranje hidden polja za API format telefona
                            if (!$('#dexpress_api_phone').length) {
                                $('<input type="hidden" id="dexpress_api_phone" name="dexpress_api_phone" />')
                                    .insertAfter($phone);
                            }
                            $('#dexpress_api_phone').val(phoneDigits);
                        }
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