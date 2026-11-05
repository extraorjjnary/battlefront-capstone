<?php

return [
    'cleanup_enabled' => env('BATTLEFRONT_DEMO_CLEANUP_ENABLED', false),
    'rehearsal_enabled' => env('BATTLEFRONT_DEMO_REHEARSAL_ENABLED', false),
    'rehearsal_run_id' => env('BATTLEFRONT_DEMO_REHEARSAL_RUN_ID'),
    'rehearsal_key_hash' => env('BATTLEFRONT_DEMO_REHEARSAL_KEY_HASH'),
    'connection' => env('BATTLEFRONT_DEMO_CONNECTION'),
    'host' => env('BATTLEFRONT_DEMO_DATABASE_HOST'),
    'provisioning_enabled' => env('BATTLEFRONT_DEMO_PROVISIONING_ENABLED', false),
    'forecasting_enabled' => env('BATTLEFRONT_DEMO_FORECASTING_ENABLED', false),
    'password' => env('BATTLEFRONT_DEMO_PASSWORD'),
];
