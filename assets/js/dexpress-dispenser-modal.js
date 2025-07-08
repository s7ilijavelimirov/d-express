(function ($) {
    'use strict';

    var DExpressDispenserModal = {
        // Osnovne varijable
        dispensers: [],
        towns: [],
        filteredDispensers: [],
        selectedTownId: null,
        
        // Mapa varijable
        map: null,
        markers: [],
        bounds: null,
        infoWindow: null,
        hasGoogleMaps: false,
        isMapInitialized: false,

        // Paginacija
        currentPage: 1,
        pageSize: 20,

        init: function () {
            console.log('[D-Express] Inicijalizujem dispenser modal...');
            
            this.hasGoogleMaps = (typeof google !== 'undefined' && typeof google.maps !== 'undefined');
            console.log('[D-Express] Google Maps available:', this.hasGoogleMaps);

            this.bindEvents();
            this.setupShippingMethodHandler();
        },

        bindEvents: function () {
            var self = this;
            
            // Otvaranje modala
            $(document).on('click', '.dexpress-select-dispenser-btn, .dexpress-change-dispenser', function (e) {
                e.preventDefault();
                self.openModal();
            });

            // Zatvaranje modala
            $(document).on('click', '.dexpress-modal-close', function () {
                self.closeModal();
            });

            // ESC dugme za zatvaranje
            $(document).on('keyup', function (e) {
                if (e.key === "Escape") {
                    self.closeModal();
                }
            });

            // Reset filter
            $(document).on('click', '.dexpress-reset-filter', function () {
                self.resetFilter();
            });

            // Pagination
            $(document).on('click', '.dexpress-load-more-btn', function () {
                self.loadMoreDispensers();
            });

            // Checkout update
            $(document.body).on('updated_checkout.dexpress', function () {
                self.setupShippingMethodHandler();
            });
        },

        openModal: function () {
            console.log('[D-Express] Otvaranje modala...');
            
            $('#dexpress-dispenser-modal').addClass('show');
            $('body').css('overflow', 'hidden');

            if (!this.isMapInitialized) {
                this.loadData();
            } else {
                this.renderDispensers();
            }
        },

        closeModal: function () {
            console.log('[D-Express] Zatvaranje modala...');
            $('#dexpress-dispenser-modal').removeClass('show');
            $('body').css('overflow', '');
        },

        loadData: function () {
            var self = this;
            
            this.showLoader('#dexpress-dispensers-list', 'Učitavanje podataka...');
            
            // Učitaj sve podatke odjednom
            $.when(
                this.loadTowns(),
                this.loadDispensers()
            ).done(function () {
                console.log('[D-Express] Svi podaci učitani, inicijalizujem autocomplete');
                self.hideLoader('#dexpress-dispensers-list');
                self.initTownAutocomplete(); // DODANO - eksplicitno pozivanje
                self.initMap();
                self.renderDispensers();
                self.isMapInitialized = true;
            }).fail(function () {
                self.hideLoader('#dexpress-dispensers-list');
                self.showError('Greška pri učitavanju podataka');
            });
        },

        loadTowns: function () {
            var self = this;
            
            return $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'dexpress_get_towns_with_dispensers',
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    if (response.success && response.data.towns) {
                        self.towns = response.data.towns;
                        console.log('[D-Express] Učitano gradova:', self.towns.length);
                    }
                }
            });
        },

        loadDispensers: function () {
            var self = this;
            
            return $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'dexpress_get_all_dispensers',
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    if (response.success && response.data.dispensers) {
                        self.dispensers = response.data.dispensers;
                        console.log('[D-Express] Učitano paketomata:', self.dispensers.length);
                    }
                }
            });
        },

        initTownAutocomplete: function () {
            var self = this;
            var searchTimeout;
            var $input = $('#dexpress-town-select');
            var $suggestions = $('#dexpress-town-suggestions');
            var $resetBtn = $('.dexpress-reset-filter');

            console.log('[D-Express] Inicijalizujem autocomplete, broj gradova:', this.towns.length);

            // Input event za autocomplete
            $input.on('input', function () {
                var term = $(this).val().trim().toLowerCase();
                console.log('[D-Express] Searching for:', term);

                // Clear postojeći timeout
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }

                // Prikaži/sakrij reset dugme
                if (term.length > 0) {
                    $resetBtn.addClass('show');
                } else {
                    $resetBtn.removeClass('show');
                    $suggestions.hide();
                    self.resetFilter(); // Pozovi resetFilter umesto clearAllFilters
                    return;
                }

                if (term.length < 2) {
                    $suggestions.hide();
                    return;
                }

                // Throttling - sačekaj 300ms
                searchTimeout = setTimeout(function () {
                    self.searchInLoadedTowns(term);
                }, 300);
            });

            // Reset dugme
            $resetBtn.on('click', function () {
                console.log('[D-Express] Reset filter clicked');
                $input.val('');
                $resetBtn.removeClass('show');
                $suggestions.hide();
                self.resetFilter(); // Pozovi resetFilter umesto clearAllFilters
            });

            // ESC dugme
            $input.on('keydown', function (e) {
                if (e.key === 'Escape') {
                    $suggestions.hide();
                }
            });

            // Klik van elementa
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.dexpress-town-filter').length) {
                    $suggestions.hide();
                }
            });
        },

        searchInLoadedTowns: function (term) {
            console.log('[D-Express] Searching in loaded towns for:', term);
            console.log('[D-Express] Available towns:', this.towns);
            
            // Normalizuj term za pretragu (ukloni dijakritike)
            var normalizedTerm = this.normalizeText(term);
            
            // Pretražuj kroz već učitane gradove
            var matchedTowns = this.towns.filter(function (town) {
                var normalizedTownName = this.normalizeText(town.name);
                return normalizedTownName.includes(normalizedTerm);
            }.bind(this));

            console.log('[D-Express] Found matches:', matchedTowns);

            if (matchedTowns.length > 0) {
                this.renderTownSuggestions(matchedTowns, term);
            } else {
                $('#dexpress-town-suggestions').html('<div class="no-suggestion">Nema rezultata za "' + term + '"</div>').show();
            }
        },

        // Funkcija za normalizaciju teksta (uklanja dijakritike)
        normalizeText: function (text) {
            return text.toLowerCase()
                .replace(/[čć]/g, 'c')
                .replace(/[đ]/g, 'd') 
                .replace(/[š]/g, 's')
                .replace(/[ž]/g, 'z')
                .replace(/[áàâä]/g, 'a')
                .replace(/[éèêë]/g, 'e')
                .replace(/[íìîï]/g, 'i')
                .replace(/[óòôö]/g, 'o')
                .replace(/[úùûü]/g, 'u');
        },

        renderTownSuggestions: function (towns, term) {
            console.log('[D-Express] Rendering suggestions:', towns);
            
            var html = '';
            var MAX_SUGGESTIONS = 8;
            
            // Sortiraj - prvo gradovi koji počinju sa terminom
            towns.sort(function (a, b) {
                var normalizedTerm = this.normalizeText(term);
                var aStartsWith = this.normalizeText(a.name).startsWith(normalizedTerm);
                var bStartsWith = this.normalizeText(b.name).startsWith(normalizedTerm);
                
                if (aStartsWith && !bStartsWith) return -1;
                if (!aStartsWith && bStartsWith) return 1;
                
                // Zatim po broju paketomata
                return (b.dispenser_count || 0) - (a.dispenser_count || 0);
            }.bind(this));

            // Ograniči rezultate
            var limitedTowns = towns.slice(0, MAX_SUGGESTIONS);
            
            limitedTowns.forEach(function (town) {
                var displayName = town.name;
                if (town.dispenser_count) {
                    displayName += ' (' + town.dispenser_count + ' paketomata)';
                }
                
                html += '<div class="town-suggestion-header" data-town-id="' + town.id + '" data-name="' + town.name + '">';
                html += '<strong>' + displayName + '</strong>';
                html += '</div>';
            });

            if (towns.length > MAX_SUGGESTIONS) {
                html += '<div class="dispenser-suggestion-more">... i još ' + (towns.length - MAX_SUGGESTIONS) + ' gradova</div>';
            }

            if (html) {
                $('#dexpress-town-suggestions').html(html).show();
                this.bindSuggestionEvents();
            } else {
                $('#dexpress-town-suggestions').html('<div class="no-suggestion">Nema rezultata</div>').show();
            }
        },

        bindSuggestionEvents: function () {
            var self = this;
            
            // Klik na grad
            $('.town-suggestion-header').off('click').on('click', function () {
                var townId = $(this).data('town-id');
                var townName = $(this).data('name');
                
                $('#dexpress-town-select').val(townName);
                $('#dexpress-town-suggestions').hide();
                $('.dexpress-reset-filter').addClass('show');
                
                self.filterByTown(townId);
            });
        },

        initTownSelect: function () {
            var $select = $('#dexpress-town-select');
            $select.empty();
            
            // Dodaj default opciju
            $select.append('<option value="">' + dexpressCheckout.i18n.allTowns + '</option>');
            
            // Sortiraj gradove po nazivu
            var sortedTowns = this.towns.sort(function (a, b) {
                return a.name.localeCompare(b.name, 'sr-RS');
            });
            
            // Dodaj gradove
            sortedTowns.forEach(function (town) {
                $select.append('<option value="' + town.id + '">' + town.name + ' (' + town.dispenser_count + ')</option>');
            });
        },

        initMap: function () {
            if (!this.hasGoogleMaps) {
                this.showMapPlaceholder();
                return;
            }

            try {
                this.map = new google.maps.Map(document.getElementById('dexpress-dispensers-map'), {
                    center: { lat: 44.0165, lng: 21.0059 }, // Centar Srbije
                    zoom: 7,
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: true,
                    zoomControl: true,
                    gestureHandling: 'greedy'
                });

                this.bounds = new google.maps.LatLngBounds();
                this.infoWindow = new google.maps.InfoWindow();
                
                console.log('[D-Express] Google Maps uspešno inicijalizovana');
            } catch (error) {
                console.error('[D-Express] Greška pri inicijalizaciji mape:', error);
                this.showMapPlaceholder();
            }
        },

        showMapPlaceholder: function () {
            $('#dexpress-dispensers-map').html(`
                <div class="dexpress-map-placeholder">
                    <div class="icon">🗺️</div>
                    <p>Mapa nije dostupna</p>
                    <small>Google Maps API nije omogućen</small>
                </div>
            `);
        },

        filterByTown: function (townId) {
            console.log('[D-Express] Filtriram po gradu:', townId);
            
            this.selectedTownId = townId ? parseInt(townId) : null;
            this.currentPage = 1;
            this.renderDispensers();
        },

        resetFilter: function () {
            console.log('[D-Express] Resetujem filter');
            
            this.selectedTownId = null;
            this.currentPage = 1;
            $('#dexpress-town-select').val('');
            $('.dexpress-reset-filter').removeClass('show');
            $('#dexpress-town-suggestions').hide();
            this.renderDispensers();
        },

        renderDispensers: function () {
            console.log('[D-Express] Renderujem paketomata...');
            
            // Filtriraj paketomata
            this.filteredDispensers = this.dispensers.filter(function (dispenser) {
                if (!this.selectedTownId) return true;
                return parseInt(dispenser.town_id) === this.selectedTownId;
            }.bind(this));

            if (this.filteredDispensers.length === 0) {
                this.showNoResults();
                this.clearMapMarkers();
                return;
            }

            // Renderuj listu
            this.renderDispensersList();
            
            // Renderuj mapu
            this.renderMapMarkers();
        },

        renderDispensersList: function () {
            var startIndex = (this.currentPage - 1) * this.pageSize;
            var endIndex = startIndex + this.pageSize;
            var pageDispensers = this.filteredDispensers.slice(startIndex, endIndex);
            
            var html = '';
            
            // Info header
            html += '<div class="dexpress-dispensers-info">';
            html += '<p>Prikazano ' + Math.min(endIndex, this.filteredDispensers.length) + ' od ' + this.filteredDispensers.length + ' paketomata</p>';
            if (this.selectedTownId) {
                html += '<button type="button" class="dexpress-reset-filter">Prikaži sve gradove</button>';
            }
            html += '</div>';

            // Lista paketomata
            pageDispensers.forEach(function (dispenser) {
                var paymentMethods = [];
                if (dispenser.pay_by_cash) paymentMethods.push('Gotovina');
                if (dispenser.pay_by_card) paymentMethods.push('Kartica');

                html += '<div class="dexpress-dispenser-item" data-id="' + dispenser.id + '">';
                html += '  <div class="dispenser-content">';
                html += '    <div class="dispenser-header">';
                html += '      <h4 class="dispenser-name">' + dispenser.name + '</h4>';
                html += '      <div class="dispenser-status online"></div>';
                html += '    </div>';
                html += '    <div class="dispenser-address">' + dispenser.address + '</div>';
                html += '    <div class="dispenser-town">' + dispenser.town + '</div>';
                html += '    <div class="dispenser-details">';
                html += '      <span class="work-hours">Radno vreme: ' + (dispenser.work_hours || '0-24') + '</span>';
                if (paymentMethods.length > 0) {
                    html += '      <span class="payment-methods">Plaćanje: ' + paymentMethods.join(', ') + '</span>';
                }
                html += '    </div>';
                html += '  </div>';
                html += '  <button class="dispenser-select-btn" data-id="' + dispenser.id + '">Izaberi</button>';
                html += '</div>';
            });

            // Load more dugme
            if (endIndex < this.filteredDispensers.length) {
                html += '<div class="dexpress-load-more-container">';
                html += '  <button class="dexpress-load-more-btn">';
                html += '    Učitaj još (' + (this.filteredDispensers.length - endIndex) + ' preostalo)';
                html += '  </button>';
                html += '</div>';
            }

            // Zameni sadržaj ili dodaj
            if (this.currentPage === 1) {
                $('#dexpress-dispensers-list').html(html);
            } else {
                $('#dexpress-dispensers-list .dexpress-load-more-container').remove();
                $('#dexpress-dispensers-list').append(html);
            }

            this.bindDispenserEvents();
        },

        bindDispenserEvents: function () {
            var self = this;
            
            // Klik na paketomat
            $('.dexpress-dispenser-item').off('click').on('click', function (e) {
                if ($(e.target).hasClass('dispenser-select-btn')) return;
                
                var id = $(this).data('id');
                self.highlightDispenser(id);
            });

            // Klik na dugme za izbor
            $('.dispenser-select-btn').off('click').on('click', function (e) {
                e.stopPropagation();
                var id = $(this).data('id');
                self.selectDispenser(id);
            });
        },

        renderMapMarkers: function () {
            if (!this.hasGoogleMaps || !this.map) return;
            
            this.clearMapMarkers();
            
            this.filteredDispensers.forEach(function (dispenser) {
                if (!dispenser.latitude || !dispenser.longitude) return;
                
                this.createMarker(dispenser);
            }.bind(this));
            
            this.adjustMapView();
        },

        createMarker: function (dispenser) {
            var marker = new google.maps.Marker({
                position: { 
                    lat: parseFloat(dispenser.latitude), 
                    lng: parseFloat(dispenser.longitude) 
                },
                map: this.map,
                title: dispenser.name,
                icon: {
                    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(`
                        <svg width="32" height="32" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="16" cy="16" r="14" fill="#E90000" stroke="#ffffff" stroke-width="2"/>
                            <circle cx="16" cy="16" r="5" fill="white"/>
                        </svg>
                    `),
                    scaledSize: new google.maps.Size(32, 32),
                    anchor: new google.maps.Point(16, 16)
                }
            });

            marker.addListener('click', function () {
                this.showDispenserInfo(dispenser, marker);
            }.bind(this));

            this.markers.push({
                marker: marker,
                dispenser: dispenser
            });

            this.bounds.extend(marker.getPosition());
        },

        showDispenserInfo: function (dispenser, marker) {
            var paymentMethods = [];
            if (dispenser.pay_by_cash) paymentMethods.push('Gotovina');
            if (dispenser.pay_by_card) paymentMethods.push('Kartica');

            var content = `
                <div class="dexpress-info-window">
                    <h4>${dispenser.name}</h4>
                    <p><strong>Adresa:</strong> ${dispenser.address}</p>
                    <p><strong>Grad:</strong> ${dispenser.town}</p>
                    <p><strong>Radno vreme:</strong> ${dispenser.work_hours || '0-24'}</p>
                    ${paymentMethods.length > 0 ? `<p><strong>Plaćanje:</strong> ${paymentMethods.join(', ')}</p>` : ''}
                    <button class="dexpress-select-this-dispenser" data-id="${dispenser.id}">
                        Izaberi ovaj paketomat
                    </button>
                </div>
            `;

            this.infoWindow.setContent(content);
            this.infoWindow.open(this.map, marker);

            // ISPRAVKA: Dodaj event listener za dugme u info window
            var self = this;
            setTimeout(function() {
                $('.dexpress-select-this-dispenser').off('click').on('click', function () {
                    var id = $(this).data('id');
                    console.log('[D-Express] Selecting dispenser from info window:', id);
                    self.selectDispenser(id);
                    self.infoWindow.close();
                });
            }, 100);
        },

        highlightDispenser: function (id) {
            if (!this.hasGoogleMaps) return;

            var markerData = this.markers.find(function (m) {
                return m.dispenser.id == id;
            });

            if (markerData) {
                this.map.setCenter(markerData.marker.getPosition());
                this.map.setZoom(15);
                this.showDispenserInfo(markerData.dispenser, markerData.marker);

                // Highlight u listi
                $('.dexpress-dispenser-item').removeClass('selected');
                $('.dexpress-dispenser-item[data-id="' + id + '"]').addClass('selected');
            }
        },

        selectDispenser: function (id) {
            var dispenser = this.dispensers.find(function (d) {
                return d.id == id;
            });

            if (!dispenser) {
                console.error('[D-Express] Paketomat sa ID ' + id + ' nije pronađen');
                return;
            }

            console.log('[D-Express] Selektujem paketomat:', dispenser);

            // Prikaži success animaciju UNUTAR modala
            this.showSuccessAnimation(dispenser);

            // Sačuvaj paketomat
            this.saveChosenDispenser(dispenser);
        },

        showSuccessAnimation: function (dispenser) {
            var self = this;
            
            // Kreiraj success overlay SAMO preko modal body-ja
            var successOverlay = $(`
                <div class="dexpress-success-overlay">
                    <div class="dexpress-success-content">
                        <div class="dexpress-success-icon">
                            <div class="checkmark">
                                <div class="checkmark_stem"></div>
                                <div class="checkmark_kick"></div>
                            </div>
                        </div>
                        <h3>Paketomat je izabran!</h3>
                        <div class="dexpress-success-details">
                            <strong>${dispenser.name}</strong><br>
                            <span>${dispenser.address}, ${dispenser.town}</span>
                        </div>
                        <div class="dexpress-success-message">
                            <div class="dexpress-progress-bar">
                                <div class="dexpress-progress-fill"></div>
                            </div>
                            <span>Čuvanje i zatvaranje...</span>
                        </div>
                    </div>
                </div>
            `);

            // Dodaj overlay SAMO u modal body (ne preko celog modala)
            $('.dexpress-modal-body').css('position', 'relative').append(successOverlay);

            // Animacija pojavljivanja
            setTimeout(function() {
                successOverlay.addClass('show');
            }, 50);
        },

        hideSuccessAnimation: function() {
            var successOverlay = $('.dexpress-success-overlay');
            
            successOverlay.addClass('fade-out');
            setTimeout(function() {
                successOverlay.remove();
                $('.dexpress-modal-body').css('position', '');
            }, 500);
        },

        saveChosenDispenser: function (dispenser) {
            var chosenDispenser = {
                id: dispenser.id,
                name: dispenser.name,
                address: dispenser.address,
                town: dispenser.town,
                town_id: dispenser.town_id,
                postal_code: dispenser.postal_code
            };

            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dexpress_save_chosen_dispenser',
                    dispenser: chosenDispenser,
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    console.log('[D-Express] Save response:', response);

                    if (response.success) {
                        // Ažuriraj poruku da je uspešno sačuvano
                        $('.dexpress-success-message span').text('Uspešno sačuvano!');
                        $('.dexpress-progress-fill').css('width', '100%');
                        
                        // Zatvori modal nakon kratke pauze
                        setTimeout(function() {
                            self.hideSuccessAnimation();
                            
                            setTimeout(function() {
                                self.closeModal();
                                
                                // Osvezi checkout
                                setTimeout(function () {
                                    $('body').trigger('update_checkout');
                                }, 100);
                            }, 300);
                        }, 1000);
                    } else {
                        console.error('[D-Express] Greška pri čuvanju paketomata:', response);
                        
                        // Prikaži grešku u animaciji
                        $('.dexpress-success-message span').text('Greška! Pokušajte ponovo.');
                        $('.dexpress-success-content h3').text('Greška!').css('color', '#dc3545');
                        $('.checkmark').css('border-color', '#dc3545');
                        
                        setTimeout(function() {
                            self.hideSuccessAnimation();
                        }, 2000);
                    }
                }.bind(this),
                error: function (xhr, status, error) {
                    console.error('[D-Express] AJAX greška pri čuvanju:', error);
                    
                    // Prikaži grešku u animaciji
                    $('.dexpress-success-message span').text('Greška pri komunikaciji!');
                    $('.dexpress-success-content h3').text('Greška!').css('color', '#dc3545');
                    $('.checkmark').css('border-color', '#dc3545');
                    
                    setTimeout(function() {
                        self.hideSuccessAnimation();
                    }, 2000);
                }
            });
        },

        loadMoreDispensers: function () {
            this.currentPage++;
            this.renderDispensersList();
        },

        clearMapMarkers: function () {
            if (this.markers.length > 0) {
                this.markers.forEach(function (markerData) {
                    markerData.marker.setMap(null);
                });
                this.markers = [];
            }

            if (this.bounds) {
                this.bounds = new google.maps.LatLngBounds();
            }
        },

        adjustMapView: function () {
            if (!this.hasGoogleMaps || !this.map || this.markers.length === 0) return;

            if (this.markers.length === 1) {
                this.map.setCenter(this.markers[0].marker.getPosition());
                this.map.setZoom(14);
            } else {
                this.map.fitBounds(this.bounds);
                
                google.maps.event.addListenerOnce(this.map, 'bounds_changed', function () {
                    if (this.map.getZoom() > 16) {
                        this.map.setZoom(16);
                    }
                }.bind(this));
            }
        },

        showNoResults: function () {
            $('#dexpress-dispensers-list').html(`
                <div class="no-results">
                    <div class="no-results-message">Nema dostupnih paketomata za izabrani filter</div>
                    <div class="no-results-hint">
                        <button type="button" class="dexpress-reset-filter">Resetuj filter</button>
                    </div>
                </div>
            `);
        },

        showError: function (message) {
            $('#dexpress-dispensers-list').html(`
                <div class="no-results">
                    <div class="no-results-message">${message}</div>
                    <div class="no-results-hint">Molimo pokušajte ponovo</div>
                </div>
            `);
        },

        showLoader: function (container, text = 'Učitavanje...') {
            var $container = $(container);
            $container.addClass('dexpress-loading');
            
            if ($container.find('.dexpress-loading-overlay').length === 0) {
                $container.append(`
                    <div class="dexpress-loading-overlay">
                        <div class="dexpress-loader"></div>
                        <span class="dexpress-loading-text">${text}</span>
                    </div>
                `);
            }
        },

        hideLoader: function (container) {
            $(container).removeClass('dexpress-loading');
            $(container).find('.dexpress-loading-overlay').remove();
        },

        setupShippingMethodHandler: function () {
            console.log('[D-Express] Podešavam shipping method handler');

            $('input.shipping_method').off('change.dexpress').on('change.dexpress', function () {
                var methodId = $(this).val();
                console.log('[D-Express] Shipping method changed to:', methodId);

                if (methodId && methodId.indexOf('dexpress_dispenser') !== -1) {
                    $('.dexpress-dispenser-selection').show();
                    $('.dexpress-dispenser-wrapper').show();
                } else {
                    $('.dexpress-dispenser-selection').hide();
                    $('.dexpress-dispenser-wrapper').hide();
                }
            });

            // Trigger za trenutno izabranu metodu
            $('input.shipping_method:checked').trigger('change.dexpress');
        }
    };

    // Inicijalizacija kada je DOM spreman
    $(document).ready(function () {
        if ($('form.woocommerce-checkout').length > 0) {
            console.log('[D-Express] Inicijalizujem na checkout stranici');
            DExpressDispenserModal.init();
        }
    });

})(jQuery);