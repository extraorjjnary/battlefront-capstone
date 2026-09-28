<?php

use App\Console\Commands\ResetDevCommand;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\RealCatalogImportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('development reset rejects unsafe environments even with force', function (string $environment, bool $force) {
    $sentinel = User::factory()->create();
    $this->app->instance('env', $environment);

    $this->artisan('battlefront:reset-dev', ['--force' => $force])
        ->expectsOutputToContain('only allowed in local and testing')
        ->assertFailed();

    $this->assertModelExists($sentinel);
})->with(['production', 'staging'])->with([false, true]);

test('development reset requires confirmation', function (string $environment) {
    $this->app->instance('env', $environment);
    $sentinel = User::factory()->create();
    $this->mock(RealCatalogImportService::class, function ($mock) {
        $mock->shouldReceive('inspectFiles')->once()->andReturn(array_fill(0, 654, []));
        $mock->shouldNotReceive('execute');
    });

    $this->artisan('battlefront:reset-dev')
        ->expectsConfirmation('Recreate this development database?', 'no')
        ->expectsOutputToContain('Reset cancelled')
        ->assertFailed();

    $this->assertModelExists($sentinel);
})->with(['local', 'testing']);

test('noninteractive reset requires force', function () {
    $sentinel = User::factory()->create();
    $this->mock(RealCatalogImportService::class, function ($mock) {
        $mock->shouldReceive('inspectFiles')->once()->andReturn(array_fill(0, 654, []));
        $mock->shouldNotReceive('execute');
    });

    $this->artisan('battlefront:reset-dev', ['--no-interaction' => true])
        ->expectsOutputToContain('Reset cancelled')
        ->assertFailed();

    $this->assertModelExists($sentinel);
});

test('invalid restore inputs preserve the existing database', function (string $problem) {
    Storage::fake('public');
    $sentinel = User::factory()->create();
    $manifest = tempnam(sys_get_temp_dir(), 'reset-manifest-');
    $mapping = tempnam(sys_get_temp_dir(), 'reset-mapping-');
    $imagePath = 'products/graphics-card/00123.webp';
    Storage::disk('public')->makeDirectory(dirname($imagePath));
    $image = imagecreatetruecolor(1024, 1024);
    imagewebp($image, Storage::disk('public')->path($imagePath));
    $bytes = Storage::disk('public')->get($imagePath);
    file_put_contents($manifest, json_encode(['version' => 1, 'entries' => [[
        'product_code' => '00123',
        'name' => 'Test GPU',
        'category' => 'Graphics Card',
        'category_slug' => 'graphics-card',
        'quantity' => '5',
        'price' => '7500',
        'image_path' => $imagePath,
        'status' => 'complete',
        'image_sha256' => hash('sha256', $bytes),
        'image_bytes' => strlen($bytes),
    ]]], JSON_THROW_ON_ERROR));
    file_put_contents($mapping, "product_code,product_name,category,brand,quantity_override,reorder_level\n00123,Test GPU,Graphics Card,Biostar,,2\n");
    $options = ['--force' => true, '--manifest' => $manifest, '--mapping' => $mapping];

    try {
        match ($problem) {
            'missing manifest' => $options['--manifest'] = $manifest . '.missing',
            'missing mapping' => $options['--mapping'] = $mapping . '.missing',
            'invalid manifest' => file_put_contents($manifest, '{'),
            'invalid mapping' => file_put_contents($mapping, 'wrong,columns'),
            'missing image' => Storage::disk('public')->delete('products/graphics-card/00123.webp'),
            'changed image' => Storage::disk('public')->put('products/graphics-card/00123.webp', 'invalid'),
            'wrong count' => null,
        };

        $this->artisan('battlefront:reset-dev', $options)
            ->expectsOutputToContain('Catalog preflight failed')
            ->expectsOutputToContain('No database records were changed')
            ->assertFailed();

        $this->assertModelExists($sentinel);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
})->with(['missing manifest', 'missing mapping', 'invalid manifest', 'invalid mapping', 'missing image', 'changed image', 'wrong count']);

test('reset stops when an orchestration stage fails', function (string $stage) {
    $sentinel = User::factory()->create();
    $this->mock(RealCatalogImportService::class, function ($mock) use ($stage) {
        $mock->shouldReceive('inspectFiles')->once()->andReturn(array_fill(0, 654, []));
        if ($stage === 'import') {
            $mock->shouldReceive('execute')->once()->andThrow(new RuntimeException('Import failed.'));
        } elseif ($stage === 'count') {
            $mock->shouldReceive('execute')->once()->andReturn(['created' => 653, 'updated' => 0]);
        } else {
            $mock->shouldNotReceive('execute');
        }
    });
    $command = Mockery::mock(ResetDevCommand::class)->makePartial();
    $command->setName('battlefront:reset-dev');
    $command->setDefinition((new ResetDevCommand)->getDefinition());
    $command->shouldReceive('call')->with('migrate:fresh', Mockery::any())->once()->andReturn($stage === 'migration' ? 1 : 0);
    if ($stage !== 'migration') {
        $command->shouldReceive('call')->with('db:seed', Mockery::any())->once()->andReturn($stage === 'seeding' ? 1 : 0);
    }
    Artisan::registerCommand($command);

    $this->artisan('battlefront:reset-dev', ['--force' => true])
        ->expectsOutputToContain('Reset incomplete')
        ->doesntExpectOutput('Development reset completed.')
        ->assertFailed();

    $this->assertModelExists($sentinel);
})->with(['migration', 'seeding', 'import', 'count']);

test('reset restores the complete real catalog and removes transient records without changing images', function () {
    $manifestPath = storage_path('app/private/product-catalog-images/manifest.json');
    $mappingPath = storage_path('app/imports/product_catalog/verified-product-details.csv');
    if (! is_file($manifestPath) || ! is_file($mappingPath)) {
        $this->markTestSkipped('The private catalog data is unavailable.');
    }
    $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    foreach ($manifest['entries'] as $entry) {
        if (! Storage::disk('public')->exists($entry['image_path'])) {
            $this->markTestSkipped('The private catalog images are unavailable.');
        }
    }

    $originalConnection = DB::getDefaultConnection();
    config(['database.connections.reset_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('reset_test');

    try {
        $expected = app(RealCatalogImportService::class)->inspectFiles($mappingPath, $manifestPath);
        expect($expected)->toHaveCount(654);
        expect(DB::connection()->getSchemaBuilder()->hasTable('products'))->toBeFalse();
        Artisan::call('migrate', ['--force' => true]);
        $transient = Product::factory()->create(['product_code' => $expected[0]['product_code']]);
        $transient->inventory()->create(['quantity' => 999, 'reorder_level' => 999]);
        OrderItem::factory()->for($transient)->create();
        CartItem::factory()->for($transient)->create();
        Sale::factory()->create();
        $filesBefore = collect(Storage::disk('public')->allFiles('products'))
            ->mapWithKeys(fn($path) => [$path => hash_file('sha256', Storage::disk('public')->path($path))])->all();

        $this->artisan('battlefront:reset-dev', ['--force' => true])
            ->expectsOutput('Database recreated.')
            ->expectsOutput('Seeders completed.')
            ->expectsOutput('654 products imported.')
            ->expectsOutput('Development reset completed.')
            ->assertSuccessful();

        $products = Product::with(['inventory', 'category', 'tags'])->get()->keyBy('product_code');
        expect($products)->toHaveCount(654);
        $this->assertDatabaseCount('inventories', 654);
        foreach ($expected as $row) {
            $product = $products->get($row['product_code']);
            expect($product)->not->toBeNull();
            expect($product->is_catalog_imported)->toBeTrue();
            expect($product->name)->toBe($row['name']);
            expect($product->inventory->quantity)->toBe($row['quantity']);
            expect($product->inventory->reorder_level)->toBe(2);
            expect($product->category->name)->toBe($row['category']);
            expect($product->tags->pluck('name')->sort()->values()->all())->toBe(collect($row['tags'])->sort()->values()->all());
            expect($product->image_path)->toBe($row['image_path']);
        }
        $filesAfter = collect(Storage::disk('public')->allFiles('products'))
            ->mapWithKeys(fn($path) => [$path => hash_file('sha256', Storage::disk('public')->path($path))])->all();
        expect($filesAfter)->toBe($filesBefore);
        foreach (['orders', 'order_items', 'sales', 'carts', 'cart_items'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
        $this->assertDatabaseHas('branches', ['city' => 'Sagay City']);
        $this->assertDatabaseCount('chatbot_knowledge', 11);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('reset_test');
    }
});
