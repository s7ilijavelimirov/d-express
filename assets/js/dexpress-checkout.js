(function ($) {
    'use strict';

    function validateAddressDesc(value) {
        if (!value) return true;
        const pattern = /^([\-a-zžćčđšA-ZĐŠĆŽČ:,._0-9]+\.?)( [\-a-zžćčđšA-ZĐŠĆŽČ:,._0-9]+\.?)*$/u;
        return value.length <= 150 && pattern.test(value);
    }

    var DExpressCheckout = {
        selectedStreet: {}, // {billing: 'Street Name', shipping: 'Street Name'}
        selectedTown: {}, // {billing: {id: 123, name: 'Town'}, shipping: {...}}

        init: function () {
            if (!$('#billing_first_name').length) {
                return;
            }

            this.initSmartAutocomplete('billing');
            this.initSmartAutocomplete('shipping');
            this.initPhoneFormatter();
            this.watchShippingMethod();

            $('#ship-to-different-address-checkbox').on('change', function () {
                setTimeout(function () {
                    DExpressCheckout.initSmartAutocomplete('shipping');
                }, 300);
            });

            this.initCheckoutValidation();
            this.setupAddressSync();
        },

        // PAMETNI povezani autocomplete
        initSmartAutocomplete: function (addressType) {
            this.initStreetField(addressType);
            this.initCityField(addressType);
        },

        // ULICA polje sa pametnim vezivanjem
        initStreetField: function (addressType) {
            var self = this;
            var $street = $('#' + addressType + '_street');
            var $streetId = $('#' + addressType + '_street_id');

            if (!$street.length) return;

            // Wrap input u wrapper za loader
            if (!$street.parent().hasClass('dexpress-input-wrapper')) {
                $street.wrap('<div class="dexpress-input-wrapper"></div>');
            }

            // Autocomplete za ulice
            $street.autocomplete({
                source: function (request, response) {
                    if (request.term.length < 2) return;

                    $street.addClass('dexpress-loading');

                    // Ako je izabran grad, pretraži ulice u tom gradu
                    if (self.selectedTown[addressType]) {
                        self.searchStreetsInTown(request.term, self.selectedTown[addressType].id, response);
                    } else {
                        // Inače pretraži sve ulice
                        self.searchAllStreets(request.term, response);
                    }
                },
                minLength: 2,
                delay: 300,
                select: function (event, ui) {
                    console.log('Street selected:', ui.item); // DEBUG

                    if (ui.item.is_custom) {
                        // Custom ulica
                        $streetId.val(ui.item.value);
                        $street.val(ui.item.value);
                        self.selectedStreet[addressType] = ui.item.value;
                    } else {
                        // Postojeća ulica
                        $streetId.val(ui.item.value);
                        $street.val(ui.item.value);
                        self.selectedStreet[addressType] = ui.item.value;
                    }

                    console.log('selectedStreet after setting:', self.selectedStreet[addressType]); // DEBUG
                    self.updateStandardAddressField(addressType);
                    return false;
                },
                focus: function (event, ui) {
                    return false;
                },
                open: function () {
                    $street.removeClass('dexpress-loading');
                }
            }).autocomplete("instance")._renderItem = function (ul, item) {
                var $li = $("<li>").appendTo(ul);
                var $div = $("<div>").appendTo($li);

                if (item.is_custom) {
                    $div.html('<strong style="color: #0073aa;">' + item.label + '</strong>');
                } else {
                    $div.text(item.label);
                }

                return $li;
            };

            // Kada se fokusira polje i već ima izabranu ulicu
            $street.on('focus', function () {
                $(this).css('text-align', 'left');
            });

            $street.on('input keyup', function () {
                $(this).css('text-align', 'left');
                if (!$street.val()) {
                    $streetId.val('');
                    self.selectedStreet[addressType] = null;
                }
            });
        },
        // GRAD polje sa pametnim vezivanjem  
        // GRAD polje sa pametnim vezivanjem  
        initCityField: function (addressType) {
            var self = this;
            var $city = $('#' + addressType + '_city');
            var $cityId = $('#' + addressType + '_city_id');
            var $postcode = $('#' + addressType + '_postcode');

            if (!$city.length) return;

            // Wrap input u wrapper za loader
            if (!$city.parent().hasClass('dexpress-input-wrapper')) {
                $city.wrap('<div class="dexpress-input-wrapper"></div>');
            }

            // Autocomplete za gradove
            $city.autocomplete({
                source: function (request, response) {
                    $city.addClass('dexpress-loading');

                    // Ako je izabrana ulica, pretraži gradove koji imaju tu ulicu
                    if (self.selectedStreet[addressType]) {
                        console.log('Using searchTownsWithStreet for:', self.selectedStreet[addressType]); // DEBUG
                        self.searchTownsWithStreet(request.term, self.selectedStreet[addressType], response);
                    } else {
                        console.log('Using searchAllTowns'); // DEBUG
                        // Inače pretraži sve gradove - SAMO KADA NEMA IZABRANU ULICU
                        if (request.term.length >= 2) {
                            self.searchAllTowns(request.term, response);
                        } else {
                            response([]);
                        }
                    }
                },
                minLength: 0,
                delay: 300,
                select: function (event, ui) {
                    $cityId.val(ui.item.town_id);
                    $city.val(ui.item.value);
                    $postcode.val(ui.item.postal_code);

                    self.selectedTown[addressType] = {
                        id: ui.item.town_id,
                        name: ui.item.value
                    };

                    // Ažuriraj standardna polja
                    $('#' + addressType + '_city').val(ui.item.value);
                    $('#' + addressType + '_postcode').val(ui.item.postal_code);

                    // Vizuelno označi kao popunjeno
                    $city.addClass('dexpress-filled');
                    $postcode.addClass('dexpress-filled');

                    self.updateStandardAddressField(addressType);
                    return false;
                },
                focus: function (event, ui) {
                    return false;
                },
                open: function () {
                    $city.removeClass('dexpress-loading');
                }
            }).autocomplete("instance")._renderItem = function (ul, item) {
                var $li = $("<li>").appendTo(ul);
                var $div = $("<div>").appendTo($li);

                console.log('Rendering item:', item.label); // DEBUG

                // Prikaži samo naziv grada BEZ PTT broja
                $div.text(item.label);

                return $li;
            };

            // NOVI FOCUS EVENT - direktan i prost
            $city.on('focus', function () {
                console.log('City field focused, selected street:', self.selectedStreet[addressType]); // DEBUG
                $(this).css('text-align', 'left');

                // Ako ima izabranu ulicu i polje je prazno
                if (self.selectedStreet[addressType] && !$(this).val().trim()) {
                    console.log('Triggering search for street:', self.selectedStreet[addressType]); // DEBUG

                    // Direktno trigger autocomplete search sa praznim stringom
                    $city.autocomplete('search', '');
                }
            });

            $city.on('input keyup', function () {
                $(this).css('text-align', 'left');
                if (!$city.val()) {
                    $cityId.val('');
                    $postcode.val('');
                    $city.removeClass('dexpress-filled');
                    $postcode.removeClass('dexpress-filled');
                    self.selectedTown[addressType] = null;
                }
            });
        },
        // Automatski prikaz gradova za izabranu ulicu
        showTownsForStreetOnFocus: function (addressType) {
            if (!this.selectedStreet[addressType]) return;

            var $city = $('#' + addressType + '_city');
            var self = this;

            // Pozovi search sa praznim stringom za sve rezultate
            this.searchTownsWithStreet('', this.selectedStreet[addressType], function (results) {
                if (results.length > 0) {
                    // Postavi source i otvori dropdown
                    $city.autocomplete('option', 'source', function (request, response) {
                        var filtered = results.filter(function (item) {
                            return item.label.toLowerCase().includes(request.term.toLowerCase());
                        });
                        response(filtered);
                    });

                    // Otvori dropdown
                    $city.autocomplete('search', '');
                }
            });
        },
        // AJAX pozivi
        searchAllStreets: function (term, callback) {
            var self = this;
            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                dataType: 'json',
                data: {
                    action: 'dexpress_search_streets',
                    term: term,
                    nonce: dexpressCheckout.nonce
                },
                success: function (data) {
                    $('#billing_street, #shipping_street').removeClass('dexpress-loading');
                    callback(data);
                },
                error: function () {
                    $('#billing_street, #shipping_street').removeClass('dexpress-loading');
                    callback([]);
                }
            });
        },

        searchStreetsInTown: function (term, townId, callback) {
            var self = this;
            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                dataType: 'json',
                data: {
                    action: 'dexpress_search_streets_for_town',
                    term: term,
                    town_id: townId,
                    nonce: dexpressCheckout.nonce
                },
                success: function (data) {
                    $('#billing_street, #shipping_street').removeClass('dexpress-loading');
                    // Dodaj custom opciju na dno
                    var results = data || [];
                    results.push({
                        id: 'custom',
                        label: '✏️ Unesite svoju ulicu: "' + term + '"',
                        value: term,
                        is_custom: true
                    });
                    callback(results);
                },
                error: function () {
                    $('#billing_street, #shipping_street').removeClass('dexpress-loading');
                    callback([{
                        id: 'custom',
                        label: '✏️ Unesite svoju ulicu: "' + term + '"',
                        value: term,
                        is_custom: true
                    }]);
                }
            });
        },

        searchAllTowns: function (term, callback) {
            var self = this;
            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                dataType: 'json',
                data: {
                    action: 'dexpress_search_all_towns',
                    term: term,
                    nonce: dexpressCheckout.nonce
                },
                success: function (data) {
                    $('#billing_city, #shipping_city').removeClass('dexpress-loading');

                    // DODAJ OVAJ DEBUG:
                    console.log('All towns data received:', data);
                    if (data && data.length > 0) {
                        data.forEach(function (item, index) {
                            if (index < 3) { // prikaži samo prva 3
                                console.log('Town ' + index + ':', 'label=' + item.label, 'value=' + item.value);
                            }
                        });
                    }

                    callback(data);
                },
                error: function () {
                    $('#billing_city, #shipping_city').removeClass('dexpress-loading');
                    callback([]);
                }
            });
        },

        searchTownsWithStreet: function (term, streetName, callback) {
            var self = this;
            console.log('Searching towns for street:', streetName, 'with term:', term); // DEBUG

            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'dexpress_get_towns_for_street',
                    street_name: streetName,
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    $('#billing_city, #shipping_city').removeClass('dexpress-loading');
                    console.log('Towns response:', response); // DEBUG

                    if (response.success && response.data.towns) {
                        // Filtriraj gradove po unetom terminu
                        var filtered = response.data.towns.filter(function (town) {
                            var displayName = town.display_name || town.name || '';
                            return displayName.toLowerCase().includes(term.toLowerCase());
                        });

                        // Konvertuj u format za autocomplete - BEZ PTT broja
                        var results = filtered.map(function (town) {
                            var displayName = town.display_name || town.name;
                            return {
                                town_id: town.id,
                                value: displayName,
                                label: displayName, // SAMO naziv grada
                                postal_code: town.postal_code
                            };
                        });

                        console.log('Final results:', results); // DEBUG
                        callback(results);
                    } else {
                        callback([]);
                    }
                },
                error: function () {
                    $('#billing_city, #shipping_city').removeClass('dexpress-loading');
                    callback([]);
                }
            });
        },

        // Automatski prikaz kada se fokusira polje
        showTownsForStreet: function (addressType) {
            if (!this.selectedStreet[addressType]) return;

            var $city = $('#' + addressType + '_city');

            // Direktno pozovi search sa empty stringom za sve rezultate
            this.searchTownsWithStreet('', this.selectedStreet[addressType], function (results) {
                if (results.length > 0) {
                    // Prikaži results u autocomplete
                    $city.autocomplete('option', 'source', function (request, response) {
                        // Filtriraj rezultate na osnovu input-a
                        var filtered = results.filter(function (item) {
                            return item.label.toLowerCase().includes(request.term.toLowerCase());
                        });
                        response(filtered);
                    });

                    // Otvori dropdown
                    $city.autocomplete('search', '');
                }
            });
        },

        showStreetsForTown: function (addressType) {
            if (!this.selectedTown[addressType]) return;

            var $street = $('#' + addressType + '_street');

            // Trigger autocomplete sa kratkim stringom da pokaže opcije
            $street.autocomplete('search', 'a');
            setTimeout(() => $street.autocomplete('search', ''), 50);
        },

        // Pomoćne funkcije
        updateStandardAddressField: function (addressType) {
            var $street = $('#' + addressType + '_street');
            var $number = $('#' + addressType + '_number');
            var $address1 = $('#' + addressType + '_address_1');

            if ($street.val() && $number.val()) {
                $address1.val($street.val() + ' ' + $number.val());
            } else if ($street.val()) {
                $address1.val($street.val());
            }
        },

        setupAddressSync: function () {
            var self = this;
            $('.dexpress-street, .dexpress-number, .dexpress-city').on('change', function () {
                var addressType = $(this).attr('id').startsWith('billing_') ? 'billing' : 'shipping';
                self.updateStandardAddressField(addressType);
            });

            // Event listener za broj
            $('#billing_number, #shipping_number').on('input', function () {
                var addressType = $(this).attr('id').startsWith('billing_') ? 'billing' : 'shipping';

                // Validacija broja
                if ($(this).val().includes(' ')) {
                    $(this).addClass('dexpress-error');
                    if (!$(this).next('.dexpress-error-message').length) {
                        $('<div class="dexpress-error-message">' + dexpressCheckout.i18n.numberNoSpaces + '</div>').insertAfter($(this));
                    }
                } else {
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

                self.updateStandardAddressField(addressType);
            });
        },

        // TELEFON FORMATTER
        initPhoneFormatter: function () {
            var self = this;
            var $phone = $('#billing_phone');

            if (!$phone.length) return;

            // Postavi početnu vrednost
            if (!$phone.val()) {
                $phone.val('+381 ');
            }

            // Dodaj helper text
            if (!$phone.next('.dexpress-phone-hint').length) {
                $('<div class="dexpress-phone-hint" style="font-size: 12px; color: #666; margin-top: 3px;">Primer: +381 60 123 4567 (mobilni) ili +381 11 123 456 (fiksni)</div>')
                    .insertAfter($phone);
            }

            $phone.on('input', function () {
                var value = $(this).val();
                var numbersOnly = value.replace(/[^0-9]/g, '');

                // Automatski ukloni početnu nulu
                if (numbersOnly.startsWith('3810')) {
                    numbersOnly = '381' + numbersOnly.substring(4);
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
                setTimeout(function () {
                    var input = $phone[0];
                    if (input.setSelectionRange) {
                        var cursorPos = Math.max(5, input.value.length);
                        input.setSelectionRange(cursorPos, cursorPos);
                    }
                }, 10);
            });

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

        validatePhoneField: function ($phone) {
            if (!$phone.length) return false;

            var value = $phone.val();
            if (!value) return false;

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
            $('#dexpress_phone_api').remove();

            if (numbersOnly && numbersOnly.length >= 10) {
                $('<input type="hidden" id="dexpress_phone_api" name="dexpress_phone_api" />')
                    .val(numbersOnly)
                    .insertAfter('#billing_phone');
            }
        },

        // SHIPPING METHOD WATCHER
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

            self.updatePhoneRequirement();

            $(document.body).on('updated_checkout', function () {
                self.updatePhoneRequirement();
            });

            $(document).on('change', 'input.shipping_method', function () {
                self.updatePhoneRequirement();
            });
        },

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

                $phoneField.removeClass('woocommerce-invalid');
                $phoneField.parent().find('.woocommerce-error').remove();
            }
        },

        // VALIDACIJA
        initCheckoutValidation: function () {
            var self = this;

            // Validacija address_desc
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

            // Validacija pre slanja forme
            $(document).on('checkout_place_order', function () {
                let isValid = true;
                const addressType = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';
                var $phone = $('#billing_phone');
                var phoneValue = $phone.val().trim();

                // Validacija address_desc
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
                }

                // Validacija D-Express polja
                if (self.isDExpressSelected()) {
                    var $street = $('#' + addressType + '_street');
                    var $streetId = $('#' + addressType + '_street_id');
                    var $number = $('#' + addressType + '_number');
                    var $city = $('#' + addressType + '_city');
                    var $cityId = $('#' + addressType + '_city_id');

                    // Validacija ulice
                    if (!$street.val()) {
                        isValid = self.showFieldError($street, dexpressCheckout.i18n.enterStreet);
                    } else if (!$streetId.val()) {
                        isValid = self.showFieldError($street, dexpressCheckout.i18n.selectStreet);
                    }

                    // Validacija broja
                    if (!$number.val()) {
                        isValid = self.showFieldError($number, dexpressCheckout.i18n.enterNumber);
                    } else if ($number.val().includes(' ')) {
                        isValid = self.showFieldError($number, dexpressCheckout.i18n.numberNoSpaces);
                    } else {
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

            if ($field.attr('id') === 'billing_phone') {
                if ($field.parent().find('.woocommerce-error').length === 0) {
                    $('<div class="woocommerce-error">' + message + '</div>')
                        .insertAfter($field);
                }
            } else {
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

// DEXPRESS VALIDATION
jQuery(function ($) {
    'use strict';

    $(document.body).on('checkout_error', function () {
        let isDExpress = false;
        $('input.shipping_method:checked, input.shipping_method:hidden').each(function () {
            if ($(this).val() && $(this).val().indexOf('dexpress') !== -1) {
                isDExpress = true;
                return false;
            }
        });

        if (!isDExpress) return;

        const addressType = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';
        const fields = [
            addressType + '_street',
            addressType + '_number',
            addressType + '_city'
        ];

        $('.woocommerce-error li').each(function () {
            let errorText = $(this).text().trim();

            fields.forEach(function (fieldId) {
                const $field = $('#' + fieldId);
                const fieldLabel = $field.closest('.form-row').find('label').text().trim();

                if (errorText.includes(fieldLabel) ||
                    errorText.includes('Ulica') ||
                    errorText.includes('Kućni broj') ||
                    errorText.includes('Grad')) {

                    $field.addClass('woocommerce-invalid woocommerce-invalid-required-field');
                    $(this).attr('data-id', fieldId);
                }
            });
        });
    });
});