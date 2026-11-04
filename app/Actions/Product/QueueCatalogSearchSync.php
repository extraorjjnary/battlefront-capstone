<?php

namespace App\Actions\Product;

use App\Jobs\SyncCatalogSearch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueCatalogSearchSync
{
    /** @param list<int> $productIds */
    public function execute(array $productIds): void
    {
        if (config('scout.driver') !== 'algolia' || $productIds === []) {
            return;
        }

        DB::afterCommit(function () use ($productIds): void {
            try {
                Bus::dispatch(new SyncCatalogSearch($productIds));
            } catch (Throwable) {
                try {
                    Log::error('Catalog indexing could not be queued. Use SQL search until catalog reconciliation succeeds.', [
                        'product_ids' => $productIds,
                        'product_count' => count($productIds),
                    ]);
                } catch (Throwable) {
                }
            }
        });
    }
}
