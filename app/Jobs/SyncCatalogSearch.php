<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCatalogSearch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @param list<int> $productIds */
    public function __construct(public array $productIds)
    {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(): void
    {
        if (config('scout.driver') !== 'algolia') {
            return;
        }

        $engine = (new Product)->searchableUsing();
        $products = Product::query()->with('category:id,name')->whereKey($this->productIds)->get();
        $engine->update($products);

        $removed = array_diff($this->productIds, $products->modelKeys());
        $tombstones = (new Product)->newCollection();
        foreach ($removed as $id) {
            $product = new Product;
            $product->forceFill(['id' => $id]);
            $tombstones->push($product);
        }
        $engine->delete($tombstones);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Catalog indexing job failed after retries.', [
            'product_count' => count($this->productIds),
        ]);
    }
}
