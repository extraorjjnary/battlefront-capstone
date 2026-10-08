<?php

return [
    'chatbot_timeout_seconds' => env('CHATBOT_TIMEOUT_SECONDS', 20),

    'recommendations' => [
        'slow_query_threshold_ms' => (int) env('BATTLEFRONT_RECOMMENDATION_SLOW_QUERY_THRESHOLD_MS', 750),
    ],

    'operational_branch_city' => 'Sagay City',

    'operating_hours' => '8:00 AM–6:00 PM',

    /**
     * Battlefront-configured capstone/demo assumptions, not official LBC rates.
     * Sagay is the fixed origin for this table; no runtime distance lookup is used.
     */
    'delivery' => [
        'carrier' => 'lbc',
        'origin_city' => 'Sagay City',
        'is_demo' => true,
        'assumption_label' => 'Battlefront-configured demo delivery assumptions; not official LBC rates.',
        'destinations' => [
            'Sagay City' => ['base_fee' => '80.00', 'transit_min_days' => 0, 'transit_max_days' => 1],
            'Escalante City' => ['base_fee' => '100.00', 'transit_min_days' => 1, 'transit_max_days' => 1],
            'Cadiz City' => ['base_fee' => '120.00', 'transit_min_days' => 1, 'transit_max_days' => 1],
            'Toboso' => ['base_fee' => '140.00', 'transit_min_days' => 1, 'transit_max_days' => 1],
            'Manapla' => ['base_fee' => '160.00', 'transit_min_days' => 1, 'transit_max_days' => 1],
            'Calatrava' => ['base_fee' => '180.00', 'transit_min_days' => 1, 'transit_max_days' => 1],
            'Victorias City' => ['base_fee' => '180.00', 'transit_min_days' => 1, 'transit_max_days' => 1],
            'E.B. Magalona' => ['base_fee' => '200.00', 'transit_min_days' => 1, 'transit_max_days' => 2],
            'San Carlos City' => ['base_fee' => '220.00', 'transit_min_days' => 1, 'transit_max_days' => 2],
            'Silay City' => ['base_fee' => '220.00', 'transit_min_days' => 1, 'transit_max_days' => 2],
            'Talisay City' => ['base_fee' => '240.00', 'transit_min_days' => 1, 'transit_max_days' => 2],
            'Bacolod City' => ['base_fee' => '250.00', 'transit_min_days' => 1, 'transit_max_days' => 2],
        ],
        'profiles' => [
            'standard' => ['surcharge' => '0.00', 'preparation_days' => 1],
            'fragile' => ['surcharge' => '50.00', 'preparation_days' => 2],
            'bulky' => ['surcharge' => '100.00', 'preparation_days' => 3],
        ],
    ],

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
