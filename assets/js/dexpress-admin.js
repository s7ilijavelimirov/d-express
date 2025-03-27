jQuery(document).ready(function ($) {
    console.log('wp-pointer loaded:', !!$.fn.pointer);

    $('.dexpress-tooltip').each(function () {
        console.log('Tooltip element:', this);
        console.log('Tooltip data:', $(this).data('wp-tooltip'));
    });
    $('.dexpress-tooltip').each(function () {
        var $icon = $(this);
        var $label = $icon.closest('tr').find('th label');
        var labelText = $label.length ? $label.text().trim() : 'Informacija';

        $icon.pointer({
            content: '<h3>' + labelText + '</h3><p>' + $icon.data('wp-tooltip') + '</p>',
            // position: {
            //     edge: 'left',
            //     align: 'center'
            // }
        });

        $icon.on('mouseenter', function () {
            $(this).pointer('open');
        }).on('mouseleave', function () {
            $(this).pointer('close');
        });
    });
});