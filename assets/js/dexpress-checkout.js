(function ($) {
    'use strict';

    var DExpressCheckout = {
        selectedStreet: {},
        selectedTown: {},
        cache: {},
        townsForStreet: {},
        activeRequests: {},
        loadingTowns: {},

        init: function () {
            if (!$('#billing_first_name').length) return;

            this.initAutocomplete('billing');
            this.initAutocomplete('shipping');
            this.initPhoneFormatter();
            this.initValidation();
            this.watchShippingMethod();

            $('#ship-to-different-address-checkbox').on('change', function () {
                setTimeout(() => DExpressCheckout.initAutocomplete('shipping'), 100);
            });
        },

        initAutocomplete: function (type) {
            this.initStreetField(type);
            this.initCityField(type);
        },
        cleanCityName: function (cityDisplayText) {
            if (!cityDisplayText) return '';

            // "21000 Novi Sad (Novi Sad) - 21000" → "Novi Sad"
            var match = cityDisplayText.match(/^(\d{5})\s+(.+?)\s+\(\1\)\s+-\s+\1$/);
            if (match) return match[2].trim();

            // "Novi Sad (Vojvodina) - 21000" → "Novi Sad"
            match = cityDisplayText.match(/^(.+?)\s+\([^)]+\)\s+-\s+\d{5}$/);
            if (match) return match[1].trim();

            // "21000 Novi Sad - 21000" → "Novi Sad"
            match = cityDisplayText.match(/^(\d{5})\s+(.+?)\s+-\s+\1$/);
            if (match) return match[2].trim();

            // "Novi Sad - 21000" → "Novi Sad"
            match = cityDisplayText.match(/^(.+?)\s+-\s+\d{5}$/);
            if (match) return match[1].trim();

            return cityDisplayText.trim();
        },
        // ULTRA BRZA ULICA
        // ISPRAVLJENA LOGIKA - ZAMENI initStreetField funkciju
        initStreetField: function (type) {
            var self = this;
            var $street = $('#' + type + '_street');
            var $streetId = $('#' + type + '_street_id');

            if (!$street.length) return;

            $street.autocomplete({
                source: function (request, response) {
                    if (!request.term || request.term.length < 1) return response([]);

                    // ISPRAVKA: UVEK prvo proveri da li je grad izabran
                    if (self.selectedTown[type] && self.selectedTown[type].id) {
                        // Ako je grad izabran, traži SAMO ulice za taj grad
                        console.log('Tražim ulice za grad:', self.selectedTown[type].name);
                        self.searchStreetsInTown(request.term, self.selectedTown[type].id, response);
                    } else {
                        // Ako grad nije izabran, traži sve ulice
                        console.log('Grad nije izabran, tražim sve ulice');
                        // INSTANT cache check za sve ulice
                        var results = self.getCachedStreets(request.term);
                        if (results) {
                            response(results);
                            return;
                        }

                        $street.addClass('dexpress-loading');
                        self.searchAllStreets(request.term, response);
                    }
                },
                minLength: 1,
                delay: 0,
                select: function (event, ui) {
                    if (!ui.item) return false;

                    if (ui.item.is_custom) {
                        self.showCustomStreetModal(type, ui.item.value);
                        return false;
                    }

                    $street.val(ui.item.value || '');
                    $streetId.val(ui.item.value || '');
                    self.selectedStreet[type] = ui.item.value || '';

                    self.clearFieldError($street);

                    // Ako je grad već izabran, ne mijenjaj ga
                    if (!self.selectedTown[type] || !self.selectedTown[type].id) {
                        // INSTANT load towns SAMO ako grad nije izabran
                        if (ui.item.value) {
                            self.loadTownsForStreet(type, ui.item.value);
                        }
                    }

                    self.updateStandardField(type);
                    return false;
                },
                open: function () {
                    $street.removeClass('dexpress-loading');
                },
                close: function () {
                    setTimeout(function () {
                        self.validateStreetSelection(type);
                    }, 100);
                }
            }).autocomplete("instance")._renderItem = function (ul, item) {
                var $li = $("<li>").appendTo(ul);
                if (item && item.is_custom) {
                    $li.html('<div style="font-weight: bold; color: #dc3545;">⚠️ Dodajte novu ulicu</div>');
                } else {
                    $li.html('<div>' + (item ? item.label || '' : '') + '</div>');
                }
                return $li;
            };

            // AŽURIRAJ input handler
            $street.on('input', function () {
                self.clearFieldError($street);

                if (!$(this).val()) {
                    $streetId.val('');
                    self.selectedStreet[type] = null;

                    // ISPRAVKA: Ne briši townsForStreet ako je grad već izabran
                    if (!self.selectedTown[type] || !self.selectedTown[type].id) {
                        if (self.townsForStreet) {
                            self.townsForStreet[type] = null;
                        }

                        var $city = $('#' + type + '_city');
                        $city.autocomplete('option', 'source', function (request, response) {
                            if (!request.term || request.term.length < 1) return response([]);
                            self.searchAllTowns(request.term, response);
                        });
                    }
                } else {
                    $streetId.val('');
                    self.selectedStreet[type] = null;
                }
            });

            $street.on('blur', function () {
                setTimeout(function () {
                    self.validateStreetSelection(type);
                }, 200);
            });
        },

        // ULTRA BRZ GRAD
        initCityField: function (type) {
            var self = this;
            var $city = $('#' + type + '_city');
            var $cityId = $('#' + type + '_city_id');
            var $postcode = $('#' + type + '_postcode');

            if (!$city.length) return;

            $city.autocomplete({
                source: function (request, response) {
                    // INSTANT check for prepared towns
                    if (self.townsForStreet && self.townsForStreet[type] && self.townsForStreet[type].length > 0) {
                        var filtered = self.townsForStreet[type].filter(town => {
                            var searchIn = (town.display_text || '').toLowerCase();
                            return !request.term || searchIn.includes(request.term.toLowerCase());
                        });
                        response(filtered);
                        return;
                    }

                    if (!request.term || request.term.length < 1) return response([]);

                    // INSTANT cache check
                    var results = self.getCachedTowns(request.term);
                    if (results) {
                        response(results);
                        return;
                    }

                    $city.addClass('dexpress-loading');
                    self.searchAllTowns(request.term, response);
                },
                minLength: 0,
                delay: 0, // ZERO delay
                // AŽURIRAJ select handler u initCityField funkciji - ZAMENI samo select deo:
                select: function (event, ui) {
                    if (!ui.item) return false;

                    // ČIŠĆENJE NAZIVA GRADA
                    var cleanCityName = self.cleanCityName(ui.item.display_text || ui.item.label || '');

                    $city.val(cleanCityName);  // ← ČIST NAZIV!
                    $cityId.val(ui.item.town_id || '');
                    $postcode.val(ui.item.postal_code || '');

                    // ISPRAVKA: Definiši varijable
                    var oldTownId = self.selectedTown[type] ? self.selectedTown[type].id : null;
                    var newTownId = ui.item.town_id || '';

                    self.selectedTown[type] = {
                        id: newTownId,
                        name: cleanCityName  // ← ČIST NAZIV!
                    };

                    // NOVO: Ako se grad promenio, očisti ulicu
                    if (oldTownId && oldTownId !== newTownId) {
                        console.log('Grad promenjen sa', oldTownId, 'na', newTownId, '- čistim ulicu');
                        var $street = $('#' + type + '_street');
                        var $streetId = $('#' + type + '_street_id');

                        $street.val('');
                        $streetId.val('');
                        self.selectedStreet[type] = null;

                        // Očisti keš za stari grad
                        Object.keys(self.cache).forEach(key => {
                            if (key.startsWith('streets_town_' + oldTownId + '_')) {
                                delete self.cache[key];
                            }
                        });
                    }

                    self.updateStandardField(type);
                    return false;
                },
                open: function () {
                    $city.removeClass('dexpress-loading');
                }
            }).autocomplete("instance")._renderItem = function (ul, item) {
                var $li = $("<li>").appendTo(ul);
                var displayName = item.display_text || item.label || '';
                $li.html('<div>' + displayName + '</div>');
                return $li;
            };

            $city.on('input', function () {
                if (!$(this).val()) {
                    $cityId.val('');
                    $postcode.val('');
                    self.selectedTown[type] = null;
                }
            });

            $city.on('focus', function () {
                if (self.townsForStreet && self.townsForStreet[type] && self.townsForStreet[type].length > 0) {
                    $(this).autocomplete('search', '');
                    $(this).autocomplete('widget').show();
                } else if (self.selectedStreet[type]) {
                    // Ako nema gradova ali imamo ulicu, učitaj ih ponovo
                    self.loadTownsForStreet(type, self.selectedStreet[type]);
                }
            });
        },

        // ULTRA BRZI CACHE SYSTEM
        getCachedStreets: function (term) {
            // Direct cache hit
            var cacheKey = 'streets_' + term;
            if (this.cache[cacheKey]) {
                return this.cache[cacheKey];
            }

            // Smart cache - check shorter terms
            if (term.length >= 3) {
                for (var i = term.length - 1; i >= 2; i--) {
                    var shorterTerm = term.substring(0, i);
                    var shorterKey = 'streets_' + shorterTerm;

                    if (this.cache[shorterKey] && this.cache[shorterKey].length > 0) {
                        var filtered = this.cache[shorterKey].filter(street =>
                            street.label.toLowerCase().includes(term.toLowerCase())
                        );

                        if (filtered.length > 0) {
                            this.cache[cacheKey] = filtered;
                            return filtered;
                        }
                    }
                }
            }

            return null;
        },

        getCachedTowns: function (term) {
            // Direct cache hit
            var cacheKey = 'towns_' + term;
            if (this.cache[cacheKey]) {
                return this.cache[cacheKey];
            }

            // Smart cache - check shorter terms
            if (term.length >= 3) {
                for (var i = term.length - 1; i >= 2; i--) {
                    var shorterTerm = term.substring(0, i);
                    var shorterKey = 'towns_' + shorterTerm;

                    if (this.cache[shorterKey] && this.cache[shorterKey].length > 0) {
                        var filtered = this.cache[shorterKey].filter(town =>
                            (town.display_text || '').toLowerCase().includes(term.toLowerCase())
                        );

                        if (filtered.length > 0) {
                            this.cache[cacheKey] = filtered;
                            return filtered;
                        }
                    }
                }
            }

            return null;
        },

        // OPTIMIZED AJAX - CANCEL PREVIOUS REQUESTS
        searchAllStreets: function (term, callback) {
            if (!term) return callback([]);

            var cacheKey = 'streets_' + term;

            // Cancel previous request
            if (this.activeRequests[cacheKey]) {
                this.activeRequests[cacheKey].abort();
            }

            this.activeRequests[cacheKey] = $.get(dexpressCheckout.ajaxUrl, {
                action: 'dexpress_search_streets',
                term: term,
                nonce: dexpressCheckout.nonce
            })
                .done((data) => {
                    $('.dexpress-street').removeClass('dexpress-loading');
                    this.cache[cacheKey] = data || [];
                    callback(this.cache[cacheKey]);
                    delete this.activeRequests[cacheKey];
                })
                .fail((xhr) => {
                    $('.dexpress-street').removeClass('dexpress-loading');
                    if (xhr.statusText !== 'abort') {
                        callback([]);
                    }
                    delete this.activeRequests[cacheKey];
                });
        },

        searchAllTowns: function (term, callback) {
            if (!term) return callback([]);

            var cacheKey = 'towns_' + term;

            // Cancel previous request
            if (this.activeRequests[cacheKey]) {
                this.activeRequests[cacheKey].abort();
            }

            this.activeRequests[cacheKey] = $.get(dexpressCheckout.ajaxUrl, {
                action: 'dexpress_search_all_towns',
                term: term,
                nonce: dexpressCheckout.nonce
            })
                .done((data) => {
                    $('.dexpress-city').removeClass('dexpress-loading');

                    var formattedData = (data || []).map(town => {
                        var displayText = '';

                        // ISPRAVKA: Proveri da li display_name već sadrži poštanski broj
                        if (town.display_name && (town.display_name.includes('(') || town.display_name.includes('-'))) {
                            // Već je formatiran, koristi ga direktno
                            displayText = town.display_name;
                        } else {
                            // Formatiraj normalno
                            if (town.display_name) {
                                displayText = town.display_name;
                                if (town.municipality_name && town.municipality_name !== town.display_name) {
                                    displayText += ' (' + town.municipality_name + ')';
                                }
                            } else {
                                displayText = town.label || town.value || '';
                            }

                            // ISPRAVKA: Dodaj poštanski broj SAMO ako ga već nema
                            if (town.postal_code && !displayText.includes(town.postal_code)) {
                                displayText += ' - ' + town.postal_code;
                            }
                        }

                        return {
                            ...town,
                            display_text: displayText
                        };
                    });

                    this.cache[cacheKey] = formattedData;
                    callback(formattedData);
                })
                .fail((xhr) => {
                    $('.dexpress-city').removeClass('dexpress-loading');
                    if (xhr.statusText !== 'abort') {
                        callback([]);
                    }
                    delete this.activeRequests[cacheKey];
                });
        },

        // ISPRAVLJENA FUNKCIJA - ZAMENI searchStreetsInTown
        searchStreetsInTown: function (term, townId, callback) {
            if (!term || !townId) return callback([]);

            // ISPRAVKA: Keš PO GRADU I TERMU
            var cacheKey = 'streets_town_' + townId + '_' + term;
            if (this.cache[cacheKey]) {
                console.log('Cache hit za grad:', townId, 'term:', term);
                return callback(this.cache[cacheKey]);
            }

            console.log('API poziv za grad:', townId, 'term:', term);
            $('.dexpress-street').addClass('dexpress-loading');

            $.get(dexpressCheckout.ajaxUrl, {
                action: 'dexpress_search_streets_for_town',
                term: term,
                town_id: townId,
                nonce: dexpressCheckout.nonce
            })
                .done((data) => {
                    $('.dexpress-street').removeClass('dexpress-loading');
                    var results = data || [];

                    // UVEK dodaj opciju za custom ulicu
                    results.push({
                        id: 'custom',
                        label: 'Dodajte novu ulicu: "' + term + '"',
                        value: term,
                        is_custom: true
                    });

                    // ISPRAVKA: Sačuvaj u keš PO GRADU
                    this.cache[cacheKey] = results;
                    callback(results);
                })
                .fail(() => {
                    $('.dexpress-street').removeClass('dexpress-loading');
                    // Ako API ne radi, ponudi samo custom opciju
                    callback([{
                        id: 'custom',
                        label: 'Dodajte novu ulicu: "' + term + '"',
                        value: term,
                        is_custom: true
                    }]);
                });
        },

        // INSTANT TOWNS LOADING
        loadTownsForStreet: function (type, streetName) {
            var self = this;

            // Sprečavanje dupliciranja zahteva
            var loadKey = type + '_' + streetName;
            if (this.loadingTowns[loadKey]) {
                return; // Već se učitava
            }
            this.loadingTowns[loadKey] = true;

            $.post(dexpressCheckout.ajaxUrl, {
                action: 'dexpress_get_towns_for_street',
                street_name: streetName,
                nonce: dexpressCheckout.nonce
            })
                .done((response) => {
                    if (response && response.success && response.data && response.data.towns) {
                        var towns = response.data.towns.map(town => {
                            var displayText = '';

                            // ISPRAVKA: Ista logika kao gore
                            if (town.display_name && (town.display_name.includes('(') || town.display_name.includes('-'))) {
                                displayText = town.display_name;
                            } else {
                                if (town.display_name) {
                                    displayText = town.display_name;
                                    if (town.municipality_name && town.municipality_name !== town.display_name) {
                                        displayText += ' (' + town.municipality_name + ')';
                                    }
                                } else {
                                    displayText = town.name || '';
                                }

                                // ISPRAVKA: Dodaj poštanski broj SAMO ako ga već nema
                                if (town.postal_code && !displayText.includes(town.postal_code)) {
                                    displayText += ' - ' + town.postal_code;
                                }
                            }

                            return {
                                town_id: town.id || '',
                                label: town.display_name || town.name || '',
                                display_text: displayText,
                                display_name: town.display_name || '',
                                municipality_name: town.municipality_name || '',
                                postal_code: town.postal_code || ''
                            };
                        });

                        if (!self.townsForStreet) {
                            self.townsForStreet = {};
                        }
                        self.townsForStreet[type] = towns;

                        var $city = $('#' + type + '_city');
                        $city.autocomplete('option', 'source', function (request, response) {
                            if (self.townsForStreet && self.townsForStreet[type]) {
                                var filtered = self.townsForStreet[type].filter(town => {
                                    var searchIn = (town.display_text || '').toLowerCase();
                                    return !request.term || searchIn.includes(request.term.toLowerCase());
                                });
                                return response(filtered);
                            }
                            return response([]);
                        });

                        // NOVO: Ako je city polje trenutno fokusirano, otvori dropdown
                        if ($city.is(':focus')) {
                            setTimeout(() => {
                                $city.autocomplete('search', '');
                                $city.autocomplete('widget').show();
                            }, 50);
                        }


                    }

                    // Ukloni loading flag
                    delete self.loadingTowns[loadKey];
                })
                .fail(() => {

                    delete self.loadingTowns[loadKey];
                });
        },

        // VALIDATION & MODAL - same as before
        validateStreetSelection: function (type) {
            var $street = $('#' + type + '_street');
            var $streetId = $('#' + type + '_street_id');
            var streetValue = $street.val().trim();
            var streetIdValue = $streetId.val();

            if (!streetValue) {
                this.clearFieldError($street);
                return true;
            }

            if (streetValue && !streetIdValue) {
                this.showFieldError($street, 'Molimo izaberite ulicu iz liste ili dodajte novu ulicu pomoću "Dodajte novu ulicu" opcije.');
                return false;
            }

            this.clearFieldError($street);
            return true;
        },

        clearFieldError: function ($field) {
            $field.removeClass('dexpress-error woocommerce-invalid');
            $field.next('.dexpress-error-message').remove();
        },

        showCustomStreetModal: function (type, searchTerm) {
            var modalId = 'dexpress-custom-street-modal';
            $('#' + modalId).remove();

            var modalHtml = `
                <div id="${modalId}" class="dexpress-modal-overlay">
                    <div class="dexpress-modal-content">
                        <div class="dexpress-modal-header">
                            <h3>Dodavanje nove ulice</h3>
                            <span class="dexpress-modal-close">&times;</span>
                        </div>
                        <div class="dexpress-modal-body">
                            <p>Ulica "<strong>${searchTerm}</strong>" nije pronađena u našoj bazi.</p>
                            <label for="custom-street-input">Unesite tačan naziv ulice:</label>
                            <input type="text" id="custom-street-input" value="${searchTerm}" 
                                   style="width: 100%; padding: 8px; margin: 8px 0; border: 1px solid #ccc; border-radius: 4px;">
                            <p style="font-size: 13px; color: #666;">
                                Napomena: Custom ulice mogu dovesti do problema pri dostavi. 
                                Proverite da li postoji slična ulica u ponudi.
                            </p>
                        </div>
                        <div class="dexpress-modal-footer">
                            <button type="button" class="dexpress-btn-secondary dexpress-modal-close">Odustani</button>
                            <button type="button" class="dexpress-btn-primary" id="confirm-custom-street">Potvrdi ulicu</button>
                        </div>
                    </div>
                </div>
            `;

            $('body').append(modalHtml);

            var $modal = $('#' + modalId);
            var $input = $('#custom-street-input');
            var self = this;

            $modal.find('.dexpress-modal-close').on('click', function () {
                $modal.remove();
            });

            $modal.find('#confirm-custom-street').on('click', function () {
                var customStreet = $input.val().trim();
                if (customStreet) {
                    var $street = $('#' + type + '_street');
                    var $streetId = $('#' + type + '_street_id');

                    $street.val(customStreet);
                    $streetId.val('custom_' + customStreet);
                    self.selectedStreet[type] = customStreet;

                    self.clearFieldError($street);
                    self.updateStandardField(type);
                    $modal.remove();
                } else {
                    alert('Molimo unesite naziv ulice');
                }
            });

            $input.focus().select();

            $input.on('keypress', function (e) {
                if (e.which === 13) {
                    $modal.find('#confirm-custom-street').click();
                }
            });

            $(document).on('keydown.customStreet', function (e) {
                if (e.which === 27) {
                    $modal.remove();
                    $(document).off('keydown.customStreet');
                }
            });
        },

        updateStandardField: function (type) {
            var $street = $('#' + type + '_street');
            var $number = $('#' + type + '_number');
            var $address1 = $('#' + type + '_address_1');

            var streetVal = $street.val() || '';
            var numberVal = $number.val() || '';

            if (streetVal && numberVal) {
                $address1.val(streetVal + ' ' + numberVal);
            } else if (streetVal) {
                $address1.val(streetVal);
            }
        },

        // PHONE, VALIDATION, SHIPPING - same as before
        initPhoneFormatter: function () {
            var $phone = $('#billing_phone');
            if (!$phone.length) return;

            // Postavi početnu vrednost
            if (!$phone.val()) $phone.val('+381 ');

            // Dodaj hint sa jasnim uputstvom
            if (!$phone.next('.dexpress-phone-hint').length) {
                $('<div class="dexpress-phone-hint">Primer: +381 60 123 4567 (bez nule nakon prefiksa)</div>').insertAfter($phone);
            }

            // Dodaj error container
            if (!$phone.siblings('.dexpress-phone-error').length) {
                $('<div class="dexpress-phone-error" style="display:none; color:#e2401c; font-size:12px; margin-top:5px;"></div>').insertAfter($phone.next('.dexpress-phone-hint'));
            }

            var self = this;

            $phone.on('input', function () {
                var $this = $(this);
                var value = $this.val();

                // Ukloni sve što nije broj ili +
                var cleanValue = value.replace(/[^0-9+]/g, '');

                // Osiguraj da počinje sa +381
                if (!cleanValue.startsWith('+381')) {
                    if (cleanValue.startsWith('381')) {
                        cleanValue = '+' + cleanValue;
                    } else if (cleanValue.startsWith('0')) {
                        // UKLONI početnu nulu i dodaj +381
                        cleanValue = '+381' + cleanValue.substring(1);
                    } else if (cleanValue.length > 0 && !cleanValue.startsWith('+')) {
                        cleanValue = '+381' + cleanValue;
                    } else {
                        cleanValue = '+381';
                    }
                }

                // Izvuci brojeve nakon +381
                var digitsAfter381 = cleanValue.substring(4);

                // GLAVNA VALIDACIJA: Proveri da li počinje sa 0
                if (digitsAfter381.startsWith('0')) {
                    self.showPhoneError('Broj telefona ne sme počinjati sa 0 nakon +381');
                    $this.addClass('dexpress-error woocommerce-invalid');
                } else {
                    self.clearPhoneError();
                    $this.removeClass('dexpress-error woocommerce-invalid');
                }

                // Formatiraj broj
                var formatted = self.formatPhoneDisplay(cleanValue);
                $this.val(formatted);

                // Generiši API format (bez + i razmaka)
                var apiFormat = cleanValue.replace(/[^0-9]/g, '');
                $('#dexpress_phone_api').remove();

                if (apiFormat.length >= 10 && !digitsAfter381.startsWith('0')) {
                    $('<input type="hidden" id="dexpress_phone_api" name="dexpress_phone_api" />')
                        .val(apiFormat).insertAfter($phone);
                }
            });

            $phone.on('focus', function () {
                if ($(this).val() === '+381' || $(this).val() === '') {
                    $(this).val('+381 ');
                }
            });

            $phone.on('blur', function () {
                self.validatePhoneComplete($(this));
            });
        },
        showPhoneError: function (message) {
            var $errorDiv = $('.dexpress-phone-error');
            $errorDiv.text(message).show();
        },
        clearPhoneError: function () {
            var $errorDiv = $('.dexpress-phone-error');
            $errorDiv.hide();
        },
        formatPhoneDisplay: function (phoneNumber) {
            // Ukloni sve što nije broj
            var numbers = phoneNumber.replace(/[^0-9]/g, '');

            if (numbers.length <= 3) return '+381 ';

            var afterPrefix = numbers.substring(3);
            if (afterPrefix.length <= 2) {
                return '+381 ' + afterPrefix;
            } else if (afterPrefix.length <= 5) {
                return '+381 ' + afterPrefix.substring(0, 2) + ' ' + afterPrefix.substring(2);
            } else {
                return '+381 ' + afterPrefix.substring(0, 2) + ' ' +
                    afterPrefix.substring(2, 5) + ' ' + afterPrefix.substring(5);
            }
        },
        formatPhone: function (numbers) {
            if (!numbers || numbers.length <= 3) return '+381 ';
            var local = numbers.substring(3);
            if (local.length <= 2) return '+381 ' + local;
            if (local.length <= 5) return '+381 ' + local.substring(0, 2) + ' ' + local.substring(2);
            return '+381 ' + local.substring(0, 2) + ' ' + local.substring(2, 5) + ' ' + local.substring(5);
        },

        watchShippingMethod: function () {
            this.updatePhoneRequirement();
            $(document.body).on('updated_checkout', () => this.updatePhoneRequirement());
            $(document).on('change', 'input.shipping_method', () => this.updatePhoneRequirement());
        },

        isDExpressSelected: function () {
            return $('.shipping_method:checked, .shipping_method:hidden').toArray()
                .some(el => el.value && el.value.includes('dexpress'));
        },
        // NOVA: Kompleta validacija telefona
        validatePhoneComplete: function ($phone) {
            var value = $phone.val();
            var apiPhone = $('#dexpress_phone_api').val();

            if (!apiPhone || apiPhone.length < 10) {
                this.showPhoneError('Broj telefona mora imati najmanje 8 cifara nakon +381');
                $phone.addClass('dexpress-error woocommerce-invalid');
                return false;
            }

            // Proveri regex pattern iz API dokumentacije
            var pattern = /^(381[1-9][0-9]{7,8}|38167[0-9]{6,8})$/;
            if (!pattern.test(apiPhone)) {
                this.showPhoneError('Neispavan format telefona. Primer: +381 60 123 4567');
                $phone.addClass('dexpress-error woocommerce-invalid');
                return false;
            }

            this.clearPhoneError();
            $phone.removeClass('dexpress-error woocommerce-invalid');
            return true;
        },
        updatePhoneRequirement: function () {
            var isDExpress = this.isDExpressSelected();
            var $phoneLabel = $('label[for="billing_phone"]');
            var $phoneField = $('#billing_phone');

            if (isDExpress) {
                $phoneLabel.find('.optional').remove();
                if (!$phoneLabel.find('.required').length) {
                    $phoneLabel.append('<abbr class="required" title="required">*</abbr>');
                }
                $phoneField.attr('required', 'required');
            } else {
                $phoneLabel.find('.required').remove();
                if (!$phoneLabel.find('.optional').length) {
                    $phoneLabel.append('<span class="optional">(optional)</span>');
                }
                $phoneField.removeAttr('required');
            }
        },

        initValidation: function () {
            $(document).on('checkout_place_order', () => {
                if (!this.isDExpressSelected()) return true;

                var type = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';
                var isValid = true;

                if (!this.validateStreetSelection(type)) {
                    isValid = false;
                }

                var fields = {
                    street: 'Ulica je obavezna',
                    number: 'Kućni broj je obavezan',
                    city: 'Grad je obavezan'
                };

                Object.keys(fields).forEach(field => {
                    var $field = $('#' + type + '_' + field);
                    if (!$field.val()) {
                        this.showError($field, fields[field]);
                        isValid = false;
                    }
                });

                var $number = $('#' + type + '_number');
                if ($number.val() && $number.val().includes(' ')) {
                    this.showError($number, 'Kućni broj ne sme sadržati razmake');
                    isValid = false;
                }

                if (!isValid) {
                    $('html, body').animate({
                        scrollTop: $('.dexpress-error').first().offset().top - 100
                    }, 300);
                }

                return isValid;
            });
        },

        showError: function ($field, message) {
            $field.addClass('dexpress-error');
            $field.next('.dexpress-error-message').remove();
            $('<div class="dexpress-error-message">' + message + '</div>').insertAfter($field);
            return false;
        },

        showFieldError: function ($field, message) {
            $field.addClass('dexpress-error woocommerce-invalid');
            $field.next('.dexpress-error-message').remove();
            $('<div class="dexpress-error-message">' + message + '</div>').insertAfter($field);
            return false;
        }
    };

    $(document).ready(() => DExpressCheckout.init());

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