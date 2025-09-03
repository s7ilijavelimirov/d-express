(function ($) {
    'use strict';

    var DExpressCheckout = {
        // State management
        selectedStreet: {},
        selectedTown: {},
        cache: {},
        townsForStreet: {},
        activeRequests: {},
        loadingTowns: {},

        // Configuration
        config: {
            minLength: 1,
            delay: 300, // POVEĆANO sa 0 na 300ms
            cacheTime: 15 * 60 * 1000,
            phonePattern: /^(381[1-9][0-9]{7,8}|38167[0-9]{6,8})$/,
            numberPattern: /^((bb|BB|b\.b\.|B\.B\.)(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*|(\d(-\d){0,1}[a-zžćčđšA-ZĐŠĆŽČ_0-9]{0,2})+(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*)$/
        },
        normalizeInput: function (str) {
            return str
                .toLowerCase()
                .replace(/dj/g, "đ")
                .replace(/nj/g, "nj")
                .replace(/lj/g, "lj")
                .replace(/dž/g, "dz")
                .replace(/dz/g, "dž")

                .replace(/č/g, "c")
                .replace(/ć/g, "c")
                .replace(/š/g, "s")
                .replace(/ž/g, "z")

                .replace(/\bc\b/g, "č")
                .replace(/\bs\b/g, "š")
                .replace(/\bz\b/g, "ž");
        },
        /**
         * Initialize the checkout functionality
         */
        init: function () {
            if (!$('#billing_first_name').length) return;

            // DODAJ OVO - Force enable all D Express fields (otključaj polja)
            $('#billing_street, #billing_city, #billing_number, #shipping_street, #shipping_city, #shipping_number')
                .prop('disabled', false)
                .attr('readonly', false)
                .css({
                    'pointer-events': 'auto',
                    'user-select': 'text',
                    'cursor': 'text'
                });

            // Remove any blocking event handlers
            $('#billing_city, #shipping_city').off('click.blocked keydown.blocked input.blocked');

            // Ensure autocomplete is always available
            setTimeout(() => {
                $('.dexpress-error, .woocommerce-invalid').each(function () {
                    $(this).css({
                        'pointer-events': 'auto',
                        'cursor': 'text',
                        'user-select': 'text'
                    });
                });
            }, 100);

            this.initAddressFields('billing');
            this.initAddressFields('shipping');
            this.initPhoneValidation();
            this.initFormValidation();
            this.initShippingMethodWatcher();
            this.bindEvents();
        },

        /**
         * Initialize address fields for given type (billing/shipping)
         */
        initAddressFields: function (type) {
            this.initStreetField(type);
            this.initCityField(type);
        },

        /**
         * Initialize street field with autocomplete
         */
        initStreetField: function (type) {
            const self = this;
            const $street = $('#' + type + '_street');
            const $streetId = $('#' + type + '_street_id');

            if (!$street.length) return;

            $street.autocomplete({
                source: function (request, response) {
                    const term = self.normalizeInput(request.term);
                    self.handleStreetSearch(type, term, response);
                },
                minLength: self.config.minLength,
                delay: self.config.delay,
                select: function (event, ui) {
                    return self.handleStreetSelect(type, ui.item);
                },
                open: function () {
                    self.hideLoading($street);
                },
                close: function () {
                    setTimeout(() => self.validateStreetField(type), 0);
                }
            }).autocomplete("instance")._renderItem = function (ul, item) {
                return self.renderStreetItem(ul, item);
            };

            // Input event handlers
            $street.on('input', function () {
                self.handleStreetInput(type, $(this));
            });

            $street.on('blur', function () {
                setTimeout(() => self.validateStreetField(type), 200);
            });
        },

        /**
         * Initialize city field with autocomplete
         */
        initCityField: function (type) {
            const self = this;
            const $city = $('#' + type + '_city');
            const $cityId = $('#' + type + '_city_id');
            const $postcode = $('#' + type + '_postcode');

            if (!$city.length) return;

            $city.autocomplete({
                source: function (request, response) {
                    const term = self.normalizeInput(request.term);
                    self.handleCitySearch(type, term, response);
                },
                minLength: 0,
                delay: self.config.delay,
                select: function (event, ui) {
                    return self.handleCitySelect(type, ui.item);
                },
                open: function () {
                    self.hideLoading($city);
                },
                close: function () {
                    setTimeout(() => self.validateCityField(type), 100);
                }
            }).autocomplete("instance")._renderItem = function (ul, item) {
                return self.renderCityItem(ul, item);
            };

            // Input event handlers
            $city.on('input', function () {
                self.handleCityInput(type, $(this));
            });

            $city.on('blur', function () {
                setTimeout(() => self.validateCityField(type), 200);
            });

            $city.on('focus', function () {
                self.handleCityFocus(type);
            });
        },

        /**
         * Handle street search with smart caching
         */
        handleStreetSearch: function (type, term, callback) {
            if (!term || term.length < 1) return callback([]);

            // If city is selected, search only in that city
            if (this.selectedTown[type] && this.selectedTown[type].id) {
                this.searchStreetsInTown(term, this.selectedTown[type].id, callback);
            } else {
                // Search all streets
                const cached = this.getCachedData('streets', term);
                if (cached) {
                    callback(cached);
                    return;
                }
                this.searchAllStreets(term, callback);
            }
        },


        /**
         * Handle city search with smart caching
         */
        handleCitySearch: function (type, term, callback) {
            // Priority 1: Gradovi za izabranu ulicu
            if (this.townsForStreet[type] && this.townsForStreet[type].length > 0) {
                const filtered = this.townsForStreet[type].filter(town => {
                    const searchIn = (town.display_text || '').toLowerCase();
                    return !term || searchIn.includes(term.toLowerCase());
                });
                callback(filtered);
                return;
            }

            if (!term || term.length < 1) return callback([]);

            // Priority 2: Pretraži sve gradove
            const cached = this.getCachedData('towns', term);
            if (cached) {
                callback(cached);
                return;
            }
            this.searchAllTowns(term, callback);
        },

        /**
         * Handle street selection - FLEKSIBILNA VERZIJA
         */
        handleStreetSelect: function (type, item) {
            if (!item) return false;

            const $street = $('#' + type + '_street');
            const $streetId = $('#' + type + '_street_id');

            if (item.is_custom) {
                this.showCustomStreetModal(type, item.value);
                return false;
            }

            $street.val(item.value || '');
            $streetId.val(item.value || '');
            this.selectedStreet[type] = item.value || '';
            this.clearFieldError($street);

            // DODAJ OVO - očisti cache gradova kada se bira nova ulica
            this.clearTownCacheForStreet(type);

            // Loadaj gradove za izabranu ulicu (samo ako grad nije već izabran)
            if (!this.selectedTown[type] || !this.selectedTown[type].id) {
                if (item.value) {
                    this.loadTownsForStreet(type, item.value);
                }
            }

            this.updateStandardAddressField(type);
            return false;
        },
        /**
         * Handle city selection - POBOLJŠANA VERZIJA
         */
        handleCitySelect: function (type, item) {
            if (!item) return false;

            const $city = $('#' + type + '_city');
            const $cityId = $('#' + type + '_city_id');
            const $postcode = $('#' + type + '_postcode');

            const cleanCityName = this.cleanCityName(item.display_text || item.label || '');

            $city.val(cleanCityName);
            $cityId.val(item.town_id || '');
            $postcode.val(item.postal_code || '');
            $city.data('validSelection', true);
            this.clearFieldError($city);

            this.selectedTown[type] = {
                id: item.town_id || '',
                name: cleanCityName
            };

            // Ažuriraj street autocomplete da pretraži samo ulice u ovom gradu
            this.updateStreetAutocomplete(type);

            this.updateStandardAddressField(type);
            return false;
        },

        /**
         * Handle street input changes
         */
        /**
  * Handle street input - DODAJ CLEAR CACHE
  */
        handleStreetInput: function (type, $field) {
            this.clearFieldError($field);
            const $streetId = $('#' + type + '_street_id');

            if (!$field.val()) {
                $streetId.val('');
                this.selectedStreet[type] = null;
                // DODAJ OVO - očisti cache kad se briše ulica
                this.clearTownCacheForStreet(type);
                return;
            }

            // Ako se menja ulica, očisti cache
            if (this.selectedStreet[type] && this.selectedStreet[type] !== $field.val()) {
                this.clearTownCacheForStreet(type);
            }

            $streetId.val('');
            this.selectedStreet[type] = null;

            clearTimeout(this.inputTimeout);
            this.inputTimeout = setTimeout(() => {
                if ($field.val().length >= 1 && $field.is(':focus')) {
                    this.showLoading($field);
                }
            }, 200);
        },
        /**
         * Handle city input changes
         */
        handleCityInput: function (type, $field) {
      
            const $cityId = $('#' + type + '_city_id');
            const $postcode = $('#' + type + '_postcode');
            const currentVal = $field.val();

            if ($field.hasClass('dexpress-error')) {
                this.clearFieldError($field);
            }

            if (!currentVal) {
                this.resetCityField(type);
                return;
            }

            $field.data('validSelection', false);

            if ($cityId.val()) {
                $cityId.val('');
                $postcode.val('');
                this.selectedTown[type] = null;
            }

            // NOVO: Pokaži loader dok kuca
            clearTimeout(this.inputTimeout);
            this.inputTimeout = setTimeout(() => {
                if ($field.val().length >= 1 && $field.is(':focus')) {
                    this.showLoading($field, 'cities', 'Pretražujem...');
                }
            }, 200);
        },

        /**
         * Handle city field focus
         */
        handleCityFocus: function (type) {
            const $city = $('#' + type + '_city');

            // Show prepared towns if available
            if (this.townsForStreet[type] && this.townsForStreet[type].length > 0) {
                $city.autocomplete('search', '');
                $city.autocomplete('widget').show();
            } else if (this.selectedStreet[type]) {
                this.loadTownsForStreet(type, this.selectedStreet[type]);
            }
        },

        /**
         * Initialize phone validation with real-time feedback
         */
        initPhoneValidation: function () {
            const $phone = $('#billing_phone');
            if (!$phone.length) return;

            // Set initial value
            if (!$phone.val()) $phone.val('+381');

            // Add hint and error containers
            this.setupPhoneUI($phone);

            const self = this;

            $phone.on('input', function () {
                self.handlePhoneInput($(this));
            });

            $phone.on('focus', function () {
                if (!$(this).val() || $(this).val() === '+381') {
                    $(this).val('+381');
                }
            });

            $phone.on('blur', function () {
                self.validatePhoneField($(this));
            });

            // NOVO: Sprečava kucanje 0 direktno nakon +381
            $phone.on('keypress', function (e) {
                const currentVal = $(this).val();
                const key = String.fromCharCode(e.which);

                // Ako je trenutna vrednost "+381" i korisnik pokušava da ukuca 0
                if (currentVal === '+381' && key === '0') {
                    e.preventDefault();
                    self.showPhoneError('Broj ne može počinjati sa 0 nakon +381');
                    setTimeout(() => self.clearPhoneError(), 2000);
                    return false;
                }

                // Dozvoli samo brojevi i +
                if (!/[0-9+]/.test(key) && e.which !== 8 && e.which !== 46) {
                    e.preventDefault();
                    return false;
                }
            });
        },


        /**
         * Handle phone input with real-time validation
         */
        handlePhoneInput: function ($phone) {
            let value = $phone.val();

            // Clean and format value - ukloni sve što nije broj ili +
            let cleanValue = value.replace(/[^0-9+]/g, '');

            // Ensure it starts with +381
            if (!cleanValue.startsWith('+381')) {
                if (cleanValue.startsWith('381')) {
                    cleanValue = '+' + cleanValue;
                } else if (cleanValue.startsWith('0')) {
                    // BLOKIRA 0 na početku - prebaci na +381
                    cleanValue = '+381' + cleanValue.substring(1);
                } else if (cleanValue.length > 0 && !cleanValue.startsWith('+')) {
                    cleanValue = '+381' + cleanValue;
                } else {
                    cleanValue = '+381';
                }
            }

            // KRITIČNA PROVERA: Sprečava +3810... format
            if (cleanValue.length > 4) {
                const afterPrefix = cleanValue.substring(4);
                if (afterPrefix.startsWith('0')) {
                    // Ukloni 0 ako je unet nakon +381
                    cleanValue = '+381' + afterPrefix.substring(1);
                    this.showPhoneError('Broj ne može počinjati sa 0 nakon +381');
                    setTimeout(() => this.clearPhoneError(), 2000);
                }
            }

            // Ograniči dužinu na maksimalno 14 karaktera (+381 + 10 cifara)
            if (cleanValue.length > 14) {
                cleanValue = cleanValue.substring(0, 14);
            }

            $phone.val(cleanValue);

            // Clear errors while typing (osim ako nije 0 problem)
            if (!cleanValue.substring(4).startsWith('0')) {
                this.clearPhoneError();
                $phone.removeClass('dexpress-error woocommerce-invalid');
            }
        },

        /**
         * Real-time phone validation
         */
        validatePhoneRealTime: function ($phone, phoneValue) {
            const digitsAfter381 = phoneValue.substring(4);
            const apiFormat = phoneValue.replace(/[^0-9]/g, '');

            this.clearPhoneError();
            $phone.removeClass('dexpress-error woocommerce-invalid');

            // Ako je samo +381, nema greške
            if (phoneValue === '+381') {
                $('#dexpress_phone_api').remove();
                return true;
            }

            // STRIKTNA PROVERA: Ne sme počinjati sa 0 nakon +381
            if (digitsAfter381.startsWith('0')) {
                this.showPhoneError('Broj ne može počinjati sa 0 nakon +381');
                $phone.addClass('dexpress-error woocommerce-invalid');
                $('#dexpress_phone_api').remove();
                return false;
            }

            // Prva cifra mora biti 1-9 (fiksni ili mobilni)
            if (digitsAfter381.length > 0 && !/^[1-9]/.test(digitsAfter381)) {
                this.showPhoneError('Prvi broj mora biti između 1 i 9');
                $phone.addClass('dexpress-error woocommerce-invalid');
                $('#dexpress_phone_api').remove();
                return false;
            }

            // Mora imati 8-10 cifara nakon +381
            if (digitsAfter381.length > 0 && (digitsAfter381.length < 8 || digitsAfter381.length > 10)) {
                this.showPhoneError('Broj mora imati između 8 i 10 cifara nakon +381');
                $phone.addClass('dexpress-error woocommerce-invalid');
                $('#dexpress_phone_api').remove();
                return false;
            }

            // Regex validacija za kompletne brojeve (8+ cifara)
            if (digitsAfter381.length >= 8) {
                // Poboljšani regex koji pokriva sve srpske formate
                const serbianPhonePattern = /^381([1-3][0-9]{6,7}|[456][0-9]{7,8}|7[0-9]{7,8}|60[0-9]{6,7}|61[0-9]{6,7}|62[0-9]{6,7}|63[0-9]{6,7}|64[0-9]{6,7}|65[0-9]{6,7}|66[0-9]{6,7}|67[0-9]{6,7}|68[0-9]{6,7}|69[0-9]{6,7})$/;

                if (!serbianPhonePattern.test(apiFormat)) {
                    this.showPhoneError('Neispravan broj. Primer: +381601234567');
                    $phone.addClass('dexpress-error woocommerce-invalid');
                    $('#dexpress_phone_api').remove();
                    return false;
                }
            }

            // Generiši API format za validne brojeve (8+ cifara)
            if (apiFormat.length >= 11 && digitsAfter381.length >= 8) {
                $('#dexpress_phone_api').remove();
                $('<input type="hidden" id="dexpress_phone_api" name="dexpress_phone_api">')
                    .val(apiFormat).insertAfter($phone);
            }

            return true;
        },

        /**
         * Initialize form validation
         */
        initFormValidation: function () {
            const self = this;

            $(document).on('checkout_place_order', function () {
                if (!self.isDExpressSelected()) return true;
                return self.validateForm();
            });
        },

        /**
         * Validate the entire form
         */
        validateForm: function () {
            const type = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';
            let isValid = true;
            const errorMessages = [];
            if (!this.forceDropdownSelection(type, 'street')) {
                isValid = false;
                errorMessages.push('<strong>Ulica</strong> mora biti izabrana iz padajućeg menija.');
            }
            if (!this.forceDropdownSelection(type, 'city')) {
                isValid = false;
                errorMessages.push('<strong>Grad</strong> mora biti izabran iz padajuće liste.');
            }

            // Validate street
            if (!this.validateStreetField(type)) {
                isValid = false;
                errorMessages.push('<strong>Ulica</strong> mora biti izabrana iz padajućeg menija.');
            }

            // Validate city
            if (!this.validateCityField(type)) {
                isValid = false;
                errorMessages.push('<strong>Grad</strong> mora biti izabran iz padajuće liste.');
            }

            // Validate required fields
            const requiredFields = ['street', 'number', 'city'];
            requiredFields.forEach(field => {
                const $field = $('#' + type + '_' + field);
                if (!$field.val() || !$field.val().trim()) {
                    this.markFieldInvalid($field);
                    errorMessages.push('<strong>' + this.getFieldLabel($field) + '</strong> je obavezno polje.');
                    isValid = false;
                }
            });

            // Validate house number format
            const $number = $('#' + type + '_number');
            if ($number.val()) {
                if ($number.val().includes(' ')) {
                    this.markFieldInvalid($number);
                    errorMessages.push('Kućni broj ne sme sadržati razmake.');
                    isValid = false;
                } else if (!this.config.numberPattern.test($number.val()) || $number.val().length > 10) {
                    this.markFieldInvalid($number);
                    errorMessages.push('Neispravan format kućnog broja. Podržani formati: bb, 10, 15a, 23/4, 44b/2');
                    isValid = false;
                }
            }

            // Validate phone
            const $phone = $('#billing_phone');
            if ($phone.length && $phone.hasClass('dexpress-error')) {
                const existingError = $('.dexpress-phone-error').text();
                if (existingError) {
                    errorMessages.push('Telefon: ' + existingError);
                    isValid = false;
                }
            } else if ($phone.length) {
                const apiPhone = $('#dexpress_phone_api').val();
                if (!apiPhone || apiPhone.length < 10) {
                    errorMessages.push('Broj telefona mora imati između 8 i 10 cifara nakon +381.');
                    isValid = false;
                }
            }

            // Display errors if any
            if (!isValid) {
                this.displayFormErrors(errorMessages);
            }

            return isValid;
        },

        /**
         * Validate street field
         */
        validateStreetField: function (type) {
            const $street = $('#' + type + '_street');
            const $streetId = $('#' + type + '_street_id');
            const streetValue = $street.val().trim();
            const streetIdValue = $streetId.val();

            if (!streetValue) {
                this.clearFieldError($street);
                return true;
            }

            if (streetValue && !streetIdValue) {
                this.showFieldError($street, 'Molimo izaberite ulicu iz liste ili dodajte novu ulicu.');
                return false;
            }

            this.clearFieldError($street);
            return true;
        },
        showLoading: function ($field) {
            $field.closest('.woocommerce-input-wrapper').addClass('dexpress-loading');
        },
        hideLoading: function ($field) {
            $field.closest('.woocommerce-input-wrapper').removeClass('dexpress-loading');
        },
        /**
         * Validate city field
         */
        validateCityField: function (type) {
            const $city = $('#' + type + '_city');
            const $cityId = $('#' + type + '_city_id');
            const cityValue = $city.val().trim();
            const cityIdValue = $cityId.val();
            const validSelection = $city.data('validSelection');

            if (!cityValue) {
                this.clearFieldError($city);
                return true;
            }

            if (cityValue && (!cityIdValue || !validSelection)) {
                this.showFieldError($city, 'Molimo izaberite grad iz padajuće liste.');
                return false;
            }

            this.clearFieldError($city);
            return true;
        },

        /**
         * Validate phone field
         */
        validatePhoneField: function ($phone) {
            return this.validatePhoneRealTime($phone, $phone.val());
        },

        // ========== AJAX METHODS ==========

        /**
         * Search all streets
         */
        searchAllStreets: function (term, callback) {
            if (!term || term.length < 1) return callback([]);

            // Otkaži prethodnu pretragu
            this.cancelPreviousRequest('streets_' + term);

            // Proveri cache PRVO
            const cached = this.getCachedData('streets', term);
            if (cached) {

                callback(cached);
                return;
            }

            // Pokaži loader
            const $streetFields = $('#billing_street, #shipping_street');
            this.showLoading($streetFields);



            this.activeRequests['streets_' + term] = $.get(dexpressCheckout.ajaxUrl, {
                action: 'dexpress_search_streets',
                term: term,
                nonce: dexpressCheckout.nonce
            })
                .done(data => {

                    this.hideLoading($streetFields);
                    this.setCachedData('streets', term, data || []);
                    callback(data || []);
                    delete this.activeRequests['streets_' + term];
                })
                .fail(xhr => {

                    this.hideLoading($streetFields);
                    if (xhr.statusText !== 'abort') callback([]);
                    delete this.activeRequests['streets_' + term];
                });
        },

        /**
         * Search all towns
         */
        searchAllTowns: function (term, callback) {
            if (!term || term.length < 1) return callback([]);

            this.cancelPreviousRequest('towns_' + term);

            // Proveri cache PRVO
            const cached = this.getCachedData('towns', term);
            if (cached) {

                callback(this.formatTownsData(cached));
                return;
            }

            // Pokaži loader
            const $cityFields = $('#billing_city, #shipping_city');
            this.showLoading($cityFields);



            this.activeRequests['towns_' + term] = $.get(dexpressCheckout.ajaxUrl, {
                action: 'dexpress_search_all_towns',
                term: term,
                nonce: dexpressCheckout.nonce
            })
                .done(data => {

                    this.hideLoading($cityFields);
                    const formattedData = this.formatTownsData(data || []);
                    this.setCachedData('towns', term, formattedData);
                    callback(formattedData);
                    delete this.activeRequests['towns_' + term];
                })
                .fail(xhr => {

                    this.hideLoading($cityFields);
                    if (xhr.statusText !== 'abort') callback([]);
                    delete this.activeRequests['towns_' + term];
                });
        },
        /**
         * Search streets in specific town
         */
        searchStreetsInTown: function (term, townId, callback) {
            if (!term || !townId) return callback([]);

            const cached = this.getCachedData('streets_town', townId + '_' + term);
            if (cached) return callback(cached);

            const $streetFields = $('#billing_street, #shipping_street');
            this.showLoading($streetFields);

            $.get(dexpressCheckout.ajaxUrl, {
                action: 'dexpress_search_streets_for_town',
                term: term,
                town_id: townId,
                nonce: dexpressCheckout.nonce
            })
                .done(data => {
                    this.hideLoading($streetFields);
                    let results = data || [];

                    // Always add custom street option
                    results.push({
                        id: 'custom',
                        label: 'Dodajte novu ulicu: "' + term + '"',
                        value: term,
                        is_custom: true
                    });

                    this.setCachedData('streets_town', townId + '_' + term, results);
                    callback(results);
                })
                .fail(() => {
                    this.hideLoading($streetFields);
                    callback([{
                        id: 'custom',
                        label: 'Dodajte novu ulicu: "' + term + '"',
                        value: term,
                        is_custom: true
                    }]);
                });
        },

        /**
         * Load towns for selected street
         */
        loadTownsForStreet: function (type, streetName) {
            const loadKey = type + '_' + streetName;
            if (this.loadingTowns[loadKey]) return;



            this.loadingTowns[loadKey] = true;

            $.post(dexpressCheckout.ajaxUrl, {
                action: 'dexpress_get_towns_for_street',
                street_name: streetName,
                nonce: dexpressCheckout.nonce
            })
                .done(response => {


                    if (response && response.success && response.data && response.data.towns) {
                        const formattedTowns = this.formatTownsData(response.data.towns);

                        // ISPRAVKA - postavi fresh podatke
                        this.townsForStreet[type] = formattedTowns;
                        this.updateCityAutocomplete(type);



                        const $city = $('#' + type + '_city');
                        if ($city.is(':focus')) {
                            setTimeout(() => {
                                $city.autocomplete('search', '');
                                $city.autocomplete('widget').show();
                            }, 50);
                        }
                    }
                    delete this.loadingTowns[loadKey];
                })
                .fail(() => {

                    delete this.loadingTowns[loadKey];
                });
        },

        // ========== CACHE MANAGEMENT ==========

        /**
         * Get cached data with smart fallback
         */
        getCachedData: function (type, term) {
            const cacheKey = type + '_' + term;
            if (this.cache[cacheKey]) {
                return this.cache[cacheKey];
            }

            // Smart cache - check shorter terms for partial matches
            if (term.length >= 3) {
                for (let i = term.length - 1; i >= 2; i--) {
                    const shorterTerm = term.substring(0, i);
                    const shorterKey = type + '_' + shorterTerm;

                    if (this.cache[shorterKey] && this.cache[shorterKey].length > 0) {
                        const filtered = this.cache[shorterKey].filter(item => {
                            const searchField = type === 'towns' ? 'display_text' : 'label';
                            const searchIn = (item[searchField] || '').toLowerCase();
                            return searchIn.includes(term.toLowerCase());
                        });

                        if (filtered.length > 0) {
                            this.cache[cacheKey] = filtered;
                            return filtered;
                        }
                    }
                }
            }

            return null;
        },

        /**
         * Set cached data
         */
        setCachedData: function (type, term, data) {
            this.cache[type + '_' + term] = data;
        },

        /**
         * Cancel previous AJAX request
         */
        cancelPreviousRequest: function (key) {
            if (this.activeRequests[key]) {
                this.activeRequests[key].abort();
                delete this.activeRequests[key];
            }
        },

        // ========== AUTOCOMPLETE MANAGEMENT ==========

        /**
         * Update city autocomplete source
         */
        updateCityAutocomplete: function (type) {
            const $city = $('#' + type + '_city');
            const self = this;

            $city.autocomplete('option', 'source', function (request, response) {
                self.handleCitySearch(type, request.term, response);
            });
        },

        /**
         * Update street autocomplete source
         */
        updateStreetAutocomplete: function (type) {
            const $street = $('#' + type + '_street');
            const self = this;

            $street.autocomplete('option', 'source', function (request, response) {
                self.handleStreetSearch(type, request.term, response);
            });
        },


        // ========== UI HELPER METHODS ==========

        /**
         * Setup phone UI elements
         */
        setupPhoneUI: function ($phone) {
            if (!$phone.next('.dexpress-phone-hint').length) {
                $('<div class="dexpress-phone-hint">Primer: +381601234567</div>').insertAfter($phone);
            }

            if (!$phone.siblings('.dexpress-phone-error').length) {
                $('<div class="dexpress-phone-error" style="display:none; color:#e2401c; font-size:12px; margin-top:5px; padding:5px; background:#f8d7da; border-radius:3px;"></div>')
                    .insertAfter($phone.next('.dexpress-phone-hint'));
            }
        },

        /**
         * Show custom street modal
         */
        showCustomStreetModal: function (type, searchTerm) {
            const modalId = 'dexpress-custom-street-modal';
            $('#' + modalId).remove();

            const modalHtml = `
        <div id="${modalId}" class="dexpress-modal-overlay">
            <div class="dexpress-modal-content">
                <div class="dexpress-modal-header">
                    <h3>Unos nove ulice</h3>
                    <span class="dexpress-modal-close">&times;</span>
                </div>
                <div class="dexpress-modal-body">
                    <p>Ulica "<strong>${searchTerm}</strong>" nije u našoj bazi podataka.</p>
                    <label for="custom-street-input">Unesite naziv ulice:</label>
                    <input type="text" id="custom-street-input" value="${searchTerm}" 
                           placeholder="Unesite tačan naziv ulice">
                    <p style="font-size: 13px; color: #666; line-height: 1.4;">
                        <strong>Napomena:</strong> Unosite ulicu koja postoji u vašem mestu, ali još nije ažurirana u našoj bazi. 
                        Molimo proverite da li ste pravilno ukucali naziv.
                    </p>
                </div>
                <div class="dexpress-modal-footer">
                    <button type="button" class="dexpress-btn-secondary dexpress-modal-close">Odustani</button>
                    <button type="button" class="dexpress-btn-primary" id="confirm-custom-street">Potvrdi</button>
                </div>
            </div>
        </div>
    `;

            $('body').append(modalHtml);
            this.bindModalEvents(type, modalId);
        },
        /**
         * Bind modal events
         */
        bindModalEvents: function (type, modalId) {
            const $modal = $('#' + modalId);
            const $input = $('#custom-street-input');
            const self = this;

            // Close events
            $modal.find('.dexpress-modal-close').on('click', () => {
                $modal.remove();
                $(document).off('keydown.customStreet'); // Cleanup
            });

            // Click outside to close
            $modal.on('click', function (e) {
                if (e.target === this) {
                    $modal.remove();
                    $(document).off('keydown.customStreet');
                }
            });

            // Confirm event
            $modal.find('#confirm-custom-street').on('click', function () {
                const customStreet = $input.val().trim();
                if (customStreet) {
                    self.setCustomStreet(type, customStreet);
                    $modal.remove();
                    $(document).off('keydown.customStreet');
                } else {
                    alert('Molimo unesite naziv ulice');
                    $input.focus();
                }
            });

            // Keyboard events
            $input.focus().select();
            $input.on('keypress', e => {
                if (e.which === 13) { // Enter
                    e.preventDefault();
                    $modal.find('#confirm-custom-street').click();
                }
            });

            $(document).on('keydown.customStreet', e => {
                if (e.which === 27) { // Escape
                    $modal.remove();
                    $(document).off('keydown.customStreet');
                }
            });
        },

        /**
         * Set custom street value
         */
        setCustomStreet: function (type, streetName) {
            const $street = $('#' + type + '_street');
            const $streetId = $('#' + type + '_street_id');

            // ISPRAVKA - postavi custom ulicu pravilno
            $street.val(streetName);
            $streetId.val('custom_' + streetName);
            this.selectedStreet[type] = streetName; // POSTAVI SELECTED STREET!
            this.clearFieldError($street);

            // Očisti cache
            this.clearTownCacheForStreet(type);

            // Za custom ulicu - omogući pretragu svih gradova
            this.townsForStreet[type] = null;

            const $city = $('#' + type + '_city');
            if (!this.selectedTown[type] || !this.selectedTown[type].id) {
            }


            this.updateStandardAddressField(type);
        },

        /**
         * NOVA METODA - čisti cache gradova za ulicu
         */
        clearTownCacheForStreet: function (type) {
            this.townsForStreet[type] = null;

            // Jednostavnije čišćenje cache-a
            Object.keys(this.cache).forEach(key => {
                if (key.startsWith('towns_for_street_' + type)) {
                    delete this.cache[key];
                }
            });
        },
        /**
         * Render street autocomplete item
         */
        renderStreetItem: function (ul, item) {
            const $li = $("<li>").appendTo(ul);
            if (item && item.is_custom) {
                $li.addClass('custom-street-option');
                $li.html('<div style="font-weight: normal;">Dodajte ulicu</div>');
            } else {
                $li.html('<div>' + (item ? item.label || '' : '') + '</div>');
            }
            return $li;
        },

        /**
         * Render city autocomplete item
         */
        renderCityItem: function (ul, item) {
            const $li = $("<li>").appendTo(ul);
            const displayName = item.display_text || item.label || '';
            $li.html('<div>' + displayName + '</div>');
            return $li;
        },

        /**
         * Display form validation errors
         */
        displayFormErrors: function (errorMessages) {
            // Remove existing errors
            $('.woocommerce-error, .woocommerce-message').remove();

            // Add error list
            let errorHtml = '<ul class="woocommerce-error" role="alert">';
            errorMessages.forEach(msg => {
                errorHtml += '<li>' + msg + '</li>';
            });
            errorHtml += '</ul>';

            $('.woocommerce-notices-wrapper').first().html(errorHtml);

            // Scroll to errors
            $('html, body').animate({
                scrollTop: $('.woocommerce-error').offset().top - 100
            }, 300);
        },

        /**
         * Mark field as invalid
         */
        markFieldInvalid: function ($field) {
            $field.addClass('woocommerce-invalid woocommerce-invalid-required-field dexpress-error');
        },

        /**
         * Get field label for error messages
         */
        getFieldLabel: function ($field) {
            const fieldId = $field.attr('id');
            const $label = $('label[for="' + fieldId + '"]');
            return $label.length ? $label.text().replace('*', '').trim() : fieldId;
        },

        // ========== FIELD MANAGEMENT ==========

        /**
         * Clear street field
         */
        clearStreetField: function (type) {
            const $street = $('#' + type + '_street');
            const $streetId = $('#' + type + '_street_id');

            $street.val('');
            $streetId.val('');
            this.selectedStreet[type] = null;
            this.clearFieldError($street);
        },

        /**
         * Reset city field
         */
        resetCityField: function (type) {
            const $city = $('#' + type + '_city');
            const $cityId = $('#' + type + '_city_id');
            const $postcode = $('#' + type + '_postcode');

            $city.val('').data('validSelection', false);
            $cityId.val('');
            $postcode.val('');
            this.selectedTown[type] = null;
            this.clearFieldError($city);
        },

        /**
         * Update standard WooCommerce address field
         */
        updateStandardAddressField: function (type) {
            const $street = $('#' + type + '_street');
            const $number = $('#' + type + '_number');
            const $address1 = $('#' + type + '_address_1');

            const streetVal = $street.val() || '';
            const numberVal = $number.val() || '';

            if (streetVal && numberVal) {
                $address1.val(streetVal + ' ' + numberVal);
            } else if (streetVal) {
                $address1.val(streetVal);
            }
        },

        /**
         * Clean city name from display format
         */
        cleanCityName: function (cityDisplayText) {
            if (!cityDisplayText) return '';

            // "21000 Novi Sad (Novi Sad) - 21000" → "Novi Sad"
            let match = cityDisplayText.match(/^(\d{5})\s+(.+?)\s+\(\1\)\s+-\s+\1$/);
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

        /**
         * Format towns data for display
         */
        formatTownsData: function (towns) {
            return (towns || []).map(town => {
                let displayText = '';

                if (town.display_name && (town.display_name.includes('(') || town.display_name.includes('-'))) {
                    displayText = town.display_name;
                } else {
                    if (town.display_name) {
                        displayText = town.display_name;
                        if (town.municipality_name && town.municipality_name !== town.display_name) {
                            displayText += ' (' + town.municipality_name + ')';
                        }
                    } else {
                        displayText = town.label || town.value || town.name || '';
                    }

                    if (town.postal_code && !displayText.includes(town.postal_code)) {
                        displayText += ' - ' + town.postal_code;
                    }
                }

                return {
                    ...town,
                    display_text: displayText,
                    town_id: town.town_id || town.id || '',
                    label: town.display_name || town.name || town.label || '',
                    postal_code: town.postal_code || ''
                };
            });
        },

        // ========== ERROR HANDLING ==========

        /**
         * Show field error
         */
        showFieldError: function ($field, message) {
            $field.addClass('dexpress-error woocommerce-invalid');
            $field.next('.dexpress-error-message').remove();
            $('<div class="dexpress-error-message" style="color:#e2401c; font-size:12px; margin-top:5px; padding:5px; background:#f8d7da; border-radius:3px;">' + message + '</div>')
                .insertAfter($field);
        },

        /**
         * Clear field error
         */
        clearFieldError: function ($field) {
            $field.removeClass('dexpress-error woocommerce-invalid woocommerce-invalid-required-field');
            $field.next('.dexpress-error-message').remove();
        },

        /**
         * Show phone error
         */
        showPhoneError: function (message) {
            $('.dexpress-phone-error').text(message).show();
        },

        /**
         * Clear phone error
         */
        clearPhoneError: function () {
            $('.dexpress-phone-error').hide();
        },

        // ========== SHIPPING METHOD MANAGEMENT ==========

        /**
         * Initialize shipping method watcher
         */
        initShippingMethodWatcher: function () {
            this.updatePhoneRequirement();
            $(document.body).on('updated_checkout', () => this.updatePhoneRequirement());
            $(document).on('change', 'input.shipping_method', () => this.updatePhoneRequirement());
        },

        /**
         * Validation - dodaj missing forceDropdownSelection metodu
         */
        forceDropdownSelection: function (type, fieldType) {
            const $field = $('#' + type + '_' + fieldType);
            const $idField = $('#' + type + '_' + fieldType + '_id');

            // Ako ima vrednost ali nema ID, nije izabrano iz dropdown-a
            if ($field.val() && !$idField.val()) {
                this.showFieldError($field, 'Morate izabrati iz padajuće liste');
                return false;
            }

            return true;
        },
        /**
         * Check if D Express shipping is selected
         */
        isDExpressSelected: function () {
            return $('.shipping_method:checked, .shipping_method:hidden').toArray()
                .some(el => el.value && el.value.includes('dexpress'));
        },

        /**
         * Update phone field requirement based on shipping method
         */
        updatePhoneRequirement: function () {
            const isDExpress = this.isDExpressSelected();
            const $phoneLabel = $('label[for="billing_phone"]');
            const $phoneField = $('#billing_phone');

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

        // ========== EVENT BINDING ==========

        /**
         * Bind global events
         */
        bindEvents: function () {
            const self = this;

            // Handle shipping address checkbox
            $('#ship-to-different-address-checkbox').on('change', function () {
                setTimeout(() => self.initAddressFields('shipping'), 100);
            });

            // Handle checkout error highlighting
            $(document.body).on('checkout_error', function () {
                if (!self.isDExpressSelected()) return;

                const addressType = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing';
                const fields = [addressType + '_street', addressType + '_number', addressType + '_city'];

                $('.woocommerce-error li').each(function () {
                    const errorText = $(this).text().trim();

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

            // Handle house number input
            $('body').on('input', '#billing_number, #shipping_number', function () {
                self.updateStandardAddressField($(this).attr('id').split('_')[0]);
            });
        }
    };

    // Initialize when DOM is ready
    $(document).ready(() => DExpressCheckout.init());

})(jQuery);
