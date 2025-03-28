// Dodatak za dexpress-frontend.js
function initTrackingMap(trackingData) {
    // Ako nemamo koordinate, nema potrebe za mapom
    if (!trackingData.coords || trackingData.coords.length === 0) return;

    var mapContainer = $('<div id="tracking-map" style="height:300px; margin:15px 0;"></div>');
    $('.dexpress-tracking-info').append(mapContainer);

    var map = new google.maps.Map(document.getElementById('tracking-map'), {
        zoom: 8,
        center: trackingData.coords[0]
    });

    var path = new google.maps.Polyline({
        path: trackingData.coords,
        geodesic: true,
        strokeColor: '#0073aa',
        strokeOpacity: 1.0,
        strokeWeight: 2
    });

    path.setMap(map);

    // Označi početnu i krajnju tačku
    new google.maps.Marker({
        position: trackingData.coords[0],
        map: map,
        title: trackingData.statuses[trackingData.statuses.length - 1].status,
        icon: 'https://maps.google.com/mapfiles/ms/icons/green-dot.png'
    });

    if (trackingData.coords.length > 1) {
        new google.maps.Marker({
            position: trackingData.coords[trackingData.coords.length - 1],
            map: map,
            title: trackingData.statuses[0].status,
            icon: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'
        });
    }
}