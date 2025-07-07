(function ($) {
    'use strict';

    var DExpressDispenserModal = {
        currentTownFilter: null,
        dispensers: [],
        map: null,
        markers: [],
        bounds: null,
        infoWindow: null,
        isMapInitialized: false,
        hasGoogleMaps: false,
        currentPage: 1,
        pageSize: 10,
        totalDispensers: 0,
        filteredDispensers: [],

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

            this.initTownAutocomplete();
            this.setupShippingMethodHandler();
        },

        openModal: function () {
            console.log('[D-Express] Otvaranje modala');
            $('#dexpress-dispenser-modal').addClass('show');
            
            // Blokira scroll na body
            $('body').css('overflow', 'hidden');
            
            if (!this.isMapInitialized) {
                this.initMap();
                this.loadDispensers();
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
                    mapTypeControl: true,
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
                $('#dexpress-dispensers-map').html(`
                    <div class="dexpress-map-placeholder">
                        <div class="icon">⚠️</div>
                        <p>Greška pri učitavanju mape</p>
                        <small>${error.message}</small>
                    </div>
                `);
            }
        },

        loadDispensers: function () {
            var self = this;
            console.log('[D-Express] Učitavam paketomata...');

            // Loading state
            self.showLoader('#dexpress-dispensers-list', 'Učitavanje paketomata...');
            if (self.hasGoogleMaps) {
                self.showLoader('#dexpress-dispensers-map', 'Učitavanje mape...');
            }

            // AJAX poziv
            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dexpress_get_dispensers',
                    nonce: dexpressCheckout.nonce
                },
                timeout: 15000,
                success: function (response) {
                    console.log('[D-Express] AJAX odgovor:', response);
                    
                    self.hideLoader('#dexpress-dispensers-list');
                    if (self.hasGoogleMaps) {
                        self.hideLoader('#dexpress-dispensers-map');
                    }

                    if (response.success && response.data.dispensers) {
                        self.dispensers = response.data.dispensers;
                        console.log('[D-Express] Učitano ' + self.dispensers.length + ' paketomata');
                        
                        if (self.dispensers.length > 0) {
                            console.log('[D-Express] Prvi paketomat:', self.dispensers[0]);
                            self.renderDispensers();
                        } else {
                            self.showNoResults('Nema dostupnih paketomata u bazi');
                        }
                    } else {
                        console.error('[D-Express] Neuspešan AJAX odgovor:', response);
                        self.showNoResults('Greška pri učitavanju paketomata');
                    }
                },
                error: function (xhr, status, error) {
                    console.error('[D-Express] AJAX greška:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText
                    });
                    
                    self.hideLoader('#dexpress-dispensers-list');
                    if (self.hasGoogleMaps) {
                        self.hideLoader('#dexpress-dispensers-map');
                    }
                    
                    self.showNoResults('Greška pri komunikaciji sa serverom');
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
            
            // Filtriraj paketomata
            this.filteredDispensers = this.dispensers.filter(function (dispenser) {
                if (self.currentTownFilter && dispenser.town_id != self.currentTownFilter) {
                    return false;
                }
                return true;
            });

            console.log('[D-Express] Filtriran broj paketomata:', this.filteredDispensers.length);

            if (this.filteredDispensers.length === 0) {
                this.showNoResults('Nema dostupnih paketomata za izabrani filter');
                return;
            }

            // Kreiraj markere za sve filtrirane paketomata (ali prikaži samo prva 10)
            this.createMapMarkers();
            
            // Prikaži prvu stranu paketomata
            this.renderDispensersList();
            
            // Podesi mapu
            this.adjustMapView();
        },

        createMapMarkers: function () {
            var self = this;
            var validMarkersCount = 0;

            this.filteredDispensers.forEach(function (dispenser) {
                var lat = parseFloat(dispenser.latitude);
                var lng = parseFloat(dispenser.longitude);
                
                if (self.hasGoogleMaps && self.map && lat && lng && !isNaN(lat) && !isNaN(lng)) {
                    try {
                        var position = new google.maps.LatLng(lat, lng);
                        self.bounds.extend(position);

                        var marker = new google.maps.Marker({
                            position: position,
                            map: self.map,
                            title: dispenser.name,
                            icon: {
                                url: self.createMarkerIcon(),
                                scaledSize: new google.maps.Size(32, 32),
                                anchor: new google.maps.Point(16, 32)
                            },
                            animation: google.maps.Animation.DROP
                        });

                        // Info window za marker
                        google.maps.event.addListener(marker, 'click', function () {
                            self.infoWindow.setContent(self.renderDispenserInfo(dispenser));
                            self.infoWindow.open(self.map, marker);
                        });

                        self.markers.push({
                            marker: marker,
                            dispenser: dispenser
                        });
                        
                        validMarkersCount++;
                    } catch (error) {
                        console.error('[D-Express] Greška pri kreiranju markera:', error);
                    }
                }
            });

            console.log('[D-Express] Kreiran je ' + validMarkersCount + ' markera na mapi');
        },

        renderDispensersList: function () {
            var self = this;
            
            // Paginiraj podatke
            var startIndex = (this.currentPage - 1) * this.pageSize;
            var endIndex = startIndex + this.pageSize;
            var pageDispensers = this.filteredDispensers.slice(startIndex, endIndex);
            
            console.log('[D-Express] Prikazujem paketomata od', startIndex, 'do', endIndex);

            // Grupiši po gradu za trenutnu stranu
            var groupedByTown = {};
            pageDispensers.forEach(function (dispenser) {
                if (!groupedByTown[dispenser.town]) {
                    groupedByTown[dispenser.town] = [];
                }
                groupedByTown[dispenser.town].push(dispenser);
            });

            // Generiši HTML
            var listHtml = '';
            
            Object.keys(groupedByTown).sort().forEach(function(town) {
                var dispensersInTown = groupedByTown[town];
                listHtml += `
                    <div class="dexpress-dispensers-town">
                        <h4 data-count="${dispensersInTown.length}">${town}</h4>
                        <div class="dexpress-town-dispensers">
                `;
                
                dispensersInTown.forEach(function(dispenser) {
                    var paymentBadges = '';
                    if (dispenser.pay_by_cash == 1) {
                        paymentBadges += '<span class="payment-badge cash">Gotovina</span>';
                    }
                    if (dispenser.pay_by_card == 1) {
                        paymentBadges += '<span class="payment-badge card">Kartica</span>';
                    }
                    
                    listHtml += `
                        <div class="dexpress-dispenser-item" data-id="${dispenser.id}">
                            <div class="dispenser-content">
                                <div class="dispenser-name">${dispenser.name}</div>
                                <div class="dispenser-address">${dispenser.address}</div>
                                <div class="dispenser-info">
                                    Radno vreme: ${dispenser.work_hours || 'Nije definisano'}
                                </div>
                                ${paymentBadges ? `<div class="dispenser-payment">${paymentBadges}</div>` : ''}
                            </div>
                            <button class="dispenser-select-btn" data-id="${dispenser.id}">Izaberi</button>
                        </div>
                    `;
                });
                
                listHtml += `
                        </div>
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

        createMarkerIcon: function() {
            return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(`
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32">
                    <circle cx="16" cy="16" r="15" fill="#e60054" stroke="white" stroke-width="2"/>
                    <path d="M16,6c-3.5,0-6.3,2.8-6.3,6.3c0,4.7,6.3,11.7,6.3,11.7s6.3-7,6.3-11.7C22.3,8.8,19.5,6,16,6z" fill="white"/>
                    <circle cx="16" cy="12.3" r="2.3" fill="#e60054"/>
                </svg>
            `);
        },

        adjustMapView: function() {
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

        clearMarkers: function() {
            if (this.markers.length > 0) {
                this.markers.forEach(function(markerObj) {
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

        renderDispenserInfo: function (dispenser) {
            var paymentInfo = '';
            var paymentOptions = [];
            if (dispenser.pay_by_cash == 1) paymentOptions.push('Gotovina');
            if (dispenser.pay_by_card == 1) paymentOptions.push('Kartica');
            if (paymentOptions.length > 0) {
                paymentInfo = '<p><strong>Načini plaćanja:</strong> ' + paymentOptions.join(', ') + '</p>';
            }

            return `
                <div class="dexpress-dispenser-info">
                    <h4>${dispenser.name}</h4>
                    <p><strong>Grad:</strong> ${dispenser.town}</p>
                    <p><strong>Adresa:</strong> ${dispenser.address}</p>
                    <p><strong>Radno vreme:</strong> ${dispenser.work_hours || 'Nije definisano'}</p>
                    ${paymentInfo}
                    <button class="dexpress-select-this-dispenser" data-id="${dispenser.id}">
                        Izaberi ovaj paketomat
                    </button>
                </div>
            `;
        },

        showNoResults: function(message) {
            $('#dexpress-dispensers-list').html(`
                <div class="no-results">
                    <div class="no-results-message">${message}</div>
                    <div class="no-results-hint">Pokušajte sa drugim filterom ili resetujte pretragu</div>
                </div>
            `);
        },

        setupMapListeners: function() {
            var self = this;
            
            // Klik na dispenser u listi
            $(document).off('click', '.dexpress-dispenser-item').on('click', '.dexpress-dispenser-item', function(e) {
                if ($(e.target).hasClass('dispenser-select-btn')) {
                    return;
                }

                var id = $(this).data('id');
                console.log('[D-Express] Klik na paketomat ID:', id);
                self.highlightDispenser(id);
            });

            // Klik na dugme "Izaberi" u listi
            $(document).off('click', '.dispenser-select-btn').on('click', '.dispenser-select-btn', function(e) {
                e.stopPropagation();
                var id = $(this).data('id');
                console.log('[D-Express] Klik na dugme Izaberi, ID:', id);
                self.selectDispenser(id);
            });

            // Klik na dugme "Izaberi ovaj paketomat" u info prozoru
            $(document).off('click', '.dexpress-select-this-dispenser').on('click', '.dexpress-select-this-dispenser', function() {
                var id = $(this).data('id');
                console.log('[D-Express] Klik na dugme u info prozoru, ID:', id);
                self.selectDispenser(id);
                if (self.infoWindow) {
                    self.infoWindow.close();
                }
            });

            // NOVO: Klik na "Učitaj još"
            $(document).off('click', '.dexpress-load-more-btn').on('click', '.dexpress-load-more-btn', function() {
                console.log('[D-Express] Učitavam sledeću stranu...');
                self.currentPage++;
                self.renderDispensersList();
            });
        },

        highlightDispenser: function(id) {
            console.log('[D-Express] Označavam paketomat ID:', id);
            
            // Pronađi marker i centrraj mapu na njega
            var marker = this.markers.find(function(m) {
                return m.dispenser.id == id;
            });

            if (marker && marker.marker && this.map) {
                this.map.setCenter(marker.marker.getPosition());
                this.map.setZoom(15);

                this.infoWindow.setContent(this.renderDispenserInfo(marker.dispenser));
                this.infoWindow.open(this.map, marker.marker);

                marker.marker.setAnimation(google.maps.Animation.BOUNCE);
                setTimeout(function() {
                    marker.marker.setAnimation(null);
                }, 1500);
                
                $('.dexpress-dispenser-item').removeClass('selected');
                $('.dexpress-dispenser-item[data-id="' + id + '"]').addClass('selected');
            }
        },

        filterDispensers: function (townId) {
            console.log('[D-Express] Filtriram po gradu:', townId);
            
            this.currentTownFilter = (townId === '' || townId === 0 || townId === null) ? null : townId;
            
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
        },

        initTownAutocomplete: function () {
            var self = this;
            var towns = [];
            var $input = $('#dexpress-town-filter');
            var $suggestions = $('#dexpress-town-suggestions');
            var isLoadingTowns = false;

            // Dodaj dugme za reset filtera
            if ($input.next('.reset-filter').length === 0) {
                $('<button type="button" class="reset-filter" title="Resetuj filter">×</button>')
                    .insertAfter($input)
                    .on('click', function () {
                        console.log('[D-Express] Reset filter clicked');
                        $input.val('');
                        $suggestions.removeClass('show');
                        $('.dexpress-town-filter').removeClass('has-value');
                        self.filterDispensers(null);
                    });
            }

            // Funkcija za učitavanje gradova
            function loadTowns() {
                if (isLoadingTowns || towns.length > 0) {
                    return;
                }

                isLoadingTowns = true;
                self.showLoader('.dexpress-town-filter', 'Učitavanje gradova...');
                
                $.ajax({
                    url: dexpressCheckout.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'dexpress_get_towns_list',
                        nonce: dexpressCheckout.nonce
                    },
                    success: function (response) {
                        self.hideLoader('.dexpress-town-filter');
                        console.log('[D-Express] Towns response:', response);
                        
                        if (response.success && response.data.towns) {
                            towns = response.data.towns;
                            console.log('[D-Express] Učitano ' + towns.length + ' gradova');
                            
                            // Ako korisnik već kuca, prikaži sugestije
                            var currentTerm = $input.val().trim();
                            if (currentTerm.length >= 2 && $input.is(':focus')) {
                                showSuggestions(filterTowns(currentTerm));
                            }
                        } else {
                            console.error('[D-Express] Greška pri učitavanju gradova:', response);
                        }
                        isLoadingTowns = false;
                    },
                    error: function (xhr, status, error) {
                        console.error('[D-Express] AJAX greška za gradove:', error);
                        self.hideLoader('.dexpress-town-filter');
                        isLoadingTowns = false;
                    }
                });
            }

            // Funkcija za filtriranje gradova
            function filterTowns(term) {
                if (towns.length === 0) {
                    return [];
                }
                
                term = term.toLowerCase();
                return towns.filter(function (town) {
                    return town.name.toLowerCase().indexOf(term) > -1 ||
                           (town.display_name && town.display_name.toLowerCase().indexOf(term) > -1);
                }).slice(0, 10);
            }

            // Funkcija za prikazivanje predloga
            function showSuggestions(filteredTowns) {
                $suggestions.empty();

                if (filteredTowns.length === 0) {
                    $suggestions.append('<div class="town-suggestion" style="padding: 8px 12px; color: #999;">Nema rezultata</div>');
                } else {
                    filteredTowns.forEach(function (town) {
                        var displayText = town.name;
                        if (town.postal_code) {
                            displayText += ' (' + town.postal_code + ')';
                        }
                        if (town.dispenser_count) {
                            displayText += ' - ' + town.dispenser_count + ' paketomata';
                        }
                        
                        var $suggestion = $('<div class="town-suggestion"></div>')
                            .text(displayText)
                            .data('town-id', town.id)
                            .data('town-name', town.name);
                        
                        $suggestions.append($suggestion);
                    });
                }

                $suggestions.addClass('show');
            }

            // Event listeneri
            $input.on('input', function () {
                var term = $(this).val().trim();

                if (term.length < 2) {
                    $suggestions.removeClass('show');
                    return;
                }

                // Ako još nema učitanih gradova, učitaj ih
                if (towns.length === 0) {
                    loadTowns();
                    return;
                }

                showSuggestions(filterTowns(term));
            });

            $input.on('focus', function () {
                var term = $(this).val().trim();
                
                // Učitaj gradove ako nisu učitani
                if (towns.length === 0) {
                    loadTowns();
                    return;
                }
                
                if (term.length >= 2) {
                    showSuggestions(filterTowns(term));
                }
            });

            // Zatvaranje sugestija klikom van
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.dexpress-town-filter').length) {
                    $suggestions.removeClass('show');
                }
            });

            // Klik na sugestiju
            $(document).on('click', '.town-suggestion', function () {
                var townId = $(this).data('town-id');
                var townName = $(this).data('town-name');
                
                console.log('[D-Express] Selected town:', townName, 'ID:', townId);
                
                if (townId && townName) {
                    $input.val(townName);
                    $('.dexpress-town-filter').addClass('has-value');
                    $suggestions.removeClass('show');
                    self.filterDispensers(townId);
                }
            });

            // KLJUČNA ISPRAVKA: Resetuj podatke kada se menja input vrednost
            $input.on('keydown', function(e) {
                // Kad korisnik briše sadržaj (Backspace ili Delete), resetuj filter
                if (e.key === 'Backspace' || e.key === 'Delete') {
                    setTimeout(function() {
                        var currentValue = $input.val().trim();
                        if (currentValue.length === 0) {
                            console.log('[D-Express] Input je prazan, resetujem filter');
                            $('.dexpress-town-filter').removeClass('has-value');
                            $suggestions.removeClass('show');
                            self.filterDispensers(null);
                        }
                    }, 10);
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
                error: function(xhr, status, error) {
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