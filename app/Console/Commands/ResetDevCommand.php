<?php

namespace App\Console\Commands;

use App\Services\RealCatalogImportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

#[Signature('battlefront:reset-dev
    {--force : Skip confirmation in local/testing environments}
    {--manifest= : Path to the audited catalog image manifest}
    {--mapping= : Path to the verified product details CSV}')]
#[Description('Recreate the local development database and restore the verified 654-product catalog')]
class ResetDevCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RealCatalogImportService $importer): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('Development reset is only allowed in local and testing environments.');

            return self::FAILURE;
        }

        $stage = 'Catalog preflight';
        $resetStarted = false;

        try {
            $manifest = $this->option('manifest') ?? storage_path('app/private/product-catalog-images/manifest.json');
            $mapping = $this->option('mapping') ?? storage_path('app/imports/product_catalog/verified-product-details.csv');
            $rows = $importer->inspectFiles($mapping, $manifest);
            if (count($rows) !== 654) {
                throw new RuntimeException('Expected exactly 654 verified catalog products; found '.count($rows).'.');
            }
            $this->info('Catalog audit passed: 654 products and image paths validated.');

            $connection = DB::connection();
            $this->warn("This will delete all tables in {$connection->getName()} / {$connection->getDatabaseName()}.");
            if (! $this->option('force') && (! $this->input->isInteractive() || ! $this->confirm('Recreate this development database?', false))) {
                $this->warn('Reset cancelled. Use --force for noninteractive development resets.');

                return self::FAILURE;
            }

            $stage = 'Database recreation';
            $resetStarted = true;
            if ($this->call('migrate:fresh', ['--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                throw new RuntimeException('migrate:fresh failed.');
            }
            $this->info('Database recreated.');

            $stage = 'Application seeding';
            if ($this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                throw new RuntimeException('db:seed failed.');
            }
            $this->info('Seeders completed.');

            $stage = 'Catalog import';
            $result = $importer->execute($mapping, $manifest);
            if ($result['created'] !== 654 || $result['updated'] !== 0) {
                throw new RuntimeException('Expected 654 newly imported products and no updates.');
            }
            $this->info("{$result['created']} products imported.");
            $this->info('Development reset completed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error("$stage failed: {$exception->getMessage()}");
            $this->warn($resetStarted
                ? 'Reset incomplete. Database recreation has started; the previous database cannot be restored by this command.'
                : 'Reset stopped before database recreation. No database records were changed.');

            return self::FAILURE;
        }
    }
}
