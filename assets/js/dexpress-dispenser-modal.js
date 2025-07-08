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

        // Timeout varijable
        zoomChangeTimeout: null,
        boundsChangeTimeout: null,
        infoWindowTimeout: null,
        selectedCityName: null,
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
            this.resetModalState();
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
                self.initTownAutocomplete();
                self.initMapWithClustering();
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
                    self.resetFilter();
                    return;
                }

                if (term.length < 2) {
                    $suggestions.hide();
                    return;
                }

                // Throttling - sačekaj 300ms
                searchTimeout = setTimeout(function () {
                    self.searchInLoadedDispensers(term);
                }, 300);
            });

            // Reset dugme
            $resetBtn.on('click', function () {
                console.log('[D-Express] Reset filter clicked');
                $input.val('');
                $resetBtn.removeClass('show');
                $suggestions.hide();
                self.resetFilter();
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

        searchInLoadedDispensers: function (term) {
            var self = this;
            console.log('[D-Express] Searching dispensers for:', term);

            // PRIKAŽI LOADER U INPUT POLJU
            this.showInputLoader(true);

            // Pretraži putem AJAX-a
            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'dexpress_search_dispensers',
                    term: term,
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    // SAKRIJ LOADER
                    self.showInputLoader(false);

                    if (response.success && response.data.dispensers) {
                        self.renderDispenserSuggestions(response.data.dispensers, term);
                    } else {
                        $('#dexpress-town-suggestions').html('<div class="no-suggestion">Nema rezultata za "' + term + '"</div>').show();
                    }
                },
                error: function () {
                    // SAKRIJ LOADER
                    self.showInputLoader(false);
                    $('#dexpress-town-suggestions').html('<div class="no-suggestion">Greška pri pretrazi</div>').show();
                }
            });
        },
        showInputLoader: function (show) {
            var $input = $('#dexpress-town-select');
            var $resetBtn = $('.dexpress-reset-filter');

            if (show) {
                // Dodaj loader ikonu
                if (!$input.siblings('.dexpress-input-loader').length) {
                    var loaderHtml = '<div class="dexpress-input-loader">' +
                        '<div class="dexpress-input-spinner"></div>' +
                        '</div>';
                    $input.after(loaderHtml);
                }
                $resetBtn.hide(); // Sakrij reset dugme dok učitava
            } else {
                // Ukloni loader
                $input.siblings('.dexpress-input-loader').remove();
                if ($input.val().length > 0) {
                    $resetBtn.show(); // Prikaži reset dugme
                }
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

        renderDispenserSuggestions: function (dispensers, term) {
            console.log('[D-Express] Rendering dispenser suggestions:', dispensers);

            var html = '';

            dispensers.forEach(function (dispenser) {
                // Podeli adresu i grad
                var addressParts = dispenser.address.split(',');
                var streetAddress = addressParts[0] || dispenser.address;

                html += '<div class="dispenser-suggestion" data-dispenser-id="' + dispenser.id + '">';
                html += '  <div class="dispenser-suggestion-name"><strong>' + dispenser.name + '</strong></div>';
                html += '  <div class="dispenser-suggestion-address">' + streetAddress + '</div>';
                html += '  <div class="dispenser-suggestion-city">' + dispenser.town + '</div>';
                html += '</div>';
            });

            if (html) {
                $('#dexpress-town-suggestions').html(html).show();
                this.bindDispenserSuggestionEvents();
            } else {
                $('#dexpress-town-suggestions').html('<div class="no-suggestion">Nema rezultata</div>').show();
            }
        },

        bindDispenserSuggestionEvents: function () {
            var self = this;

            // Klik na paketomat iz suggestions
            $('.dispenser-suggestion').off('click').on('click', function () {
                var dispenserId = $(this).data('dispenser-id');
                var selectedDispenser = self.dispensers.find(function (d) {
                    return d.id == dispenserId;
                });

                if (!selectedDispenser) return;

                // Sakrij suggestions
                $('#dexpress-town-suggestions').hide();
                $('#dexpress-town-select').val('');
                $('.dexpress-reset-filter').removeClass('show');

                // NOVA LOGIKA: Filtriraj po nazivu grada (town) umesto town_id
                var selectedCityName = self.normalizeCityName(selectedDispenser.town);

                // DEBUG
                console.log('[DEBUG] suggestion click - selectedDispenser.town:', selectedDispenser.town);
                console.log('[DEBUG] suggestion click - normalized:', selectedCityName);

                // POSTAVI selectedCityName umesto selectedTownId
                self.selectedCityName = selectedCityName;
                self.selectedTownId = null; // resetuj town_id
                self.currentPage = 1; // KRITIČNO - resetuj paginaciju!

                self.filteredDispensers = self.dispensers.filter(function (d) {
                    var normalizedTown = self.normalizeCityName(d.town);

                    // DEBUG
                    if (normalizedTown === selectedCityName) {
                        console.log('[DEBUG] suggestion MATCH:', d.town, '→', normalizedTown);
                    }

                    return normalizedTown === selectedCityName;
                });

                console.log('[D-Express] Prikazujem sve paketomata iz grada:', selectedDispenser.town,
                    '(ukupno:', self.filteredDispensers.length, ')');

                // Renderuj listu i mapu
                self.renderDispensersList();
                self.renderMapMarkers();

                // Fokusiraj mapu na izabrani paketomat ali prikaži sve
                self.focusMapOnDispenserAndShowAll(selectedDispenser);

                // Highlight paketomat u listi
                setTimeout(function () {
                    $('.dexpress-dispenser-item[data-id="' + dispenserId + '"]').addClass('selected');
                }, 100);
            });
        },

        focusMapOnDispenserAndShowAll: function (selectedDispenser) {
            if (!this.hasGoogleMaps || !this.map || !selectedDispenser.latitude || !selectedDispenser.longitude) {
                return;
            }

            console.log('[D-Express] Focusing map on dispenser and showing all from city:', selectedDispenser.name);

            // Fokusiraj mapu na izabrani paketomat
            var position = { lat: selectedDispenser.latitude, lng: selectedDispenser.longitude };
            this.map.setCenter(position);

            // JEDNOSTAVAN ZOOM - uvek 13
            this.map.setZoom(13);

            // KRACI TIMEOUT
            setTimeout(function () {
                var markerData = this.markers.find(function (m) {
                    return m.dispenser && m.dispenser.id == selectedDispenser.id;
                });

                if (markerData) {
                    this.showDispenserInfo(selectedDispenser, markerData.marker);
                }
            }.bind(this), 400); // Skraćen sa 600ms na 400ms
        },

        // AŽURIRANE FUNKCIJE ZA CLUSTERING I ZOOM

        initMapWithClustering: function () {
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

                // Inicijalizuj progressive rendering
                this.initProgressiveMapRendering();

                console.log('[D-Express] Google Maps sa clustering uspešno inicijalizovana');
            } catch (error) {
                console.error('[D-Express] Greška pri inicijalizaciji mape:', error);
                this.showMapPlaceholder();
            }
        },

        initProgressiveMapRendering: function () {
            if (!this.hasGoogleMaps || !this.map) return;

            // Početni prikaz
            this.showInitialClustering();

            // Event listener za zoom promene
            this.map.addListener('zoom_changed', function () {
                this.handleZoomChange();
            }.bind(this));

            // VRATITI BOUNDS_CHANGED LISTENER - za pomeranje mape levo/desno/gore/dole
            this.map.addListener('bounds_changed', function () {
                clearTimeout(this.boundsChangeTimeout);
                this.boundsChangeTimeout = setTimeout(function () {
                    this.updateVisibleDispensersOnPan();
                }.bind(this), 300); // Debounce 300ms
            }.bind(this));
        },
        updateVisibleDispensersOnPan: function () {
            var currentZoom = this.map.getZoom();
            var bounds = this.map.getBounds();

            if (!bounds) return;

            console.log('[D-Express] 🚀 MAPA POMERENA:');
            console.log('  📏 Trenutni zoom:', currentZoom);
            console.log('  🗺️ Novi bounds: NE(' + bounds.getNorthEast().lat().toFixed(2) + ',' +
                bounds.getNorthEast().lng().toFixed(2) + ') SW(' +
                bounds.getSouthWest().lat().toFixed(2) + ',' + bounds.getSouthWest().lng().toFixed(2) + ')');

            // Pozovi istu logiku kao za zoom, ali bez debounce-a za zoom
            this.renderMarkersBasedOnZoom(currentZoom);
        },
        showInitialClustering: function () {
            var majorCities = this.getMajorCitiesForClustering();
            var currentZoom = this.map.getZoom();

            console.log('[D-Express] 🗺️ POČETNI PRIKAZ MAPE:');
            console.log('  📍 Trenutni zoom:', currentZoom);
            console.log('  🏙️ Ukupno gradova sa 2+ paketomata:', majorCities.length);
            console.log('  📊 Top 5 gradova:',
                majorCities.slice(0, 5).map(c => c.name + ': ' + c.count).join(', '));

            this.clearMapMarkers();

            // SAMO gradovi sa 2+ paketomata - BEZ pojedinačnih
            var citiesToShow = majorCities.filter(function (city) {
                return city.count >= 2;
            });

            console.log('  ✅ Prikazujem', citiesToShow.length, 'cluster markera sa 2+ paketomata');

            citiesToShow.forEach(function (city) {
                this.createClusterMarker(city);
            }.bind(this));

            // Fit bounds za sve cluster markere
            if (this.markers.length > 0) {
                this.map.fitBounds(this.bounds);

                // Ograniči početni zoom na 7
                var self = this;
                google.maps.event.addListenerOnce(this.map, 'bounds_changed', function () {
                    var newZoom = self.map.getZoom();
                    console.log('  🔄 Zoom nakon fitBounds:', newZoom);

                    if (newZoom > 7) {
                        console.log('  ⬇️ Ograničavam zoom sa', newZoom, 'na 7');
                        self.map.setZoom(7);
                    }
                });
            }
        },

        handleZoomChange: function () {
            var zoom = this.map.getZoom();
            var bounds = this.map.getBounds();

            console.log('[D-Express] ⚡ ZOOM EVENT TRIGGERED:');
            console.log('  📏 Zoom level:', zoom);
            if (bounds) {
                console.log('  🗺️ Map bounds: NE(' + bounds.getNorthEast().lat().toFixed(2) + ',' + bounds.getNorthEast().lng().toFixed(2) +
                    ') SW(' + bounds.getSouthWest().lat().toFixed(2) + ',' + bounds.getSouthWest().lng().toFixed(2) + ')');
            }

            clearTimeout(this.zoomChangeTimeout);
            this.zoomChangeTimeout = setTimeout(function () {
                console.log('  ⏰ Zoom debounce završen - renderujem markere');
                this.renderMarkersBasedOnZoom(zoom);
            }.bind(this), 200);
        },

        // JEDAN ZOOM DA PRIKAŽE SVE MARKERE

        // IZMENI renderMarkersBasedOnZoom funkciju:
        renderMarkersBasedOnZoom: function (zoom) {
            var bounds = this.map.getBounds();
            if (!bounds) return;

            console.log('[D-Express] 🔍 ZOOM PROMENA:');
            console.log('  📏 Novi zoom level:', zoom);

            this.clearMapMarkers();

            // Uvek koristi sve paketomata za clustering
            var allVisibleDispensers = this.getDispensersInBounds(bounds);
            console.log('  👁️ Vidljivih paketomata u bounds:', allVisibleDispensers.length);

            if (zoom <= 7) {
                // ZOOM 7 i manje - SAMO cluster markeri za gradove sa 2+ paketomata
                console.log('  🏙️ Početni zoom (≤7) - SAMO cluster markeri za gradove sa 2+ paketomata');
                this.showOnlyClusteringForZoom(allVisibleDispensers, 2);
            } else if (zoom === 8) {
                // ZOOM 8 - PRVI zoom nakon početka - prikaži miks (cluster + pojedinačni)
                console.log('  🏘️ Prvi zoom (8) - miks cluster (2+) i pojedinačni (1)');
                this.showMixedViewForZoom(allVisibleDispensers, 2);
            } else if (zoom <= 10) {
                // ZOOM 9-10 - nastavi sa miks view
                console.log('  🏘️ Srednji zoom (9-10) - miks cluster (2+) i pojedinačni (1)');
                this.showMixedViewForZoom(allVisibleDispensers, 2);
            } else {
                // ZOOM 11+ - svi paketomati pojedinačno
                console.log('  🏠 Visok zoom (≥11) - svi paketomati pojedinačno');
                this.showIndividualMarkersForZoom(allVisibleDispensers);
            }

            console.log('  ✅ Prikazano markera:', this.markers.length);
        },
        showOnlyClusteringForZoom: function (dispensers, minCount) {
            var cityGroups = this.groupDispensersByCity(dispensers);
            var clusteredCities = 0;
            var skippedSingle = 0;

            Object.keys(cityGroups).forEach(function (cityKey) {
                var group = cityGroups[cityKey];

                if (group.dispensers.length >= minCount) {
                    this.createClusterMarker(group);
                    clusteredCities++;
                } else {
                    // NOVO: NE prikazuj pojedinačne markere na niskom zoom-u
                    skippedSingle += group.dispensers.length;
                }
            }.bind(this));

            console.log('    📊 SAMO CLUSTERING - Cluster markeri:', clusteredCities, '| Preskočeni pojedinačni:', skippedSingle);
        },
        showClusteringForZoom: function (dispensers, minCount) {
            var cityGroups = this.groupDispensersByCity(dispensers);
            var clusteredCities = 0;
            var individualDispensers = 0;

            Object.keys(cityGroups).forEach(function (cityKey) {
                var group = cityGroups[cityKey];

                if (group.dispensers.length >= minCount) {
                    this.createClusterMarker(group);
                    clusteredCities++;
                } else {
                    // Za gradove sa manje paketomata, prikaži ih pojedinačno
                    group.dispensers.forEach(function (dispenser) {
                        this.createMarker(dispenser);
                        individualDispensers++;
                    }.bind(this));
                }
            }.bind(this));

            console.log('    📊 Cluster markeri:', clusteredCities, '| Pojedinačni markeri:', individualDispensers);
        },

        showMixedViewForZoom: function (dispensers, minCount) {
            var cityGroups = this.groupDispensersByCity(dispensers);
            var clusteredCities = 0;
            var individualDispensers = 0;

            Object.keys(cityGroups).forEach(function (cityKey) {
                var group = cityGroups[cityKey];

                if (group.dispensers.length >= minCount) {
                    this.createClusterMarker(group);
                    clusteredCities++;
                } else {
                    group.dispensers.forEach(function (dispenser) {
                        this.createMarker(dispenser);
                        individualDispensers++;
                    }.bind(this));
                }
            }.bind(this));

            console.log('    📊 MIKS VIEW - Cluster markeri:', clusteredCities, '| Pojedinačni markeri:', individualDispensers);
        },

        showIndividualMarkersForZoom: function (dispensers) {
            var count = 0;
            dispensers.forEach(function (dispenser) {
                if (dispenser.latitude && dispenser.longitude) {
                    this.createMarker(dispenser);
                    count++;
                }
            }.bind(this));

            console.log('    📊 POJEDINAČNI VIEW - Ukupno markera:', count);
        },

        getDispensersInBounds: function (bounds) {
            return this.dispensers.filter(function (dispenser) {
                if (!dispenser.latitude || !dispenser.longitude) return false;

                var position = new google.maps.LatLng(dispenser.latitude, dispenser.longitude);
                return bounds.contains(position);
            });
        },

        groupDispensersByCity: function (dispensers) {
            var groups = {};

            dispensers.forEach(function (dispenser) {
                var cityKey = this.normalizeCityName(dispenser.town);

                if (!groups[cityKey]) {
                    groups[cityKey] = {
                        name: dispenser.town,
                        dispensers: [],
                        lat: 0,
                        lng: 0,
                        count: 0
                    };
                }

                groups[cityKey].dispensers.push(dispenser);
                groups[cityKey].count++;
            }.bind(this));

            // Izračunaj centre gradova
            Object.keys(groups).forEach(function (cityKey) {
                var group = groups[cityKey];
                var totalLat = 0, totalLng = 0;

                group.dispensers.forEach(function (d) {
                    totalLat += d.latitude;
                    totalLng += d.longitude;
                });

                group.lat = totalLat / group.dispensers.length;
                group.lng = totalLng / group.dispensers.length;
            });

            return groups;
        },

        getMajorCitiesForClustering: function () {
            var cityGroups = this.groupDispensersByCity(this.dispensers);
            var majorCities = [];

            Object.keys(cityGroups).forEach(function (cityKey) {
                var city = cityGroups[cityKey];
                if (city.count >= 2) {
                    majorCities.push(city);
                }
            });

            return majorCities.sort((a, b) => b.count - a.count);
        },

        updateVisibleDispensers: function () {
            // NE RADI NIŠTA - izbegni duplo pozivanje
            //return;
        },

        // Normalizuj naziv grada za grupisanje
        normalizeCityName: function (cityName) {
            if (!cityName) return '';

            var normalized = cityName.toLowerCase()
                .replace(/[čć]/g, 'c')
                .replace(/[đ]/g, 'd')
                .replace(/[š]/g, 's')
                .replace(/[ž]/g, 'z')
                .replace(/\s+/g, ' ')
                .trim();

            // SPECIJALKO - grupiši sve Beogradske delove u "beograd"
            if (normalized.includes('beograd')) {
                return 'beograd';
            }

            return normalized;
        },
        getCityDisplayName: function (cityName, dispensers) {
            var normalized = this.normalizeCityName(cityName);

            if (normalized === 'beograd') {
                return 'Beograd'; // Jednostavan naziv za sve Beogradske delove
            }

            return cityName; // Ostali gradovi kao što jesu
        },
        // Kreiraj cluster marker
        createClusterMarker: function (city) {
            var displayName = this.getCityDisplayName(city.name, city.dispensers);

            const marker = new google.maps.Marker({
                position: { lat: city.lat, lng: city.lng },
                map: this.map,
                title: displayName + ' (' + city.count + ' paketomata)',
                icon: {
                    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(`
                <svg width="50" height="50" viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="25" cy="25" r="20" fill="#E90000" stroke="#ffffff" stroke-width="3"/>
                    <text x="25" y="30" font-family="Arial, sans-serif" font-size="14" font-weight="bold" 
                          fill="white" text-anchor="middle">${city.count}</text>
                </svg>
            `),
                    scaledSize: new google.maps.Size(50, 50),
                    anchor: new google.maps.Point(25, 25)
                }
            });

            marker.addListener('click', function () {
                this.showCityDispensers(city);
            }.bind(this));

            this.markers.push({
                marker: marker,
                city: city,
                type: 'cluster'
            });

            this.bounds.extend(marker.getPosition());
        },

        // Prikaži paketomata određenog grada
        showCityDispensers: function (city) {
            var displayName = this.getCityDisplayName(city.name, city.dispensers);

            // Zoomuj na grad
            this.map.setCenter({ lat: city.lat, lng: city.lng });
            this.map.setZoom(11); // Povećano sa 13 na 11 da se vide pojedinačni markeri

            // Filtriraj listu da prikaže samo taj grad
            const cityKey = this.normalizeCityName(city.name);
            this.selectedCityName = cityKey;
            this.selectedTownId = null;
            this.currentPage = 1;

            // Kreiraj novi filter za grad
            this.filteredDispensers = this.dispensers.filter(function (dispenser) {
                return this.normalizeCityName(dispenser.town) === cityKey;
            }.bind(this));

            console.log('[D-Express] Cluster click - filtriram po gradu:', displayName,
                '(ukupno:', this.filteredDispensers.length, ')');

            // Renderuj SAMO desnu stranu
            this.renderDispensersList();
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
            this.selectedCityName = null; // NOVO - resetuj i city name
            this.currentPage = 1;
            $('#dexpress-town-select').val('');
            $('.dexpress-reset-filter').removeClass('show');
            $('#dexpress-town-suggestions').hide();
            this.renderDispensers();
        },

        renderDispensers: function () {
            console.log('[D-Express] Renderujem paketomata...');

            // NOVA LOGIKA - filtriraj po city name ili town_id
            this.filteredDispensers = this.dispensers.filter(function (dispenser) {
                // Ako je postavljen selectedCityName, filtriraj po gradu
                if (this.selectedCityName) {
                    return this.normalizeCityName(dispenser.town) === this.selectedCityName;
                }

                // Inače filtriraj po town_id (stara logika)
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

            // DEBUG
            console.log('[DEBUG] renderDispensersList: currentPage=', this.currentPage,
                'total=', this.filteredDispensers.length,
                'showing=', pageDispensers.length,
                'selectedCity=', this.selectedCityName);

            var html = '';

            // POBOLJŠANI Info header
            html += '<div class="dexpress-dispensers-info">';
            if (this.filteredDispensers.length > 0) {
                var cityName = this.filteredDispensers[0].town;
                var displayName = this.getCityDisplayName(cityName, this.filteredDispensers);

                if (this.selectedCityName && this.filteredDispensers.length > 0) {
                    // Kada je filtriran po gradu - koristi display name
                    html += '<p><strong>🏙️ ' + displayName + '</strong></p>';
                    html += '<p>Pronađeno ' + this.filteredDispensers.length + ' paketomata u ovom gradu</p>';

                    if (this.filteredDispensers.length > this.pageSize) {
                        html += '<p><small>Prikazano ' + Math.min(endIndex, this.filteredDispensers.length) + ' od ' + this.filteredDispensers.length + '</small></p>';
                    }
                } else {
                    // Kada nije filtriran
                    html += '<p>Prikazano ' + Math.min(endIndex, this.filteredDispensers.length) + ' od ' +
                        this.filteredDispensers.length + ' paketomata</p>';
                }
            } else {
                html += '<p>Nema paketomata</p>';
            }

            // Reset dugme
            if (this.selectedTownId || this.selectedCityName) {
                html += '<button type="button" class="dexpress-reset-filter">↩️ Prikaži sve gradove</button>';
            }
            html += '</div>';

            // Ostatak koda ostaje isti...
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

            // SAČEKAJ DA SE MAPA UČITA
            var bounds = this.map.getBounds();
            if (!bounds) {
                setTimeout(function () {
                    this.renderMapMarkers();
                }.bind(this), 100);
                return;
            }

            var zoom = this.map.getZoom();
            this.renderMarkersBasedOnZoom(zoom);
        },

        createMarker: function (dispenser) {
            var self = this;

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

            // Klik na marker
            marker.addListener('click', function () {
                console.log('[D-Express] Marker clicked:', dispenser.name);
                self.showDispenserInfo(dispenser, marker);

                // DODAJ preventivnu proveru
                self.ensureInfoWindowStaysOpen(dispenser, marker);
            });

            this.markers.push({
                marker: marker,
                dispenser: dispenser
            });

            this.bounds.extend(marker.getPosition());
        },

        showDispenserInfo: function (dispenser, marker) {
            var self = this;

            // KRITIČNO - sakrij postojeći info window
            if (this.infoWindow) {
                this.infoWindow.close();
            }

            // Očisti postojeći timeout
            if (this.infoWindowTimeout) {
                clearTimeout(this.infoWindowTimeout);
                this.infoWindowTimeout = null;
            }

            var paymentMethods = [];
            if (dispenser.pay_by_cash) paymentMethods.push('Gotovina');
            if (dispenser.pay_by_card) paymentMethods.push('Kartica');

            var content = `
        <div class="dexpress-info-window" style="min-width: 250px;">
            <h4 style="margin: 0 0 10px 0;">${dispenser.name}</h4>
            <p style="margin: 5px 0;"><strong>Adresa:</strong> ${dispenser.address}</p>
            <p style="margin: 5px 0;"><strong>Grad:</strong> ${dispenser.town}</p>
            <p style="margin: 5px 0;"><strong>Radno vreme:</strong> ${dispenser.work_hours || '0-24'}</p>
            ${paymentMethods.length > 0 ? `<p style="margin: 5px 0;"><strong>Plaćanje:</strong> ${paymentMethods.join(', ')}</p>` : ''}
            <div style="text-align: center; margin-top: 15px;">
                <button class="dexpress-select-this-dispenser" data-id="${dispenser.id}" 
                        style="background: #E90000; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                    Izaberi ovaj paketomat
                </button>
            </div>
        </div>
    `;

            console.log('[D-Express] Otvaranje info window za:', dispenser.name);

            this.infoWindow.setContent(content);
            this.infoWindow.open(this.map, marker);

            // ODMAH vezivanje event-a bez timeout-a
            google.maps.event.addListenerOnce(this.infoWindow, 'domready', function () {
                console.log('[D-Express] Info window domready - vezujem click event');

                $('.dexpress-select-this-dispenser').off('click.infowindow').on('click.infowindow', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var id = $(this).data('id');
                    console.log('[D-Express] Selecting dispenser from info window:', id);
                    self.selectDispenser(id);
                    self.infoWindow.close();
                });
            });

            // SAMO JEDNOM auto filtriraj desnu stranu - bez renderovanja mape
            console.log('[D-Express] Auto filtriram desnu stranu za:', dispenser.town);
            this.autoFilterByCity(dispenser);
        },
        autoFilterByCity: function (selectedDispenser) {
            // Pozovi novu funkciju koja NE dira mapu
            this.autoFilterRightSideOnly(selectedDispenser);
        },
        ensureInfoWindowStaysOpen: function (dispenser, marker) {
            var self = this;

            // Proveri nakon 500ms da li je info window još uvek otvoren
            setTimeout(function () {
                if (self.infoWindow && self.infoWindow.getMap()) {
                    console.log('[D-Express] Info window je još uvek otvoren - OK');
                } else {
                    console.log('[D-Express] Info window je zatvoren - ponovo otvaranje');
                    self.showDispenserInfo(dispenser, marker);
                }
            }, 500);
        },
        highlightDispenser: function (id) {
            if (!this.hasGoogleMaps) return;

            var dispenser = this.dispensers.find(function (d) {
                return d.id == id;
            });

            if (!dispenser || !dispenser.latitude || !dispenser.longitude) {
                console.log('[D-Express] Dispenser not found or missing coordinates:', id);
                return;
            }

            console.log('[D-Express] Highlighting dispenser:', dispenser.name);

            // Postavi centar mape na paketomat
            var position = { lat: parseFloat(dispenser.latitude), lng: parseFloat(dispenser.longitude) };
            this.map.setCenter(position);
            this.map.setZoom(15);

            // SAČEKAJ da se mapa pozicionira, zatim prikaži info window
            setTimeout(function () {
                // Pronađi postojeći marker
                var markerData = this.markers.find(function (m) {
                    return m.dispenser && m.dispenser.id == id;
                });

                if (markerData) {
                    console.log('[D-Express] Pronašao postojeći marker, otvaranje info window');
                    this.showDispenserInfo(markerData.dispenser, markerData.marker);
                } else {
                    console.log('[D-Express] Marker ne postoji, kreiram novi');
                    // Ako marker ne postoji, kreiraj ga
                    this.createMarker(dispenser);

                    // Pronađi novokreirani marker
                    setTimeout(function () {
                        var newMarkerData = this.markers.find(function (m) {
                            return m.dispenser && m.dispenser.id == id;
                        });

                        if (newMarkerData) {
                            console.log('[D-Express] Kreiran novi marker, otvaranje info window');
                            this.showDispenserInfo(newMarkerData.dispenser, newMarkerData.marker);
                        }
                    }.bind(this), 200);
                }

                // Highlight u listi
                $('.dexpress-dispenser-item').removeClass('selected');
                $('.dexpress-dispenser-item[data-id="' + id + '"]').addClass('selected');
            }.bind(this), 600); // Povećan timeout da se mapa stabilizuje
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
        ensureMarkerExists: function (dispenser) {
            // Proveri da li marker već postoji
            var existingMarker = this.markers.find(function (m) {
                return m.dispenser && m.dispenser.id == dispenser.id;
            });

            if (!existingMarker) {
                // Kreiraj marker ako ne postoji
                this.createMarker(dispenser);
                return this.markers.find(function (m) {
                    return m.dispenser && m.dispenser.id == dispenser.id;
                });
            }

            return existingMarker;
        },
        // 3. DODAJ NOVU FUNKCIJU za auto filter SAMO desne strane:
        autoFilterRightSideOnly: function (selectedDispenser) {
            var selectedCityName = this.normalizeCityName(selectedDispenser.town);

            console.log('[D-Express] Auto filtriram SAMO desnu stranu za:', selectedDispenser.town);

            // Postavi filter
            this.selectedCityName = selectedCityName;
            this.selectedTownId = null;
            this.currentPage = 1;

            // Filtriraj paketomata po gradu
            this.filteredDispensers = this.dispensers.filter(function (d) {
                return this.normalizeCityName(d.town) === selectedCityName;
            }.bind(this));

            console.log('[D-Express] Filtrirani paketomati:', this.filteredDispensers.length);

            // Renderuj SAMO desnu stranu - NIKAD ne diraj mapu!
            this.renderDispensersList();

            // Highlight izabrani paketomat u listi
            setTimeout(function () {
                $('.dexpress-dispenser-item').removeClass('selected');
                $('.dexpress-dispenser-item[data-id="' + selectedDispenser.id + '"]').addClass('selected');
            }, 100);
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
            setTimeout(function () {
                successOverlay.addClass('show');
            }, 50);
        },

        hideSuccessAnimation: function () {
            var successOverlay = $('.dexpress-success-overlay');

            successOverlay.addClass('fade-out');
            setTimeout(function () {
                successOverlay.remove();
                $('.dexpress-modal-body').css('position', '');
            }, 500);
        },

        resetModalState: function () {
            // Ukloni sve success animacije
            $('.dexpress-success-overlay').remove();
            $('.dexpress-modal-body').css('position', '');

            // Resetuj filter
            $('#dexpress-town-select').val('');
            $('.dexpress-reset-filter').removeClass('show');
            $('#dexpress-town-suggestions').hide();
            this.selectedTownId = null;
            this.selectedCityName = null; // NOVO
            this.currentPage = 1;
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
                        setTimeout(function () {
                            this.hideSuccessAnimation();

                            setTimeout(function () {
                                this.closeModal();

                                // Osvezi checkout
                                setTimeout(function () {
                                    $('body').trigger('update_checkout');
                                }, 100);
                            }.bind(this), 300);
                        }.bind(this), 1000);
                    } else {
                        console.error('[D-Express] Greška pri čuvanju paketomata:', response);

                        // Prikaži grešku u animaciji
                        $('.dexpress-success-message span').text('Greška! Pokušajte ponovo.');
                        $('.dexpress-success-content h3').text('Greška!').css('color', '#dc3545');
                        $('.checkmark').addClass('error');

                        setTimeout(function () {
                            this.hideSuccessAnimation();
                        }.bind(this), 2000);
                    }
                }.bind(this),
                error: function (xhr, status, error) {
                    console.error('[D-Express] AJAX greška pri čuvanju:', error);

                    // Prikaži grešku u animaciji
                    $('.dexpress-success-message span').text('Greška pri komunikaciji!');
                    $('.dexpress-success-content h3').text('Greška!').css('color', '#dc3545');
                    $('.checkmark').addClass('error');

                    setTimeout(function () {
                        this.hideSuccessAnimation();
                    }.bind(this), 2000);
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
            } else if (this.markers.length > 1) {
                this.map.fitBounds(this.bounds);

                google.maps.event.addListenerOnce(this.map, 'bounds_changed', function () {
                    if (this.map.getZoom() > 15) {
                        this.map.setZoom(15);
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