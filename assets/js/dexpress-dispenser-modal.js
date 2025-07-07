(function ($) {
    'use strict';

    var DExpressDispenserModal = {
        currentTownFilter: null,
        dispensers: [],
        towns: [],
        map: null,
        markers: [],
        clusterMarkers: [],
        bounds: null,
        infoWindow: null,
        markerCluster: null,
        isMapInitialized: false,
        hasGoogleMaps: false,
        currentPage: 1,
        pageSize: 10,
        totalDispensers: 0,
        filteredDispensers: [],
        currentZoom: 7,
        clusteringEnabled: true,

        init: function () {
            console.log('[D-Express] Inicijalizujem dispenser modal...');

            // Proverava da li je Google Maps dostupan
            this.hasGoogleMaps = (typeof google !== 'undefined' && typeof google.maps !== 'undefined');
            console.log('[D-Express] Google Maps available:', this.hasGoogleMaps);

            // Event listeneri
            $(document).on('click', '.dexpress-select-dispenser-btn, .dexpress-change-dispenser', function (e) {
                e.preventDefault();
                console.log('[D-Express] Klik na dugme za izbor paketomata');
                DExpressDispenserModal.openModal();
            });

            $(document).on('click', '.dexpress-modal-close', function () {
                DExpressDispenserModal.closeModal();
            });

            $(document).on('keyup', function (e) {
                if (e.key === "Escape") {
                    DExpressDispenserModal.closeModal();
                }
            });

            // Shipping method handler
            $(document.body).off('updated_checkout.dexpress').on('updated_checkout.dexpress', function () {
                DExpressDispenserModal.setupShippingMethodHandler();
            });

            this.setupShippingMethodHandler();
        },

        openModal: function () {
            console.log('[D-Express] Otvaranje modala');
            $('#dexpress-dispenser-modal').addClass('show');

            // Blokira scroll na body
            $('body').css('overflow', 'hidden');

            if (!this.isMapInitialized) {
                this.initMap();
                this.loadTownsAndDispensers();
                this.isMapInitialized = true;
            } else {
                this.renderDispensers();
            }
        },

        closeModal: function () {
            console.log('[D-Express] Zatvaranje modala');
            $('#dexpress-dispenser-modal').removeClass('show');
            $('body').css('overflow', '');
        },

        initMap: function () {
            console.log('[D-Express] Inicijalizujem mapu...');

            if (!this.hasGoogleMaps) {
                console.log('[D-Express] Google Maps nije dostupan, koristim placeholder');
                $('#dexpress-dispensers-map').html(`
                    <div class="dexpress-map-placeholder">
                        <div class="icon">🗺️</div>
                        <p>Mapa nije dostupna</p>
                        <small>Google Maps API billing nije omogućen</small>
                        <br><small>Lista paketomata je dostupna u desnoj koloni</small>
                    </div>
                `);
                return;
            }

            try {
                // Inicijalizuj mapu
                this.map = new google.maps.Map(document.getElementById('dexpress-dispensers-map'), {
                    center: { lat: 44.0165, lng: 21.0059 }, // Centar Srbije
                    zoom: 7,
                    styles: [
                        {
                            featureType: "poi",
                            elementType: "labels",
                            stylers: [{ visibility: "off" }]
                        }
                    ],
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: true,
                    zoomControl: true,
                    gestureHandling: 'greedy'
                });

                this.bounds = new google.maps.LatLngBounds();
                this.infoWindow = new google.maps.InfoWindow();

                // Zoom change listener za clustering
                this.map.addListener('zoom_changed', () => {
                    this.currentZoom = this.map.getZoom();
                    console.log('[D-Express] Zoom changed to:', this.currentZoom);
                    this.updateMarkersBasedOnZoom();
                });

                // Idle listener za bounds update
                this.map.addListener('idle', () => {
                    this.updateVisibleDispensers();
                });

                console.log('[D-Express] Google Maps uspešno inicijalizovana');
            } catch (error) {
                console.error('[D-Express] Greška pri inicijalizaciji mape:', error);
                $('#dexpress-dispensers-map').html(`
                    <div class="dexpress-map-placeholder">
                        <div class="icon">⚠️</div>
                        <p>Greška pri učitavanju mape</p>
                        <small>${error.message}</small>
                    </div>
                `);
            }
        },

        loadTownsAndDispensers: function () {
            var self = this;

            this.showLoader('#dexpress-dispensers-list');
            if (this.hasGoogleMaps) {
                this.showLoader('#dexpress-dispensers-map');
            }

            // Učitaj gradove i paketomata paralelno
            $.when(
                this.loadTowns(),
                this.loadDispensers()
            ).done(function (townsResult, dispensersResult) {
                console.log('[D-Express] Svi podaci učitani');

                // OVDE inicijalizujemo autocomplete NAKON što su paketomati učitani
                self.initTownAutocomplete();

                self.hideLoader('#dexpress-dispensers-list');
                if (self.hasGoogleMaps) {
                    self.hideLoader('#dexpress-dispensers-map');
                }
                self.renderDispensers();
            }).fail(function () {
                console.error('[D-Express] Greška pri učitavanju podataka');
                self.hideLoader('#dexpress-dispensers-list');
                if (self.hasGoogleMaps) {
                    self.hideLoader('#dexpress-dispensers-map');
                }
                self.showNoResults('Greška pri učitavanju podataka');
            });
        },

        loadTowns: function () {
            var self = this;

            return $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'dexpress_get_towns_list',
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
                    action: 'dexpress_get_dispensers',
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    if (response.success && response.data.dispensers) {
                        self.dispensers = response.data.dispensers;
                        console.log('[D-Express] Učitano paketomata:', self.dispensers.length);
                        if (self.dispensers.length > 0) {
                            console.log('[D-Express] Prvi paketomat:', self.dispensers[0]);
                            console.log('[D-Express] Town value:', "'" + self.dispensers[0].town + "'");
                            console.log('[D-Express] Town_id:', self.dispensers[0].town_id);
                        }
                        var unknownCount = self.dispensers.filter(d => d.town === 'Unknown Town' || d.town === 'Nepoznat grad').length;
                        console.log('[D-Express] Broj "Unknown Town" paketomata:', unknownCount);
                    }
                }
            });
        },

        renderDispensers: function () {
            var self = this;
            console.log('[D-Express] Renderujem paketomata, filter:', this.currentTownFilter);

            // Resetuj postojeće markere
            this.clearMarkers();

            // Resetuj paginaciju
            this.currentPage = 1;

            // Filtriranje paketomata
            this.filteredDispensers = this.dispensers.filter(function (dispenser) {
                if (self.currentTownFilter && parseInt(dispenser.town_id) !== parseInt(self.currentTownFilter)) {
                    return false;
                }
                return true;
            });

            console.log('[D-Express] Filtriran broj paketomata:', this.filteredDispensers.length);

            if (this.filteredDispensers.length === 0) {
                this.showNoResults('Nema dostupnih paketomata za izabrani filter');
                return;
            }

            // UKLANJAMO: Clear filter dugme se ne prikazuje više

            // Kreiraj markere za sve filtrirane paketomata
            if (this.hasGoogleMaps) {
                this.createMapClusters();
            }

            // Prikaži prvu stranu paketomata
            this.renderDispensersList();

            // Podesi mapu
            this.adjustMapView();
        },

        createMapClusters: function () {
            console.log('[D-Express] Kreiram cluster markere...');
            console.log('[D-Express] Ukupno paketomata za clustering:', this.filteredDispensers.length);

            // Grupisanje paketomata po gradovima (town_id)
            var townGroups = {};
            this.filteredDispensers.forEach(dispenser => {
                if (!dispenser.latitude || !dispenser.longitude) return;

                var townKey = dispenser.town_id.toString();
                var mainTownName = this.getMainTownName(dispenser.town);

                if (!townGroups[townKey]) {
                    townGroups[townKey] = {
                        dispensers: [],
                        town: mainTownName,
                        town_id: dispenser.town_id,
                        center: { lat: 0, lng: 0 },
                        count: 0
                    };
                }

                townGroups[townKey].dispensers.push(dispenser);
                townGroups[townKey].count++;
            });

            // Izračunaj centar za svaki grad
            Object.keys(townGroups).forEach(townKey => {
                var group = townGroups[townKey];
                var latSum = 0, lngSum = 0;

                group.dispensers.forEach(dispenser => {
                    latSum += dispenser.latitude;
                    lngSum += dispenser.longitude;
                });

                group.center.lat = latSum / group.dispensers.length;
                group.center.lng = lngSum / group.dispensers.length;
            });

            // Kreiraj cluster markere
            this.clusterMarkers = [];
            Object.keys(townGroups).forEach(townKey => {
                var group = townGroups[townKey];
                this.createClusterMarker(group);
            });

            this.updateMarkersBasedOnZoom();
        },

        getMainTownName: function (townName) {
            if (!townName || townName === 'Nepoznat grad' || townName === 'Unknown Town') {
                return 'Nepoznat grad';
            }

            var cleanName = townName.trim();
            if (cleanName.includes('(')) {
                cleanName = cleanName.split('(')[0].trim();
            }

            cleanName = cleanName.replace(/[^\w\sšđčćžŠĐČĆŽ]/g, '').trim();
            return cleanName || 'Nepoznat grad';
        },

        createClusterMarker: function (group) {
            var self = this;

            // Kreiraj cluster marker
            var clusterMarker = new google.maps.Marker({
                position: group.center,
                map: this.map,
                icon: {
                    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(`
                        <svg width="40" height="40" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="20" cy="20" r="18" fill="#E90000" stroke="#ffffff" stroke-width="2"/>
                            <text x="20" y="26" text-anchor="middle" fill="white" font-size="12" font-weight="bold">${group.count}</text>
                        </svg>
                    `),
                    scaledSize: new google.maps.Size(40, 40),
                    anchor: new google.maps.Point(20, 20)
                },
                title: `${group.town} - ${group.count} paketomata`,
                zIndex: 1000
            });

            clusterMarker.addListener('click', () => {
                // Zoom na grad
                this.map.setCenter(group.center);
                this.map.setZoom(12);

                // Filtriraj po gradu
                this.filterDispensers(group.town_id);
            });

            this.clusterMarkers.push({
                marker: clusterMarker,
                group: group,
                type: 'cluster'
            });

            // Kreiraj individualne markere (nevidljive na početku)
            group.dispensers.forEach(dispenser => {
                this.createIndividualMarker(dispenser, group.town_id);
            });
        },

        createIndividualMarker: function (dispenser, townId) {
            var marker = new google.maps.Marker({
                position: { lat: dispenser.latitude, lng: dispenser.longitude },
                map: null, // Ne prikazuj odmah
                icon: {
                    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(`
                        <svg width="30" height="30" viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="15" cy="15" r="12" fill="#E90000" stroke="#ffffff" stroke-width="2"/>
                            <circle cx="15" cy="15" r="4" fill="white"/>
                        </svg>
                    `),
                    scaledSize: new google.maps.Size(30, 30),
                    anchor: new google.maps.Point(15, 15)
                },
                title: dispenser.name,
                zIndex: 500
            });

            marker.addListener('click', () => {
                this.showDispenserInfo(dispenser, marker);
            });

            this.markers.push({
                marker: marker,
                dispenser: dispenser,
                townId: townId,
                type: 'individual'
            });

            this.bounds.extend(marker.getPosition());
        },

        updateMarkersBasedOnZoom: function () {
            if (!this.map) return;

            var zoom = this.map.getZoom();
            console.log('[D-Express] Ažuriram markere za zoom:', zoom);

            if (zoom <= 10) {
                // Prikaži cluster markere
                this.clusterMarkers.forEach(item => {
                    item.marker.setMap(this.map);
                });

                // Sakrij individualne markere
                this.markers.forEach(item => {
                    item.marker.setMap(null);
                });
            } else {
                // Sakrij cluster markere
                this.clusterMarkers.forEach(item => {
                    item.marker.setMap(null);
                });

                // Prikaži individualne markere za trenutni filter
                this.markers.forEach(item => {
                    var shouldShow = !this.currentTownFilter || parseInt(item.townId) === parseInt(this.currentTownFilter);
                    item.marker.setMap(shouldShow ? this.map : null);
                });
            }
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
        },

        renderDispensersList: function () {
            var self = this;

            // Paginiraj podatke
            var startIndex = (this.currentPage - 1) * this.pageSize;
            var endIndex = startIndex + this.pageSize;
            var pageDispensers = this.filteredDispensers.slice(startIndex, endIndex);

            console.log('[D-Express] Prikazujem paketomata od', startIndex, 'do', endIndex);

            // Generiši HTML
            var listHtml = '';

            pageDispensers.forEach(function (dispenser) {
                var paymentMethods = [];
                if (dispenser.pay_by_cash) paymentMethods.push('Gotovina');
                if (dispenser.pay_by_card) paymentMethods.push('Kartica');

                listHtml += `
                    <div class="dexpress-dispenser-item" data-id="${dispenser.id}">
                        <div class="dispenser-content">
                            <div class="dispenser-header">
                                <h4 class="dispenser-name">${dispenser.name}</h4>
                                <div class="dispenser-status online"></div>
                            </div>
                            <div class="dispenser-address">${dispenser.address}</div>
                            <div class="dispenser-town">${dispenser.town}</div>
                            <div class="dispenser-details">
                                <span class="work-hours">Radno vreme: ${dispenser.work_hours || '0-24'}</span>
                                ${paymentMethods.length > 0 ? `<span class="payment-methods">Plaćanje: ${paymentMethods.join(', ')}</span>` : ''}
                            </div>
                        </div>
                        <button class="dispenser-select-btn" data-id="${dispenser.id}">
                            Izaberi
                        </button>
                    </div>
                `;
            });

            // Dodaj dugme "Učitaj još" ako ima više paketomata
            if (endIndex < this.filteredDispensers.length) {
                listHtml += `
                    <div class="dexpress-load-more-container">
                        <button class="dexpress-load-more-btn">
                            Učitaj još paketomata (${this.filteredDispensers.length - endIndex} preostalo)
                        </button>
                    </div>
                `;
            }

            // Ako je prva strana, zameni sadržaj, inače dodaj
            if (this.currentPage === 1) {
                $('#dexpress-dispensers-list').html(listHtml);
            } else {
                $('#dexpress-dispensers-list .dexpress-load-more-container').remove();
                $('#dexpress-dispensers-list').append(listHtml);
            }

            this.setupMapListeners();
        },

        adjustMapView: function () {
            if (!this.hasGoogleMaps || !this.map || this.markers.length === 0) {
                return;
            }

            var self = this;

            if (this.markers.length === 1) {
                this.map.setCenter(this.markers[0].marker.getPosition());
                this.map.setZoom(14);
            } else {
                this.map.fitBounds(this.bounds);

                var listener = google.maps.event.addListener(this.map, 'idle', function () {
                    if (self.map.getZoom() > 16) {
                        self.map.setZoom(16);
                    }
                    google.maps.event.removeListener(listener);
                });
            }
        },

        clearMarkers: function () {
            // Očisti cluster markere
            this.clusterMarkers.forEach(item => {
                if (item.marker) item.marker.setMap(null);
            });
            this.clusterMarkers = [];

            // Očisti individualne markere
            if (this.markers.length > 0) {
                this.markers.forEach(function (markerObj) {
                    if (markerObj.marker) {
                        markerObj.marker.setMap(null);
                    }
                });
                this.markers = [];
            }

            if (this.hasGoogleMaps && this.bounds) {
                this.bounds = new google.maps.LatLngBounds();
            }
        },

        showNoResults: function (message) {
            $('#dexpress-dispensers-list').html(`
                <div class="no-results">
                    <div class="no-results-message">${message}</div>
                    <div class="no-results-hint">Pokušajte sa drugim filterom ili resetujte pretragu</div>
                </div>
            `);
        },

        setupMapListeners: function () {
            var self = this;

            // Klik na dispenser u listi
            $(document).off('click', '.dexpress-dispenser-item').on('click', '.dexpress-dispenser-item', function (e) {
                if ($(e.target).hasClass('dispenser-select-btn')) {
                    return;
                }

                var id = $(this).data('id');
                console.log('[D-Express] Klik na paketomat ID:', id);
                self.highlightDispenser(id);
            });

            // Klik na dugme "Izaberi" u listi
            $(document).off('click', '.dispenser-select-btn').on('click', '.dispenser-select-btn', function (e) {
                e.stopPropagation();
                var id = $(this).data('id');
                console.log('[D-Express] Klik na dugme Izaberi, ID:', id);
                self.selectDispenser(id);
            });

            // Klik na dugme "Izaberi ovaj paketomat" u info prozoru
            $(document).off('click', '.dexpress-select-this-dispenser').on('click', '.dexpress-select-this-dispenser', function () {
                var id = $(this).data('id');
                console.log('[D-Express] Klik na dugme u info prozoru, ID:', id);
                self.selectDispenser(id);
                if (self.infoWindow) {
                    self.infoWindow.close();
                }
            });

            // Klik na "Učitaj još"
            $(document).off('click', '.dexpress-load-more-btn').on('click', '.dexpress-load-more-btn', function () {
                console.log('[D-Express] Učitavam sledeću stranu...');
                self.currentPage++;
                self.renderDispensersList();
            });

            // Klik na Clear Filter dugme
            $(document).off('click', '.dexpress-clear-filter-btn').on('click', '.dexpress-clear-filter-btn', function () {
                console.log('[D-Express] Clear filter klik');
                $('#dexpress-town-filter').val('');
                self.currentTownFilter = null;
                self.renderDispensers();
            });
        },

        highlightDispenser: function (id) {
            if (!this.hasGoogleMaps) return;

            var markerItem = this.markers.find(m => m.dispenser.id == id);
            if (markerItem && markerItem.marker) {
                this.map.setCenter(markerItem.marker.getPosition());
                this.map.setZoom(15);
                this.showDispenserInfo(markerItem.dispenser, markerItem.marker);

                $('.dexpress-dispenser-item').removeClass('selected');
                $('.dexpress-dispenser-item[data-id="' + id + '"]').addClass('selected');
            }
        },

        filterDispensers: function (townId) {
            var self = this;
            console.log('[D-Express] Filtriram po gradu:', townId);

            this.currentTownFilter = townId ? parseInt(townId) : null;
            console.log('[D-Express] Set filter to:', this.currentTownFilter);

            this.showLoader('#dexpress-dispensers-list', 'Filtriranje paketomata...');
            if (this.hasGoogleMaps) {
                this.showLoader('#dexpress-dispensers-map', 'Ažuriranje mape...');
            }

            // Renderiraj sa novim filterom
            this.renderDispensers();

            this.hideLoader('#dexpress-dispensers-list');
            if (this.hasGoogleMaps) {
                this.hideLoader('#dexpress-dispensers-map');
            }

            // Ažuriraj mapu zoom
            if (this.hasGoogleMaps && townId) {
                var townDispensers = this.dispensers.filter(d => parseInt(d.town_id) === parseInt(townId));
                if (townDispensers.length > 0) {
                    var bounds = new google.maps.LatLngBounds();
                    townDispensers.forEach(dispenser => {
                        if (dispenser.latitude && dispenser.longitude) {
                            bounds.extend(new google.maps.LatLng(dispenser.latitude, dispenser.longitude));
                        }
                    });
                    this.map.fitBounds(bounds);

                    // Postavi minimum zoom
                    google.maps.event.addListenerOnce(this.map, 'bounds_changed', () => {
                        if (this.map.getZoom() > 15) this.map.setZoom(15);
                    });
                }
            }
        },

        initTownAutocomplete: function () {
            var self = this;

            $('#dexpress-town-filter').on('input', function () {
                var term = $(this).val().toLowerCase().trim();

                if (term.length < 2) {
                    $('#dexpress-town-suggestions').hide();
                    // Reset filter kada obrišete pretragu
                    if (term.length === 0) {
                        self.currentTownFilter = null;
                        self.renderDispensers();
                    }
                    return;
                }

                // AŽURIRANO: Koristi novi optimizovani endpoint
                $.ajax({
                    url: dexpressCheckout.ajaxUrl,
                    type: 'GET',
                    data: {
                        action: 'dexpress_search_dispensers_by_town',
                        term: term,
                        nonce: dexpressCheckout.nonce
                    },
                    success: function (grouped_results) {
                        console.log('[D-Express] Autocomplete results:', grouped_results);

                        var html = '';
                        var resultCount = 0;

                        // grouped_results je objekat sa town_id kao ključevima
                        Object.values(grouped_results).forEach(town => {
                            // POBOLJŠANO FILTRIRANJE: Proveri da li grad POČINJE sa traženim terminom
                            if (town.town_name.toLowerCase().startsWith(term)) {
                                resultCount++;

                                html += `
                            <div class="town-suggestion-header" data-id="${town.town_id}" data-name="${town.town_name}">
                                <strong>${town.town_name} (${town.dispensers.length} paketomata)</strong>
                            </div>
                        `;

                                // Prikaži sve paketomata za ovaj grad (ne ograničavaj na 5)
                                town.dispensers.forEach(dispenser => {
                                    html += `
                                <div class="dispenser-suggestion" data-id="${dispenser.id}" data-town-id="${town.town_id}">
                                    ${dispenser.name} - ${dispenser.address}
                                </div>
                            `;
                                });
                            }
                        });

                        // Ako nema rezultata koji počinju sa terminom, onda pokušaj sa "contains"
                        if (resultCount === 0) {
                            Object.values(grouped_results).slice(0, 3).forEach(town => { // Maksimalno 3 grada
                                if (town.town_name.toLowerCase().includes(term)) {
                                    html += `
                                <div class="town-suggestion-header" data-id="${town.town_id}" data-name="${town.town_name}">
                                    <strong>${town.town_name} (${town.dispensers.length} paketomata)</strong>
                                </div>
                            `;

                                    town.dispensers.slice(0, 3).forEach(dispenser => { // Maksimalno 3 paketomata
                                        html += `
                                    <div class="dispenser-suggestion" data-id="${dispenser.id}" data-town-id="${town.town_id}">
                                        ${dispenser.name} - ${dispenser.address}
                                    </div>
                                `;
                                    });

                                    if (town.dispensers.length > 3) {
                                        html += `
                                    <div class="dispenser-suggestion-more">
                                        ... i još ${town.dispensers.length - 3} paketomata
                                    </div>
                                `;
                                    }
                                }
                            });
                        }

                        if (html) {
                            $('#dexpress-town-suggestions').html(html).show();
                        } else {
                            $('#dexpress-town-suggestions').html('<div class="no-suggestion">Nema rezultata</div>').show();
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('[D-Express] Autocomplete greška:', error);
                        $('#dexpress-town-suggestions').hide();
                    }
                });
            });

            // Klik na grad header - filtrira po gradu
            $(document).on('click', '.town-suggestion-header', function () {
                var townId = $(this).data('id');
                var townName = $(this).data('name');

                $('#dexpress-town-filter').val(townName);
                $('#dexpress-town-suggestions').hide();
                self.filterDispensers(townId);
            });

            // Klik na konkretan paketomat - direktno selektuje
            $(document).on('click', '.dispenser-suggestion', function () {
                var dispenserId = $(this).data('id');
                var townId = $(this).data('town-id');

                $('#dexpress-town-suggestions').hide();

                // Prvo filtruj po gradu
                self.filterDispensers(townId);

                // Zatim selektuj paketomat
                setTimeout(() => {
                    self.selectDispenser(dispenserId);
                }, 100);
            });

            // Sakrij predloge kada kliknemo van
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.dexpress-town-filter').length) {
                    $('#dexpress-town-suggestions').hide();
                }
            });

            // NOVI: Esc key za zatvaranje predloga
            $(document).on('keydown', '#dexpress-town-filter', function (e) {
                if (e.key === 'Escape') {
                    $('#dexpress-town-suggestions').hide();
                }
            });
        },
        showLoader: function (container, text = 'Učitavanje...') {
            var $container = $(container);
            $container.addClass('dexpress-container-relative');

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
            $(container).removeClass('dexpress-container-relative');
            $(container).find('.dexpress-loading-overlay').remove();
        },

        selectDispenser: function (dispenserId) {
            var dispenser = this.dispensers.find(function (d) {
                return d.id == dispenserId;
            });

            if (!dispenser) {
                console.error('[D-Express] Paketomat sa ID ' + dispenserId + ' nije pronađen');
                return;
            }

            console.log('[D-Express] Selektujem paketomat:', dispenser);

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
                        console.log('[D-Express] Paketomat uspešno sačuvan');

                        DExpressDispenserModal.closeModal();

                        setTimeout(function () {
                            console.log('[D-Express] Osvežavam checkout...');
                            $('body').trigger('update_checkout');
                        }, 100);
                    } else {
                        console.error('[D-Express] Greška pri čuvanju paketomata:', response);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('[D-Express] AJAX greška pri čuvanju:', error);
                }
            });
        },

        setupShippingMethodHandler: function () {
            console.log('[D-Express] Podešavam shipping method handler');

            $('input.shipping_method').off('change.dexpress');

            $('input.shipping_method').on('change.dexpress', function () {
                var methodId = $(this).val();
                console.log('[D-Express] Shipping method changed to:', methodId);

                if (methodId.indexOf('dexpress_dispenser') !== -1) {
                    $('.dexpress-dispenser-selection').show();
                    $('.dexpress-dispenser-wrapper').show();
                    console.log('[D-Express] Prikazujem paketomat opcije');
                } else {
                    $('.dexpress-dispenser-selection').hide();
                    $('.dexpress-dispenser-wrapper').hide();
                    console.log('[D-Express] Skrivam paketomat opcije');
                }
            });

            $('input.shipping_method:checked').trigger('change.dexpress');
        },

        updateVisibleDispensers: function () {
            // Ova metoda se poziva kada se mapa pomeri ili zoom-uje
            // Možemo dodati optimizacije za prikaz samo vidljivih paketomata
        }
    };

    $(document).ready(function () {
        if (is_checkout()) {
            console.log('[D-Express] Inicijalizujem na checkout stranici');
            DExpressDispenserModal.init();
        }
    });

    function is_checkout() {
        return $('form.woocommerce-checkout').length > 0;
    }

})(jQuery);