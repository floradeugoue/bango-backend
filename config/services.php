<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'zomloa' => [
        'api_key' => env('ZOMLOA_API_KEY'),
        'base_url' => env('ZOMLOA_BASE_URL', 'https://api.sms.zomloa.app'),
        'sender_id' => env('ZOMLOA_SENDER_ID', 'FlowSMS'),
    ],

    'cloudflare_stream' => [
        'account_id' => env('CLOUDFLARE_STREAM_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_STREAM_API_TOKEN'),
        'customer_subdomain' => env('CLOUDFLARE_STREAM_CUSTOMER_SUBDOMAIN'),
        'webhook_secret' => env('CLOUDFLARE_STREAM_WEBHOOK_SECRET'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_ids' => array_values(array_filter([
            env('GOOGLE_CLIENT_ID'),
            env('GOOGLE_CLIENT_ID_WEB'),
            env('GOOGLE_CLIENT_ID_ANDROID'),
            env('GOOGLE_CLIENT_ID_IOS'),
        ])),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID', 'com.bango.app'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
        'client_ids' => array_values(array_filter([
            env('APPLE_CLIENT_ID', 'com.bango.app'),
            env('APPLE_BUNDLE_ID'),
            env('APPLE_SERVICE_ID'),
        ])),
    ],

    'contact_matching' => [
        'salt' => env('CONTACT_MATCHING_SALT', 'bango_contact_salt_secret_2026'),
    ],

];
