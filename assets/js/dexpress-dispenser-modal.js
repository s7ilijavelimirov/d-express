(function ($) {
    'use strict';

    var DExpressDispenserModal = {
        currentTownFilter: null, // Dodaj ovo
        dispensers: [],
        init: function () {
            // Slušamo događaj klika na dugme za izbor paketomata
            $(document).on('click', '.dexpress-select-dispenser-btn, .dexpress-change-dispenser', function (e) {
                e.preventDefault();
                DExpressDispenserModal.openModal();
            });

            // Zatvaranje modala
            $(document).on('click', '.dexpress-modal-close', function () {
                DExpressDispenserModal.closeModal();
            });

            // Zatvaranje na Escape taster
            $(document).on('keyup', function (e) {
                if (e.key === "Escape") {
                    DExpressDispenserModal.closeModal();
                }
            });

            // Filtriranje po gradu
            $('#dexpress-town-filter').on('change', function () {
                DExpressDispenserModal.filterDispensers($(this).val());
            });

            // Inicijalizacija nakon što se učita checkout
            $(document.body).off('updated_checkout.dexpress').on('updated_checkout.dexpress', function () {
                DExpressDispenserModal.setupShippingMethodHandler();
            });
            this.initTownAutocomplete();
            this.setupShippingMethodHandler();
        },

        openModal: function () {
            $('#dexpress-dispenser-modal').show();
            this.initMap();
        },

        closeModal: function () {
            $('#dexpress-dispenser-modal').hide();
        },

        initMap: function () {
            // Provera da li je Google Maps API učitan
            if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
                console.log('Google Maps API nije učitan');
                $('#dexpress-dispensers-map').html('<p>Učitavanje Google Maps nije uspelo</p>');
                return;
            }

            // Inicijalizacija mape sa centrom na Srbiji
            var map = new google.maps.Map(document.getElementById('dexpress-dispensers-map'), {
                center: { lat: 44.0165, lng: 21.0059 },
                zoom: 7,
                mapTypeControl: true,
                streetViewControl: false,
                fullscreenControl: true,
                zoomControl: true
            });

            this.loadDispensers(map);
        },
        loadDispensers: function (map) {
            var self = this;

            // Prikaz animacije učitavanja za mapu i listu
            self.showLoader('#dexpress-dispensers-map', 'Učitavanje mape...');
            self.showLoader('#dexpress-dispensers-list', 'Učitavanje paketomata...');

            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'dexpress_get_dispensers',
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    // Uklanjanje animacije
                    self.hideLoader('#dexpress-dispensers-map');
                    self.hideLoader('#dexpress-dispensers-list');

                    if (response.success && response.data.dispensers) {
                        self.dispensers = response.data.dispensers;
                        self.renderDispensers(map);
                    } else {
                        $('#dexpress-dispensers-list').html('<p>Nema dostupnih paketomata</p>');
                    }
                },
                error: function () {
                    // Uklanjanje animacije u slučaju greške
                    self.hideLoader('#dexpress-dispensers-map');
                    self.hideLoader('#dexpress-dispensers-list');

                    $('#dexpress-dispensers-list').html('<p>Greška pri učitavanju paketomata</p>');
                }
            });
        },

        renderDispenserInfo: function (dispenser) {
            // Pomoćna funkcija za proveru da li postoji vrednost
            function ifExists(value, prefix = '', suffix = '') {
                return value ? prefix + value + suffix : '';
            }

            // Formatiranje radnog vremena
            function formatWorkHours(workHours) {
                // Ako nema podataka, prikaži poruku
                if (!workHours || typeof workHours !== "string") {
                    return `<li>❌ Radno vreme nije dostupno</li>`;
                }

                // Ako radno vreme dolazi u formatu "Svakim danom 6-22", samo ga prikaži
                if (workHours.toLowerCase().includes("svakim danom")) {
                    return `<li>🕒 ${workHours}</li>`;
                }

                // Ako je radno vreme već formatirano pravilno kao niz, koristi ga
                const days = ["Ponedeljak", "Utorak", "Sreda", "Četvrtak", "Petak", "Subota", "Nedelja"];
                const hoursArray = workHours.split(', '); // Razbijanje stringa u niz prema zarezima

                // Ako API vraća svih 7 dana, formatiraj ih lepo
                if (hoursArray.length === 7) {
                    let output = "<ul class='dexpress-work-hours'>";
                    hoursArray.forEach((hours, index) => {
                        output += `<li>📅 <strong>${days[index]}:</strong> ${hours}</li>`;
                    });
                    output += "</ul>";
                    return output;
                }

                // Ako format ne odgovara ni jednom pravilu, vrati default poruku
                return `<li>⚠ Format radnog vremena nije prepoznat</li>`;
            }

            // Priprema informacija o načinu plaćanja
            let paymentOptions = [];
            if (dispenser.pay_by_cash == 1) paymentOptions.push('Gotovina');
            if (dispenser.pay_by_card == 1) paymentOptions.push('Kartica');

            let paymentInfo = paymentOptions.length > 0 ?
                '<p><strong>Načini plaćanja:</strong> ' + paymentOptions.join(', ') + '</p>' : '';

            // Formatiranje sadržaja info prozora
            return `
                <div class="dexpress-dispenser-info">
                    <h4>${dispenser.name}</h4>

                    <div class="dexpress-info-grid">
                        <!-- Leva kolona -->
                        <div class="dexpress-info-left">
                            <p>📍 <strong>Grad:</strong> ${dispenser.town}</p>
                            <p>📌 <strong>Adresa podizanja:</strong> ${dispenser.address}</p>
                            ${dispenser.phone ? `<p>📞 <strong>Pozovite nas:</strong> ${dispenser.phone}</p>` : ""}
                        </div>

                        <!-- Desna kolona -->
                        <div class="dexpress-info-right">
                            <p>⏰ <strong>Radno vreme:</strong> <br> ${formatWorkHours(dispenser.work_hours)}</p>
                            ${paymentInfo}
                        </div>
                    </div>

                    <button class="button dexpress-select-this-dispenser" data-id="${dispenser.id}">
                        ✅ Izaberi ovaj paketomat
                    </button>
                </div>
            `;
        },
        // Dodaj ovo u init funkciju
        initTownAutocomplete: function () {
            var self = this;
            var towns = []; // Svi gradovi će biti učitani iz AJAX poziva
            var $input = $('#dexpress-town-filter');
            var $suggestions = $('#dexpress-town-suggestions');

            // Dodaj dugme za reset filtera
            $('<button type="button" class="reset-filter" title="Resetuj filter">×</button>')
                .insertAfter($input)
                .on('click', function () {
                    $input.val('');
                    $suggestions.hide();
                    $('.dexpress-town-filter').removeClass('has-value');
                    self.filterDispensers(''); // Resetuj filter
                });
            self.showLoader('.dexpress-town-filter', 'Učitavanje gradova...');
            // Učitaj listu gradova (ovo možemo optimizovati da koristimo već učitane podatke)
            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dexpress_get_towns_list',
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    self.hideLoader('.dexpress-town-filter');
                    if (response.success) {
                        towns = response.data.towns;
                    }
                },
                error: function () {
                    self.hideLoader('.dexpress-town-filter');
                }
            });

            // Funkcija za filtriranje gradova
            function filterTowns(term) {
                term = term.toLowerCase();
                return towns.filter(function (town) {
                    return town.name.toLowerCase().indexOf(term) > -1;
                }).slice(0, 10); // Ograniči na 10 rezultata
            }

            // Funkcija za prikazivanje predloga
            function showSuggestions(filteredTowns) {
                $suggestions.empty();

                if (filteredTowns.length === 0) {
                    $suggestions.append('<div class="town-suggestion">Nema rezultata</div>');
                } else {
                    filteredTowns.forEach(function (town) {
                        $suggestions.append(
                            $('<div class="town-suggestion"></div>')
                                .text(town.name + (town.postal_code ? ' (' + town.postal_code + ')' : ''))
                                .data('town-id', town.id)
                        );
                    });
                }

                $suggestions.show();
            }

            // Event listeneri
            $input.on('input', function () {
                var term = $(this).val().trim();

                if (term.length < 2) {
                    $suggestions.hide();
                    return;
                }

                showSuggestions(filterTowns(term));
            });

            $input.on('focus', function () {
                var term = $(this).val().trim();
                if (term.length >= 2) {
                    showSuggestions(filterTowns(term));
                }
            });

            // Zatvaranje sugestija klikom van
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.dexpress-town-filter').length) {
                    $suggestions.hide();
                }
            });

            // Navigacija tastaturom
            $input.on('keydown', function (e) {
                var $active = $suggestions.find('.town-suggestion.active');

                switch (e.keyCode) {
                    case 40: // Down arrow
                        e.preventDefault();
                        if ($active.length) {
                            $active.removeClass('active')
                                .next('.town-suggestion').addClass('active');
                        } else {
                            $suggestions.find('.town-suggestion:first').addClass('active');
                        }
                        break;

                    case 38: // Up arrow
                        e.preventDefault();
                        if ($active.length) {
                            $active.removeClass('active')
                                .prev('.town-suggestion').addClass('active');
                        } else {
                            $suggestions.find('.town-suggestion:last').addClass('active');
                        }
                        break;

                    case 13: // Enter
                        e.preventDefault();
                        if ($active.length) {
                            var townId = $active.data('town-id');
                            var townName = $active.text();
                            $input.val(townName);
                            $('.dexpress-town-filter').addClass('has-value');
                            $suggestions.hide();
                            self.filterDispensers(townId);
                        }
                        break;

                    case 27: // Escape
                        e.preventDefault();
                        $suggestions.hide();
                        break;
                }
            });

            // Klik na sugestiju
            $(document).on('click', '.town-suggestion', function () {
                var townId = $(this).data('town-id');
                var townName = $(this).text();
                $input.val(townName);
                $('.dexpress-town-filter').addClass('has-value');
                $suggestions.hide();
                self.filterDispensers(townId);
            });
        },
        showLoader: function (container, text = 'Učitavanje...') {
            $(container).addClass('dexpress-container-relative');
            $(container).append(`
                <div class="dexpress-loading-overlay">
                    <div class="dexpress-loader"></div>
                    <span>${text}</span>
                </div>
            `);
        },

        // Funkcija za uklanjanje animacije učitavanja
        hideLoader: function (container) {
            $(container).removeClass('dexpress-container-relative');
            $(container).find('.dexpress-loading-overlay').remove();
        },
        renderDispensers: function (map) {
            var self = this;
            console.log("Renderovanje sa filterom:", this.currentTownFilter);
            var bounds = new google.maps.LatLngBounds();
            var markers = [];
            var infoWindow = new google.maps.InfoWindow();
            var listHtml = '';

            // Resetovanje town filtera
            var townFilter = $('#dexpress-town-filter').val();

            // Ikona za markere - ista kao u primeru
            var markerIcon = {
                url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36"><circle cx="18" cy="18" r="18" fill="#e60054"/><path d="M18,7c-3.9,0-7,3.1-7,7c0,5.2,7,13,7,13s7-7.8,7-13C25,10.1,21.9,7,18,7z" fill="white"/></svg>'),
                scaledSize: new google.maps.Size(36, 36),
                origin: new google.maps.Point(0, 0),
                anchor: new google.maps.Point(18, 36)
            };
            this.dispensers.forEach(function (dispenser) {
                // Primena filtera po gradu - ISPRAVI OVO
                if (self.currentTownFilter && dispenser.town_id != self.currentTownFilter) {
                    return;
                }

                // Izvlačenje koordinata iz JSON stringa ili objekta
                var coordinates = dispenser.coordinates;
                var lat = null;
                var lng = null;

                if (typeof coordinates === 'string') {
                    try {
                        var coordObj = JSON.parse(coordinates);
                        lat = parseFloat(coordObj.latitude || coordObj.lat);
                        lng = parseFloat(coordObj.longitude || coordObj.lng);
                    } catch (e) {
                        console.log('Greška pri parsiranju koordinata:', e);
                    }
                } else if (typeof coordinates === 'object') {
                    lat = parseFloat(coordinates.latitude || coordinates.lat);
                    lng = parseFloat(coordinates.longitude || coordinates.lng);
                }

                // Kreiranje markera na mapi ako imamo koordinate
                if (lat && lng && !isNaN(lat) && !isNaN(lng)) {
                    var position = new google.maps.LatLng(lat, lng);
                    bounds.extend(position);

                    var marker = new google.maps.Marker({
                        position: position,
                        map: map,
                        title: dispenser.name,
                        icon: markerIcon,
                        dispenserId: dispenser.id,
                        animation: google.maps.Animation.DROP
                    });

                    // Klik na marker otvara info prozor
                    google.maps.event.addListener(marker, 'click', function () {
                        infoWindow.setContent(self.renderDispenserInfo(dispenser));
                        infoWindow.open(map, marker);
                    });

                    markers.push({
                        marker: marker,
                        dispenser: dispenser
                    });
                }

                // Dodavanje u listu - Unapređeni prikaz sa dugmetom
                listHtml += `
                <div class="dexpress-dispenser-item" data-id="${dispenser.id}">
                    <div class="dispenser-content">
                        <strong>${dispenser.name}</strong><br>
                        ${dispenser.address}, ${dispenser.town} ${dispenser.postal_code ? `(${dispenser.postal_code})` : ''}<br>
                        <small>Radno vreme: ${dispenser.work_hours || 'Nije definisano'}</small>
                        ${(dispenser.pay_by_cash == 1 || dispenser.pay_by_card == 1) ?
                        `<br><small>Plaćanje: ${dispenser.pay_by_cash == 1 ? 'Gotovina' : ''}${(dispenser.pay_by_cash == 1 && dispenser.pay_by_card == 1) ? ', ' : ''}${dispenser.pay_by_card == 1 ? 'Kartica' : ''}</small>`
                        : ''}
                    </div>
                    <button class="button dispenser-select-btn" data-id="${dispenser.id}">Izaberi</button>
                </div>
                `;
            });

            // Dodavanje liste paketomata
            $('#dexpress-dispensers-list').html(listHtml);

            $(document).on('click', '.dexpress-dispenser-item', function (e) {
                // Ignoriši klik ako je kliknuto na dugme
                if ($(e.target).hasClass('dispenser-select-btn') || $(e.target).closest('.dispenser-select-btn').length) {
                    return;
                }

                var id = $(this).data('id');

                // Pronađi marker i centrraj mapu na njega
                var marker = markers.find(function (m) {
                    return m.dispenser.id == id;
                });

                if (marker && marker.marker) {
                    // Centriraj mapu na marker
                    map.setCenter(marker.marker.getPosition());
                    map.setZoom(15);

                    // Otvori info prozor
                    infoWindow.setContent(self.renderDispenserInfo(marker.dispenser));
                    infoWindow.open(map, marker.marker);

                    // Animiraj marker da bi bio uočljiviji
                    marker.marker.setAnimation(google.maps.Animation.BOUNCE);
                    setTimeout(function () {
                        marker.marker.setAnimation(null);
                    }, 1500);
                }
            });

            // Klik na dugme "Izaberi" u listi
            $(document).on('click', '.dispenser-select-btn', function (e) {
                e.stopPropagation(); // Sprečava da se okine event na roditelju
                var id = $(this).data('id');
                self.selectDispenser(id, markers, map);
            });

            // Klik na dugme "Izaberi ovaj paketomat" u info prozoru
            $(document).on('click', '.dexpress-select-this-dispenser', function () {
                var id = $(this).data('id');
                self.selectDispenser(id, markers, map);
                infoWindow.close();
            });

            // Podešavanje granica mape
            if (markers.length > 0) {
                map.fitBounds(bounds);

                // Ograniči maksimalni zoom da ne bude previše blizu
                var listener = google.maps.event.addListener(map, 'idle', function () {
                    if (map.getZoom() > 15) {
                        map.setZoom(15);
                    }
                    google.maps.event.removeListener(listener);
                });
            } else {
                // Ako nema markera, centriraj na Srbiju
                map.setCenter(new google.maps.LatLng(44.0165, 21.0059));
                map.setZoom(7);
            }
        },

        // Filtriranje paketomata po gradu
        filterDispensers: function (townId) {
            console.log("Filtriranje po gradu ID:", townId);
            // Sačuvaj filter vrednost u promenljivu za kasniju upotrebu
            this.currentTownFilter = townId;
            // Ponovo inicijalizuj mapu sa filterom
            this.initMap();
        },

        // Selektovanje paketomata
        selectDispenser: function (dispenserId, markers, map) {
            var dispenser = this.dispensers.find(function (d) {
                return d.id == dispenserId;
            });

            if (!dispenser) return;

            // Sačuvaj izabrani paketomat
            $('#dexpress_chosen_dispenser').val(dispenserId);

            // Kreiranje podataka za sesiju
            var chosenDispenser = {
                id: dispenser.id,
                name: dispenser.name,
                address: dispenser.address,
                town: dispenser.town,
                town_id: dispenser.town_id,
                postal_code: dispenser.postal_code
            };

            // AJAX zahtev za čuvanje u sesiji
            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dexpress_save_chosen_dispenser',
                    dispenser: chosenDispenser,
                    nonce: dexpressCheckout.nonce
                },
                success: function (response) {
                    if (response.success) {
                        // Prvo zatvorimo modal
                        DExpressDispenserModal.closeModal();

                        // Zatim osvežimo checkout nakon kratke pauze
                        setTimeout(function () {
                            $('body').trigger('update_checkout');
                        }, 100);
                    }
                }
            });
        },

        // Podešavanje handler-a za promenu shipping metoda
        setupShippingMethodHandler: function () {
            // Ukloni postojeće handlere
            $('input.shipping_method').off('change.dexpress');

            // Dodaj novi handler sa namespace-om
            $('input.shipping_method').on('change.dexpress', function () {
                var methodId = $(this).val();

                // Ako je izabran paketomat
                if (methodId.indexOf('dexpress_dispenser') !== -1) {
                    $('.dexpress-dispenser-selection').show();
                    $('.dexpress-dispenser-wrapper').show(); // Prikaži informacije
                } else {
                    $('.dexpress-dispenser-selection').hide();
                    $('.dexpress-dispenser-wrapper').hide(); // Sakrij informacije
                }
            });

            // Inicijalno podešavanje - samo jednom
            $('input.shipping_method:checked').trigger('change.dexpress');
        }
    };

    $(document).ready(function () {
        // Inicijalizacija samo ako je checkout stranica
        if (is_checkout()) {
            DExpressDispenserModal.init();
        }
    });

    function is_checkout() {
        return $('form.woocommerce-checkout').length > 0;
    }

})(jQuery);