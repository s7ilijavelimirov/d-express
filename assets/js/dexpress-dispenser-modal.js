(function ($) {
    'use strict';

    var DExpressLocationModal = {
        // Osnovne varijable - PROŠIRENE
        dispensers: [],
        shops: [],
        centres: [],
        towns: [],
        currentLocations: [], // Trenutno prikazane lokacije
        filteredLocations: [],
        selectedTownId: null,
        currentLocationType: 'dispensers', // NOVO: trenutni tip lokacije

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

        // Blokiranje map update-a tokom info window prikaza
        blockMapUpdates: false,

        init: function () {
            this.hasGoogleMaps = (typeof google !== 'undefined' && typeof google.maps !== 'undefined');
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
            $(document).on('click', '.dexpress-modal-close', function (e) {
                e.preventDefault();
                self.closeModal();
            });

            // ESC za zatvaranje
            $(document).on('keydown', function (e) {
                if (e.keyCode === 27 && $('#dexpress-dispenser-modal').is(':visible')) {
                    self.closeModal();
                }
            });

            // NOVO: Tab switching
            $(document).on('click', '.dexpress-tab-btn', function (e) {
                e.preventDefault();
                var locationType = $(this).data('type');
                self.switchLocationTab(locationType);
            });

            // Autocomplete events
            $(document).on('input', '#dexpress-town-select', function () {
                var query = $(this).val();
                if (query.length >= 2) {
                    self.searchLocations(query);
                } else {
                    self.hideLocationSuggestions();
                    self.showAllLocations();
                }
            });

            // Reset filter
            $(document).on('click', '.dexpress-reset-filter', function () {
                $('#dexpress-town-select').val('');
                self.hideLocationSuggestions();
                self.showAllLocations();
            });

            // Click na suggestion
            $(document).on('click', '.dispenser-suggestion', function () {
                var locationId = $(this).data('location-id');
                var locationType = $(this).data('location-type');

                // Prebaci na odgovarajući tab ako nije već
                if (locationType !== self.currentLocationType) {
                    self.switchLocationTab(locationType);
                }

                setTimeout(function () {
                    self.highlightLocation(locationId);
                }, 300);

                self.hideLocationSuggestions();
            });
        },

        // NOVA METODA: Prebacivanje između tabova
        switchLocationTab: function (locationType) {
            var self = this;

            console.log('[D-Express] Switching to:', locationType);

            // Update active tab
            $('.dexpress-tab-btn').removeClass('active');
            $('.dexpress-tab-btn[data-type="' + locationType + '"]').addClass('active');

            // Update current type
            this.currentLocationType = locationType;

            // Resetuj search
            $('#dexpress-town-select').val('');
            this.hideLocationSuggestions();

            // Load data za odgovarajući tip
            this.loadLocationData(locationType);
        },

        // NOVA METODA: Učitavanje podataka za tip lokacije
        loadLocationData: function (locationType) {
            var self = this;

            // Provjeri da li su podaci već učitani
            if (this[locationType] && this[locationType].length > 0) {
                console.log('[D-Express] Using cached data for:', locationType);
                this.currentLocations = this[locationType];
                this.showAllLocations();
                return;
            }

            // Show loader
            this.showLoader('#dexpress-dispensers-list', 'Učitavanje lokacija...');

            // Određi koji AJAX call da pozoveš
            var ajaxAction;

            if (locationType === 'dispensers') {
                ajaxAction = 'dexpress_get_dispensers';
            } else {
                ajaxAction = 'dexpress_get_' + locationType;
            }

            console.log('[D-Express] Making AJAX call:', ajaxAction);

            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'POST',
                data: {
                    action: ajaxAction,
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    console.log('[D-Express] AJAX response:', response);

                    self.hideLoader('#dexpress-dispensers-list');

                    if (response.success) {
                        var dataKey = locationType;
                        self[dataKey] = response.data[dataKey] || [];
                        self.currentLocations = self[dataKey];

                        console.log('[D-Express] Loaded', self.currentLocations.length, locationType);

                        // SAMO SADA PRIKAŽI LOKACIJE
                        self.showAllLocations();

                        // INICIJALIZUJ MAPU NAKON ŠTO SU LOKACIJE UČITANE
                        if (self.hasGoogleMaps && !self.isMapInitialized) {
                            setTimeout(function () {
                                self.initMap();
                            }, 100);
                        }
                    } else {
                        console.error('[D-Express] AJAX error:', response);
                        self.showError('Greška pri učitavanju lokacija: ' + (response.data?.message || 'Nepoznata greška'));
                    }
                },
                error: function (xhr, status, error) {
                    console.error('[D-Express] AJAX call failed:', error);
                    self.hideLoader('#dexpress-dispensers-list');
                    self.showError('Greška pri komunikaciji sa serverom');
                }
            });
        },

        openModal: function () {
            $('#dexpress-dispenser-modal').show();
            this.ensureModalStructure();

            // Inicijalizuj sa dispensers tabom - ali ne inicijalizuj mapu ovde
            this.switchLocationTab('dispensers');
        },

        closeModal: function () {
            $('#dexpress-dispenser-modal').hide();
            this.hideLocationSuggestions();
        },

        // AŽURIRANA METODA: Pretraga kroz sve tipove lokacija
        searchLocations: function (query) {
            var self = this;

            if (!query || query.length < 2) {
                this.hideLocationSuggestions();
                return;
            }

            // Pretraži kroz sve tipove lokacija
            var allResults = [];

            // Pretraži dispensers
            if (this.dispensers && this.dispensers.length > 0) {
                var dispenserResults = this.dispensers.filter(function (item) {
                    return self.matchesSearchQuery(item, query);
                }).map(function (item) {
                    return { ...item, type: 'dispensers' };
                });
                allResults = allResults.concat(dispenserResults);
            }

            // Pretraži shops
            if (this.shops && this.shops.length > 0) {
                var shopResults = this.shops.filter(function (item) {
                    return self.matchesSearchQuery(item, query);
                }).map(function (item) {
                    return { ...item, type: 'shops' };
                });
                allResults = allResults.concat(shopResults);
            }

            // Pretraži centres
            if (this.centres && this.centres.length > 0) {
                var centreResults = this.centres.filter(function (item) {
                    return self.matchesSearchQuery(item, query);
                }).map(function (item) {
                    return { ...item, type: 'centres' };
                });
                allResults = allResults.concat(centreResults);
            }

            // Ograniči na prvih 10 rezultata
            allResults = allResults.slice(0, 10);

            this.showLocationSuggestions(allResults);
        },

        // HELPER: Provjerava da li lokacija odgovara upitu
        matchesSearchQuery: function (item, query) {
            var searchableText = [
                item.name || '',
                item.address || '',
                item.town || ''
            ].join(' ').toLowerCase();

            return searchableText.includes(query.toLowerCase());
        },

        // AŽURIRANA METODA: Prikaz suggestion-a sa tipovima
        // AŽURIRANA METODA: Prikaz suggestion-a sa tipovima
        showLocationSuggestions: function (locations) {
            var self = this; // DODAJ OVO
            var html = '';

            if (locations.length === 0) {
                html = '<div class="no-suggestion">Nema rezultata</div>';
            } else {
                locations.forEach(function (location) {
                    var typeLabel = self.getLocationTypeLabel(location.type);
                    var icon = self.getLocationTypeIcon(location.type);

                    html += '<div class="dispenser-suggestion" data-location-id="' + location.id + '" data-location-type="' + location.type + '">';
                    html += '<div class="dispenser-suggestion-name">' + icon + ' ' + (location.name || 'Nepoznato ime') + '</div>';
                    html += '<div class="dispenser-suggestion-address">' + (location.address || 'Nepoznata adresa') + '</div>';
                    html += '<div class="dispenser-suggestion-city">' + (location.town || 'Nepoznat grad') + ' • ' + typeLabel + '</div>';
                    html += '</div>';
                });
            }

            $('#dexpress-town-suggestions').html(html).show();
        },

        // NOVA METODA: Dobija label za tip lokacije
        getLocationTypeLabel: function (type) {
            var labels = {
                'dispensers': 'Paketomat',
                'shops': 'Prodavnica',
                'centres': 'Centar'
            };
            return labels[type] || type;
        },

        // NOVA METODA: Dobija ikonu za tip lokacije
        getLocationTypeIcon: function (type) {
            var icons = {
                'dispensers': '📦',
                'shops': '🏪',
                'centres': '🏢'
            };
            return icons[type] || '📍';
        },

        hideLocationSuggestions: function () {
            $('#dexpress-town-suggestions').hide();
        },

        showAllLocations: function () {
            this.filteredLocations = this.currentLocations;
            this.currentPage = 1;
            this.renderLocationsList();

            // POZOVI showAllLocationsOnMap SAMO AKO JE MAPA INICIJALIZOVANA
            if (this.hasGoogleMaps && this.isMapInitialized && this.currentLocations && this.currentLocations.length > 0) {
                this.showAllLocationsOnMap();
            }
        },

        // AŽURIRANA METODA: Renderovanje liste lokacija
        renderLocationsList: function () {
            var container = $('#dexpress-dispensers-list');

            if (!this.currentLocations || this.currentLocations.length === 0) {
                container.html('<div class="no-results"><div class="no-results-message">Nema dostupnih lokacija</div></div>');
                return;
            }

            var startIndex = (this.currentPage - 1) * this.pageSize;
            var endIndex = startIndex + this.pageSize;
            var itemsToShow = this.filteredLocations.slice(startIndex, endIndex);
            var totalItems = this.filteredLocations.length;

            var html = '';

            // Info header
            html += '<div class="dexpress-dispensers-info">';
            html += '<span class="dexpress-locations-count">Prikazuje se ' + itemsToShow.length + ' od ' + totalItems + ' lokacija</span>';
            html += '</div>';

            // Lista lokacija
            itemsToShow.forEach((function (location) {
                html += this.renderLocationItem(location);
            }).bind(this));

            // Load more dugme
            if (endIndex < totalItems) {
                html += '<div class="dexpress-load-more-container">';
                html += '<button type="button" class="dexpress-load-more-btn">Učitaj još lokacija (' + (totalItems - endIndex) + ')</button>';
                html += '</div>';
            }

            if (this.currentPage === 1) {
                container.html(html);
            } else {
                container.find('.dexpress-load-more-container').remove();
                container.append(html);
            }

            this.bindLocationEvents();
        },

        // NOVA METODA: Renderovanje jedne lokacije
        // NOVA METODA: Renderovanje jedne lokacije
        renderLocationItem: function (location) {
            var typeLabel = this.getLocationTypeLabel(this.currentLocationType);
            var icon = this.getLocationTypeIcon(this.currentLocationType);
            var workingHours = location.working_hours || location.work_hours || 'Nepoznato';

            // Prilagodi prikazane informacije prema tipu lokacije
            var additionalInfo = '';
            if (this.currentLocationType === 'shops' && location.phone) {
                additionalInfo = '<span class="dispenser-info-phone">📞 ' + location.phone + '</span>';
            } else if (this.currentLocationType === 'centres' && location.phone) {
                additionalInfo = '<span class="dispenser-info-phone">📞 ' + location.phone + '</span>';
            }

            var html = '<div class="dexpress-dispenser-item" data-id="' + location.id + '">';
            html += '<div class="dispenser-header">';
            html += '<h4 class="dispenser-title">' + icon + ' ' + (location.name || 'Nepoznato ime') + '</h4>';
            html += '<span class="dispenser-type-badge" data-type="' + this.currentLocationType + '">' + typeLabel + '</span>';
            html += '</div>';

            html += '<div class="dispenser-details">';
            html += '<div class="dispenser-location">';
            html += '<span class="dispenser-address">📍 ' + (location.address || 'Nepoznata adresa') + ', ' + (location.town || 'Nepoznat grad') + '</span>';
            html += '</div>';

            html += '<div class="dispenser-info">';
            html += '<span class="dispenser-hours">🕐 ' + workingHours + '</span>';
            if (additionalInfo) {
                html += additionalInfo;
            }
            html += '</div>';

            // Payment methods samo za dispensers
            if (this.currentLocationType === 'dispensers') {
                html += '<div class="dispenser-payment">';
                if (location.pay_by_cash) html += '<span class="payment-method">💵 Gotovina</span>';
                if (location.pay_by_card) html += '<span class="payment-method">💳 Kartica</span>';
                html += '</div>';
            }
            html += '</div>';

            html += '<div class="dispenser-actions">';
            html += '<button type="button" class="dispenser-select-btn" data-id="' + location.id + '">IZABERI</button>';
            html += '</div>';

            html += '</div>';

            return html;
        },
        bindLocationEvents: function () {
            var self = this;

            // Klik na lokaciju
            $('.dexpress-dispenser-item').off('click').on('click', function (e) {
                if ($(e.target).hasClass('dispenser-select-btn')) return;

                var id = $(this).data('id');
                self.highlightLocation(id);
            });

            // Klik na dugme za izbor
            $('.dispenser-select-btn').off('click').on('click', function (e) {
                e.stopPropagation();
                var id = $(this).data('id');
                self.selectLocation(id);
            });

            // Load more
            $('.dexpress-load-more-btn').off('click').on('click', function () {
                self.loadMoreLocations();
            });
        },

        // AŽURIRANA METODA: Highlight lokacije
        highlightLocation: function (id) {
            if (!this.hasGoogleMaps) return;

            var location = this.currentLocations.find(function (l) {
                return l.id == id;
            });

            if (!location || !location.latitude || !location.longitude) {
                return;
            }

            console.log('[D-Express] Highlighting location:', location.name);

            // Privremeno odblokiraj za pomeranje mape
            this.blockMapUpdates = false;

            // Pomeri mapu na lokaciju sa boljim zoom-om
            var position = { lat: parseFloat(location.latitude), lng: parseFloat(location.longitude) };

            // Smooth transition ka lokaciji
            this.map.panTo(position);

            // Postaviti optimalni zoom
            var currentZoom = this.map.getZoom();
            var targetZoom = currentZoom < 12 ? 14 : Math.max(currentZoom, 12);

            setTimeout(function () {
                this.map.setZoom(targetZoom);
            }.bind(this), 300);

            // Sačekaj da se mapa pozicionira
            setTimeout(function () {
                // Očisti postojeće markere
                this.clearMapMarkers();

                // Kreiraj samo marker za izabranu lokaciju
                var marker = this.createMarker(location);

                // Prikaži info window
                setTimeout(function () {
                    this.showLocationInfo(location, marker);
                }.bind(this), 200);

            }.bind(this), 500);

            // Highlight u listi
            $('.dexpress-dispenser-item').removeClass('selected');
            $('.dexpress-dispenser-item[data-id="' + id + '"]').addClass('selected');

            // Scroll do elementa u listi
            var $selectedItem = $('.dexpress-dispenser-item[data-id="' + id + '"]');
            if ($selectedItem.length > 0) {
                var $container = $('#dexpress-dispensers-list');
                var containerTop = $container.scrollTop();
                var containerHeight = $container.height();
                var itemTop = $selectedItem.position().top + containerTop;
                var itemHeight = $selectedItem.outerHeight();

                // Scroll only if item is not fully visible
                if (itemTop < containerTop || itemTop + itemHeight > containerTop + containerHeight) {
                    $container.animate({
                        scrollTop: itemTop - (containerHeight / 2) + (itemHeight / 2)
                    }, 300);
                }
            }
        },

        // AŽURIRANA METODA: Selekcija lokacije
        selectLocation: function (id) {
            var location = this.currentLocations.find(function (l) {
                return l.id == id;
            });

            if (!location) {
                return;
            }

            // Prikaži success animaciju
            this.showSuccessAnimation(location);

            // Sačuvaj izabranu lokaciju
            this.saveChosenLocation(location);
        },

        showSuccessAnimation: function (location) {
            var self = this;
            var typeLabel = this.getLocationTypeLabel(this.currentLocationType);

            // Kreiraj success overlay
            var successOverlay = $(`
                <div class="dexpress-success-overlay">
                    <div class="dexpress-success-content">
                        <div class="checkmark">
                            <div class="checkmark_stem"></div>
                            <div class="checkmark_kick"></div>
                        </div>
                        <h3>Uspešno!</h3>
                        <div class="dexpress-success-message">
                            <span>Izabrali ste ${typeLabel.toLowerCase()}:</span>
                            <strong>${location.name}</strong>
                            <small>${location.address}, ${location.town}</small>
                        </div>
                    </div>
                </div>
            `);

            $('.dexpress-modal-body').append(successOverlay);

            setTimeout(function () {
                self.hideSuccessAnimation();
                self.closeModal();
            }, 2000);
        },

        hideSuccessAnimation: function () {
            $('.dexpress-success-overlay').remove();
        },

        // AŽURIRANA METODA: Čuvanje izabrane lokacije
        // AŽURIRANA METODA: Čuvanje izabrane lokacije
        saveChosenLocation: function (location) {
            var self = this;

            // Dodaj tip lokacije u podatke
            var locationData = {
                id: location.id,
                name: location.name,
                address: location.address,
                town: location.town,
                town_id: location.town_id,
                postal_code: location.postal_code || '',
                type: this.currentLocationType,
                phone: location.phone || '',
                working_hours: location.working_hours || location.work_hours || '',
                latitude: location.latitude || 0,
                longitude: location.longitude || 0,
                pay_by_cash: location.pay_by_cash || 0,
                pay_by_card: location.pay_by_card || 0
            };

            console.log('[D-Express] Saving location data:', locationData);

            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dexpress_save_chosen_dispenser',
                    nonce: dexpressCheckout.nonce,
                    dispenser: locationData
                },
                success: function (response) {
                    console.log('[D-Express] Save response:', response);

                    if (response.success) {
                        console.log('[D-Express] Location saved successfully');

                        // Ažuriraj UI na checkout stranici
                        self.updateCheckoutUI(locationData);

                        // Triggeruj WooCommerce update
                        $('body').trigger('update_checkout');

                    } else {
                        $('.dexpress-success-message span').text('Greška pri čuvanju! Pokušajte ponovo.');
                        $('.dexpress-success-content h3').text('Greška!').css('color', '#dc3545');
                        $('.checkmark').addClass('error');

                        setTimeout(function () {
                            self.hideSuccessAnimation();
                        }, 2000);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('[D-Express] Save failed:', error);
                    $('.dexpress-success-message span').text('Greška pri komunikaciji!');
                    $('.dexpress-success-content h3').text('Greška!').css('color', '#dc3545');
                    $('.checkmark').addClass('error');

                    setTimeout(function () {
                        self.hideSuccessAnimation();
                    }, 2000);
                }
            });
        },

        // NOVA METODA: Ažuriranje checkout UI-ja
        updateCheckoutUI: function (location) {
            var typeLabel = this.getLocationTypeLabel(location.type);

            // Ažuriraj prikaz izabrane lokacije
            $('.dexpress-chosen-dispenser-info').html(`
                <strong>${location.name}</strong><br>
                ${location.address}, ${location.town}<br>
                <small>Tip: ${typeLabel}</small>
                <br><a href="#" class="dexpress-change-dispenser">Promenite lokaciju</a>
            `).show();

            $('.dexpress-dispenser-warning').hide();
        },

        loadMoreLocations: function () {
            this.currentPage++;
            this.renderLocationsList();
        },

        // Mapa metode - ažurirane za različite tipove lokacija
        initMap: function () {
            if (!this.hasGoogleMaps) return;

            var mapOptions = {
                zoom: 7,
                center: { lat: 44.0165, lng: 21.0059 },
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                gestureHandling: 'cooperative',
                zoomControl: true,
                mapTypeControl: false,
                scaleControl: true,
                streetViewControl: false,
                rotateControl: false,
                fullscreenControl: false
            };

            this.map = new google.maps.Map(document.getElementById('dexpress-dispensers-map'), mapOptions);
            this.bounds = new google.maps.LatLngBounds();
            this.infoWindow = new google.maps.InfoWindow({
                maxWidth: 300
            });
            this.isMapInitialized = true;

            this.setupMapEventListeners();

            // POZOVI showAllLocationsOnMap SAMO AKO IMAŠ LOKACIJE
            if (this.currentLocations && this.currentLocations.length > 0) {
                this.showAllLocationsOnMap();
            }
        },
        showAllLocationsOnMap: function () {
            if (!this.currentLocations || this.currentLocations.length === 0) return;

            // Očisti postojeće markere
            this.clearMapMarkers();

            // Kreiraj markere za sve lokacije
            this.currentLocations.forEach(function (location) {
                if (location.latitude && location.longitude) {
                    this.createMarker(location);
                }
            }.bind(this));

            // Fit mapa da prikaže sve markere
            if (this.markers.length > 0) {
                this.map.fitBounds(this.bounds);

                // Ograniči minimalni zoom
                var self = this;
                google.maps.event.addListenerOnce(this.map, 'bounds_changed', function () {
                    if (self.map.getZoom() > 15) {
                        self.map.setZoom(15);
                    }
                    if (self.map.getZoom() < 6) {
                        self.map.setZoom(6);
                    }
                });
            }
        },
        setupMapEventListeners: function () {
            var self = this;

            this.map.addListener('zoom_changed', function () {
                if (!self.blockMapUpdates) {
                    self.handleZoomChange();
                }
            });

            this.map.addListener('bounds_changed', function () {
                if (!self.blockMapUpdates) {
                    clearTimeout(self.boundsChangeTimeout);
                    self.boundsChangeTimeout = setTimeout(function () {
                        self.handleBoundsChange();
                    }, 300);
                }
            });
        },

        renderMapMarkers: function () {
            if (!this.hasGoogleMaps || !this.map || !this.currentLocations) return;

            var bounds = this.map.getBounds();
            if (!bounds) {
                setTimeout(() => this.renderMapMarkers(), 100);
                return;
            }

            var zoom = this.map.getZoom();
            this.renderMarkersBasedOnZoom(zoom);
        },

        renderMarkersBasedOnZoom: function (zoom) {
            var bounds = this.map.getBounds();
            if (!bounds) return;

            this.clearMapMarkers();

            var allVisibleLocations = this.getLocationsInBounds(bounds);

            if (zoom <= 7) {
                this.showOnlyClusteringForZoom(allVisibleLocations, 2);
            } else if (zoom === 8) {
                this.showMixedViewForZoom(allVisibleLocations);
            } else {
                this.showIndividualMarkersForZoom(allVisibleLocations);
            }
        },

        getLocationsInBounds: function (bounds) {
            return this.currentLocations.filter(function (location) {
                if (!location.latitude || !location.longitude) return false;

                var position = new google.maps.LatLng(
                    parseFloat(location.latitude),
                    parseFloat(location.longitude)
                );
                return bounds.contains(position);
            });
        },
        createMarker: function (location) {
            var self = this;
            var iconSvg = this.getMarkerIcon(this.currentLocationType);

            var marker = new google.maps.Marker({
                position: {
                    lat: parseFloat(location.latitude),
                    lng: parseFloat(location.longitude)
                },
                map: this.map,
                title: location.name,
                zIndex: 500,
                icon: {
                    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(iconSvg),
                    scaledSize: new google.maps.Size(28, 28), // Malo manji markeri
                    anchor: new google.maps.Point(14, 14)
                },
                animation: google.maps.Animation.DROP // Animacija pada
            });

            marker.addListener('click', function () {
                // Highlight u listi takođe
                $('.dexpress-dispenser-item').removeClass('selected');
                $('.dexpress-dispenser-item[data-id="' + location.id + '"]').addClass('selected');

                self.showLocationInfo(location, marker);
            });

            this.markers.push({
                marker: marker,
                location: location,
                temporary: false
            });

            this.bounds.extend(marker.getPosition());

            return marker;
        },

        // NOVA METODA: Različite ikone za različite tipove
        getMarkerIcon: function (type) {
            var colors = {
                'dispensers': '#E90000',
                'shops': '#2196F3',
                'centres': '#4CAF50'
            };

            var color = colors[type] || '#E90000';

            return `
                <svg width="32" height="32" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="16" cy="16" r="14" fill="${color}" stroke="#ffffff" stroke-width="2"/>
                    <circle cx="16" cy="16" r="5" fill="white"/>
                </svg>
            `;
        },

        showLocationInfo: function (location, marker) {
            var typeLabel = this.getLocationTypeLabel(this.currentLocationType);
            var icon = this.getLocationTypeIcon(this.currentLocationType);

            var content = '<div class="dexpress-info-window">';
            content += '<div class="info-header">';
            content += '<h4>' + icon + ' ' + (location.name || 'Nepoznato ime') + '</h4>';
            content += '<span class="info-type-badge">' + typeLabel + '</span>';
            content += '</div>';
            content += '<div class="info-address">📍 ' + (location.address || 'Nepoznata adresa') + '</div>';
            content += '<div class="info-town">' + (location.town || 'Nepoznat grad') + '</div>';

            if (location.working_hours || location.work_hours) {
                content += '<div class="info-hours">🕐 ' + (location.working_hours || location.work_hours) + '</div>';
            }

            if (this.currentLocationType === 'dispensers') {
                content += '<div class="info-payment">';
                if (location.pay_by_cash) content += '<span class="payment-tag">💵 Gotovina</span>';
                if (location.pay_by_card) content += '<span class="payment-tag">💳 Kartica</span>';
                content += '</div>';
            }

            if ((this.currentLocationType === 'shops' || this.currentLocationType === 'centres') && location.phone) {
                content += '<div class="info-phone">📞 ' + location.phone + '</div>';
            }

            content += '<div class="info-actions">';
            content += '<button type="button" class="info-select-btn" onclick="DExpressLocationModal.selectLocation(' + location.id + ')">IZABERI</button>';
            content += '</div>';
            content += '</div>';

            this.infoWindow.setContent(content);
            this.infoWindow.open(this.map, marker);

            this.blockMapUpdates = true;
            clearTimeout(this.infoWindowTimeout);
            this.infoWindowTimeout = setTimeout(function () {
                this.blockMapUpdates = false;
            }.bind(this), 2000);
        },

        // Ostale helper metode...
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

        showError: function (message) {
            $('#dexpress-dispensers-list').html(`
                <div class="no-results">
                    <div class="no-results-message">${message}</div>
                    <div class="no-results-hint">Molimo pokušajte ponovo</div>
                </div>
            `);
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

        ensureModalStructure: function () {
            var modalBody = $('.dexpress-modal-body');
            console.log('[DEBUG] Modal body found:', modalBody.length);
            console.log('[DEBUG] Main content exists:', modalBody.find('.dexpress-main-content').length);
            // Proveri da li već postoji main-content struktura
            if (modalBody.find('.dexpress-main-content').length === 0) {
                console.log('[DEBUG] Creating modal structure...');
                // Sačuvaj postojeći search sadržaj
                var existingSearch = modalBody.find('.dexpress-town-filter').length > 0 ?
                    modalBody.find('.dexpress-town-filter')[0].outerHTML :
                    `<div class="dexpress-town-filter">
                <label for="dexpress-town-select">Pretražite lokacije:</label>
                <input type="text" id="dexpress-town-select" placeholder="Unesite naziv, adresu ili grad..." autocomplete="off">
                <button type="button" class="dexpress-reset-filter">✕</button>
                <div id="dexpress-town-suggestions"></div>
            </div>`;

                // Sačuvaj postojeći map sadržaj
                var existingMap = modalBody.find('#dexpress-dispensers-map').length > 0 ?
                    modalBody.find('#dexpress-dispensers-map')[0].outerHTML :
                    '<div id="dexpress-dispensers-map"><div class="dexpress-map-placeholder"><div class="icon"></div><p>Učitavanje mape...</p></div></div>';

                // Sačuvaj postojeći list sadržaj  
                var existingList = modalBody.find('#dexpress-dispensers-list').length > 0 ?
                    modalBody.find('#dexpress-dispensers-list')[0].outerHTML :
                    '<div id="dexpress-dispensers-list"><div class="no-results"><div class="no-results-message">Učitavanje lokacija...</div></div></div>';

                // Kreiraj novu strukturu
                modalBody.html(`
                    ${existingSearch}
                 <div class="dexpress-main-content">
                        <div class="dexpress-map-section">
                         ${existingMap}
                        </div>
                        <div class="dexpress-dispensers-section">
                          ${existingList}
                     </div>
                    </div>
        `);
        console.log('[DEBUG] Modal structure created');
            }
        },

        // Clustering metode za mapu
        showInitialClustering: function () {
            if (!this.currentLocations || this.currentLocations.length === 0) return;

            var majorCities = this.getMajorCitiesForClustering();
            var currentZoom = this.map.getZoom();

            this.clearMapMarkers();

            var citiesToShow = majorCities.filter(function (city) {
                return city.count >= 2;
            });

            citiesToShow.forEach(function (city) {
                this.createClusterMarker(city);
            }.bind(this));

            if (this.markers.length > 0) {
                this.map.fitBounds(this.bounds);

                var self = this;
                google.maps.event.addListenerOnce(this.map, 'bounds_changed', function () {
                    var newZoom = self.map.getZoom();
                    if (newZoom > 7) {
                        self.map.setZoom(7);
                    }
                });
            }
        },

        getMajorCitiesForClustering: function () {
            var cityGroups = {};

            this.currentLocations.forEach(function (location) {
                if (!location.town || !location.latitude || !location.longitude) return;

                var cityKey = location.town.toLowerCase();
                if (!cityGroups[cityKey]) {
                    cityGroups[cityKey] = {
                        name: location.town,
                        locations: [],
                        count: 0,
                        lat: 0,
                        lng: 0
                    };
                }

                cityGroups[cityKey].locations.push(location);
                cityGroups[cityKey].count++;
                cityGroups[cityKey].lat += parseFloat(location.latitude);
                cityGroups[cityKey].lng += parseFloat(location.longitude);
            });

            // Kalkuliši prosečne koordinate za svaki grad
            Object.keys(cityGroups).forEach(function (cityKey) {
                var city = cityGroups[cityKey];
                city.lat = city.lat / city.count;
                city.lng = city.lng / city.count;
            });

            return Object.values(cityGroups);
        },

        createClusterMarker: function (city) {
            var self = this;
            var clusterIconSvg = this.getClusterMarkerIcon(city.count);

            var marker = new google.maps.Marker({
                position: { lat: city.lat, lng: city.lng },
                map: this.map,
                title: city.name + ' (' + city.count + ' lokacija)',
                zIndex: 1000,
                icon: {
                    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(clusterIconSvg),
                    scaledSize: new google.maps.Size(40, 40),
                    anchor: new google.maps.Point(20, 20)
                }
            });

            marker.addListener('click', function () {
                self.zoomToCity(city);
            });

            this.markers.push({
                marker: marker,
                city: city,
                isCluster: true
            });

            this.bounds.extend(marker.getPosition());
        },

        getClusterMarkerIcon: function (count) {
            var color = this.getClusterColor(count);

            return `
                <svg width="40" height="40" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="20" cy="20" r="18" fill="${color}" stroke="#ffffff" stroke-width="2"/>
                    <text x="20" y="25" text-anchor="middle" fill="white" font-family="Arial, sans-serif" font-size="12" font-weight="bold">${count}</text>
                </svg>
            `;
        },

        getClusterColor: function (count) {
            if (count >= 10) return '#E90000';
            if (count >= 5) return '#FF6B00';
            return '#FFA500';
        },

        zoomToCity: function (city) {
            this.map.setCenter({ lat: city.lat, lng: city.lng });
            this.map.setZoom(11);
        },

        showOnlyClusteringForZoom: function (locations, minClusterSize) {
            var cityGroups = this.groupLocationsByCity(locations);

            Object.values(cityGroups).forEach(function (city) {
                if (city.count >= minClusterSize) {
                    this.createClusterMarker(city);
                }
            }.bind(this));
        },

        showMixedViewForZoom: function (locations) {
            var cityGroups = this.groupLocationsByCity(locations);

            Object.values(cityGroups).forEach(function (city) {
                if (city.count >= 2) {
                    this.createClusterMarker(city);
                } else {
                    city.locations.forEach(function (location) {
                        this.createMarker(location);
                    }.bind(this));
                }
            }.bind(this));
        },

        showIndividualMarkersForZoom: function (locations) {
            locations.forEach(function (location) {
                this.createMarker(location);
            }.bind(this));
        },

        groupLocationsByCity: function (locations) {
            var cityGroups = {};

            locations.forEach(function (location) {
                if (!location.town || !location.latitude || !location.longitude) return;

                var cityKey = location.town.toLowerCase();
                if (!cityGroups[cityKey]) {
                    cityGroups[cityKey] = {
                        name: location.town,
                        locations: [],
                        count: 0,
                        lat: 0,
                        lng: 0
                    };
                }

                cityGroups[cityKey].locations.push(location);
                cityGroups[cityKey].count++;
                cityGroups[cityKey].lat += parseFloat(location.latitude);
                cityGroups[cityKey].lng += parseFloat(location.longitude);
            });

            Object.keys(cityGroups).forEach(function (cityKey) {
                var city = cityGroups[cityKey];
                city.lat = city.lat / city.count;
                city.lng = city.lng / city.count;
            });

            return cityGroups;
        },

        handleZoomChange: function () {
            var zoom = this.map.getZoom();

            clearTimeout(this.zoomChangeTimeout);
            this.zoomChangeTimeout = setTimeout(function () {
                this.renderMarkersBasedOnZoom(zoom);
            }.bind(this), 200);
        },

        handleBoundsChange: function () {
            var currentZoom = this.map.getZoom();
            var bounds = this.map.getBounds();

            if (!bounds) return;

            this.renderMarkersBasedOnZoom(currentZoom);
        },

        createTemporaryMarkerForInfo: function (location) {
            var self = this;
            var iconSvg = this.getMarkerIcon(this.currentLocationType);

            var marker = new google.maps.Marker({
                position: {
                    lat: parseFloat(location.latitude),
                    lng: parseFloat(location.longitude)
                },
                map: this.map,
                title: location.name,
                zIndex: 1100,
                icon: {
                    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(iconSvg),
                    scaledSize: new google.maps.Size(32, 32),
                    anchor: new google.maps.Point(16, 16)
                }
            });

            marker.addListener('click', function () {
                self.showLocationInfo(location, marker);
            });

            this.markers.push({
                marker: marker,
                location: location,
                temporary: true
            });

            this.showLocationInfo(location, marker);
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

        setupShippingMethodHandler: function () {
            $('input.shipping_method').off('change.dexpress').on('change.dexpress', function () {
                var methodId = $(this).val();

                if (methodId && methodId.indexOf('dexpress_dispenser') !== -1) {
                    $('.dexpress-dispenser-selection').show();
                    $('.dexpress-dispenser-wrapper').show();
                } else {
                    $('.dexpress-dispenser-selection').hide();
                    $('.dexpress-dispenser-wrapper').hide();
                }
            });

            $('input.shipping_method:checked').trigger('change.dexpress');
        }
    };

    // Globalno dostupan objekat
    window.DExpressLocationModal = DExpressLocationModal;

    // Inicijalizacija kada je DOM spreman
    $(document).ready(function () {
        if ($('form.woocommerce-checkout').length > 0) {
            DExpressLocationModal.init();
        }
    });

})(jQuery);