<?php

return [
    'chatbot_timeout_seconds' => env('CHATBOT_TIMEOUT_SECONDS', 20),

    'recommendations' => [
        'slow_query_threshold_ms' => (int) env('BATTLEFRONT_RECOMMENDATION_SLOW_QUERY_THRESHOLD_MS', 750),
    ],

    'operational_branch_city' => 'Sagay City',

    'timezone' => 'Asia/Manila',

    'returns' => [
        // Proposed discretionary policy; enable only after Battlefront approval.
        'window_days' => (int) env('BATTLEFRONT_RETURN_WINDOW_DAYS', 7),
        'window_approved' => (bool) env('BATTLEFRONT_RETURN_WINDOW_APPROVED', false),
        'discretionary_reasons' => ['changed_mind'],
    ],

    /** Legacy bootstrap defaults; published branch information is database-backed. */
    'operating_hours' => '8:00 AM–6:00 PM',

    /**
     * Battlefront-configured demonstration estimates, not official LBC quotations.
     * Sagay is the fixed origin for this table; no runtime distance lookup is used.
     */
    'delivery' => [
        'carrier' => 'lbc',
        'origin_city' => 'Sagay City',
        'is_demo' => true,
        'assumption_label' => 'Battlefront demonstration estimates. New Negros Occidental rates use OSM administrative-boundary centroids as straight-line proxies; they are not official LBC rates or verified production shipping charges.',
        'rules_version' => 'nationwide-demo-v4',
        'negros_occidental_distance_method' => [
            'name' => 'great_circle_haversine',
            'earth_radius_km' => 6371,
            'rounded_distance_precision_km' => 0.1,
            'source' => 'OpenStreetMap administrative-boundary relation centroids',
            'source_url' => 'https://nominatim.openstreetmap.org/lookup?osm_ids=R{relation_id}&format=jsonv2',
            'origin' => [
                'city' => 'Sagay City',
                'osm_relation_id' => 11491048,
                'latitude' => 10.8960681,
                'longitude' => 123.4154617,
            ],
            'limitations' => 'Centroids are geographic proxies, not Battlefront storefront locations, town centers, road distances, or official LBC pricing inputs.',
        ],
        'negros_occidental_distance_bands' => [
            'km_30_45' => ['minimum_exclusive_km' => 30, 'maximum_inclusive_km' => 45, 'base_fee' => '180.00', 'transit_min_days' => 1, 'transit_max_days' => 2],
            'km_45_60' => ['minimum_exclusive_km' => 45, 'maximum_inclusive_km' => 60, 'base_fee' => '220.00', 'transit_min_days' => 1, 'transit_max_days' => 2],
            'km_60_100' => ['minimum_exclusive_km' => 60, 'maximum_inclusive_km' => 100, 'base_fee' => '280.00', 'transit_min_days' => 2, 'transit_max_days' => 3],
            'km_100_125' => ['minimum_exclusive_km' => 100, 'maximum_inclusive_km' => 125, 'base_fee' => '320.00', 'transit_min_days' => 2, 'transit_max_days' => 3],
            'km_125_155' => ['minimum_exclusive_km' => 125, 'maximum_inclusive_km' => 155, 'base_fee' => '360.00', 'transit_min_days' => 3, 'transit_max_days' => 4],
            'km_155_180' => ['minimum_exclusive_km' => 155, 'maximum_inclusive_km' => 180, 'base_fee' => '400.00', 'transit_min_days' => 3, 'transit_max_days' => 4],
        ],
        'negros_occidental_distance_proxies' => [
            '1804503000' => ['osm_relation_id' => 11525661, 'latitude' => 10.1937474, 'longitude' => 122.8587743, 'distance_km' => 99.0, 'distance_band' => 'km_60_100'],
            '1804506000' => ['osm_relation_id' => 3740498, 'latitude' => 9.8306756, 'longitude' => 122.6429611, 'distance_km' => 145.5, 'distance_band' => 'km_125_155'],
            '1804507000' => ['osm_relation_id' => 3740499, 'latitude' => 9.9733632, 'longitude' => 122.6260198, 'distance_km' => 134.1, 'distance_band' => 'km_125_155'],
            '1804502000' => ['osm_relation_id' => 11366393, 'latitude' => 10.5376299, 'longitude' => 122.8352802, 'distance_km' => 74.9, 'distance_band' => 'km_60_100'],
            '1804510000' => ['osm_relation_id' => 11525214, 'latitude' => 10.0992841, 'longitude' => 122.8705390, 'distance_km' => 106.8, 'distance_band' => 'km_100_125'],
            '1804515000' => ['osm_relation_id' => 3740502, 'latitude' => 9.9888817, 'longitude' => 122.8134501, 'distance_km' => 120.5, 'distance_band' => 'km_100_125'],
            '1804516000' => ['osm_relation_id' => 11984373, 'latitude' => 10.4268649, 'longitude' => 122.9208074, 'distance_km' => 75.1, 'distance_band' => 'km_60_100'],
            '1804527000' => ['osm_relation_id' => 3740505, 'latitude' => 9.7491266, 'longitude' => 122.4040602, 'distance_km' => 168.8, 'distance_band' => 'km_155_180'],
            '1804511000' => ['osm_relation_id' => 12599665, 'latitude' => 10.2713832, 'longitude' => 122.8519909, 'distance_km' => 92.8, 'distance_band' => 'km_60_100'],
            '1804512000' => ['osm_relation_id' => 3740500, 'latitude' => 9.6021766, 'longitude' => 122.4670994, 'distance_km' => 177.4, 'distance_band' => 'km_155_180'],
            '1804513000' => ['osm_relation_id' => 3740501, 'latitude' => 10.0242067, 'longitude' => 122.7691808, 'distance_km' => 120.0, 'distance_band' => 'km_100_125'],
            '1804514000' => ['osm_relation_id' => 18527747, 'latitude' => 10.2047969, 'longitude' => 122.9892506, 'distance_km' => 89.9, 'distance_band' => 'km_60_100'],
            '1804517000' => ['osm_relation_id' => 17749026, 'latitude' => 10.3230498, 'longitude' => 123.0187418, 'distance_km' => 77.1, 'distance_band' => 'km_60_100'],
            '1804519000' => ['osm_relation_id' => 18491788, 'latitude' => 10.2708302, 'longitude' => 123.0733691, 'distance_km' => 78.9, 'distance_band' => 'km_60_100'],
            '1804520000' => ['osm_relation_id' => 11366415, 'latitude' => 10.6066483, 'longitude' => 123.0404676, 'distance_km' => 52.1, 'distance_band' => 'km_45_60'],
            '1804521000' => ['osm_relation_id' => 12716470, 'latitude' => 10.3751168, 'longitude' => 122.8668210, 'distance_km' => 83.4, 'distance_band' => 'km_60_100'],
            '1804522000' => ['osm_relation_id' => 12245305, 'latitude' => 10.5192005, 'longitude' => 122.8034563, 'distance_km' => 78.9, 'distance_band' => 'km_60_100'],
            '1804532000' => ['osm_relation_id' => 12717917, 'latitude' => 10.5770770, 'longitude' => 123.2203670, 'distance_km' => 41.4, 'distance_band' => 'km_30_45'],
            '1804525000' => ['osm_relation_id' => 12599666, 'latitude' => 10.4196780, 'longitude' => 122.8495462, 'distance_km' => 81.4, 'distance_band' => 'km_60_100'],
            '1804530000' => ['osm_relation_id' => 12599678, 'latitude' => 10.4615480, 'longitude' => 122.8234493, 'distance_km' => 80.7, 'distance_band' => 'km_60_100'],
        ],
        'payment_assumptions' => [
            'gcash' => 'Customer pays product subtotal and estimated delivery fee to Battlefront; Battlefront arranges shipment and pays the carrier at dispatch.',
            'maya' => 'Customer pays product subtotal and estimated delivery fee to Battlefront; Battlefront arranges shipment and pays the carrier at dispatch.',
            'cod' => 'Demonstration assumption: courier collects product payment and delivery fee together; carrier collection and remittance arrangements require confirmation. Administrator confirms successful collection.',
        ],
        'unconfigured_route' => [
            'base_fee' => '0.00',
            'payment_methods' => ['gcash', 'maya', 'cod'],
            'assumption_label' => 'No Battlefront delivery estimate is configured. The 0.00 placeholder is not a free-delivery offer or an LBC quotation.',
            'notice' => 'No delivery estimate is configured for this destination. The amount shown covers products only; any delivery arrangement and charge must be confirmed separately. No carrier or ETA is included.',
            'payment_assumptions' => [
                'gcash' => 'Customer pays the product subtotal only. No delivery fee is included; any delivery arrangement and charge must be confirmed separately. No LBC booking is represented.',
                'maya' => 'Customer pays the product subtotal only. No delivery fee is included; any delivery arrangement and charge must be confirmed separately. No LBC booking is represented.',
                'cod' => 'Customer pays the product subtotal only upon delivery. No delivery fee or LBC carrier collection arrangement is included; delivery arrangements must be confirmed separately.',
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
                '1804503000' => ['distance_band' => 'km_60_100', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804506000' => ['distance_band' => 'km_125_155', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804507000' => ['distance_band' => 'km_125_155', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804502000' => ['distance_band' => 'km_60_100', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804510000' => ['distance_band' => 'km_100_125', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804515000' => ['distance_band' => 'km_100_125', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804516000' => ['distance_band' => 'km_60_100', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804527000' => ['distance_band' => 'km_155_180', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804511000' => ['distance_band' => 'km_60_100', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804512000' => ['distance_band' => 'km_155_180', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804513000' => ['distance_band' => 'km_100_125', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804514000' => ['distance_band' => 'km_60_100', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804517000' => ['distance_band' => 'km_60_100', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804519000' => ['distance_band' => 'km_60_100', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804520000' => ['distance_band' => 'km_45_60', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804521000' => ['distance_band' => 'km_60_100', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804522000' => ['distance_band' => 'km_60_100', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804532000' => ['distance_band' => 'km_30_45', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804525000' => ['distance_band' => 'km_60_100', 'payment_methods' => ['gcash', 'maya', 'cod']],
                '1804530000' => ['distance_band' => 'km_60_100', 'payment_methods' => ['gcash', 'maya', 'cod']],
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
