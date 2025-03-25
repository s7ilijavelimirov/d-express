(function ($) {
    'use strict';

    var DExpressDispenserModal = {
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

            $.ajax({
                url: dexpressCheckout.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'dexpress_get_dispensers',
                    nonce: dexpressCheckout.nonce
                },
                beforeSend: function () {
                    $('#dexpress-dispensers-list').html('<p>Učitavanje paketomata...</p>');
                },
                success: function (response) {
                    if (response.success && response.data.dispensers) {
                        self.dispensers = response.data.dispensers;
                        self.renderDispensers(map);
                    } else {
                        $('#dexpress-dispensers-list').html('<p>Nema dostupnih paketomata</p>');
                    }
                },
                error: function () {
                    $('#dexpress-dispensers-list').html('<p>Greška pri učitavanju paketomata</p>');
                }
            });
        },

        // Nova funkcija za renderovanje informacija o paketomatu
        renderDispenserInfo: function(dispenser) {
            // Pomoćna funkcija za proveru da li postoji vrednost
            function ifExists(value, prefix = '', suffix = '') {
                return value ? prefix + value + suffix : '';
            }
            
            // Formatiranje radnog vremena
            function formatWorkHours(workHours, workDays) {
                if (!workHours) return 'Nije definisano';
                
                let formatted = workHours;
                if (workDays) {
                    formatted += ' (' + workDays + ')';
                }
                return formatted;
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
                    <p>${dispenser.address}, ${dispenser.town} ${ifExists(dispenser.postal_code, '(', ')')}</p>
                    <p><strong>Radno vreme:</strong> ${formatWorkHours(dispenser.work_hours, dispenser.work_days)}</p>
                    ${paymentInfo}
                    <button class="button dexpress-select-this-dispenser" data-id="${dispenser.id}">
                        Izaberi ovaj paketomat
                    </button>
                </div>
            `;
        },

        renderDispensers: function (map) {
            var self = this;
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
                // Primena filtera po gradu
                if (townFilter && townFilter != dispenser.town_id) {
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

                // Dodavanje u listu - Unapređeni prikaz
                listHtml += `
                    <div class="dexpress-dispenser-item" data-id="${dispenser.id}">
                        <strong>${dispenser.name}</strong><br>
                        ${dispenser.address}, ${dispenser.town} ${dispenser.postal_code ? `(${dispenser.postal_code})` : ''}<br>
                        <small>Radno vreme: ${dispenser.work_hours || 'Nije definisano'}</small>
                        ${(dispenser.pay_by_cash == 1 || dispenser.pay_by_card == 1) ? 
                            `<br><small>Plaćanje: ${dispenser.pay_by_cash == 1 ? 'Gotovina' : ''}${(dispenser.pay_by_cash == 1 && dispenser.pay_by_card == 1) ? ', ' : ''}${dispenser.pay_by_card == 1 ? 'Kartica' : ''}</small>` 
                            : ''}
                    </div>
                `;
            });

            // Dodavanje liste paketomata
            $('#dexpress-dispensers-list').html(listHtml);

            // Klik na paketomat u listi
            $('.dexpress-dispenser-item').on('click', function () {
                var id = $(this).data('id');
                
                // Pronađi marker i centrraj mapu na njega
                var marker = markers.find(function(m) { 
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
                    setTimeout(function() {
                        marker.marker.setAnimation(null);
                    }, 1500);
                }
                
                self.selectDispenser(id, markers, map);
            });

            // Klik na dugme u info prozoru
            $(document).on('click', '.dexpress-select-this-dispenser', function () {
                var id = $(this).data('id');
                self.selectDispenser(id, markers, map);
                infoWindow.close();
            });

            // Podešavanje granica mape
            if (markers.length > 0) {
                map.fitBounds(bounds);
                
                // Ograniči maksimalni zoom da ne bude previše blizu
                var listener = google.maps.event.addListener(map, 'idle', function() { 
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
            // Ovo će ponovo renderovati paketomata sa filterom
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

                // Ako je izabran paketomat, prikaži izbor paketomata
                if (methodId.indexOf('dexpress_dispenser') !== -1) {
                    $('.dexpress-dispenser-selection').show();
                } else {
                    $('.dexpress-dispenser-selection').hide();
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