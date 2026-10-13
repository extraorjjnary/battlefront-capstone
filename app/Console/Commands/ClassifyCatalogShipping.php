<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\CatalogShippingClassification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('catalog:classify-shipping {--apply : Apply the reviewed initial mapping; default is dry run}')]
#[Description('Review catalog shipping classifications while preserving manual and changed assignments')]
class ClassifyCatalogShipping extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CatalogShippingClassification $classification): int
    {
        $applied = 0;
        $review = 0;
        foreach (Product::query()->where('is_catalog_imported', true)->with('category')->lazyById(100) as $product) {
            $suggestion = $classification->suggestion($product);
            $this->line($product->product_code.' | '.($suggestion['profile'] ?? 'review').' | '.($product->shipping_profile_source ?? 'unreviewed').' | '.($suggestion['reason'] ?? 'Identity changed; review required.'));
            if ($suggestion === null || $suggestion['profile'] === null) {
                $review++;
            }
            if ($this->option('apply')) {
                $applied += (int) DB::transaction(function () use ($product, $classification): bool {
                    $locked = Product::query()->with('category')->lockForUpdate()->findOrFail($product->id);

                    return $classification->apply($locked);
                });
            }
        }
        $this->info("Applied: {$applied}; requiring review: {$review}. Existing/manual assignments are preserved.");

        return self::SUCCESS;
    }
}
