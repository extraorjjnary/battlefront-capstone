<?php

return [
    'chatbot_timeout_seconds' => env('CHATBOT_TIMEOUT_SECONDS', 20),

    'recommendations' => [
        'slow_query_threshold_ms' => (int) env('BATTLEFRONT_RECOMMENDATION_SLOW_QUERY_THRESHOLD_MS', 750),
    ],

    'operational_branch_city' => 'Sagay City',

    'operating_hours' => '8:00 AM–6:00 PM',

    /**
     * Battlefront-configured demonstration estimates, not official LBC quotations.
     * Sagay is the fixed origin for this table; no runtime distance lookup is used.
     */
    'delivery' => [
        'carrier' => 'lbc',
        'origin_city' => 'Sagay City',
        'is_demo' => true,
        'assumption_label' => 'Battlefront-configured demonstration estimates, not official LBC quotations.',
        'rules_version' => 'nationwide-demo-v3',
        'payment_assumptions' => [
            'gcash' => 'Customer pays product subtotal and estimated delivery fee to Battlefront; Battlefront arranges shipment and pays the carrier at dispatch.',
            'maya' => 'Customer pays product subtotal and estimated delivery fee to Battlefront; Battlefront arranges shipment and pays the carrier at dispatch.',
            'cod' => 'Demonstration assumption: courier collects product payment and delivery fee together; carrier collection and remittance arrangements require confirmation. Administrator confirms successful collection.',
        ],
        'unconfigured_route' => [
            'base_fee' => '0.00',
            'payment_methods' => ['gcash', 'maya', 'cod'],
            'assumption_label' => 'Delivery pricing and third-party logistics are not configured for this destination. No delivery fee is included in this order, and no LBC quotation or booking exists.',
            'notice' => 'Delivery fee not configured. Your order includes the product subtotal only; Battlefront will coordinate fulfillment separately.',
            'payment_assumptions' => [
                'gcash' => 'Customer pays the product subtotal only. No delivery fee is charged, and delivery logistics are coordinated separately.',
                'maya' => 'Customer pays the product subtotal only. No delivery fee is charged, and delivery logistics are coordinated separately.',
                'cod' => 'Customer pays the product subtotal only upon delivery. No delivery fee is collected, and no LBC booking or carrier collection arrangement is represented.',
            ],
        ],
        // Specific rules supersede broader rules, including quotation-only exclusions.
        // Enabled rules require base_fee, transit_min_days, transit_max_days and payment_methods.
        'zones' => [
            'cities' => [
                '1804523000' => ['legacy_destination' => 'Sagay City', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804509000' => ['legacy_destination' => 'Escalante City', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804504000' => ['legacy_destination' => 'Cadiz City', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804529000' => ['legacy_destination' => 'Toboso', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804518000' => ['legacy_destination' => 'Manapla', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804505000' => ['legacy_destination' => 'Calatrava', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804531000' => ['legacy_destination' => 'Victorias City', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804508000' => ['legacy_destination' => 'E.B. Magalona', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804524000' => ['legacy_destination' => 'San Carlos City', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804526000' => ['legacy_destination' => 'Silay City', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804528000' => ['legacy_destination' => 'Talisay City', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1830200000' => ['legacy_destination' => 'Bacolod City', 'payment_methods' => ['gcash', 'maya', 'cod']],
            ],
            'provinces' => [],
            'regions' => [],
        ],
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

    'sagay_cod_notice' => 'LBC COD Arrangement: Collection of both product payment and delivery fees is subject to confirmation with the LBC branch serving Battlefront\'s Sagay City operations. Current shipping rates and collection arrangements are configured for demonstration purposes and are not official LBC quotations.',

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
