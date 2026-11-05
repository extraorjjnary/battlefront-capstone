<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoCatalogInventory
{
    public function __construct(private readonly RealCatalogImportService $importer) {}

    /** @param array<string, string> $paths
     * @param  array<string, string>  $hashes
     */
    public function run(array $paths, array $hashes, string $batch, bool $apply): int
    {
        if (! config('catalog-demo.enabled') || ! config('catalog-demo.provisioning_enabled')
            || ! is_string(config('catalog-demo.database')) || config('catalog-demo.database') === ''
            || DB::connection()->getDatabaseName() !== config('catalog-demo.database')) {
            throw new RuntimeException('Demo labeling, provisioning permission and an exact demonstration database match are required.');
        }
        $rows = $this->importer->inspectCatalog($paths['verified-product-details.csv'], $paths['manifest.json']);
        $manifestBytes = file_get_contents($paths['manifest.json']);
        $approvalBytes = file_get_contents($paths['development-value-approvals.json']);
        if ($manifestBytes === false || $approvalBytes === false) {
            throw new RuntimeException('Staged catalog sources are not readable.');
        }
        $manifest = json_decode($manifestBytes, true, flags: JSON_THROW_ON_ERROR);
        $approvals = json_decode($approvalBytes, true, flags: JSON_THROW_ON_ERROR);
        if (count($rows) !== 654 || ($approvals['version'] ?? null) !== 2 || ($approvals['profile'] ?? null) !== 'retailer-demo-v1'
            || count($approvals['products'] ?? []) !== 654) {
            throw new RuntimeException('Expected the complete approved 654-product retailer-demo-v1 profile.');
        }
        $handle = fopen($paths['verified-product-details.csv'], 'r');
        if ($handle === false) {
            throw new RuntimeException('Staged catalog mapping is not readable.');
        }
        $mapping = [];
        try {
            fgetcsv($handle, escape: '');
            while (($cells = fgetcsv($handle, escape: '')) !== false) {
                if ($cells !== [null]) {
                    $mapping[$cells[0]] = $cells;
                }
            }
        } finally {
            fclose($handle);
        }
        $counts = [0, 0, 0];
        foreach ($manifest['entries'] as $entry) {
            $code = $entry['product_code'];
            $approved = $approvals['products'][$code] ?? null;
            $cells = $mapping[$code];
            $quantity = $entry['quantity'] ?? $cells[4];
            if (($entry['quantity_source'] ?? null) !== 'development_demo' || ! array_key_exists('source_quantity', $entry)
                || ! is_array($approved) || ! array_key_exists('source_quantity', $approved)
                || $entry['source_quantity'] !== $approved['source_quantity']
                || ($entry['source_quantity'] !== null && (! is_string($entry['source_quantity']) || ! preg_match('/^\d+$/D', $entry['source_quantity'])))
                || ! is_int($approved['quantity'] ?? null) || ! is_int($approved['reorder_level'] ?? null)
                || $approved['quantity'] < 0 || $approved['quantity'] > 2147483647
                || $approved['reorder_level'] < 0 || $approved['reorder_level'] > 4294967295
                || (string) $approved['quantity'] !== $quantity || (string) $approved['reorder_level'] !== $cells[5]
                || ($entry['quantity'] !== null && $cells[4] !== '')) {
                throw new RuntimeException('Demo approval and immutable source provenance disagree.');
            }
            $counts[$approved['quantity'] === 0 ? 2 : ($approved['quantity'] <= $approved['reorder_level'] ? 1 : 0)]++;
        }
        if ($counts !== [650, 3, 1]) {
            throw new RuntimeException('Expected 650 in-stock, 3 low-stock and 1 out-of-stock demo products.');
        }

        return DB::transaction(function () use ($rows, $approvals, $hashes, $batch, $apply): int {
            $products = Product::query()->whereIn('product_code', array_column($rows, 'product_code'))->orderBy('id');
            if ($apply) {
                $products->lockForUpdate();
            }
            $products = $products->get();
            $completed = DB::table('audit_logs')->where('action', 'catalog.demo_inventory.provisioned')->where('subject_type', 'product')->whereIn('subject_id', $products->modelKeys())->get();
            if ($completed->isNotEmpty()) {
                if ($completed->count() !== 654 || $completed->unique('subject_id')->count() !== 654 || $completed->contains(fn ($audit): bool => (json_decode($audit->changes, true)['batch'] ?? null) !== $batch
                    || (json_decode($audit->changes, true)['source_hashes'] ?? null) !== $hashes)
                    || Inventory::query()->whereIn('product_id', $products->modelKeys())->count() !== 654) {
                    throw new RuntimeException('Incomplete or conflicting demo batch history; manual review required.');
                }

                return 654;
            }
            if (Inventory::query()->whereIn('product_id', $products->modelKeys())->exists()
                || DB::table('order_items')->whereIn('product_id', $products->modelKeys())->exists()
                || DB::table('inventory_movements')->whereIn('product_id', $products->modelKeys())->exists()
                || DB::table('inventory_adjustment_requests')->whereIn('product_id', $products->modelKeys())->exists()) {
                throw new RuntimeException('Target inventory or operational history already exists. Demo provisioning cannot overwrite it.');
            }
            foreach (['damage_reports', 'supplier_returns'] as $table) {
                if (DB::table($table)->whereIn('product_id', $products->modelKeys())->exists()) {
                    throw new RuntimeException('Target inventory cases already exist. Manual review is required.');
                }
            }
            if (! $apply) {
                return 654;
            }
            if ($products->count() !== 654 || $products->contains(fn (Product $product): bool => ! $product->is_catalog_imported)) {
                throw new RuntimeException('Import all 654 catalog identities before provisioning inventory.');
            }
            foreach ($products as $product) {
                $values = $approvals['products'][$product->product_code];
                $inventory = Inventory::query()->create(['product_id' => $product->id, 'quantity' => $values['quantity'], 'reorder_level' => $values['reorder_level']]);
                $audit = DB::table('audit_logs')->insertGetId([
                    'actor_id' => null, 'action' => 'catalog.demo_inventory.provisioned', 'subject_type' => 'product', 'subject_id' => $product->id,
                    'reason' => config('catalog-demo.notice'),
                    'changes' => json_encode(['batch' => $batch, 'profile' => 'retailer-demo-v1', 'source_hashes' => $hashes, ...$values], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
                DB::table('inventory_movements')->insert([
                    'product_id' => $product->id, 'actor_id' => null, 'kind' => 'demo_initialization', 'reference_type' => 'audit_log', 'reference_id' => $audit,
                    'sellable_before' => 0, 'sellable_delta' => $inventory->quantity, 'sellable_after' => $inventory->quantity,
                    'quarantined_before' => 0, 'quarantined_delta' => 0, 'quarantined_after' => 0, 'created_at' => now(),
                ]);
            }

            return 654;
        });
    }
}
