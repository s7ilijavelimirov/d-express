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
            // DODAJ OVE 3 LINIJE:
            this.zoomChangeTimeout = null;
            this.boundsChangeTimeout = null;
            this.infoWindowTimeout = null; // NOVA linija

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
                self.initTownAutocomplete(); // DODANO - eksplicitno pozivanje
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
                    self.resetFilter(); // Pozovi resetFilter umesto clearAllFilters
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

        searchInLoadedDispensers: function (term) {
            var self = this;
            console.log('[D-Express] Searching dispensers for:', term);

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
                    if (response.success && response.data.dispensers) {
                        self.renderDispenserSuggestions(response.data.dispensers, term);
                    } else {
                        $('#dexpress-town-suggestions').html('<div class="no-suggestion">Nema rezultata za "' + term + '"</div>').show();
                    }
                },
                error: function () {
                    $('#dexpress-town-suggestions').html('<div class="no-suggestion">Greška pri pretrazi</div>').show();
                }
            });
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

            // Renderuj SVE gradove bez ograničenja
            towns.forEach(function (town) {
                var displayName = town.name;
                if (town.dispenser_count) {
                    displayName += ' (' + town.dispenser_count + ' paketomata)';
                }

                html += '<div class="town-suggestion-header" data-town-id="' + town.id + '" data-name="' + town.name + '">';
                html += '<strong>' + displayName + '</strong>';
                html += '</div>';
            });

            if (html) {
                $('#dexpress-town-suggestions').html(html).show();
                this.bindSuggestionEvents();
            } else {
                $('#dexpress-town-suggestions').html('<div class="no-suggestion">Nema rezultata</div>').show();
            }
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

                // NOVA LOGIKA: Filtriraj da prikaže SVE paketomata iz istog grada
                var selectedTownId = selectedDispenser.town_id;
                var selectedCityName = self.normalizeCityName(selectedDispenser.town);

                self.filteredDispensers = self.dispensers.filter(function (d) {
                    // Filtriraj po town_id ili po nazivu grada (za slučaj kada town_id nije isti)
                    return d.town_id == selectedTownId ||
                        self.normalizeCityName(d.town) === selectedCityName;
                });

                console.log('[D-Express] Prikazujem sve paketomata iz grada:', selectedDispenser.town,
                    '(ukupno:', self.filteredDispensers.length, ')');

                // Renderuj listu i mapu
                self.renderDispensersList();
                self.renderMapMarkers();

                // NOVO: Fokusiraj mapu na izabrani paketomat ali prikaži sve
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

            // Podesi zoom na osnovu broja paketomata u gradu
            var zoomLevel = this.filteredDispensers.length === 1 ? 15 : 13;
            this.map.setZoom(zoomLevel);

            // Otvori info window za izabrani paketomat nakon kratke pauze
            setTimeout(function () {
                var markerData = this.markers.find(function (m) {
                    return m.dispenser && m.dispenser.id == selectedDispenser.id;
                });

                if (markerData) {
                    this.showDispenserInfo(selectedDispenser, markerData.marker);
                }
            }.bind(this), 800);
        },
        focusMapOnDispenser: function (dispenser) {
            if (!this.hasGoogleMaps || !this.map || !dispenser.latitude || !dispenser.longitude) {
                return;
            }

            console.log('[D-Express] Focusing map on dispenser:', dispenser.name);

            // Fokusiraj mapu na paketomat
            var position = { lat: dispenser.latitude, lng: dispenser.longitude };
            this.map.setCenter(position);
            this.map.setZoom(15);

            // Otvori info window za paketomat (opciono)
            setTimeout(function () {
                var markerData = this.markers.find(function (m) {
                    return m.dispenser && m.dispenser.id == dispenser.id;
                });

                if (markerData) {
                    this.showDispenserInfo(dispenser, markerData.marker);
                }
            }.bind(this), 500);
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
        // NOVO: Clustering funkcionalnost
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

                // NOVA LOGIKA - progressive rendering
                this.initProgressiveMapRendering();

                console.log('[D-Express] Google Maps sa clustering uspešno inicijalizovana');
            } catch (error) {
                console.error('[D-Express] Greška pri inicijalizaciji mape:', error);
                this.showMapPlaceholder();
            }
        },
        // DODAJ OVE FUNKCIJE:
        initProgressiveMapRendering: function () {
            if (!this.hasGoogleMaps || !this.map) return;

            // Početni prikaz - prikaži clustering za veće gradove
            this.showInitialClustering();

            // Event listener za zoom promene
            this.map.addListener('zoom_changed', function () {
                this.handleZoomChange();
            }.bind(this));

            // Event listener za drag/pan promene
            this.map.addListener('bounds_changed', function () {
                clearTimeout(this.boundsChangeTimeout);
                this.boundsChangeTimeout = setTimeout(function () {
                    this.updateVisibleDispensers();
                }.bind(this), 300);
            }.bind(this));
        },

        showInitialClustering: function () {
            var majorCities = this.getMajorCitiesForClustering();

            this.clearMapMarkers();

            // Prikaži SVE gradove sa 2+ paketomata na početku
            majorCities.forEach(function (city) {
                if (city.count >= 2) {
                    this.createClusterMarker(city);
                }
            }.bind(this));

            // Fit bounds za sve cluster markere
            if (this.markers.length > 0) {
                this.map.fitBounds(this.bounds);

                // Ograniči početni zoom
                var self = this;
                google.maps.event.addListenerOnce(this.map, 'bounds_changed', function () {
                    if (self.map.getZoom() > 8) {
                        self.map.setZoom(8);
                    }
                });
            }
        },

        handleZoomChange: function () {
            var zoom = this.map.getZoom();
            console.log('[D-Express] Zoom level:', zoom);

            clearTimeout(this.zoomChangeTimeout);
            this.zoomChangeTimeout = setTimeout(function () {
                if (zoom <= 7) {
                    // Zoom 7 i manji - samo najveći gradovi (5+ paketomata)
                    this.showCitiesWithMinCount(5);
                } else if (zoom <= 9) {
                    // Zoom 8-9 - gradovi sa 3+ paketomata
                    this.showCitiesWithMinCount(3);
                } else if (zoom <= 11) {
                    // Zoom 10-11 - gradovi sa 2+ paketomata + pojedinačni za manje
                    this.showMixedView();
                } else {
                    // Zoom 12+ - svi paketomata vidljivi u oblasti
                    this.showAllVisibleDispensers();
                }
            }.bind(this), 200);
        },
        // NOVA funkcija - prikaži gradove sa minimum brojem paketomata
        showCitiesWithMinCount: function (minCount) {
            var majorCities = this.getMajorCitiesForClustering();

            this.clearMapMarkers();

            majorCities.forEach(function (city) {
                if (city.count >= minCount) {
                    this.createClusterMarker(city);
                }
            }.bind(this));
        },

        // NOVA funkcija - mešavina cluster i pojedinačnih
        showMixedView: function () {
            var bounds = this.map.getBounds();
            if (!bounds) return;

            this.clearMapMarkers();

            var visibleDispensers = this.getDispensersInBounds(bounds);
            var cityGroups = this.groupDispensersByCity(visibleDispensers);

            Object.keys(cityGroups).forEach(function (cityKey) {
                var group = cityGroups[cityKey];

                if (group.dispensers.length >= 2) {
                    // Prikaži kao cluster
                    this.createClusterMarker(group);
                } else {
                    // Prikaži pojedinačne markere
                    group.dispensers.forEach(function (dispenser) {
                        this.createMarker(dispenser);
                    }.bind(this));
                }
            }.bind(this));
        },
        showMajorCityClusters: function () {
            var majorCities = this.getMajorCitiesForClustering();

            this.clearMapMarkers();

            majorCities.forEach(function (city) {
                if (city.count >= 5) { // Samo gradovi sa 5+ paketomata
                    this.createClusterMarker(city);
                }
            }.bind(this));
        },

        showMediumZoomView: function () {
            var bounds = this.map.getBounds();
            if (!bounds) return;

            this.clearMapMarkers();

            var visibleDispensers = this.getDispensersInBounds(bounds);
            var cityGroups = this.groupDispensersByCity(visibleDispensers);

            Object.keys(cityGroups).forEach(function (cityKey) {
                var group = cityGroups[cityKey];

                if (group.dispensers.length >= 3) {
                    this.createClusterMarker(group);
                } else {
                    group.dispensers.forEach(function (dispenser) {
                        this.createMarker(dispenser);
                    }.bind(this));
                }
            }.bind(this));
        },

        showAllVisibleDispensers: function () {
            var bounds = this.map.getBounds();
            if (!bounds) return;

            this.clearMapMarkers();

            var visibleDispensers = this.getDispensersInBounds(bounds);

            visibleDispensers.forEach(function (dispenser) {
                this.createMarker(dispenser);
            }.bind(this));
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
            var zoom = this.map.getZoom();

            if (zoom > 11) {
                this.showAllVisibleDispensers();
            }
        },
        // NOVO: Kreiranje cluster markera
        createClusterMarkers: function () {
            if (!this.hasGoogleMaps || !this.map) return;

            this.clearMapMarkers();

            const zoom = this.map.getZoom();
            const bounds = this.map.getBounds();

            if (!bounds) return;

            // Prikaži clustering samo na zoom 7-10
            if (zoom >= 7 && zoom <= 10) {
                this.showClustering();
            } else {
                this.showIndividualMarkers();
            }
        },

        // NOVO: Prikaži clustering za veće gradove
        showClustering: function () {
            const majorCities = this.getMajorCities();

            majorCities.forEach(function (city) {
                if (city.count > 0) {
                    this.createClusterMarker(city);
                }
            }.bind(this));
        },

        // NOVO: Dobij veće gradove sa brojem paketomata
        getMajorCities: function () {
            const cityGroups = {};

            // Grupiši po gradu
            this.filteredDispensers.forEach(function (dispenser) {
                const cityKey = this.normalizeCityName(dispenser.town);

                if (!cityGroups[cityKey]) {
                    cityGroups[cityKey] = {
                        name: dispenser.town,
                        dispensers: [],
                        lat: 0,
                        lng: 0
                    };
                }

                cityGroups[cityKey].dispensers.push(dispenser);
            }.bind(this));

            // Izračunaj centar i vrati gradove sa 2+ paketomata
            const majorCities = [];

            Object.keys(cityGroups).forEach(function (cityKey) {
                const city = cityGroups[cityKey];

                if (city.dispensers.length >= 2) {
                    // Izračunaj centar grada
                    let totalLat = 0, totalLng = 0;
                    city.dispensers.forEach(function (d) {
                        totalLat += d.latitude;
                        totalLng += d.longitude;
                    });

                    city.lat = totalLat / city.dispensers.length;
                    city.lng = totalLng / city.dispensers.length;
                    city.count = city.dispensers.length;

                    majorCities.push(city);
                }
            });

            // Sortiraj po broju paketomata
            return majorCities.sort((a, b) => b.count - a.count);
        },

        // NOVO: Normalizuj naziv grada za grupisanje
        // Funkcija za normalizaciju naziva grada za grupisanje
        normalizeCityName: function (cityName) {
            if (!cityName) return '';

            return cityName.toLowerCase()
                .replace(/\s*\([^)]*\)/g, '') // Ukloni zagrade
                .replace(/[čć]/g, 'c')
                .replace(/[đ]/g, 'd')
                .replace(/[š]/g, 's')
                .replace(/[ž]/g, 'z')
                .trim();
        },

        // NOVO: Kreiraj cluster marker
        createClusterMarker: function (city) {
            const marker = new google.maps.Marker({
                position: { lat: city.lat, lng: city.lng },
                map: this.map,
                title: city.name + ' (' + city.count + ' paketomata)',
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

        // NOVO: Prikaži paketomata određenog grada
        showCityDispensers: function (city) {
            // Zoomuj na grad
            this.map.setCenter({ lat: city.lat, lng: city.lng });
            this.map.setZoom(12);

            // Filtriraj listu da prikaže samo taj grad
            const cityKey = this.normalizeCityName(city.name);
            this.selectedTownId = null; // Reset postojeći filter

            // Kreiraj novi filter za grad
            this.filteredDispensers = this.dispensers.filter(function (dispenser) {
                return this.normalizeCityName(dispenser.town) === cityKey;
            }.bind(this));

            this.renderDispensersList();
        },

        // NOVO: Prikaži pojedinačne markere
        showIndividualMarkers: function () {
            this.filteredDispensers.forEach(function (dispenser) {
                if (!dispenser.latitude || !dispenser.longitude) return;
                this.createMarker(dispenser);
            }.bind(this));
        },

        // NOVO: Update clustering na zoom promenu
        updateClustering: function () {
            this.createClusterMarkers();
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
            if (this.filteredDispensers.length > 0) {
                var cityName = this.filteredDispensers[0].town;
                html += '<p>Prikazano ' + Math.min(endIndex, this.filteredDispensers.length) + ' od ' +
                    this.filteredDispensers.length + ' paketomata';

                // Dodaj info o gradu ako je filtriran
                if (this.filteredDispensers.length > 1 &&
                    this.filteredDispensers.every(d => this.normalizeCityName(d.town) === this.normalizeCityName(cityName))) {
                    html += ' u gradu <strong>' + cityName + '</strong>';
                }
                html += '</p>';
            } else {
                html += '<p>Nema paketomata</p>';
            }

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

            // PROMENI OVO:
            var zoom = this.map.getZoom();

            if (zoom <= 11) {
                this.handleZoomChange();
            } else {
                // Za veći zoom, prikaži sve vidljive paketomata (ne samo filtrirane)
                this.showAllVisibleDispensers();
            }

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
            var self = this;

            // Očisti postojeći timeout
            if (this.infoWindowTimeout) {
                clearTimeout(this.infoWindowTimeout);
            }

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

            // DODAJ event listener sa dužim timeout-om
            this.infoWindowTimeout = setTimeout(function () {
                $('.dexpress-select-this-dispenser').off('click').on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var id = $(this).data('id');
                    console.log('[D-Express] Selecting dispenser from info window:', id);
                    self.selectDispenser(id);
                    self.infoWindow.close();
                });
            }, 300); // Povećao timeout
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
            } else {
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