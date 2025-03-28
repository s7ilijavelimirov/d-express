jQuery(document).ready(function ($) {
    // Pratimo da li je forma promenjena
    var formChanged = false;

    // Pratimo promene u formi
    $('.dexpress-settings-form :input').on('change input', function () {
        formChanged = true;
    });

    // Pratimo klik na tab linkove
    $('.nav-tab').on('click', function (e) {
        if (formChanged) {
            // Sprečavamo prelazak na tab
            e.preventDefault();

            // Prikazujemo confirm dijalog sa lokalizovanim tekstom
            if (confirm(dexpressL10n.save_alert)) {
                // Ako korisnik potvrdi, nastavljamo sa promenom taba
                formChanged = false;
                window.location.href = $(this).attr('href');
            }
        }
    });

    // Resetujemo flag kada se forma šalje
    $('.dexpress-settings-form').on('submit', function () {
        formChanged = false;
    });

    $('.dexpress-tooltip').each(function () {
        var $icon = $(this);
        var $label = $icon.closest('tr').find('th label');
        var labelText = $label.length ? $label.text().trim() : 'Informacija';

        $icon.pointer({
            content: '<h3>' + labelText + '</h3><p>' + $icon.data('wp-tooltip') + '</p>',
            position: {
                edge: 'left',
                align: 'center'
            }
        });

        $icon.on('mouseenter', function () {
            $(this).pointer('open');
        }).on('mouseleave', function () {
            $(this).pointer('close');
        });
    });
     $('.wp-hide-pw').on('click', function() {
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
});