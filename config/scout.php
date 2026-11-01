<?php

use App\Models\Product;
use App\Repositories\Catalog\CatalogSearchIntent;

return [
    'driver' => env('SCOUT_DRIVER', null),
    'prefix' => env('SCOUT_PREFIX', 'battlefront_local_'),
    'queue' => true,
    'after_commit' => true,
    'chunk' => ['searchable' => 500, 'unsearchable' => 500],
    'soft_delete' => false,
    'identify' => false,
    'algolia' => [
        'id' => env('ALGOLIA_APP_ID', ''),
        'secret' => env('ALGOLIA_SECRET', ''),
        'connect_timeout' => 2,
        'read_timeout' => 3,
        'write_timeout' => 10,
        'index-settings' => [
            Product::class => [
                'searchableAttributes' => ['product_code', 'name', 'brand', 'category', 'description'],
                'attributesToRetrieve' => ['objectID'],
                'disableTypoToleranceOnAttributes' => ['product_code'],
                'typoTolerance' => true,
                'minWordSizefor1Typo' => 5,
                'minWordSizefor2Typos' => 9,
                'allowTyposOnNumericTokens' => false,
            ],
        ],
        'synonyms' => CatalogSearchIntent::algoliaSynonyms(),
    ],
];
