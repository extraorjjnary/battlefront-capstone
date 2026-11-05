<?php

return [
    'enabled' => env('BATTLEFRONT_CATALOG_DEMO', false),
    'provisioning_enabled' => env('BATTLEFRONT_DEMO_INVENTORY_PROVISIONING_ENABLED', false),
    'database' => env('BATTLEFRONT_DEMO_DATABASE'),
    'notice' => 'Capstone demonstration — inventory quantities are simulated and are not verified Battlefront store stock. Allowlisted demonstration accounts are fictional; generated historical sales are synthetic, not verified client transactions.',
];
