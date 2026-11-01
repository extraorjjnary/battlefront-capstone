<?php

use App\Actions\Inventory\HandleSupplierReturn;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DevelopmentSupplierSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function existingSupplierCatalog(): void
{
    foreach (DevelopmentSupplierSeeder::catalog() as $supplier) {
        foreach ($supplier['products'] as $code => $name) {
            Product::factory()->available()->create(['product_code' => (string) $code, 'name' => $name]);
        }
    }
}

test('supplier seeding uses existing selectable products without changing catalog or stock', function () {
    existingSupplierCatalog();
    $before = [];
    foreach (['products', 'categories', 'inventories'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    $this->artisan('db:seed', ['--class' => DevelopmentSupplierSeeder::class, '--no-interaction' => true])
        ->expectsOutputToContain('Demo suppliers ready: 3 suppliers')
        ->expectsOutputToContain('Demo Supplier - Components: 10035, 11026')
        ->assertSuccessful();
    $this->seed(DevelopmentSupplierSeeder::class);

    $this->assertDatabaseCount('suppliers', 3);
    $this->assertDatabaseCount('product_supplier', 6);
    foreach (DevelopmentSupplierSeeder::catalog() as $supplier) {
        $supplierId = DB::table('suppliers')->where('name', $supplier['name'])->value('id');
        foreach (array_keys($supplier['products']) as $code) {
            $this->assertDatabaseHas('product_supplier', [
                'supplier_id' => $supplierId,
                'product_id' => Product::query()->where('product_code', (string) $code)->value('id'),
            ]);
        }
    }
    foreach ($before as $table => $records) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($records);
    }
    $this->assertDatabaseCount('supplier_returns', 0);
    $owner = User::factory()->administrator()->create();
    $this->actingAs($owner)->get(route('administration.supplier-returns.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('products', 6)->has('suppliers', 3)->has('supplier_product_links', 6));
});

test('existing catalog stock works for supplier returns and is not replenished by reseeding', function () {
    existingSupplierCatalog();
    $this->seed(DevelopmentSupplierSeeder::class);
    $supplier = DB::table('suppliers')->where('name', 'Demo Supplier - Components')->sole();
    $product = Product::query()->where('product_code', '10035')->sole();
    $requester = User::factory()->administrator()->create();

    $id = app(HandleSupplierReturn::class)->request($requester, $supplier->id, $product->id, 2, 'Demo defective units', 'defective');
    $this->seed(DevelopmentSupplierSeeder::class);

    $this->assertDatabaseHas('supplier_returns', ['id' => $id, 'supplier_id' => $supplier->id, 'product_id' => $product->id, 'quantity' => 2, 'status' => 'pending']);
    $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => 8, 'quarantined_quantity' => 2]);
});

test('supplier reseeding preserves existing supplier edits and unrelated suppliers', function () {
    existingSupplierCatalog();
    DB::table('suppliers')->insert(['name' => 'Existing supplier', 'is_active' => true]);
    $this->seed(DevelopmentSupplierSeeder::class);
    DB::table('suppliers')->where('name', 'Demo Supplier - Components')->update(['is_active' => false]);
    $before = DB::table('suppliers')->orderBy('id')->get()->all();

    $this->seed(DevelopmentSupplierSeeder::class);

    expect(DB::table('suppliers')->orderBy('id')->get()->all())->toEqual($before);
});

test('supplier identity conflicts roll back the entire seed', function () {
    existingSupplierCatalog();
    DB::table('suppliers')->insert(['name' => 'Demo Supplier - Accessories', 'email' => 'existing@example.test']);
    $before = DB::table('suppliers')->get()->all();

    expect(fn () => $this->seed(DevelopmentSupplierSeeder::class))->toThrow(RuntimeException::class, 'conflicts with existing data');

    expect(DB::table('suppliers')->get()->all())->toEqual($before);
});

test('missing existing products stop supplier seeding without creating replacements', function () {
    expect(fn () => $this->seed(DevelopmentSupplierSeeder::class))->toThrow(RuntimeException::class, 'requires existing product');

    $this->assertDatabaseCount('suppliers', 0);
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('categories', 0);
});

test('supplier demonstration seeding refuses nondevelopment environments', function (string $environment) {
    $this->app->instance('env', $environment);

    expect(fn () => $this->artisan('db:seed', ['--class' => DevelopmentSupplierSeeder::class, '--force' => true, '--no-interaction' => true])->run())
        ->toThrow(RuntimeException::class, 'only allowed in local and testing');

    $this->assertDatabaseCount('suppliers', 0);
    $this->assertDatabaseCount('products', 0);
})->with(['production', 'staging']);
