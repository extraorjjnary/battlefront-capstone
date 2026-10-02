<?php

use App\Actions\Cart\CartOperationException;
use App\Actions\Order\OrderPlacementException;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\Cart\CartService;
use App\Services\Order\OrderPlacementService;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

/**
 * @return list<array{start: string, end: string, quantity: int, amount: string, components: string, peripherals: string}>
 */
function historicalSalesExpectedQuarters(): array
{
    return [
        ['start' => '2024-10-01', 'end' => '2024-12-31', 'quantity' => 63, 'amount' => '82400.00', 'components' => '80000.00', 'peripherals' => '2400.00'],
        ['start' => '2025-01-01', 'end' => '2025-03-31', 'quantity' => 67, 'amount' => '86900.00', 'components' => '82500.00', 'peripherals' => '4400.00'],
        ['start' => '2025-04-01', 'end' => '2025-06-30', 'quantity' => 74, 'amount' => '99000.00', 'components' => '85000.00', 'peripherals' => '14000.00'],
        ['start' => '2025-07-01', 'end' => '2025-09-30', 'quantity' => 79, 'amount' => '98000.00', 'components' => '87500.00', 'peripherals' => '10500.00'],
        ['start' => '2025-10-01', 'end' => '2025-12-31', 'quantity' => 63, 'amount' => '92600.00', 'components' => '90000.00', 'peripherals' => '2600.00'],
        ['start' => '2026-01-01', 'end' => '2026-03-31', 'quantity' => 73, 'amount' => '112100.00', 'components' => '92500.00', 'peripherals' => '19600.00'],
        ['start' => '2026-04-01', 'end' => '2026-06-30', 'quantity' => 71, 'amount' => '101700.00', 'components' => '95000.00', 'peripherals' => '6700.00'],
        ['start' => '2026-07-01', 'end' => '2026-09-30', 'quantity' => 88, 'amount' => '130700.00', 'components' => '97500.00', 'peripherals' => '33200.00'],
    ];
}

/** @return array<string, list<object>> */
function historicalSalesDatabaseSnapshot(): array
{
    $snapshot = [];
    foreach (['users', 'categories', 'products', 'inventories', 'orders', 'order_items', 'sales', 'product_tag'] as $table) {
        $snapshot[$table] = DB::table($table)->get()->all();
    }

    return $snapshot;
}

test('historical fixtures identify development data and preserve customer ownership', function () {
    $administrator = User::factory()->administrator()->create(['email' => 'admin@example.com']);

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    $customer = User::query()->where('email', 'historical-sales@example.test')->sole();
    expect($customer->name)->toBe('Historical Sales Development Account');
    expect($customer->role)->toBe(UserRole::Customer);
    expect(Hash::check('password', $customer->password))->toBeTrue();
    expect($customer->orders()->count())->toBe(16);
    expect($administrator->orders()->count())->toBe(0);
    expect($administrator->refresh()->role)->toBe(UserRole::Administrator);
    expect(Category::query()->orderBy('name')->pluck('name')->all())->toBe([
        'Historical Sales Components', 'Historical Sales Peripherals',
    ]);
    $this->assertDatabaseCount('products', 7);
    $this->assertDatabaseCount('inventories', 7);
    $this->assertDatabaseCount('orders', 16);
    $this->assertDatabaseCount('order_items', 51);
    $this->assertDatabaseCount('sales', 16);
    $this->assertDatabaseCount('tags', 0);

    foreach (Product::with(['category', 'tags'])->get() as $product) {
        expect($product->product_code)->toStartWith('DEVHIST40');
        expect($product->name)->not->toContain('[SYNTHETIC', 'EXT-40');
        expect($product->description)->toBe('Development-only historical sales fixture for forecasting validation. Not part of the verified Battlefront catalog. Synthetic data; does not represent actual Battlefront historical sales.');
        expect($product->category->description)->toBe($product->description);
        expect($product->is_catalog_imported)->toBeFalse();
        expect($product->is_active)->toBeFalse();
        expect($product->category->is_active)->toBeFalse();
        expect($product->is_featured)->toBeFalse();
        expect($product->discount_price)->toBeNull();
        expect($product->image_path)->toBeNull();
        expect($product->brand)->toBeNull();
        expect($product->tags)->toHaveCount(0);
        expect($product->created_at->toDateTimeString())->toBe('2024-09-30 00:00:00');
    }
});

test('historical transactions use exact calendar boundaries and consistent completed orders', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    foreach (historicalSalesExpectedQuarters() as $index => $quarter) {
        $sales = Sale::with(['order.items', 'order.user'])->whereDate('sale_date', '>=', $quarter['start'])->whereDate('sale_date', '<=', $quarter['end'])->orderBy('sale_date')->get();
        expect($sales)->toHaveCount(2);
        expect($sales->pluck('sale_date')->map->toDateString()->all())->toBe([$quarter['start'], $quarter['end']]);
        expect($sales->pluck('order.created_at')->map->toDateTimeString()->all())->toBe([
            $quarter['start'].' 00:00:00', $quarter['end'].' 23:59:59',
        ]);

        foreach ($sales as $sale) {
            $order = $sale->order;
            expect($order->recipient_name)->toBe('Historical Sales Q'.$sale->sale_date->quarter.' '.$sale->sale_date->year);
            expect($order->user->role)->toBe(UserRole::Customer);
            expect($order->status)->toBe(OrderStatus::Completed);
            expect($order->payment_status)->toBe(PaymentStatus::Verified);
            expect($order->payment_method)->toBe(PaymentMethod::Cash);
            expect($order->fulfillment_method)->toBe(FulfillmentMethod::Pickup);
            expect($order->delivery_address)->toBeNull();
            expect($order->payment_proof_path)->toBeNull();
            expect($order->sale()->count())->toBe(1);
            expect($sale->amount)->toBe($order->total_amount);
            $subtotal = '0.00';
            foreach ($order->items as $item) {
                expect($item->quantity)->toBeGreaterThan(0);
                $subtotal = bcadd($subtotal, bcmul($item->price_at_time, (string) $item->quantity, 2), 2);
            }
            expect($subtotal)->toBe($order->total_amount);
        }
    }
});

test('known product scenarios preserve exact quantities price snapshots and stock', function (string $code, array $quantities, array $prices, array $amounts, int $stock, string $currentPrice) {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::with('inventory')->where('product_code', 'DEVHIST40'.$code)->sole();

    foreach (historicalSalesExpectedQuarters() as $index => $quarter) {
        $items = $product->orderItems()->whereHas('order.sale', fn ($query) => $query->whereDate('sale_date', '>=', $quarter['start'])->whereDate('sale_date', '<=', $quarter['end']))->get();
        expect($items->sum('quantity'))->toBe($quantities[$index]);
        expect($items->pluck('price_at_time')->unique()->values()->all())->toBe($quantities[$index] === 0 ? [] : [$prices[$index]]);
        $amount = $items->reduce(fn (string $total, OrderItem $item): string => bcadd($total, bcmul($item->price_at_time, (string) $item->quantity, 2), 2), '0.00');
        expect($amount)->toBe($amounts[$index]);
    }

    expect($product->price)->toBe($currentPrice);
    expect($product->inventory->quantity)->toBe($stock);
    expect($product->inventory->reorder_level)->toBe(10);
    expect($product->inventory->last_updated->toDateTimeString())->toBe('2026-10-15 12:00:00');
})->with([
    'stable' => ['STABLE', [10, 10, 10, 10, 10, 10, 10, 10], array_fill(0, 8, '1000.00'), array_fill(0, 8, '10000.00'), 100, '1000.00'],
    'increasing' => ['INCREASING', [5, 10, 15, 20, 25, 30, 35, 40], array_fill(0, 8, '2000.00'), ['10000.00', '20000.00', '30000.00', '40000.00', '50000.00', '60000.00', '70000.00', '80000.00'], 50, '2000.00'],
    'declining' => ['DECLINING', [40, 35, 30, 25, 20, 15, 10, 5], array_fill(0, 8, '1500.00'), ['60000.00', '52500.00', '45000.00', '37500.00', '30000.00', '22500.00', '15000.00', '7500.00'], 20, '1500.00'],
    'repeating' => ['REPEATING', [4, 8, 12, 20, 4, 8, 12, 20], array_fill(0, 8, '500.00'), ['2000.00', '4000.00', '6000.00', '10000.00', '2000.00', '4000.00', '6000.00', '10000.00'], 15, '500.00'],
    'sparse' => ['SPARSE', [0, 0, 3, 0, 0, 6, 0, 9], array_fill(0, 8, '2500.00'), ['0.00', '0.00', '7500.00', '0.00', '0.00', '15000.00', '0.00', '22500.00'], 0, '2500.00'],
    'changing price' => ['PRICE', array_fill(0, 8, 4), ['100.00', '100.00', '125.00', '125.00', '150.00', '150.00', '175.00', '175.00'], ['400.00', '400.00', '500.00', '500.00', '600.00', '600.00', '700.00', '700.00'], 8, '200.00'],
    'no history' => ['NOHISTORY', array_fill(0, 8, 0), array_fill(0, 8, null), array_fill(0, 8, '0.00'), 25, '750.00'],
]);

test('quarterly product and category totals are exactly derivable from recorded sales', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    foreach (historicalSalesExpectedQuarters() as $quarter) {
        $sales = Sale::with('order.items.product.category')->whereDate('sale_date', '>=', $quarter['start'])->whereDate('sale_date', '<=', $quarter['end'])->get();
        $items = $sales->flatMap(fn (Sale $sale) => $sale->order->items);
        expect($items->sum('quantity'))->toBe($quarter['quantity']);
        expect($sales->reduce(fn (string $total, Sale $sale): string => bcadd($total, $sale->amount, 2), '0.00'))->toBe($quarter['amount']);
        $groups = $items->groupBy(fn (OrderItem $item): string => $item->product->category->name);
        foreach (['Components' => 'components', 'Peripherals' => 'peripherals'] as $category => $key) {
            $group = $groups['Historical Sales '.$category];
            $revenue = $group->reduce(fn (string $total, OrderItem $item): string => bcadd($total, bcmul($item->price_at_time, (string) $item->quantity, 2), 2), '0.00');
            expect($revenue)->toBe($quarter[$key]);
        }
        expect($groups['Historical Sales Components']->sum('quantity'))->toBe(55);
        expect($groups['Historical Sales Peripherals']->sum('quantity'))->toBe($quarter['quantity'] - 55);
    }
    expect(OrderItem::query()->sum('quantity'))->toBe(578);
    expect(bcadd((string) Sale::query()->sum('amount'), '0.00', 2))->toBe('803400.00');
});

test('repeated seeding restores fixture values without duplicates or changing unrelated records', function () {
    $realProduct = Product::factory()->create();
    $realProduct->forceFill(['is_catalog_imported' => true])->save();
    Inventory::factory()->for($realProduct)->create(['quantity' => 42]);
    $realSale = Sale::factory()->create();
    OrderItem::factory()->for($realSale->order)->for($realProduct)->create();
    $this->travelTo(now()->setDate(2026, 10, 3));
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $before = historicalSalesDatabaseSnapshot();
    $fixtureProduct = Product::where('product_code', 'DEVHIST40STABLE')->sole();
    $fixtureProduct->update(['price' => '1.00', 'is_active' => true]);
    $fixtureProduct->inventory->update(['quantity' => 1]);
    $fixtureProduct->orderItems()->firstOrFail()->update(['quantity' => 99, 'price_at_time' => '1.00']);
    Sale::query()->where('id', '!=', $realSale->id)->firstOrFail()->update(['amount' => '1.00', 'sale_date' => '2026-10-03']);

    $this->travelTo(now()->setDate(2026, 12, 5));
    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    $after = historicalSalesDatabaseSnapshot();
    expect($fixtureProduct->inventory->refresh()->last_updated->toDateTimeString())->toBe(now()->toDateTimeString());
    foreach ($after['inventories'] as $index => $inventory) {
        if ($inventory->product_id === $fixtureProduct->id) {
            $inventory->last_updated = $before['inventories'][$index]->last_updated;
        }
    }
    expect($after)->toEqual($before);
    $this->travelBack();
});

test('historical fixtures are excluded from customer catalog search recommendations and cart eligibility', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    foreach ([[], ['q' => 'Reference'], ['q' => 'DEVHIST40']] as $query) {
        $this->get(route('products.index', $query))->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
    }
    expect(app(ProductCatalogRepository::class)->recommendationInputs()->get())->toHaveCount(0);
    expect(app(ProductCatalogRepository::class)->contextMatches(['Reference']))->toHaveCount(0);
    expect(Product::query()->cartEligible()->count())->toBe(0);
    $customer = User::query()->where('email', 'historical-sales@example.test')->sole();
    foreach (Product::all() as $product) {
        $this->get(route('products.show', $product))->assertNotFound();
        expect(fn () => app(CartService::class)->add($customer, $product->id, 1))->toThrow(CartOperationException::class);
    }
    $this->assertDatabaseCount('carts', 0);
    $this->assertDatabaseCount('cart_items', 0);
});

test('checkout rejects a stale cart referencing an inactive historical fixture', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $customer = User::factory()->customer()->create();
    $product = Product::with('inventory')->where('product_code', 'DEVHIST40STABLE')->sole();
    $product->update(['is_active' => true]);
    $product->category->update(['is_active' => true]);
    CartItem::factory()->for($product)->for($customer->cart()->create())->create(['quantity' => 1]);
    $product->update(['is_active' => false]);
    $product->category->update(['is_active' => false]);

    expect(fn () => app(OrderPlacementService::class)->execute($customer, [
        'recipient_name' => 'Customer', 'contact_number' => '09000000000',
        'fulfillment_method' => FulfillmentMethod::Pickup, 'delivery_address' => null,
        'payment_method' => PaymentMethod::Cash, 'payment_proof_path' => null,
    ]))->toThrow(OrderPlacementException::class);

    expect($customer->orders()->count())->toBe(0);
    expect($product->inventory->refresh()->quantity)->toBe(100);
    $this->assertDatabaseCount('sales', 16);
    $this->assertDatabaseCount('cart_items', 1);
});

test('direct seeding rejects unsafe environments even with force without writing fixtures', function (string $environment) {
    $this->app->instance('env', $environment);
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->artisan('db:seed', [
        '--class' => DevelopmentHistoricalSalesSeeder::class, '--force' => true, '--no-interaction' => true,
    ])->run())->toThrow(RuntimeException::class, 'only allowed in local and testing');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
})->with(['production', 'staging']);

test('reserved identity collisions roll back all historical fixture writes', function (string $collision) {
    match ($collision) {
        'customer' => User::factory()->administrator()->create(['email' => 'historical-sales@example.test']),
        'category' => Category::factory()->create(['name' => 'Historical Sales Peripherals']),
        'product' => Product::factory()->create(['product_code' => 'DEVHIST40INCREASING']),
    };
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'conflicts');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
})->with(['customer', 'category', 'product']);

test('imported products cannot be adopted as historical fixtures', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    Product::where('product_code', 'DEVHIST40INCREASING')->sole()->forceFill(['is_catalog_imported' => true])->save();
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'product code conflicts');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
});

test('ambiguous historical order identities and unexpected items block reseeding', function (string $problem) {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $order = Order::query()->orderBy('created_at')->firstOrFail();
    match ($problem) {
        'order' => Order::factory()->create([
            'user_id' => $order->user_id, 'recipient_name' => $order->recipient_name, 'created_at' => $order->created_at,
        ]),
        'duplicate item' => OrderItem::factory()->for($order)->create(['product_id' => $order->items()->firstOrFail()->product_id]),
        'unrelated item' => OrderItem::factory()->for($order)->create(),
    };
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class);

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
})->with(['order', 'duplicate item', 'unrelated item']);

test('historical fixture seeding is available in the local environment', function () {
    $this->app->instance('env', 'local');

    $this->artisan('db:seed', ['--class' => DevelopmentHistoricalSalesSeeder::class, '--no-interaction' => true])
        ->expectsOutput('Synthetic development sales history: Q4 2024–Q3 2026 (8 completed quarters).')
        ->assertSuccessful();

    $this->assertDatabaseCount('sales', 16);
});

test('history uses only completed quarters in the application timezone', function (string $reference, string $timezone, string $start, string $end) {
    config(['app.timezone' => $timezone]);
    $this->travelTo(CarbonImmutable::parse($reference, 'UTC'));

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    $dates = Sale::query()->orderBy('sale_date')->get()->pluck('sale_date');
    expect($dates->first()->toDateString())->toBe($start);
    expect($dates->last()->toDateString())->toBe($end);
    expect($dates->map(fn ($date) => $date->year.'-'.$date->quarter)->unique())->toHaveCount(8);
    expect($dates)->toHaveCount(16);
    expect(Sale::query()->whereDate('sale_date', '>=', now($timezone)->startOfQuarter()->toDateString())->exists())->toBeFalse();
})->with([
    'before quarter closes' => ['2026-09-30 23:59:59', 'UTC', '2024-07-01', '2026-06-30'],
    'quarter closes' => ['2026-10-01 00:00:00', 'UTC', '2024-10-01', '2026-09-30'],
    'new year' => ['2027-01-01 00:00:00', 'UTC', '2025-01-01', '2026-12-31'],
    'leap day' => ['2024-02-29 12:00:00', 'UTC', '2022-01-01', '2023-12-31'],
    'completed leap quarter' => ['2024-04-01 00:00:00', 'UTC', '2022-04-01', '2024-03-31'],
    'application ahead of UTC' => ['2026-09-30 16:00:00', 'Asia/Shanghai', '2024-10-01', '2026-09-30'],
]);

test('changing the reference quarter replaces obsolete history and rebases exact scenarios', function (string $initialReference, string $nextReference, string $expectedStart, string $expectedEnd) {
    $this->travelTo(CarbonImmutable::parse($initialReference));
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $originalIds = Order::query()->pluck('id', 'created_at');
    $originalCreatedAt = Product::query()->firstOrFail()->created_at;
    $customer = User::query()->where('email', 'historical-sales@example.test')->sole();
    $unrelatedSale = Sale::factory()->for(Order::factory()->for($customer)->state(['recipient_name' => 'Unrelated customer order']))->create();
    OrderItem::factory()->for($unrelatedSale->order)->create();
    $unrelated = $unrelatedSale->refresh()->load('order.items')->toArray();

    $this->travelTo(CarbonImmutable::parse($nextReference));
    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    $sales = Sale::with('order.items')->where('id', '!=', $unrelatedSale->id)->orderBy('sale_date')->get();
    expect($sales)->toHaveCount(16);
    expect($sales->first()->sale_date->toDateString())->toBe($expectedStart);
    expect($sales->last()->sale_date->toDateString())->toBe($expectedEnd);
    expect($sales->flatMap(fn ($sale) => $sale->order->items))->toHaveCount(51);
    expect($sales->flatMap(fn ($sale) => $sale->order->items)->sum('quantity'))->toBe(578);
    expect($sales->reduce(fn (string $total, Sale $sale): string => bcadd($total, $sale->amount, 2), '0.00'))->toBe('803400.00');
    $increasingId = Product::where('product_code', 'DEVHIST40INCREASING')->sole()->id;
    $sparseId = Product::where('product_code', 'DEVHIST40SPARSE')->sole()->id;
    foreach ($sales->chunk(2)->values() as $index => $quarterSales) {
        $items = $quarterSales->flatMap(fn ($sale) => $sale->order->items);
        expect($items->where('product_id', $increasingId)->sum('quantity'))->toBe(($index + 1) * 5);
        expect($items->where('product_id', $sparseId)->sum('quantity'))->toBe([0, 0, 3, 0, 0, 6, 0, 9][$index]);
        expect($quarterSales->reduce(fn (string $total, Sale $sale): string => bcadd($total, $sale->amount, 2), '0.00'))->toBe(historicalSalesExpectedQuarters()[$index]['amount']);
    }
    foreach ($sales as $sale) {
        $date = $sale->order->created_at->toDateTimeString();
        if ($originalIds->has($date)) {
            expect($sale->order_id)->toBe($originalIds[$date]);
        }
        expect($sale->amount)->toBe($sale->order->total_amount);
    }
    $expectedCreatedAt = $originalCreatedAt->min(CarbonImmutable::parse($expectedStart)->subDay())->toDateTimeString();
    expect(Product::where('product_code', 'DEVHIST40STABLE')->sole()->created_at->toDateTimeString())->toBe($expectedCreatedAt);
    expect($customer->refresh()->created_at->toDateTimeString())->toBe($expectedCreatedAt);
    expect($unrelatedSale->fresh()->load('order.items')->toArray())->toBe($unrelated);
    $snapshot = historicalSalesDatabaseSnapshot();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    expect(historicalSalesDatabaseSnapshot())->toEqual($snapshot);
})->with([
    'next quarter removes sparse items from retained orders' => ['2026-10-15', '2027-01-15', '2025-01-01', '2026-12-31'],
    'jump beyond entire window' => ['2026-10-15', '2029-04-15', '2027-04-01', '2029-03-31'],
    'backward reference' => ['2026-10-15', '2025-04-15', '2023-04-01', '2025-03-31'],
    'previous fixed period' => ['2026-01-15', '2026-10-15', '2024-10-01', '2026-09-30'],
]);

test('ambiguous fixture ownership prevents rollover cleanup atomically', function (string $problem) {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $order = Order::query()->orderByDesc('created_at')->firstOrFail();
    match ($problem) {
        'label' => $order->update(['recipient_name' => 'Changed fixture label']),
        'boundary' => $order->forceFill(['created_at' => '2026-09-29 12:00:00'])->save(),
        'mixed items' => OrderItem::factory()->for($order)->create(),
    };
    $this->travelTo(CarbonImmutable::parse('2027-01-15'));
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'ownership');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
})->with(['label', 'boundary', 'mixed items']);

test('unchanged inventory retains its normal maintenance timestamp', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $before = Inventory::query()->pluck('last_updated', 'id')->toArray();
    $this->travelTo(CarbonImmutable::parse('2026-12-15 12:00:00'));

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    expect(Inventory::query()->pluck('last_updated', 'id')->toArray())->toEqual($before);
});
