<?php

namespace App\Console\Commands;

use App\Services\CloudCatalogSources;
use App\Services\DemoCatalogInventory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('catalog:provision-demo-inventory {batch} {--manifest-sha256=} {--mapping-sha256=} {--approvals-sha256=} {--confirm-demo} {--apply}')]
#[Description('Validate or initialize explicitly labeled capstone inventory in the configured demonstration database')]
class ProvisionDemoCatalogInventory extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CloudCatalogSources $sources, DemoCatalogInventory $inventory): int
    {
        try {
            if (! $this->option('confirm-demo')) {
                throw new \RuntimeException('Pass --confirm-demo to acknowledge simulated inventory, never verified store stock.');
            }
            $batch = (string) $this->argument('batch');
            $hashes = [
                'manifest.json' => (string) $this->option('manifest-sha256'),
                'verified-product-details.csv' => (string) $this->option('mapping-sha256'),
                'development-value-approvals.json' => (string) $this->option('approvals-sha256'),
            ];
            $count = $sources->using($batch, $hashes, fn (array $paths): int => $inventory->run($paths, $hashes, $batch, (bool) $this->option('apply')));
            $this->info(($this->option('apply') ? 'Provisioned or previously completed: ' : 'Dry run validated: ').$count.' demo products; 650 in stock, 3 low stock, 1 out of stock. Existing stock is never reset.');

            return self::SUCCESS;
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
