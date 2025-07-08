(function ($) {
    'use strict';

    var DExpressDispenserModal = {
        currentTownFilter: null,
        currentCityFilter: null, // DODAJ ovo za novi sistem
        currentTownIds: [], // DODAJ ovo za novi sistem
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

            // AŽURIRANO filtriranje - podržava i stari i novi sistem
            this.filteredDispensers = this.dispensers.filter(function (dispenser) {
                // Novi sistem - filtriranje po city_key
                if (self.currentCityFilter && self.currentTownIds.length > 0) {
                    return self.currentTownIds.includes(parseInt(dispenser.town_id));
                }

                // Stari sistem - filtriranje po town_id
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
            console.log('[D-Express] Kreiram napredne cluster markere...');

            // Grupiranje po glavnim gradovima 
            var cityGroups = {};

            this.filteredDispensers.forEach(dispenser => {
                if (!dispenser.latitude || !dispenser.longitude) return;

                var mainCity = this.getMainCityForLocation(dispenser.town, dispenser.town_id);

                if (!cityGroups[mainCity]) {
                    cityGroups[mainCity] = {
                        dispensers: [],
                        cityName: mainCity,
                        center: { lat: 0, lng: 0 },
                        count: 0,
                        bounds: new google.maps.LatLngBounds(),
                        isMajorCity: this.isMajorCityName(mainCity),
                        priority: this.getCityPriority(mainCity)
                    };
                }

                cityGroups[mainCity].dispensers.push(dispenser);
                cityGroups[mainCity].count++;
                cityGroups[mainCity].bounds.extend(
                    new google.maps.LatLng(dispenser.latitude, dispenser.longitude)
                );
            });

            // Izračunaj centar za svaki grad
            Object.keys(cityGroups).forEach(cityName => {
                var group = cityGroups[cityName];
                var latSum = 0, lngSum = 0;

                group.dispensers.forEach(dispenser => {
                    latSum += dispenser.latitude;
                    lngSum += dispenser.longitude;
                });

                group.center.lat = latSum / group.dispensers.length;
                group.center.lng = lngSum / group.dispensers.length;
            });

            this.cityGroups = cityGroups;
            this.createInitialTopCityMarkers();
        },

        getCityPriority: function (cityName) {
            var priorities = {
                'Beograd': 1,
                'Novi Sad': 2,
                'Niš': 3,
                'Kragujevac': 4,
                'Subotica': 5,
                'Zrenjanin': 6,
                'Pančevo': 7,
                'Čačak': 8,
                'Novi Pazar': 9,
                'Kraljevo': 10,
                'Leskovac': 11,
                'Smederevo': 12,
                'Zaječar': 13,
                'Šabac': 14,
                'Valjevo': 15,
                'Užice': 16
            };

            return priorities[cityName] || 100; // Ostali gradovi imaju nizak prioritet
        },

        createInitialTopCityMarkers: function () {
            console.log('[D-Express] Kreiram početne top city markere...');

            // Sortiraj gradove po prioritetu i broju paketomata
            var sortedCities = Object.values(this.cityGroups).sort((a, b) => {
                // Prvo po prioritetu (manji broj = veći prioritet)
                if (a.priority !== b.priority) {
                    return a.priority - b.priority;
                }
                // Zatim po broju paketomata (veći broj = veći prioritet)
                return b.count - a.count;
            });

            // Uzmi top 10 gradova za početni prikaz
            var topCities = sortedCities.slice(0, 10);

            this.clusterMarkers = [];
            this.markers = [];

            // Kreiraj markere samo za top gradove
            topCities.forEach(group => {
                this.createCityClusterMarker(group, true); // true = visible immediately

                // Kreiraj individual markere (skrivene)
                group.dispensers.forEach(dispenser => {
                    this.createIndividualMarker(dispenser, dispenser.town_id, false);
                });
            });

            // Kreiraj ostale gradove (skrivene)
            var remainingCities = sortedCities.slice(10);
            remainingCities.forEach(group => {
                this.createCityClusterMarker(group, false); // false = hidden initially

                group.dispensers.forEach(dispenser => {
                    this.createIndividualMarker(dispenser, dispenser.town_id, false);
                });
            });

            console.log(`[D-Express] Kreiran početni prikaz sa ${topCities.length} glavnih gradova`);
            console.log(`[D-Express] Skriveno je ${remainingCities.length} manjih gradova`);
        },

        isMajorCityName: function (cityName) {
            // Lista glavnih gradova koji se uvek prikazuju
            var majorCities = [
                'Beograd', 'Novi Sad', 'Niš', 'Kragujevac', 'Subotica',
                'Zrenjanin', 'Pančevo', 'Čačak', 'Novi Pazar', 'Kraljevo',
                'Leskovac', 'Smederevo', 'Zaječar', 'Šabac', 'Valjevo', 'Užice'
            ];
            return majorCities.includes(cityName);
        },

        getMainCityForLocation: function (townName, townId) {
            if (!townName || townName === 'Nepoznat grad' || townName === 'Unknown Town') {
                return 'Nepoznat grad';
            }

            var cleanName = townName.trim().toLowerCase();

            // Ukloni zagradice i dodatne informacije
            cleanName = cleanName.replace(/\s*\([^)]*\)/g, '');
            cleanName = cleanName.replace(/[^\w\sšđčćžŠĐČĆŽ\-]/g, '');
            cleanName = cleanName.replace(/\s+/g, ' ').trim();

            // PROŠIRENO MAPIRANJE - na osnovu analize podataka
            var cityMappings = {
                // BEOGRAD I OKOLINA (prošireno)
                'beograd': 'Beograd',
                'novi beograd': 'Beograd',
                'zemun': 'Beograd',
                'rakovica': 'Beograd',
                'voždovac': 'Beograd',
                'vračar': 'Beograd',
                'stari grad': 'Beograd',
                'savski venac': 'Beograd',
                'palilula': 'Beograd',
                'čukarica': 'Beograd',
                'zvezdara': 'Beograd',
                'surčin': 'Beograd',
                'lazarevac': 'Beograd',
                'mladenovac': 'Beograd',
                'obrenovac': 'Beograd',
                'sopot': 'Beograd',
                'barajevo': 'Beograd',
                'grocka': 'Beograd',

                // NOVI SAD I OKOLINA
                'novi sad': 'Novi Sad',
                'petrovaradin': 'Novi Sad',
                'sremska kamenica': 'Novi Sad',
                'futog': 'Novi Sad',
                'veternik': 'Novi Sad',

                // SUBOTICA I OKOLINA
                'subotica': 'Subotica',
                'palić': 'Subotica',
                'bajmok': 'Subotica',
                'horgoš': 'Subotica',

                // NIŠ I OKOLINA
                'niš': 'Niš',
                'niska banja': 'Niš',
                'medijana': 'Niš',
                'pantelej': 'Niš',

                // KRAGUJEVAC I OKOLINA
                'kragujevac': 'Kragujevac',
                'aerodrom': 'Kragujevac',

                // ČAČAK I OKOLINA
                'čačak': 'Čačak',

                // PANČEVO I OKOLINA
                'pančevo': 'Pančevo',

                // ZRENJANIN I OKOLINA
                'zrenjanin': 'Zrenjanin',

                // VALJEVO I OKOLINA
                'valjevo': 'Valjevo',

                // UŽICE I OKOLINA
                'užice': 'Užice',

                // NOVI PAZAR I OKOLINA
                'novi pazar': 'Novi Pazar',

                // KRALJEVO I OKOLINA
                'kraljevo': 'Kraljevo',

                // LESKOVAC I OKOLINA
                'leskovac': 'Leskovac',

                // SMEDEREVO I OKOLINA
                'smederevo': 'Smederevo',

                // ZAJEČAR I OKOLINA
                'zaječar': 'Zaječar',

                // ŠABAC I OKOLINA
                'šabac': 'Šabac'
            };

            // Probaj da nađeš mapiranje
            if (cityMappings[cleanName]) {
                return cityMappings[cleanName];
            }

            // Probaj parcijalno poklapanje za kompozitne nazive
            for (var mapping in cityMappings) {
                if (cleanName.includes(mapping) || mapping.includes(cleanName)) {
                    return cityMappings[mapping];
                }
            }

            // Kapitalizuj i vrati originalni naziv
            return cleanName.charAt(0).toUpperCase() + cleanName.slice(1);
        },

        // JEDNA DEFINICIJA createCityClusterMarker funkcije
        createCityClusterMarker: function (group, showImmediately = false) {
            var self = this;

            var markerStyle = this.getAdvancedMarkerStyle(group.count, group.isMajorCity);

            var clusterMarker = new google.maps.Marker({
                position: group.center,
                map: showImmediately ? this.map : null, // Pokaži odmah samo ako je top grad
                icon: markerStyle.icon,
                title: `${group.cityName} - ${group.count} paketomata`,
                zIndex: markerStyle.zIndex
            });

            clusterMarker.addListener('click', () => {
                console.log('[D-Express] Klik na cluster za grad:', group.cityName);

                // Zoom na grad
                this.map.fitBounds(group.bounds);

                google.maps.event.addListenerOnce(this.map, 'bounds_changed', () => {
                    var newZoom = this.map.getZoom();
                    if (newZoom > 16) {
                        this.map.setZoom(16);
                    } else if (newZoom < 11) {
                        this.map.setZoom(13);
                    }
                });
            });

            this.clusterMarkers.push({
                marker: clusterMarker,
                group: group,
                type: 'city-cluster',
                isTopCity: showImmediately
            });
        },

        getAdvancedMarkerStyle: function (count, isMajorCity) {
            var size, color, zIndex;

            // Prilagodi stil na osnovu grada i broja paketomata
            if (count >= 80) {
                size = 70; color = '#8B0000'; zIndex = 1600; // Beograd level
            } else if (count >= 50) {
                size = 65; color = '#B71C1C'; zIndex = 1500; // Veliki gradovi
            } else if (count >= 25) {
                size = 58; color = '#C62828'; zIndex = 1400; // Srednji gradovi
            } else if (count >= 15) {
                size = 52; color = '#D32F2F'; zIndex = 1300; // Manji gradovi
            } else if (count >= 8) {
                size = 47; color = '#E53935'; zIndex = 1200; // Mala mesta
            } else if (count >= 4 && isMajorCity) {
                size = 43; color = '#E90000'; zIndex = 1100; // Važni gradovi (manje paketomata)
            } else if (count >= 4) {
                size = 40; color = '#E90000'; zIndex = 1000; // Obična mesta
            } else {
                size = 36; color = '#F44336'; zIndex = 900; // Najmanja mesta
            }

            // Dodaj poseban stil za major cities
            var strokeWidth = isMajorCity ? 4 : 3;
            var fontSize = Math.min(18, Math.max(11, size / 4));

            return {
                icon: {
                    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(`
                <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="${size / 2}" cy="${size / 2}" r="${size / 2 - strokeWidth}" fill="${color}" stroke="#ffffff" stroke-width="${strokeWidth}"/>
                    <text x="${size / 2}" y="${size / 2 + fontSize / 3}" text-anchor="middle" fill="white" font-size="${fontSize}" font-weight="bold">${count}</text>
                </svg>
            `),
                    scaledSize: new google.maps.Size(size, size),
                    anchor: new google.maps.Point(size / 2, size / 2)
                },
                zIndex: zIndex
            };
        },

        createIndividualMarker: function (dispenser, townId, showImmediately = true) {
            var marker = new google.maps.Marker({
                position: { lat: dispenser.latitude, lng: dispenser.longitude },
                map: showImmediately ? this.map : null, // Pokaži odmah samo ako je eksplicitno traženo
                icon: {
                    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(`
                <svg width="32" height="32" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="16" cy="16" r="14" fill="#E90000" stroke="#ffffff" stroke-width="2"/>
                    <circle cx="16" cy="16" r="5" fill="white"/>
                </svg>
            `),
                    scaledSize: new google.maps.Size(32, 32),
                    anchor: new google.maps.Point(16, 16)
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

            if (this.bounds) {
                this.bounds.extend(marker.getPosition());
            }
        },

        updateMarkersBasedOnZoom: function () {
            if (!this.map) return;

            var zoom = this.map.getZoom();
            var mapBounds = this.map.getBounds();

            console.log('[D-Express] Ažuriram markere za zoom:', zoom);

            // Sakrij sve markere
            this.clearVisibleMarkers();

            if (!this.cityGroups) return;

            Object.keys(this.cityGroups).forEach(cityName => {
                var group = this.cityGroups[cityName];

                // Proveri da li je grad u vidljivom delu mape
                var isInView = mapBounds ? mapBounds.contains(new google.maps.LatLng(group.center.lat, group.center.lng)) : true;

                if (zoom <= 8) {
                    // Samo top gradovi sa 5+ paketomata
                    if (group.priority <= 10 && group.count >= 5) {
                        this.showCityCluster(group);
                    }
                } else if (zoom <= 10) {
                    // Top gradovi ili gradovi sa 3+ paketomata
                    if (group.priority <= 15 || group.count >= 3) {
                        this.showCityCluster(group);
                    }
                } else if (zoom <= 12) {
                    // Svi gradovi sa 2+ paketomata ili u vidljivom delu
                    if (group.count >= 2 || (isInView && group.count >= 1)) {
                        if (group.count > 1) {
                            this.showCityCluster(group);
                        } else {
                            this.showIndividualMarkersForGroup(group);
                        }
                    }
                } else {
                    // Visok zoom - prikaži individual markere za gradove u vidljivom delu
                    if (isInView) {
                        this.showIndividualMarkersForGroup(group);
                    } else if (group.priority <= 5) {
                        // Uvek prikaži top 5 gradova kao cluster
                        this.showCityCluster(group);
                    }
                }
            });
        },

        showCityCluster: function (group) {
            // Pronađi postojeći cluster marker za ovu grupu
            var clusterItem = this.clusterMarkers.find(item =>
                item.type === 'city-cluster' && item.group.cityName === group.cityName
            );

            if (clusterItem) {
                clusterItem.marker.setMap(this.map);
            }
        },

        showIndividualMarkersForGroup: function (group) {
            // Prikaži individual markere za ovu grupu
            group.dispensers.forEach(dispenser => {
                var markerItem = this.markers.find(item =>
                    item.dispenser.id === dispenser.id
                );

                if (markerItem) {
                    var shouldShow = !this.currentTownFilter ||
                        parseInt(markerItem.townId) === parseInt(this.currentTownFilter);
                    markerItem.marker.setMap(shouldShow ? this.map : null);
                }
            });
        },

        clearVisibleMarkers: function () {
            // Sakrij sve markere ali ih ne briši
            this.clusterMarkers.forEach(item => {
                item.marker.setMap(null);
            });

            this.markers.forEach(item => {
                item.marker.setMap(null);
            });
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

            // Event handleri sa optimizovanim selektorima
            $(document).off('click.dexpress-dispensers').on('click.dexpress-dispensers', '.dexpress-dispenser-item', function (e) {
                if ($(e.target).hasClass('dispenser-select-btn')) {
                    return;
                }
                var id = $(this).data('id');
                self.highlightDispenser(id);
            });

            $(document).off('click.dexpress-select').on('click.dexpress-select', '.dispenser-select-btn', function (e) {
                e.stopPropagation();
                var id = $(this).data('id');
                self.selectDispenser(id);
            });

            $(document).off('click.dexpress-info').on('click.dexpress-info', '.dexpress-select-this-dispenser', function () {
                var id = $(this).data('id');
                self.selectDispenser(id);
                if (self.infoWindow) {
                    self.infoWindow.close();
                }
            });

            $(document).off('click.dexpress-load-more').on('click.dexpress-load-more', '.dexpress-load-more-btn', function () {
                self.currentPage++;
                self.renderDispensersList();
            });

            // AŽURIRANI event handleri za novi autocomplete sistem
            $(document).off('click.dexpress-town-header').on('click.dexpress-town-header', '.town-suggestion-header', function () {
                var cityKey = $(this).data('city-key');
                var townName = $(this).data('name');
                var townIds = $(this).data('town-ids') ? $(this).data('town-ids').split(',').map(id => parseInt(id)) : [];

                $('#dexpress-town-filter').val(townName);
                $('#dexpress-town-suggestions').hide();
                self.filterDispensersByCity(cityKey, townIds);
            });

            $(document).off('click.dexpress-dispenser-direct').on('click.dexpress-dispenser-direct', '.dispenser-suggestion', function () {
                var dispenserId = $(this).data('id');
                var townId = $(this).data('town-id');

                $('#dexpress-town-suggestions').hide();

                // Filtriraj po gradu prvo
                var cityKey = self.getCityKeyForTownId(townId);
                var townIds = [townId];

                self.filterDispensersByCity(cityKey, townIds);

                // Zatim selektuj paketomat
                setTimeout(() => {
                    self.selectDispenser(dispenserId);
                }, 100);
            });

            $(document).off('click.dexpress-show-more').on('click.dexpress-show-more', '.dispenser-suggestion-more', function () {
                var cityKey = $(this).data('city-key');
                var townIds = $(this).data('town-ids') ? $(this).data('town-ids').split(',').map(id => parseInt(id)) : [];

                // Pronađi naziv grada
                var townName = $(this).prevAll('.town-suggestion-header').first().data('name') || 'Grad';

                $('#dexpress-town-filter').val(townName);
                $('#dexpress-town-suggestions').hide();
                self.filterDispensersByCity(cityKey, townIds);
            });

            // Clear filter događaji
            $(document).on('keydown.dexpress-filter', '#dexpress-town-filter', function (e) {
                if (e.key === 'Escape') {
                    $('#dexpress-town-suggestions').hide();
                }
            });

            $(document).on('click.dexpress-outside', function (e) {
                if (!$(e.target).closest('.dexpress-town-filter').length) {
                    $('#dexpress-town-suggestions').hide();
                }
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

        // STARA funkcija za backward compatibility
        filterDispensers: function (townId) {
            var self = this;
            console.log('[D-Express] Filtriram po gradu (stari sistem):', townId);

            this.currentTownFilter = townId ? parseInt(townId) : null;
            this.currentCityFilter = null; // Reset novi sistem
            this.currentTownIds = []; // Reset novi sistem

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

        // NOVA funkcija za filterovanje koja radi sa novim city_key sistemom
        filterDispensersByCity: function (cityKey, townIds) {
            var self = this;
            console.log('[D-Express] Filtriram po gradu (novi sistem):', cityKey, 'Town IDs:', townIds);

            // Reset stari sistem
            this.currentTownFilter = null;

            // Set novi sistem
            this.currentCityFilter = cityKey;
            this.currentTownIds = townIds || [];

            this.showLoader('#dexpress-dispensers-list', 'Filtriranje paketomata...');
            if (this.hasGoogleMaps) {
                this.showLoader('#dexpress-dispensers-map', 'Ažuriranje mape...');
            }

            // Resetuj paginaciju
            this.currentPage = 1;

            // Renderiraj sa novim filterom
            this.renderDispensers();

            this.hideLoader('#dexpress-dispensers-list');
            if (this.hasGoogleMaps) {
                this.hideLoader('#dexpress-dispensers-map');
            }
        },

        // NOVA funkcija za ažuriranje mape nakon filtriranja
        updateMapForFilteredDispensers: function () {
            if (!this.hasGoogleMaps || !this.filteredDispensers.length) return;

            // Sakrij sve postojeće markere
            this.clearVisibleMarkers();

            // Kreiraj nove bounds za filtrirane paketomata
            var filteredBounds = new google.maps.LatLngBounds();
            var hasValidCoordinates = false;

            this.filteredDispensers.forEach(dispenser => {
                if (dispenser.latitude && dispenser.longitude) {
                    filteredBounds.extend(new google.maps.LatLng(dispenser.latitude, dispenser.longitude));
                    hasValidCoordinates = true;

                    // Prikaži individual marker za svaki filtriran paketomat
                    var markerItem = this.markers.find(item => item.dispenser.id === dispenser.id);
                    if (markerItem) {
                        markerItem.marker.setMap(this.map);
                    }
                }
            });

            // Podesi mapu na filtrirane paketomata
            if (hasValidCoordinates) {
                this.map.fitBounds(filteredBounds);

                // Ograniči zoom
                google.maps.event.addListenerOnce(this.map, 'bounds_changed', () => {
                    var zoom = this.map.getZoom();
                    if (zoom > 15) {
                        this.map.setZoom(15);
                    } else if (zoom < 10) {
                        this.map.setZoom(12);
                    }
                });
            }
        },

        // HELPER funkcija za dobijanje city_key iz town_id
        getCityKeyForTownId: function (townId) {
            var dispenser = this.dispensers.find(d => d.town_id === parseInt(townId));
            if (dispenser) {
                return this.getMainCityForLocation(dispenser.town, dispenser.town_id);
            }
            return null;
        },

        clearAllFilters: function () {
            this.currentCityFilter = null;
            this.currentTownIds = [];
            this.currentTownFilter = null;
            $('#dexpress-town-filter').val('');
            $('#dexpress-town-suggestions').hide();
            this.renderDispensers();
        },

        initTownAutocomplete: function () {
            var self = this;
            var searchTimeout;

            $('#dexpress-town-filter').on('input', function () {
                var term = $(this).val().toLowerCase().trim();

                // Clear postojeći timeout
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }

                if (term.length < 2) {
                    $('#dexpress-town-suggestions').hide();
                    if (term.length === 0) {
                        self.clearAllFilters();
                    }
                    return;
                }

                // DODAJ LOADER
                $('#dexpress-town-suggestions').html(`
                    <div class="dexpress-autocomplete-loader">
                        <div class="dexpress-loader-spinner"></div>
                        <span>Pretraga gradova...</span>
                    </div>
                `).show();

                // THROTTLING: Sačekaj 300ms pre slanja AJAX zahteva
                searchTimeout = setTimeout(function () {
                    console.log('[D-Express] Tražim gradove za termin:', term);

                    $.ajax({
                        url: dexpressCheckout.ajaxUrl,
                        type: 'GET',
                        data: {
                            action: 'dexpress_search_dispensers_by_town',
                            term: term,
                            nonce: dexpressCheckout.nonce
                        },
                        success: function (grouped_results) {
                            console.log('[D-Express] Autocomplete results count:', Object.keys(grouped_results).length);

                            // Ukloni loader
                            $('#dexpress-town-suggestions .dexpress-autocomplete-loader').remove();

                            self.renderAutocompleteResults(grouped_results, term);
                        },
                        error: function (xhr, status, error) {
                            console.error('[D-Express] Autocomplete greška:', error);
                            $('#dexpress-town-suggestions').html(`
                                <div class="dexpress-autocomplete-error">
                                    <span>Greška pri pretrazi. Pokušajte ponovo.</span>
                                </div>
                            `).show();
                        }
                    });
                }, 300);
            });
        },

        renderAutocompleteResults: function (grouped_results, term) {
            var html = '';
            var MAX_TOWNS_DISPLAY = 5;
            var MAX_DISPENSERS_PER_TOWN = 4;

            // Sortiraj gradove - prioritet gradovima koji počinju sa terminom
            var townResults = Object.values(grouped_results).sort((a, b) => {
                var aStartsWith = a.town_name.toLowerCase().startsWith(term);
                var bStartsWith = b.town_name.toLowerCase().startsWith(term);

                if (aStartsWith && !bStartsWith) return -1;
                if (!aStartsWith && bStartsWith) return 1;

                // Zatim po broju paketomata
                return b.dispensers.length - a.dispensers.length;
            });

            // Ograniči rezultate
            townResults = townResults.slice(0, MAX_TOWNS_DISPLAY);

            townResults.forEach(town => {
                html += `
                    <div class="town-suggestion-header" data-city-key="${town.city_key}" data-name="${town.town_name}" data-town-ids="${town.town_ids ? town.town_ids.join(',') : ''}">
                        <strong>${town.town_name} (${town.dispensers.length} paketomata)</strong>
                    </div>
                `;

                if (town.dispensers.length > MAX_DISPENSERS_PER_TOWN) {
                    town.dispensers.slice(0, MAX_DISPENSERS_PER_TOWN).forEach(dispenser => {
                        html += `
                            <div class="dispenser-suggestion" data-id="${dispenser.id}" data-town-id="${dispenser.town_id}">
                                ${dispenser.name} - ${dispenser.address}
                            </div>
                        `;
                    });

                    html += `
                        <div class="dispenser-suggestion-more" data-city-key="${town.city_key}" data-town-ids="${town.town_ids ? town.town_ids.join(',') : ''}">
                            <em>... i još ${town.dispensers.length - MAX_DISPENSERS_PER_TOWN} paketomata (klik za sve)</em>
                        </div>
                    `;
                } else {
                    town.dispensers.forEach(dispenser => {
                        html += `
                            <div class="dispenser-suggestion" data-id="${dispenser.id}" data-town-id="${dispenser.town_id}">
                                ${dispenser.name} - ${dispenser.address}
                            </div>
                        `;
                    });
                }
            });

            if (html) {
                $('#dexpress-town-suggestions').html(html).show();
            } else {
                $('#dexpress-town-suggestions').html('<div class="no-suggestion">Nema rezultata</div>').show();
            }
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