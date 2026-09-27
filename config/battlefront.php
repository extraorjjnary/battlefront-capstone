<?php

return [
    'chatbot_timeout_seconds' => env('CHATBOT_TIMEOUT_SECONDS', 20),

    'operational_branch_city' => 'Sagay City',

    'operating_hours' => '8:00 AM–6:00 PM',

    'branch_emails' => [
        'Sagay City' => 'battlefrontcomputertrading@gmail.com',
        'Guihulngan City' => 'battlefrontcomputertrading@gmail.com',
        'Bacolod City' => 'battlefrontbacolod@gmail.com',
    ],

    'payment_accounts' => [
        'gcash' => [
            'account_name' => env('BATTLEFRONT_GCASH_ACCOUNT_NAME', 'Battlefront Demo GCash Account'),
            'account_number' => env('BATTLEFRONT_GCASH_ACCOUNT_NUMBER', '09XX XXX XXXX'),
            'is_demo' => env('BATTLEFRONT_GCASH_IS_DEMO', true),
        ],
        'maya' => [
            'account_name' => env('BATTLEFRONT_MAYA_ACCOUNT_NAME', 'Battlefront Demo Maya Account'),
            'account_number' => env('BATTLEFRONT_MAYA_ACCOUNT_NUMBER', '09XX XXX XXXX'),
            'is_demo' => env('BATTLEFRONT_MAYA_IS_DEMO', true),
        ],
    ],
];
