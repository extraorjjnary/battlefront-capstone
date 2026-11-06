<?php

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\RenderChatbotFallback;
use App\Enums\ChatbotQueryCategory;
use App\Models\Inventory;
use App\Models\Product;
use App\Services\Chatbot\ChatbotAiAdapter;
use App\Services\Chatbot\ChatbotOrchestrationService;
use App\Services\CloudCatalogSources;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** @return array<string, string> */
function cloudCatalogBatch(TestCase $test): array
{
    $test->fakeIsolatedStorage('public');
    $test->fakeIsolatedStorage('private');
    $image = imagecreatetruecolor(1024, 1024);
    ob_start();
    imagewebp($image);
    $base = ob_get_clean();
    $entries = [];
    $approvals = ['version' => 2, 'profile' => 'retailer-demo-v1', 'products' => []];
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, ['product_code', 'product_name', 'category', 'brand', 'quantity_override', 'reorder_level'], escape: '');
    foreach (range(1, 654) as $index) {
        $code = 'CODE'.$index;
        $metadata = '<fixture>'.$code.'</fixture>';
        $chunk = 'XMP '.pack('V', strlen($metadata)).$metadata.(strlen($metadata) % 2 ? "\0" : '');
        $bytes = substr_replace($base, pack('V', strlen($base) + strlen($chunk) - 8), 4, 4).$chunk;
        $path = "products/peripherals/$code.webp";
        Storage::disk('public')->put($path, $bytes);
        $quantity = $index === 1 ? 0 : ($index <= 4 ? 1 : 5);
        $entries[] = ['product_code' => $code, 'name' => 'Catalog product '.$index, 'category' => 'Peripherals', 'category_slug' => 'peripherals',
            'price' => '100', 'quantity' => (string) $quantity, 'source_quantity' => '5', 'quantity_source' => 'development_demo',
            'image_path' => $path, 'status' => 'complete', 'image_sha256' => hash('sha256', $bytes), 'image_bytes' => strlen($bytes)];
        $approvals['products'][$code] = ['quantity' => $quantity, 'reorder_level' => 1, 'source_quantity' => '5'];
        fputcsv($stream, [$code, 'Catalog product '.$index, 'Peripherals', '', '', '1'], escape: '');
    }
    rewind($stream);
    $contents = ['manifest.json' => json_encode(['version' => 1, 'entries' => $entries], JSON_THROW_ON_ERROR),
        'verified-product-details.csv' => stream_get_contents($stream), 'development-value-approvals.json' => json_encode($approvals, JSON_THROW_ON_ERROR)];
    fclose($stream);
    $options = ['batch' => 'test-batch'];
    foreach ($contents as $file => $bytes) {
        Storage::disk('private')->put('catalog-imports/test-batch/'.$file, $bytes);
        $option = match ($file) {
            'manifest.json' => 'manifest', 'verified-product-details.csv' => 'mapping', default => 'approvals'
        };
        $options['--'.$option.'-sha256'] = hash('sha256', $bytes);
    }
    config()->set(['catalog-demo.enabled' => true, 'catalog-demo.provisioning_enabled' => true, 'catalog-demo.database' => DB::connection()->getDatabaseName()]);

    return $options;
}

function importCloudCatalogOptions(array $options): array
{
    unset($options['--approvals-sha256']);

    return $options;
}

test('Cloud catalog import leaves stock untouched and demo provisioning is atomic and repeatable', function () {
    $options = cloudCatalogBatch($this);
    $originalSources = Storage::disk('private')->allFiles();
    $originalHashes = array_map(fn (string $path): string => hash('sha256', Storage::disk('private')->get($path)), $originalSources);
    $this->app->instance('env', 'production');
    $catalog = importCloudCatalogOptions($options);
    $this->artisan('catalog:import-cloud', $catalog)->assertSuccessful();
    $this->assertDatabaseCount('products', 0);
    $demo = [...$options, '--confirm-demo' => true];
    $this->artisan('catalog:provision-demo-inventory', $demo)->assertSuccessful();
    $this->assertDatabaseCount('inventories', 0);
    $this->artisan('catalog:import-cloud', [...$catalog, '--apply' => true])->assertSuccessful();
    $this->assertDatabaseCount('products', 654);
    $this->assertDatabaseCount('inventories', 0);
    $this->artisan('catalog:provision-demo-inventory', [...$demo, '--apply' => true])->assertSuccessful();
    $this->assertDatabaseCount('inventories', 654);
    $this->assertDatabaseCount('inventory_movements', 654);
    expect(DB::table('inventories')->where('quantity', 0)->count())->toBe(1);
    DB::table('inventories')->where('product_id', Product::query()->where('product_code', 'CODE5')->value('id'))->update(['quantity' => 2, 'reorder_level' => 7]);
    $product = Product::query()->where('product_code', 'CODE5')->firstOrFail();
    $adminPath = 'products/admin/'.$product->id.'/'.str_repeat('a', 64).'.webp';
    $product->update(['brand' => 'Reviewed brand', 'description' => 'Reviewed description', 'image_path' => $adminPath]);
    $this->artisan('catalog:provision-demo-inventory', [...$demo, '--apply' => true])->assertSuccessful();
    $this->artisan('catalog:import-cloud', [...$catalog, '--apply' => true])->assertSuccessful();
    $this->assertDatabaseHas('inventories', ['quantity' => 2, 'reorder_level' => 7]);
    $this->assertDatabaseCount('inventory_movements', 654);
    expect($product->fresh()->brand)->toBe('Reviewed brand')->and($product->fresh()->description)->toBe('Reviewed description')->and($product->fresh()->image_path)->toBe($adminPath);
    expect(array_map(fn (string $path): string => hash('sha256', Storage::disk('private')->get($path)), $originalSources))->toBe($originalHashes);
    expect(DB::table('audit_logs')->where('action', 'catalog.demo_inventory.provisioned')->count())->toBe(654);
});

test('Cloud import rejects altered private sources and corrupt public objects before writing', function (bool $source) {
    $options = cloudCatalogBatch($this);
    if ($source) {
        Storage::disk('private')->put('catalog-imports/test-batch/manifest.json', '{}');
    } else {
        Storage::disk('public')->put('products/peripherals/CODE1.webp', 'corrupt');
    }
    $this->artisan('catalog:import-cloud', [...importCloudCatalogOptions($options), '--apply' => true])->assertFailed();
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('inventories', 0);
})->with([true, false]);

test('demo provisioning gates refuse writes', function (string $gate) {
    $options = cloudCatalogBatch($this);
    config()->set('catalog-demo.'.$gate, $gate === 'database' ? 'not-this-database' : false);
    $this->artisan('catalog:provision-demo-inventory', [...$options, '--confirm-demo' => true, '--apply' => true])->assertFailed();
    $this->assertDatabaseCount('inventories', 0);
})->with(['enabled', 'provisioning_enabled', 'database']);

test('private staging cleans temporary files after callback failure and rejects traversal', function () {
    Storage::fake('private');
    Storage::disk('private')->put('catalog-imports/batch/manifest.json', '{}');
    $staged = null;
    expect(fn () => app(CloudCatalogSources::class)->using('batch', ['manifest.json' => hash('sha256', '{}')], function (array $paths) use (&$staged): void {
        $staged = $paths['manifest.json'];
        throw new RuntimeException('callback failed');
    }))->toThrow(RuntimeException::class, 'callback failed');
    expect(is_file($staged))->toBeFalse();
    expect(fn () => app(CloudCatalogSources::class)->using('../public', [], fn () => null))->toThrow(RuntimeException::class);
});

test('demo inventory refuses preexisting stock and rolls back late failures including its audit ledger', function (bool $existing) {
    $options = cloudCatalogBatch($this);
    $this->artisan('catalog:import-cloud', [...importCloudCatalogOptions($options), '--apply' => true])->assertSuccessful();
    if ($existing) {
        Product::query()->first()->inventory()->create(['quantity' => 42, 'reorder_level' => 9]);
    } else {
        $created = 0;
        Inventory::creating(function () use (&$created): void {
            if (++$created === 3) {
                throw new RuntimeException('Injected late inventory failure.');
            }
        });
    }
    $this->artisan('catalog:provision-demo-inventory', [...$options, '--confirm-demo' => true, '--apply' => true])->assertFailed();
    $this->assertDatabaseCount('inventories', $existing ? 1 : 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    expect(DB::table('audit_logs')->where('action', 'catalog.demo_inventory.provisioned')->count())->toBe(0);
    if ($existing) {
        $this->assertDatabaseHas('inventories', ['quantity' => 42, 'reorder_level' => 9]);
    }
})->with([true, false]);

test('demo inventory is disclosed in chatbot without a global banner or synthetic catalog prices', function () {
    config()->set('catalog-demo.enabled', true);
    $this->get('/')->assertOk()->assertDontSee(config('catalog-demo.notice'));
    $reply = app(RenderChatbotFallback::class)->execute(ChatbotQueryCategory::Product, 'stock', ['products' => [[
        'name' => 'Real catalog name', 'is_demo' => false, 'inventory_notice' => config('catalog-demo.notice'), 'inventory' => ['quantity' => 5, 'status' => 'in_stock'],
    ]]]);
    expect($reply)->toBe('Real catalog name. Simulated stock: 5 units.');
});

test('demo disclosure reaches product context and generated responses', function () {
    config()->set('catalog-demo.enabled', true);
    $product = Product::factory()->available()->create(['name' => 'Aurelius Link Station']);
    $context = app(ResolveProductContext::class)->forProduct($product->id);
    expect($context['products'][0]['inventory_notice'])->toBe(config('catalog-demo.notice'));
    $this->mock(ChatbotAiAdapter::class)->shouldReceive('generate')->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Is the Aurelius Link Station available?'
            && $context['products'][0]['inventory_notice'] === 'Demo inventory: Stock quantities are simulated.')
        ->andReturn([
            'successful' => true, 'text' => 'Listed catalog details.', 'failure' => null,
        ]);
    $result = app(ChatbotOrchestrationService::class)->respond('Is the Aurelius Link Station available?');
    expect($result['message'])->toBe('Listed catalog details. Stock quantities are simulated.');
});
