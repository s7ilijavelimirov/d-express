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
            $(document.body).on('updated_checkout', function () {
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
            if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
                console.log('Google Maps API nije učitan');
                $('#dexpress-dispensers-map').html('<p>Učitavanje Google Maps nije uspelo</p>');
                return;
            }

            var map = new google.maps.Map(document.getElementById('dexpress-dispensers-map'), {
                center: { lat: 44.8125, lng: 20.4612 }, // Beograd
                zoom: 10
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

        renderDispensers: function (map) {
            var self = this;
            var bounds = new google.maps.LatLngBounds();
            var markers = [];
            var infoWindow = new google.maps.InfoWindow();
            var listHtml = '';

            // Resetovanje town filtera
            var townFilter = $('#dexpress-town-filter').val();

            // Sortiranje paketomata po gradu pa po nazivu
            this.dispensers.sort(function (a, b) {
                if (a.town === b.town) {
                    return a.name.localeCompare(b.name);
                }
                return a.town.localeCompare(b.town);
            });

            this.dispensers.forEach(function (dispenser) {
                // Primena filtera po gradu
                if (townFilter && townFilter != dispenser.town_id) {
                    return;
                }

                // Kreiranje markera na mapi ako imamo koordinate
                if (dispenser.lat && dispenser.lng) {
                    var position = new google.maps.LatLng(dispenser.lat, dispenser.lng);
                    bounds.extend(position);

                    var marker = new google.maps.Marker({
                        position: position,
                        map: map,
                        title: dispenser.name,
                        dispenserId: dispenser.id
                    });

                    // Klik na marker otvara info prozor
                    google.maps.event.addListener(marker, 'click', function () {
                        infoWindow.setContent(
                            '<div class="dexpress-dispenser-info">' +
                            '<h4>' + dispenser.name + '</h4>' +
                            '<p>' + dispenser.address + ', ' + dispenser.town + '</p>' +
                            '<p><strong>Radno vreme:</strong> ' + dispenser.work_hours + '</p>' +
                            '<button class="button dexpress-select-this-dispenser" data-id="' + dispenser.id + '">' +
                            'Izaberi ovaj paketomat</button>' +
                            '</div>'
                        );
                        infoWindow.open(map, marker);
                    });

                    markers.push({
                        marker: marker,
                        dispenser: dispenser
                    });
                }

                // Dodavanje u listu
                listHtml += '<div class="dexpress-dispenser-item" data-id="' + dispenser.id + '">' +
                    '<strong>' + dispenser.name + '</strong><br>' +
                    dispenser.address + ', ' + dispenser.town + '<br>' +
                    '<small>Radno vreme: ' + dispenser.work_hours + '</small>' +
                    '</div>';
            });

            // Dodavanje liste paketomata
            $('#dexpress-dispensers-list').html(listHtml);

            // Klik na paketomat u listi
            $('.dexpress-dispenser-item').on('click', function () {
                var id = $(this).data('id');
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
                        // Osvežavanje checkout-a
                        $('body').trigger('update_checkout');
                        DExpressDispenserModal.closeModal();
                    }
                }
            });
        },

        // Podešavanje handler-a za promenu shipping metoda
        setupShippingMethodHandler: function () {
            $('input.shipping_method').on('change', function () {
                var methodId = $(this).val();

                // Ako je izabran paketomat, prikaži izbor paketomata
                if (methodId.indexOf('dexpress_dispenser') !== -1) {
                    $('.dexpress-dispenser-selection').show();
                } else {
                    $('.dexpress-dispenser-selection').hide();
                }
            });

            // Inicijalno podešavanje
            $('input.shipping_method:checked').trigger('change');
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