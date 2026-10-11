<?php

use App\Actions\Cart\CartOperationException;
use App\Actions\Order\OrderPlacementException;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Enums\ShippingProfile;
use App\Enums\UserRole;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\Cart\CartService;
use App\Services\Order\DeliveryRules;
use App\Services\Order\OrderPlacementService;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\HistoricalSalesCatalogFixtures;
use Tests\LegacyQuarterlySalesFixtures;

beforeEach(function () {
    Storage::fake('local');
    config(['app.timezone' => 'UTC', 'forecasting.operational_coverage' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

/** @return array<string, list<object>> */
function historicalSalesDatabaseSnapshot(): array
{
    $snapshot = [];
    foreach (['users', 'categories', 'products', 'inventories', 'orders', 'order_items', 'sales', 'shipments', 'product_tag'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $snapshot;
}

test('historical transactions reference existing imported identities without changing catalog or inventory', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    Product::factory()->available()->create();
    $before = historicalSalesDatabaseSnapshot();
    $administrator = User::factory()->administrator()->create(['email' => 'admin@example.com']);

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    $customer = User::where('email', 'historical-sales@example.test')->sole();
    expect($customer->name)->toBe('Historical Sales Development Account');
    expect($customer->role)->toBe(UserRole::Customer);
    expect(Hash::check('password', $customer->password))->toBeTrue();
    expect($customer->orders()->count())->toBeBetween(150, 300);
    expect($administrator->orders()->count())->toBe(0);
    foreach (['products', 'inventories', 'categories', 'product_tag'] as $table) {
        expect(historicalSalesDatabaseSnapshot()[$table])->toEqual($before[$table]);
    }
    expect(OrderItem::query()->distinct()->pluck('product_id')->sort()->values()->all())
        ->toBe(collect($products)->pluck('id')->sort()->values()->all());
    expect(Product::where('product_code', 'like', 'DEVHIST40%')->exists())->toBeFalse();
    expect(Product::whereHas('orderItems')->where('is_catalog_imported', false)->exists())->toBeFalse();
    expect(Order::count())->toBe(Sale::count());
    expect(OrderItem::count())->toBeGreaterThan(720);
    expect(DB::table('shipments')->count())->toBe(Order::where('fulfillment_method', FulfillmentMethod::Delivery)->count());
    $this->assertDatabaseCount('notifications', 0);
});

test('historical orders and sales use coherent completed mixed fulfillment snapshots on deterministic dates', function () {
    HistoricalSalesCatalogFixtures::createProducts();

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    foreach (Sale::with(['order.items.product', 'order.user', 'order.shipment'])->orderBy('sale_date')->get() as $sale) {
        $order = $sale->order;
        expect($sale->sale_date->day)->toBeBetween(2, 28);
        expect($order->created_at->hour)->toBeBetween(9, 17);
        expect($order->created_at->toDateString())->toBe($sale->sale_date->toDateString());
        expect($order->recipient_name)->toMatch('/^Demonstration Historical Sales '.$sale->sale_date->format('Y-m').' V3 0[1-7]$/');
        expect($order->user->email)->toBe('historical-sales@example.test');
        expect($order->status)->toBe(OrderStatus::Completed);
        expect($order->payment_status)->toBe(PaymentStatus::Verified);
        expect($order->payment_method->isAvailableFor($order->fulfillment_method))->toBeTrue();
        if ($order->fulfillment_method === FulfillmentMethod::Pickup) {
            expect($order->delivery_address)->toBeNull();
            expect($order->delivery_fee)->toBe('0.00');
            expect($order->delivery_destination)->toBeNull();
            expect($order->delivery_base_fee)->toBeNull();
            expect($order->handling_surcharge)->toBeNull();
            expect($order->shipping_profile)->toBeNull();
            expect($order->shipment)->toBeNull();
        } else {
            $quote = app(DeliveryRules::class)->quote(FulfillmentMethod::Delivery, $order->delivery_destination, $order->items->pluck('product'));
            expect($order->delivery_address)->toContain('Demonstration address only', $order->delivery_destination);
            expect($order->delivery_is_demo)->toBeTrue();
            expect($order->delivery_origin_city)->toBe('Sagay City');
            expect($order->delivery_assumption_label)->toContain('Synthetic historical delivery', 'no payment evidence or carrier milestones retained');
            expect($order->delivery_base_fee)->toBe($quote['base_fee']);
            expect($order->handling_surcharge)->toBe($quote['handling_surcharge']);
            expect($order->delivery_fee)->toBe($quote['delivery_fee']);
            expect($order->shipping_profile->value)->toBe($quote['shipping_profile']);
            expect($order->shipment->status)->toBe(ShipmentStatus::Delivered);
            expect($order->shipment->carrier)->toBe('lbc');
            expect($order->shipment->eta_min_days)->toBe($quote['eta_min_days']);
            expect($order->shipment->eta_max_days)->toBe($quote['eta_max_days']);
            expect($order->shipment->created_at->toDateTimeString())->toBe($order->created_at->toDateTimeString());
            foreach (['tracking_reference', 'preparing_at', 'ready_for_dispatch_at', 'handed_to_carrier_at', 'in_transit_at',
                'out_for_delivery_at', 'delivered_at', 'cancelled_at', 'eta_anchor_date', 'eta_timezone',
                'estimated_delivery_start', 'estimated_delivery_end'] as $field) {
                expect($order->shipment->getAttribute($field))->toBeNull();
            }
        }
        expect($order->payment_proof_path)->toBeNull();
        expect($order->sale()->count())->toBe(1);
        expect($order->items->count())->toBeBetween(1, 12);
        expect($order->items->pluck('product_id')->unique()->count())->toBe($order->items->count());
        $subtotal = '0.00';
        foreach ($order->items as $item) {
            expect($item->quantity)->toBeBetween(1, 3);
            expect($item->price_at_time)->toBe($item->product->price);
            $subtotal = bcadd($subtotal, bcmul($item->price_at_time, (string) $item->quantity, 2), 2);
        }
        expect($order->product_subtotal)->toBe($subtotal);
        expect($order->total_amount)->toBe(bcadd($subtotal, $order->delivery_fee, 2));
        expect($sale->amount)->toBe($order->total_amount);
    }
    expect(OrderItem::sum('quantity'))->toBe(1944);
    expect(bcadd((string) Sale::sum('amount'), '0', 2))->toBe('838450.00');
});

test('calendar variations retain known quantities and transaction totals', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    $observations = [];
    foreach (['2022-10', '2022-11', '2023-02', '2026-09'] as $month) {
        $observations[$month] = [
            array_map(fn ($product) => $product->orderItems()->whereHas('order.sale', fn ($query) => $query->whereBetween('sale_date', [$month.'-01', $month.'-28']))->sum('quantity'), array_values($products)),
            Sale::whereBetween('sale_date', [$month.'-01', $month.'-28'])->orderBy('sale_date')->pluck('amount')->all(),
        ];
    }
    expect($observations)->toBe([
        '2022-10' => [[1, 2, 3, 3, 3, 3, 3, 5, 3, 2, 2, 1, 1, 4, 5], ['4700.00', '3430.00', '3460.00', '6270.00']],
        '2022-11' => [[1, 2, 4, 1, 3, 2, 6, 4, 6, 3, 3, 3, 1, 7, 4], ['4310.00', '1970.00', '3590.00', '3530.00', '5790.00']],
        '2023-02' => [[1, 2, 1, 1, 3, 4, 5, 3, 3, 3, 1, 1, 1, 5, 4], ['1930.00', '6970.00', '3580.00', '830.00', '700.00']],
        '2026-09' => [[2, 2, 2, 2, 4, 2, 2, 3, 3, 4, 1, 2, 1, 5, 2], ['2710.00', '4070.00', '6210.00', '4310.00', '760.00', '530.00']],
    ]);
});

test('monthly transactions revenue and product demand vary within bounded retail assumptions', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $months = Sale::orderBy('sale_date')->get()->groupBy(fn ($sale) => $sale->sale_date->format('Y-m'));
    $counts = $months->map->count();
    $revenues = $months->map(fn ($sales) => bcadd((string) $sales->sum('amount'), '0', 2));

    expect($months)->toHaveCount(48);
    expect($counts->unique()->sort()->values()->all())->toBe([4, 5, 6, 7]);
    expect($revenues->unique()->count())->toBeGreaterThan(40);
    expect($counts->chunk(12)->map(fn ($year) => $year->values()->all())->unique()->count())->toBe(4);
    expect(Order::count())->toBe(259);
    expect(OrderItem::count())->toBe(1505);
    foreach ($products as $product) {
        $demand = $product->orderItems()->with('order.sale')->get()->groupBy(fn ($item) => $item->order->sale->sale_date->format('Y-m'))->map->sum('quantity');
        expect($demand)->toHaveCount(48);
        expect($demand->unique()->count())->toBeGreaterThan(1);
        foreach ($demand->chunk(12) as $year) {
            expect($year->filter(fn ($quantity) => $quantity > 0)->count())->toBeGreaterThanOrEqual(6);
        }
    }
    expect($products['10196']->orderItems()->count())->toBeGreaterThan($products['10003']->orderItems()->count());
    expect($products['10003']->orderItems()->pluck('quantity')->unique()->all())->toBe([1]);
});

test('historical payment and fulfillment distributions vary across calendar years with only supported combinations', function () {
    HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $orders = Order::orderBy('created_at')->get();
    $combinations = $orders->groupBy(fn ($order) => $order->fulfillment_method->value.'/'.$order->payment_method->value)->map->count()->sortKeys()->all();

    expect($combinations)->toBe([
        'delivery/gcash' => 37, 'delivery/maya' => 37,
        'pickup/card_at_store' => 31, 'pickup/cash' => 103, 'pickup/gcash' => 32, 'pickup/maya' => 19,
    ]);
    expect($orders->groupBy(fn ($order) => $order->created_at->year)
        ->map(fn ($year) => $year->groupBy(fn ($order) => $order->fulfillment_method->value.'/'.$order->payment_method->value)->map->count()->sortKeys()->all())
        ->unique()->count())->toBe(5);
    expect(Storage::disk('local')->allFiles('payment-proofs'))->toBe([]);
    $this->assertDatabaseCount('notifications', 0);
});

test('delivery snapshots retain highest product handling and remain immutable after configuration changes', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $products['10003']->update(['shipping_profile' => ShippingProfile::Fragile]);
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $order = Order::with('shipment')->where('fulfillment_method', FulfillmentMethod::Delivery)
        ->whereHas('items', fn ($query) => $query->where('product_id', $products['10003']->id))->firstOrFail();
    expect($order->shipping_profile)->toBe(ShippingProfile::Fragile);
    expect($order->handling_surcharge)->toBe('50.00');
    expect($order->shipment->preparation_days)->toBe(2);
    $products['10003']->update(['shipping_profile' => ShippingProfile::Standard]);
    config(['battlefront.delivery.destinations.Cadiz City.base_fee' => '999.00', 'battlefront.delivery.profiles.fragile.surcharge' => '500.00']);
    $before = historicalSalesDatabaseSnapshot();

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
});

test('damaged delivery or payment snapshots stop without reconciling any existing history', function (string $problem) {
    HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $order = Order::with('shipment')->where('fulfillment_method', FulfillmentMethod::Delivery)->firstOrFail();
    match ($problem) {
        'payment method' => $order->update(['payment_method' => PaymentMethod::Cash]),
        'payment evidence' => $order->update(['payment_proof_path' => 'payment-proofs/unrelated.png']),
        'delivery fee' => DB::table('orders')->where('id', $order->id)->update([
            'delivery_base_fee' => '0.00', 'handling_surcharge' => '0.00', 'delivery_fee' => '0.00', 'total_amount' => $order->product_subtotal,
        ]),
        'missing address' => DB::table('orders')->where('id', $order->id)->update(['delivery_address' => null]),
        'missing base fee' => DB::table('orders')->where('id', $order->id)->update([
            'delivery_destination' => null, 'delivery_base_fee' => null, 'shipping_profile' => null, 'handling_surcharge' => null,
            'delivery_origin_city' => null, 'delivery_is_demo' => null, 'delivery_assumption_label' => null,
            'delivery_fee' => '0.00', 'total_amount' => $order->product_subtotal,
        ]),
        'missing shipment' => $order->shipment->delete(),
        'shipment status' => $order->shipment->update(['status' => ShipmentStatus::Preparing]),
        'tracking evidence' => $order->shipment->update(['tracking_reference' => 'UNRELATED-TRACKING']),
        'invented milestone' => $order->shipment->update(['handed_to_carrier_at' => $order->created_at]),
        'missing timestamp' => $order->shipment->forceFill(['created_at' => null])->save(),
    };
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))
        ->toThrow(RuntimeException::class, 'battlefront:reset-dev --with-sales-history manually');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
    Storage::disk('local')->assertMissing(config('forecasting.development_manifest'));
})->with(['payment method', 'payment evidence', 'delivery fee', 'missing address', 'missing base fee', 'missing shipment', 'shipment status', 'tracking evidence', 'invented milestone', 'missing timestamp']);

test('historical delivery configuration failures roll back customer orders sales and shipments', function () {
    HistoricalSalesCatalogFixtures::createProducts();
    config(['battlefront.delivery.is_demo' => false]);
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))
        ->toThrow(RuntimeException::class, 'approved Sagay demo delivery configuration');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
    Storage::disk('local')->assertMissing(config('forecasting.development_manifest'));
});

test('repeated seeding preserves snapshots and unrelated transactions even on selected products', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $customer = User::where('email', 'historical-sales@example.test')->sole();
    $unrelatedSale = Sale::factory()->for(Order::factory()->for($customer)->state([
        'recipient_name' => 'Unrelated customer order', 'created_at' => '2025-02-10 12:30:00',
    ]))->create();
    OrderItem::factory()->for($unrelatedSale->order)->for($products['10003'])->create(['quantity' => 1]);
    $products['10003']->update(['price' => '3000.00', 'discount_price' => '2500.00', 'is_active' => false]);
    $products['10003']->inventory->update(['quantity' => 1, 'reorder_level' => 2]);
    $before = historicalSalesDatabaseSnapshot();
    $coverageBefore = Storage::disk('local')->get(config('forecasting.development_manifest'));
    $this->travelTo(CarbonImmutable::parse('2026-12-15 12:00:00', 'UTC'));

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
    expect(Storage::disk('local')->get(config('forecasting.development_manifest')))->toBe($coverageBefore);
    expect($products['10003']->orderItems()->whereHas('order', fn ($query) => $query->where('recipient_name', 'like', 'Demonstration Historical Sales %'))->pluck('price_at_time')->unique()->all())->toBe(['2800.00']);
});

test('historical seeding retains imported customer catalog and cart eligibility', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    expect(Product::query()->customerAvailable()->count())->toBe(15);
    expect(app(ProductCatalogRepository::class)->recommendationInputs()->get())->toHaveCount(15);
    $this->get(route('products.show', $products['10003']))->assertInertia(fn (Assert $page) => $page->where('product.name', $products['10003']->name));
    $customer = User::factory()->customer()->create();
    app(CartService::class)->add($customer, $products['10003']->id, 1);
    expect($products['10003']->inventory->refresh()->quantity)->toBe(14);
});

test('checkout still rejects a stale cart referencing a deactivated imported product with history', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $customer = User::factory()->customer()->create();
    $product = $products['10003'];
    $cartItem = CartItem::factory()->for($product)->for($customer->cart()->create())->create(['quantity' => 1]);
    $product->update(['is_active' => false]);

    expect(fn () => app(OrderPlacementService::class)->execute($customer, [
        'cart_item_ids' => [$cartItem->id],
        'recipient_name' => 'Customer', 'contact_number' => '09000000000',
        'fulfillment_method' => FulfillmentMethod::Pickup, 'delivery_address' => null,
        'payment_method' => PaymentMethod::Cash, 'payment_proof_path' => null,
    ]))->toThrow(OrderPlacementException::class, 'Review unavailable products or quantities in your cart before placing an order.');
    expect(fn () => app(CartService::class)->add($customer, $product->id, 1))->toThrow(CartOperationException::class);

    expect($customer->orders()->count())->toBe(0);
    expect($product->inventory->refresh()->quantity)->toBe(14);
    expect(Sale::count())->toBeBetween(150, 300);
    $this->assertDatabaseCount('cart_items', 1);
});

test('direct seeding rejects unsafe environments even with force without changing records or coverage', function (string $environment) {
    HistoricalSalesCatalogFixtures::createProducts();
    $this->app->instance('env', $environment);
    $before = historicalSalesDatabaseSnapshot();
    Storage::disk('local')->put(config('forecasting.development_manifest'), 'existing declaration');

    expect(fn () => $this->artisan('db:seed', [
        '--class' => DevelopmentHistoricalSalesSeeder::class, '--force' => true, '--no-interaction' => true,
    ])->run())->toThrow(RuntimeException::class, 'only allowed in local and testing');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
    expect(Storage::disk('local')->get(config('forecasting.development_manifest')))->toBe('existing declaration');
})->with(['production', 'staging']);

test('missing or nonimported selected products stop before customer and transaction writes', function (bool $missing, string $code) {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    if ($missing) {
        $products[$code]->inventory->delete();
        $products[$code]->delete();
    } else {
        $products[$code]->forceFill(['is_catalog_imported' => false])->save();
    }
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'existing imported product '.$code);

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
    Storage::disk('local')->assertMissing(config('forecasting.development_manifest'));
})->with([true, false])->with(['10003', '10463']);

test('reserved customer collisions roll back all historical writes', function () {
    HistoricalSalesCatalogFixtures::createProducts();
    User::factory()->administrator()->create(['email' => 'historical-sales@example.test']);
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'battlefront:reset-dev --with-sales-history manually');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
});

test('operational coverage cannot be supplemented with synthetic transactions for the same product', function () {
    HistoricalSalesCatalogFixtures::createProducts();
    config(['forecasting.operational_coverage' => ['10003' => [
        'granularity' => 'month', 'start' => '2022-10-01', 'end_exclusive' => '2026-10-01',
        'unavailable_months' => [], 'timezone' => 'UTC',
        'source_kind' => 'operational_prepared', 'sales_scope' => 'captured_system_transactions',
    ]]]);
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'conflicts with operational coverage');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
    expect(config('forecasting.operational_coverage.10003.source_kind'))->toBe('operational_prepared');
    Storage::disk('local')->assertMissing(config('forecasting.development_manifest'));
});

test('legacy products or historical transactions require a manual reset without any cleanup', function (string $legacy) {
    HistoricalSalesCatalogFixtures::createProducts();
    if ($legacy === 'product') {
        Product::factory()->create(['product_code' => 'DEVHIST40STABLE']);
    } elseif ($legacy === 'quarterly') {
        $this->seed(LegacyQuarterlySalesFixtures::class);
    } else {
        $customer = User::factory()->create(['email' => 'historical-sales@example.test']);
        Order::factory()->for($customer)->create(['recipient_name' => 'Historical Sales October 2022']);
    }
    Storage::disk('local')->put(config('forecasting.development_manifest'), 'legacy declaration');
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'battlefront:reset-dev --with-sales-history manually');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
    expect(Storage::disk('local')->get(config('forecasting.development_manifest')))->toBe('legacy declaration');
})->with(['product', 'quarterly', 'transaction']);

test('an earlier fixture stops with manual reset instructions without changing records or coverage', function (string $label) {
    HistoricalSalesCatalogFixtures::createProducts();
    $customer = User::factory()->customer()->create(['email' => 'historical-sales@example.test']);
    $sale = Sale::factory()->for(Order::factory()->for($customer)->state([
        'recipient_name' => $label, 'created_at' => '2022-10-07 10:00:00',
    ]))->create();
    OrderItem::factory()->for($sale->order)->create();
    Storage::disk('local')->put(config('forecasting.development_manifest'), 'prior declaration');
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))
        ->toThrow(RuntimeException::class, 'battlefront:reset-dev --with-sales-history manually');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
    expect(Storage::disk('local')->get(config('forecasting.development_manifest')))->toBe('prior declaration');
})->with(['Demonstration Historical Sales 2022-10 A', 'Demonstration Historical Sales 2022-10 V2 01']);

test('ambiguous or damaged owned history blocks reseeding and rollover without deleting anything', function (string $problem) {
    HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $order = Order::with(['items', 'sale'])->oldest()->firstOrFail();
    match ($problem) {
        'duplicate order' => Order::factory()->create(['user_id' => $order->user_id, 'recipient_name' => $order->recipient_name, 'created_at' => $order->created_at]),
        'foreign customer' => Order::factory()->create(['recipient_name' => $order->recipient_name]),
        'duplicate item' => OrderItem::factory()->for($order)->create(['product_id' => $order->items->first()->product_id]),
        'unrelated item' => OrderItem::factory()->for($order)->create(),
        'missing item' => $order->items->first()->delete(),
        'quantity' => $order->items->first()->update(['quantity' => 99]),
        'sale amount' => $order->sale->update(['amount' => '0.01']),
        'sale date' => $order->sale->update(['sale_date' => '2022-10-08']),
        'missing sale' => $order->sale->delete(),
        'status' => $order->update(['status' => OrderStatus::Processing]),
        'payment' => $order->update(['payment_status' => PaymentStatus::Pending]),
        'label' => $order->update(['recipient_name' => 'Changed label']),
        'timestamp' => $order->forceFill(['created_at' => '2022-10-08 12:00:00'])->save(),
    };
    $this->travelTo(CarbonImmutable::parse('2027-01-15'));
    $before = historicalSalesDatabaseSnapshot();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'battlefront:reset-dev --with-sales-history manually');

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
    Storage::disk('local')->assertMissing(config('forecasting.development_manifest'));
})->with(['duplicate order', 'foreign customer', 'duplicate item', 'unrelated item', 'missing item', 'quantity', 'sale amount', 'sale date', 'missing sale', 'status', 'payment', 'label', 'timestamp']);

test('a failure partway through transaction generation rolls back all database changes', function () {
    HistoricalSalesCatalogFixtures::createProducts();
    $before = historicalSalesDatabaseSnapshot();
    $event = 'eloquent.creating: '.Sale::class;
    Event::listen($event, function (): void {
        if (Sale::count() === 2) {
            throw new RuntimeException('Historical write failed.');
        }
    });

    try {
        expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'Historical write failed');
    } finally {
        Event::forget($event);
    }

    expect(historicalSalesDatabaseSnapshot())->toEqual($before);
    Storage::disk('local')->assertMissing(config('forecasting.development_manifest'));
});

test('historical seeding is available in local and snapshots existing effective prices including zero', function (string $price, ?string $discountPrice, string $expected) {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $products['10003']->update(['price' => $price, 'discount_price' => $discountPrice]);
    $this->app->instance('env', 'local');

    $this->artisan('db:seed', ['--class' => DevelopmentHistoricalSalesSeeder::class, '--no-interaction' => true])
        ->expectsOutputToContain('48 completed months; 15 imported products')
        ->assertSuccessful();

    expect($products['10003']->orderItems()->pluck('price_at_time')->unique()->all())->toBe([$expected]);
    expect(Sale::count())->toBeBetween(150, 300);
})->with([
    'regular price' => ['2800.00', null, '2800.00'],
    'discount price' => ['2800.00', '2500.00', '2500.00'],
    'zero price' => ['0.00', null, '0.00'],
]);

test('history includes only completed months before the quarter in the application timezone', function (string $reference, string $timezone, string $start, string $end) {
    HistoricalSalesCatalogFixtures::createProducts();
    config(['app.timezone' => $timezone]);
    $this->travelTo(CarbonImmutable::parse($reference, 'UTC'));

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    $dates = Sale::orderBy('sale_date')->get()->pluck('sale_date');
    expect($dates->first()->format('Y-m'))->toBe($start);
    expect($dates->last()->format('Y-m'))->toBe($end);
    expect($dates->map(fn ($date) => $date->format('Y-m'))->unique())->toHaveCount(48);
    expect($dates->count())->toBeBetween(150, 300);
    expect(Sale::whereDate('sale_date', '>=', now($timezone)->startOfQuarter()->toDateString())->exists())->toBeFalse();
})->with([
    'before quarter closes' => ['2026-09-30 23:59:59', 'UTC', '2022-07', '2026-06'],
    'quarter closes' => ['2026-10-01 00:00:00', 'UTC', '2022-10', '2026-09'],
    'new year' => ['2027-01-01 00:00:00', 'UTC', '2023-01', '2026-12'],
    'leap quarter' => ['2024-04-01 00:00:00', 'UTC', '2020-04', '2024-03'],
    'application ahead of UTC' => ['2026-09-30 16:00:00', 'Asia/Shanghai', '2022-10', '2026-09'],
]);

test('quarter rollover prunes only owned expired history and retains overlapping snapshots and inventory', function (string $nextReference, string $expectedStart, string $expectedEnd) {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $customer = User::where('email', 'historical-sales@example.test')->sole();
    $unrelatedSale = Sale::factory()->for(Order::factory()->for($customer)->state(['recipient_name' => 'Unrelated customer order', 'created_at' => '2025-02-10 12:30:00']))->create();
    OrderItem::factory()->for($unrelatedSale->order)->for($products['10003'])->create();
    $unrelated = $unrelatedSale->fresh()->load('order.items')->toArray();
    $originals = Order::with(['items', 'sale'])->where('id', '!=', $unrelatedSale->order_id)->get()->keyBy('recipient_name');
    $inventoryBefore = DB::table('inventories')->orderBy('id')->get()->all();
    $catalogBefore = DB::table('products')->orderBy('id')->get()->all();
    $this->travelTo(CarbonImmutable::parse($nextReference));

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    $sales = Sale::where('id', '!=', $unrelatedSale->id)->orderBy('sale_date')->get();
    expect($sales->count())->toBeBetween(150, 300);
    expect($sales->first()->sale_date->format('Y-m'))->toBe($expectedStart);
    expect($sales->last()->sale_date->format('Y-m'))->toBe($expectedEnd);
    $orders = Order::with(['items', 'sale'])->where('id', '!=', $unrelatedSale->order_id)->get();
    expect($orders->flatMap->items->count())->toBeGreaterThan(720);
    foreach ($orders as $order) {
        if (isset($originals[$order->recipient_name])) {
            expect($order->toArray())->toBe($originals[$order->recipient_name]->toArray());
        }
    }
    expect($unrelatedSale->fresh()->load('order.items')->toArray())->toBe($unrelated);
    expect(DB::table('inventories')->orderBy('id')->get()->all())->toEqual($inventoryBefore);
    expect(DB::table('products')->orderBy('id')->get()->all())->toEqual($catalogBefore);
    $snapshot = historicalSalesDatabaseSnapshot();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    expect(historicalSalesDatabaseSnapshot())->toEqual($snapshot);
})->with([
    'next quarter' => ['2027-01-15', '2023-01', '2026-12'],
    'beyond entire window' => ['2031-04-15', '2027-04', '2031-03'],
    'backward reference' => ['2025-04-15', '2021-04', '2025-03'],
]);
