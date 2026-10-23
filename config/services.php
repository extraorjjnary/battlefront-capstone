<?php

return [
    'paymongo' => [
        'enabled' => env('PAYMONGO_ENABLED', false),
        'mode' => env('PAYMONGO_MODE', 'test'),
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'hosted_methods' => array_values(array_filter(array_map('trim', explode(',', env('PAYMONGO_HOSTED_METHODS', 'card,gcash,paymaya,qrph'))))),
        'direct_banking_enabled' => env('PAYMONGO_DIRECT_BANKING_ENABLED', false),
        'direct_banking_banks' => ['bpi' => 'BPI', 'ubp' => 'UnionBank', 'landbank' => 'LandBank'],
    ],
    'expo' => [
        'enabled' => env('EXPO_PUSH_ENABLED', false),
        'access_token' => env('EXPO_PUSH_ACCESS_TOKEN'),
        'ca_bundle' => env('EXPO_PUSH_CA_BUNDLE'),
    ],

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

];
