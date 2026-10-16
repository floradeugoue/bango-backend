<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mobile App Versions & Minimum Version Policy (SP / SY-21)
    |--------------------------------------------------------------------------
    |
    | Configuration for controlling client minimum supported versions, latest
    | versions, and platform-specific store redirect URLs.
    |
    */
    'versions' => [
        'android' => [
            'min_version' => env('APP_MIN_VERSION_ANDROID', '1.0.0'),
            'latest_version' => env('APP_LATEST_VERSION_ANDROID', '1.2.0'),
            'store_url' => env('APP_STORE_URL_ANDROID', 'https://play.google.com/store/apps/details?id=com.bango.app'),
        ],
        'ios' => [
            'min_version' => env('APP_MIN_VERSION_IOS', '1.0.0'),
            'latest_version' => env('APP_LATEST_VERSION_IOS', '1.2.0'),
            'store_url' => env('APP_STORE_URL_IOS', 'https://apps.apple.com/app/bango/id000000000'),
        ],
    ],
];
