jQuery(document).ready(function ($) {
    // Dodaj u assets/js/dexpress-admin.js
    $('.dexpress-tooltip').each(function () {
        var $icon = $(this);
        var $label = $icon.closest('tr').find('th label');
        var labelText = $label.length ? $label.text().trim() : 'Informacija';

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
});