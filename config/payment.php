<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active Payment Gateway
    |--------------------------------------------------------------------------
    |
    | Defines the active gateway driver to use for mobile money payments.
    | Default is 'monetbil'. Changing this variable switches the active driver
    | without altering financial history or previous transactions.
    |
    */

    'default_gateway' => env('PAYMENT_ACTIVE_GATEWAY', 'monetbil'),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | Base operational currency for transactions and SUB catalog matching.
    |
    */

    'currency' => env('PAYMENT_DEFAULT_CURRENCY', 'XAF'),

    /*
    |--------------------------------------------------------------------------
    | Payment Timeouts
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for USSD prompt validation on the customer phone.
    |
    */

    'timeout_seconds' => (int) env('PAYMENT_TIMEOUT_SECONDS', 120),

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration and fee rules per provider. Monetbil is the only active
    | aggregator in Phase 2. All fees are configurable and billed to the customer.
    |
    */

    'gateways' => [
        'monetbil' => [
            'enabled' => (bool) env('PAYMENT_MONETBIL_ENABLED', true),
            'service_key' => env('MONETBIL_SERVICE_KEY'),
            'service_secret' => env('MONETBIL_SERVICE_SECRET'),
            'api_base_url' => env('MONETBIL_API_URL', 'https://api.monetbil.com'),

            'notify_url' => env('MONETBIL_NOTIFY_URL'),
            'http_timeout' => (int) env('PAYMENT_HTTP_TIMEOUT', 15),
            'http_connect_timeout' => (int) env('PAYMENT_HTTP_CONNECT_TIMEOUT', 5),

            // Tarification des frais facturés en sus au client
            'fee_type' => env('PAYMENT_MONETBIL_FEE_TYPE', 'percentage_with_min'), // 'percentage', 'fixed', 'percentage_with_min'
            'fee_percentage' => env('PAYMENT_MONETBIL_FEE_PERCENTAGE') !== null ? (float) env('PAYMENT_MONETBIL_FEE_PERCENTAGE') : 2.0,
            'fee_fixed' => (float) env('PAYMENT_MONETBIL_FEE_FIXED', 0.0),
            'min_fee' => (float) env('PAYMENT_MONETBIL_MIN_FEE', 100.0),
            'max_fee' => env('PAYMENT_MONETBIL_MAX_FEE') !== null ? (float) env('PAYMENT_MONETBIL_MAX_FEE') : null,
        ],

        'flutterwave' => [
            'enabled' => (bool) env('PAYMENT_FLUTTERWAVE_ENABLED', false),
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
            'fee_percentage' => (float) env('PAYMENT_FLUTTERWAVE_FEE_PERCENTAGE', 1.8),
            'min_fee' => 0.0,
        ],

        'apple_iap' => [
            'enabled' => (bool) env('PAYMENT_APPLE_IAP_ENABLED', false),
            'bundle_id' => env('APPLE_BUNDLE_ID', 'cm.bango.app'),
            'shared_secret' => env('APPLE_IAP_SHARED_SECRET'),
            'fee_percentage' => 0.0, // Commission store gérée par Apple
        ],

        'google_play' => [
            'enabled' => (bool) env('PAYMENT_GOOGLE_PLAY_ENABLED', false),
            'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME', 'cm.bango.app'),
            'service_account_credentials' => env('GOOGLE_PLAY_CREDENTIALS_PATH'),
            'fee_percentage' => 0.0, // Commission store gérée par Google
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax Configuration
    |--------------------------------------------------------------------------
    |
    | Optional tax calculation applied to transactions (e.g. VAT / TVA).
    |
    */

    'tax' => [
        'enabled' => (bool) env('PAYMENT_TAX_ENABLED', false),
        'rate_percentage' => (float) env('PAYMENT_TAX_RATE', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Reconciliation Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for automated polling and server-to-server reconciliation
    | of pending, processing and indeterminate (unknown) transactions.
    |
    */

    'reconciliation' => [
        'enabled' => (bool) env('PAYMENT_RECONCILIATION_ENABLED', true),
        'min_age_minutes' => (int) env('PAYMENT_RECONCILIATION_MIN_AGE_MINUTES', 5),
        'max_age_days' => (int) env('PAYMENT_RECONCILIATION_MAX_AGE_DAYS', 3),
        'max_retries' => (int) env('PAYMENT_RECONCILIATION_MAX_RETRIES', 5),
        'batch_size' => (int) env('PAYMENT_RECONCILIATION_BATCH_SIZE', 50),
    ],

];
