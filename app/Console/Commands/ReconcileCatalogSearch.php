<?php

namespace App\Console\Commands;

use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use App\Jobs\SyncCatalogSearch;
use App\Models\Product;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Scout\Engines\Algolia4Engine;

#[Signature('catalog:reconcile-search')]
#[Description('Synchronize catalog documents and remove stale Algolia records')]
class ReconcileCatalogSearch extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (config('scout.driver') !== 'algolia') {
            $this->error('Set SCOUT_DRIVER=algolia before reconciling the search index.');

            return self::FAILURE;
        }

        $product = new Product;
        $engine = $product->searchableUsing();
        if (! $engine instanceof Algolia4Engine) {
            $this->error('The configured search engine must use the installed Algolia v4 client.');

            return self::FAILURE;
        }

        $ids = [];
        try {
            foreach ($engine->__call('browseObjects', [$product->indexableAs(), ['attributesToRetrieve' => ['objectID']]]) as $hit) {
                $ids[] = (int) $hit['objectID'];
                if (count($ids) === 100) {
                    $this->removeStale($ids);
                    $ids = [];
                }
            }
        } catch (NotFoundException) {
            $this->line('The index is new; importing the current catalog.');
        }
        $this->removeStale($ids);

        Product::query()->select('id')->chunkById(100, function ($products): void {
            SyncCatalogSearch::dispatchSync(array_values(array_map('intval', $products->modelKeys())));
        });

        $engine->__call('saveSynonyms', [$product->indexableAs(), config('scout.algolia.synonyms', []), false, false]);

        $this->info('Catalog documents and approved synonyms synchronized. Algolia applies updates asynchronously.');

        return self::SUCCESS;
    }

    /** @param list<int> $ids */
    private function removeStale(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $missing = array_values(array_diff($ids, Product::query()->whereKey($ids)->pluck('id')->all()));
        if ($missing !== []) {
            SyncCatalogSearch::dispatchSync($missing);
        }
    }
}
