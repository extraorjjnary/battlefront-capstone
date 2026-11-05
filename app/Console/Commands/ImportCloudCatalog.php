<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\CloudCatalogSources;
use App\Services\RealCatalogImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('catalog:import-cloud {batch} {--manifest-sha256=} {--mapping-sha256=} {--apply}')]
#[Description('Validate or import Cloud catalog records without inventory')]
class ImportCloudCatalog extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CloudCatalogSources $sources, RealCatalogImportService $importer): int
    {
        try {
            return $sources->using((string) $this->argument('batch'), [
                'manifest.json' => (string) $this->option('manifest-sha256'),
                'verified-product-details.csv' => (string) $this->option('mapping-sha256'),
            ], function (array $paths) use ($importer): int {
                $mapping = $paths['verified-product-details.csv'];
                $manifest = $paths['manifest.json'];
                $rows = $importer->inspectCatalog($mapping, $manifest);
                $existing = Product::query()->whereIn('product_code', array_column($rows, 'product_code'))->count();
                $this->info('Validated '.count($rows).' catalog rows: '.(count($rows) - $existing).' new; '.$existing.' existing. Inventory excluded.');
                if ($this->option('apply')) {
                    $result = $importer->executeCatalog($mapping, $manifest);
                    $this->info("Catalog: {$result['created']} created; {$result['updated']} updated. No inventory changed.");
                } else {
                    $this->info('Dry run: no database records changed.');
                }

                return self::SUCCESS;
            });
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
