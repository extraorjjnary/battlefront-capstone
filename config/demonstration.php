<?php

return [
    'connection' => env('BATTLEFRONT_DEMO_CONNECTION'),
    'host' => env('BATTLEFRONT_DEMO_DATABASE_HOST'),
    'provisioning_enabled' => env('BATTLEFRONT_DEMO_PROVISIONING_ENABLED', false),
    'forecasting_enabled' => env('BATTLEFRONT_DEMO_FORECASTING_ENABLED', false),
    'password' => env('BATTLEFRONT_DEMO_PASSWORD'),
];
