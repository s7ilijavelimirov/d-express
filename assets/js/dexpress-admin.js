/**
 * D Express Admin JavaScript
 * Sva funkcionalnost admin interfejsa plugin-a
 */
jQuery(document).ready(function ($) {
    // Inicijalizacija tooltip-ova
    $('.dexpress-tooltip').each(function () {
        var $icon = $(this);
        var $label = $icon.closest('tr').find('th label');
        var labelText = $label.length ? $label.text().trim() : 'Informacija';
        $('.wp-pointer-arrow-inner').css({
            'pointer-events': 'none',
            'left': '-5px !important', // Pomera strelicu malo ulevo
            'margin-left': '-10px !important' // Smanjuje širinu strelice
        });
        // Poboljšaj izgled ikonice
        $icon.css({
            'color': '#0073aa',
            'cursor': 'pointer',
            'font-size': '18px',
            'vertical-align': 'middle',
            'margin-left': '5px',
            'transition': 'transform 0.2s ease-in-out'
        }).hover(
            function () { $(this).css('transform', 'scale(1.2)'); },
            function () { $(this).css('transform', 'scale(1)'); }
        );

        $icon.pointer({
            content: '<h3>' + labelText + '</h3><p>' + $icon.data('wp-tooltip') + '</p>',
            position: {
                edge: 'left',
                align: 'center'
            },
            pointerClass: 'wp-pointer dexpress-custom-pointer'
        });

        $icon.on('mouseenter', function () {
            $(this).pointer('open');
        }).on('mouseleave', function () {
            $(this).pointer('close');
        });
    });

    // Funkcionalnost za prikazivanje/skrivanje lozinke
    $('.wp-hide-pw').on('click', function () {
        var $this = $(this);
        var $passwordField = $this.prev('input');

        if ($passwordField.attr('type') === 'password') {
            $passwordField.attr('type', 'text');
            $this.find('.dashicons')
                .removeClass('dashicons-visibility')
                .addClass('dashicons-hidden');
            $this.attr('aria-label', 'Sakrij lozinku');
        } else {
            $passwordField.attr('type', 'password');
            $this.find('.dashicons')
                .removeClass('dashicons-hidden')
                .addClass('dashicons-visibility');
            $this.attr('aria-label', 'Prikaži lozinku');
        }
    });

    // Praćenje promena u formi sa pamćenjem polja koja su promenjena
    var formChanged = false;
    var changedFields = [];

    $('.dexpress-settings-form :input').on('change', function () {
        formChanged = true;

        // Dodajemo naziv polja (ili label ako postoji) u listu promenjenih
        var fieldId = $(this).attr('id') || $(this).attr('name');
        var fieldLabel = $('label[for="' + fieldId + '"]').text().trim() || fieldId;

        // Proveravamo da li već imamo ovo polje u listi
        if (changedFields.indexOf(fieldLabel) === -1) {
            changedFields.push(fieldLabel);
        }
    });

    // Funkcija za formatiranje poruke o promenama
    function formatChangesMessage() {
        if (!formChanged || changedFields.length === 0) {
            return dexpressL10n.save_alert;
        }

        // Ograničavamo broj polja koja prikazujemo
        var displayFields = changedFields.slice(0, 3);
        var message = 'Imate nesačuvane promene u sledećim poljima: ' + displayFields.join(', ');

        // Ako ima više od 3 polja, dodajemo informaciju o tome
        if (changedFields.length > 3) {
            message += ' i još ' + (changedFields.length - 3) + ' drugih...';
        }

        message += '\n\nDa li ste sigurni da želite da nastavite bez čuvanja promena?';
        return message;
    }

    // Resetovanje praćenja promena nakon čuvanja
    $('.dexpress-settings-form').on('submit', function () {
        formChanged = false;
        changedFields = [];
    });

    // Funkcija za prebacivanje tabova (dostupna globalno)
    window.switchTab = function (e, tabName) {
        e.preventDefault();

        // Upozorenje ako ima nesačuvanih promena, ali samo jednom po sesiji za isti tab
        if (formChanged) {
            // Provera da li korisnik već prelazi na isti tab (sprečava dupla upozorenja)
            var currentTab = $('input[name="active_tab"]').val();
            if (currentTab === tabName) {
                return true; // Već je na istom tabu, samo dozvolimo klik
            }

            if (!confirm(formatChangesMessage())) {
                return false;
            }
        }

        // Sakrijemo sve tabove
        $('.dexpress-tab').removeClass('active');

        // Prikažemo izabrani tab
        $('#tab-' + tabName).addClass('active');

        // Ažuriramo aktivne klase linkova
        $('.dexpress-tab-link').removeClass('active');

        // Pronađemo odgovarajući link i aktiviramo ga
        $('.dexpress-tab-link[data-tab="' + tabName + '"]').addClass('active');

        // Postavimo vrednost hidden polja za aktivni tab
        $('input[name="active_tab"]').val(tabName);

        // Ažuriramo URL u address baru bez reloada
        window.history.pushState({}, '', '?page=dexpress-settings&tab=' + tabName);

        return false;
    };

    // Uprošćeno upozorenje pri napuštanju stranice
    window.onbeforeunload = function () {
        // Proveri da li je eksplicitno onemogućeno (npr. od strane extendCodeRange funkcije)
        if (window.dexpressDisableUnloadWarning === true) {
            return null;
        }

        if (formChanged) {
            return 'Imate nesačuvane promene. Da li ste sigurni da želite da napustite stranicu?';
        }
    };

    // Upozorenje pri kliku na dugme za akcije sa informacijom o promenama
    $('.dexpress-settings-actions .button-secondary').on('click', function (e) {
        if (formChanged) {
            if (!confirm(formatChangesMessage())) {
                e.preventDefault();
                return false;
            }
        }
    });

    // Funkcija za kopiranje u clipboard
    window.copyToClipboard = function (element) {
        var $temp = $("<input>");
        $("body").append($temp);
        $temp.val($(element).val()).select();
        document.execCommand("copy");
        $temp.remove();
        alert("URL je kopiran u clipboard!");
    };

    // Funkcija za generisanje webhook secret-a
    window.generateWebhookSecret = function () {
        // Generisanje nasumičnog stringa od 32 karaktera
        var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        var secret = '';
        for (var i = 0; i < 32; i++) {
            secret += chars.charAt(Math.floor(Math.random() * chars.length));
        }

        $('#dexpress_webhook_secret').val(secret);
        formChanged = true;

        // Dodajemo i ovo polje u listu promenjenih
        if (changedFields.indexOf('Webhook tajni ključ') === -1) {
            changedFields.push('Webhook tajni ključ');
        }
    };

    // Inicijalizacija za stranicu pošiljki (ako postoji)
    if ($('.dexpress-shipments-table').length > 0) {
        // Funkcionalnost za stranice pošiljki
        initShipmentsPage();
    }

    // Funkcija za inicijalizaciju stranice pošiljki
    function initShipmentsPage() {
        // Delete potvrda
        $('.dexpress-delete-shipment').on('click', function (e) {
            if (!confirm(dexpressAdmin.i18n.confirmDelete)) {
                e.preventDefault();
                return false;
            }
        });

        // Filter datuma
        var $dateInputs = $('.dexpress-date-filter');
        if ($dateInputs.length > 0) {
            $dateInputs.datepicker({
                dateFormat: 'yy-mm-dd',
                changeMonth: true,
                changeYear: true
            });
        }
    }

    // AJAX pretraga na stranici pošiljki
    $('#dexpress-search-shipments').on('submit', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $results = $('#dexpress-shipments-results');

        $results.html('<div class="spinner is-active"></div>');

        $.ajax({
            url: dexpressAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dexpress_search_shipments',
                nonce: dexpressAdmin.nonce,
                search: $form.find('input[name="s"]').val(),
                status: $form.find('select[name="status"]').val(),
                date_from: $form.find('input[name="date_from"]').val(),
                date_to: $form.find('input[name="date_to"]').val()
            },
            success: function (response) {
                $results.html(response);
            },
            error: function () {
                $results.html('<div class="notice notice-error"><p>' + dexpressAdmin.i18n.error + '</p></div>');
            }
        });
    });
});