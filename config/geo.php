<?php

return [
    'neighbourhood_verification' => [
        // Rayon maximal (en km) entre la position du téléphone et le centre du quartier choisi.
        'max_distance_km' => (float) env('NEIGHBOURHOOD_VERIFICATION_MAX_DISTANCE_KM', 3.0),
    ],

    'geocoding' => [
        'nominatim_url' => env('GEO_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search'),
        'user_agent' => env('GEO_NOMINATIM_USER_AGENT', 'BangoBackend/1.0 (+https://bango.app)'),
        'max_city_distance_km' => (float) env('GEO_MAX_CITY_DISTANCE_KM', 40.0),
    ],
];
