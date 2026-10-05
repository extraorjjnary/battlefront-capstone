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
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\LegacyQuarterlySalesFixtures;

beforeEach(function () {
    Storage::fake('local');
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

/**
 * @return list<array{start: string, end: string, quantity: int, amount: string, components: string, peripherals: string}>
 */
function historicalSalesExpectedMonths(): array
{
    return [
        ['start' => '2022-10-01', 'end' => '2022-10-31', 'quantity' => 228, 'amount' => '293800.00', 'components' => '256000.00', 'peripherals' => '37800.00'],
        ['start' => '2022-11-01', 'end' => '2022-11-30', 'quantity' => 202, 'amount' => '266900.00', 'components' => '249000.00', 'peripherals' => '17900.00'],
        ['start' => '2022-12-01', 'end' => '2022-12-31', 'quantity' => 218, 'amount' => '282900.00', 'components' => '246000.00', 'peripherals' => '36900.00'],
        ['start' => '2023-01-01', 'end' => '2023-01-31', 'quantity' => 190, 'amount' => '253400.00', 'components' => '239000.00', 'peripherals' => '14400.00'],
        ['start' => '2023-02-01', 'end' => '2023-02-28', 'quantity' => 204, 'amount' => '269400.00', 'components' => '244000.00', 'peripherals' => '25400.00'],
        ['start' => '2023-03-01', 'end' => '2023-03-31', 'quantity' => 201, 'amount' => '273000.00', 'components' => '249000.00', 'peripherals' => '24000.00'],
        ['start' => '2023-04-01', 'end' => '2023-04-30', 'quantity' => 218, 'amount' => '288500.00', 'components' => '258000.00', 'peripherals' => '30500.00'],
        ['start' => '2023-05-01', 'end' => '2023-05-31', 'quantity' => 214, 'amount' => '287600.00', 'components' => '267000.00', 'peripherals' => '20600.00'],
        ['start' => '2023-06-01', 'end' => '2023-06-30', 'quantity' => 236, 'amount' => '312600.00', 'components' => '276000.00', 'peripherals' => '36600.00'],
        ['start' => '2023-07-01', 'end' => '2023-07-31', 'quantity' => 230, 'amount' => '309700.00', 'components' => '285000.00', 'peripherals' => '24700.00'],
        ['start' => '2023-08-01', 'end' => '2023-08-31', 'quantity' => 242, 'amount' => '321700.00', 'components' => '282000.00', 'peripherals' => '39700.00'],
        ['start' => '2023-09-01', 'end' => '2023-09-30', 'quantity' => 227, 'amount' => '319300.00', 'components' => '275000.00', 'peripherals' => '44300.00'],
        ['start' => '2023-10-01', 'end' => '2023-10-31', 'quantity' => 233, 'amount' => '310800.00', 'components' => '268000.00', 'peripherals' => '42800.00'],
        ['start' => '2023-11-01', 'end' => '2023-11-30', 'quantity' => 217, 'amount' => '293900.00', 'components' => '271000.00', 'peripherals' => '22900.00'],
        ['start' => '2023-12-01', 'end' => '2023-12-31', 'quantity' => 233, 'amount' => '309900.00', 'components' => '268000.00', 'peripherals' => '41900.00'],
        ['start' => '2024-01-01', 'end' => '2024-01-31', 'quantity' => 205, 'amount' => '280400.00', 'components' => '261000.00', 'peripherals' => '19400.00'],
        ['start' => '2024-02-01', 'end' => '2024-02-29', 'quantity' => 219, 'amount' => '296400.00', 'components' => '266000.00', 'peripherals' => '30400.00'],
        ['start' => '2024-03-01', 'end' => '2024-03-31', 'quantity' => 216, 'amount' => '300000.00', 'components' => '271000.00', 'peripherals' => '29000.00'],
        ['start' => '2024-04-01', 'end' => '2024-04-30', 'quantity' => 233, 'amount' => '315500.00', 'components' => '280000.00', 'peripherals' => '35500.00'],
        ['start' => '2024-05-01', 'end' => '2024-05-31', 'quantity' => 229, 'amount' => '314600.00', 'components' => '289000.00', 'peripherals' => '25600.00'],
        ['start' => '2024-06-01', 'end' => '2024-06-30', 'quantity' => 251, 'amount' => '339600.00', 'components' => '298000.00', 'peripherals' => '41600.00'],
        ['start' => '2024-07-01', 'end' => '2024-07-31', 'quantity' => 245, 'amount' => '336700.00', 'components' => '307000.00', 'peripherals' => '29700.00'],
        ['start' => '2024-08-01', 'end' => '2024-08-31', 'quantity' => 257, 'amount' => '348700.00', 'components' => '304000.00', 'peripherals' => '44700.00'],
        ['start' => '2024-09-01', 'end' => '2024-09-30', 'quantity' => 242, 'amount' => '346300.00', 'components' => '297000.00', 'peripherals' => '49300.00'],
        ['start' => '2024-10-01', 'end' => '2024-10-31', 'quantity' => 308, 'amount' => '397800.00', 'components' => '290000.00', 'peripherals' => '107800.00'],
        ['start' => '2024-11-01', 'end' => '2024-11-30', 'quantity' => 207, 'amount' => '295900.00', 'components' => '283000.00', 'peripherals' => '12900.00'],
        ['start' => '2024-12-01', 'end' => '2024-12-31', 'quantity' => 338, 'amount' => '426900.00', 'components' => '280000.00', 'peripherals' => '146900.00'],
        ['start' => '2025-01-01', 'end' => '2025-01-31', 'quantity' => 198, 'amount' => '285400.00', 'components' => '273000.00', 'peripherals' => '12400.00'],
        ['start' => '2025-02-01', 'end' => '2025-02-28', 'quantity' => 294, 'amount' => '383400.00', 'components' => '278000.00', 'peripherals' => '105400.00'],
        ['start' => '2025-03-01', 'end' => '2025-03-31', 'quantity' => 207, 'amount' => '303000.00', 'components' => '283000.00', 'peripherals' => '20000.00'],
        ['start' => '2025-04-01', 'end' => '2025-04-30', 'quantity' => 368, 'amount' => '462500.00', 'components' => '292000.00', 'peripherals' => '170500.00'],
        ['start' => '2025-05-01', 'end' => '2025-05-31', 'quantity' => 226, 'amount' => '323600.00', 'components' => '301000.00', 'peripherals' => '22600.00'],
        ['start' => '2025-06-01', 'end' => '2025-06-30', 'quantity' => 336, 'amount' => '436600.00', 'components' => '310000.00', 'peripherals' => '126600.00'],
        ['start' => '2025-07-01', 'end' => '2025-07-31', 'quantity' => 234, 'amount' => '337700.00', 'components' => '319000.00', 'peripherals' => '18700.00'],
        ['start' => '2025-08-01', 'end' => '2025-08-31', 'quantity' => 372, 'amount' => '475700.00', 'components' => '316000.00', 'peripherals' => '159700.00'],
        ['start' => '2025-09-01', 'end' => '2025-09-30', 'quantity' => 236, 'amount' => '352300.00', 'components' => '309000.00', 'peripherals' => '43300.00'],
        ['start' => '2025-10-01', 'end' => '2025-10-31', 'quantity' => 233, 'amount' => '334800.00', 'components' => '302000.00', 'peripherals' => '32800.00'],
        ['start' => '2025-11-01', 'end' => '2025-11-30', 'quantity' => 207, 'amount' => '307900.00', 'components' => '295000.00', 'peripherals' => '12900.00'],
        ['start' => '2025-12-01', 'end' => '2025-12-31', 'quantity' => 223, 'amount' => '323900.00', 'components' => '292000.00', 'peripherals' => '31900.00'],
        ['start' => '2026-01-01', 'end' => '2026-01-31', 'quantity' => 195, 'amount' => '294400.00', 'components' => '285000.00', 'peripherals' => '9400.00'],
        ['start' => '2026-02-01', 'end' => '2026-02-28', 'quantity' => 209, 'amount' => '310400.00', 'components' => '290000.00', 'peripherals' => '20400.00'],
        ['start' => '2026-03-01', 'end' => '2026-03-31', 'quantity' => 206, 'amount' => '314000.00', 'components' => '295000.00', 'peripherals' => '19000.00'],
        ['start' => '2026-04-01', 'end' => '2026-04-30', 'quantity' => 338, 'amount' => '444500.00', 'components' => '304000.00', 'peripherals' => '140500.00'],
        ['start' => '2026-05-01', 'end' => '2026-05-31', 'quantity' => 334, 'amount' => '443600.00', 'components' => '313000.00', 'peripherals' => '130600.00'],
        ['start' => '2026-06-01', 'end' => '2026-06-30', 'quantity' => 356, 'amount' => '468600.00', 'components' => '322000.00', 'peripherals' => '146600.00'],
        ['start' => '2026-07-01', 'end' => '2026-07-31', 'quantity' => 350, 'amount' => '465700.00', 'components' => '331000.00', 'peripherals' => '134700.00'],
        ['start' => '2026-08-01', 'end' => '2026-08-31', 'quantity' => 362, 'amount' => '477700.00', 'components' => '328000.00', 'peripherals' => '149700.00'],
        ['start' => '2026-09-01', 'end' => '2026-09-30', 'quantity' => 347, 'amount' => '475300.00', 'components' => '321000.00', 'peripherals' => '154300.00'],
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
    expect($customer->orders()->count())->toBe(96);
    expect($administrator->orders()->count())->toBe(0);
    expect($administrator->refresh()->role)->toBe(UserRole::Administrator);
    expect(Category::query()->orderBy('name')->pluck('name')->all())->toBe([
        'Historical Sales Components', 'Historical Sales Peripherals',
    ]);
    $this->assertDatabaseCount('products', 13);
    $this->assertDatabaseCount('inventories', 13);
    $this->assertDatabaseCount('orders', 96);
    $this->assertDatabaseCount('order_items', 547);
    $this->assertDatabaseCount('sales', 96);
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
        expect($product->created_at->toDateTimeString())->toBe('2022-09-30 00:00:00');
    }
});

test('historical transactions use exact calendar boundaries and consistent completed orders', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    foreach (historicalSalesExpectedMonths() as $index => $quarter) {
        $sales = Sale::with(['order.items', 'order.user'])->whereDate('sale_date', '>=', $quarter['start'])->whereDate('sale_date', '<=', $quarter['end'])->orderBy('sale_date')->get();
        expect($sales)->toHaveCount(2);
        expect($sales->pluck('sale_date')->map->toDateString()->all())->toBe([$quarter['start'], $quarter['end']]);
        expect($sales->pluck('order.created_at')->map->toDateTimeString()->all())->toBe([
            $quarter['start'].' 00:00:00', $quarter['end'].' 23:59:59',
        ]);

        foreach ($sales as $sale) {
            $order = $sale->order;
            expect($order->recipient_name)->toBe('Historical Sales '.$sale->sale_date->format('F Y'));
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

    foreach (historicalSalesExpectedMonths() as $index => $quarter) {
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
    'stable' => ['STABLE', [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10], ['1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00'], ['10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00'], 100, '1000.00'],
    'increasing' => ['INCREASING', [18, 16, 16, 14, 18, 22, 28, 34, 40, 46, 46, 44, 42, 40, 40, 38, 42, 46, 52, 58, 64, 70, 70, 68, 66, 64, 64, 62, 66, 70, 76, 82, 88, 94, 94, 92, 90, 88, 88, 86, 90, 94, 100, 106, 112, 118, 118, 116], ['2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00', '2000.00'], ['36000.00', '32000.00', '32000.00', '28000.00', '36000.00', '44000.00', '56000.00', '68000.00', '80000.00', '92000.00', '92000.00', '88000.00', '84000.00', '80000.00', '80000.00', '76000.00', '84000.00', '92000.00', '104000.00', '116000.00', '128000.00', '140000.00', '140000.00', '136000.00', '132000.00', '128000.00', '128000.00', '124000.00', '132000.00', '140000.00', '152000.00', '164000.00', '176000.00', '188000.00', '188000.00', '184000.00', '180000.00', '176000.00', '176000.00', '172000.00', '180000.00', '188000.00', '200000.00', '212000.00', '224000.00', '236000.00', '236000.00', '232000.00'], 50, '2000.00'],
    'declining' => ['DECLINING', [120, 118, 116, 114, 112, 110, 108, 106, 104, 102, 100, 98, 96, 94, 92, 90, 88, 86, 84, 82, 80, 78, 76, 74, 72, 70, 68, 66, 64, 62, 60, 58, 56, 54, 52, 50, 48, 46, 44, 42, 40, 38, 36, 34, 32, 30, 28, 26], ['1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00', '1500.00'], ['180000.00', '177000.00', '174000.00', '171000.00', '168000.00', '165000.00', '162000.00', '159000.00', '156000.00', '153000.00', '150000.00', '147000.00', '144000.00', '141000.00', '138000.00', '135000.00', '132000.00', '129000.00', '126000.00', '123000.00', '120000.00', '117000.00', '114000.00', '111000.00', '108000.00', '105000.00', '102000.00', '99000.00', '96000.00', '93000.00', '90000.00', '87000.00', '84000.00', '81000.00', '78000.00', '75000.00', '72000.00', '69000.00', '66000.00', '63000.00', '60000.00', '57000.00', '54000.00', '51000.00', '48000.00', '45000.00', '42000.00', '39000.00'], 20, '1500.00'],
    'repeating' => ['REPEATING', [18, 14, 12, 8, 10, 12, 16, 20, 24, 28, 26, 22, 18, 14, 12, 8, 10, 12, 16, 20, 24, 28, 26, 22, 18, 14, 12, 8, 10, 12, 16, 20, 24, 28, 26, 22, 18, 14, 12, 8, 10, 12, 16, 20, 24, 28, 26, 22], ['500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00', '500.00'], ['9000.00', '7000.00', '6000.00', '4000.00', '5000.00', '6000.00', '8000.00', '10000.00', '12000.00', '14000.00', '13000.00', '11000.00', '9000.00', '7000.00', '6000.00', '4000.00', '5000.00', '6000.00', '8000.00', '10000.00', '12000.00', '14000.00', '13000.00', '11000.00', '9000.00', '7000.00', '6000.00', '4000.00', '5000.00', '6000.00', '8000.00', '10000.00', '12000.00', '14000.00', '13000.00', '11000.00', '9000.00', '7000.00', '6000.00', '4000.00', '5000.00', '6000.00', '8000.00', '10000.00', '12000.00', '14000.00', '13000.00', '11000.00'], 15, '500.00'],
    'sparse' => ['SPARSE', [0, 0, 0, 0, 0, 3, 0, 0, 0, 0, 0, 9, 0, 0, 0, 0, 0, 3, 0, 0, 0, 0, 0, 9, 0, 0, 0, 0, 0, 3, 0, 0, 0, 0, 0, 9, 0, 0, 0, 0, 0, 3, 0, 0, 0, 0, 0, 9], [null, null, null, null, null, '2500.00', null, null, null, null, null, '2500.00', null, null, null, null, null, '2500.00', null, null, null, null, null, '2500.00', null, null, null, null, null, '2500.00', null, null, null, null, null, '2500.00', null, null, null, null, null, '2500.00', null, null, null, null, null, '2500.00'], ['0.00', '0.00', '0.00', '0.00', '0.00', '7500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '22500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '7500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '22500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '7500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '22500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '7500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '22500.00'], 0, '2500.00'],
    'price' => ['PRICE', [4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4], ['200.00', '225.00', '225.00', '100.00', '100.00', '125.00', '125.00', '150.00', '150.00', '175.00', '175.00', '200.00', '200.00', '225.00', '225.00', '100.00', '100.00', '125.00', '125.00', '150.00', '150.00', '175.00', '175.00', '200.00', '200.00', '225.00', '225.00', '100.00', '100.00', '125.00', '125.00', '150.00', '150.00', '175.00', '175.00', '200.00', '200.00', '225.00', '225.00', '100.00', '100.00', '125.00', '125.00', '150.00', '150.00', '175.00', '175.00', '200.00'], ['800.00', '900.00', '900.00', '400.00', '400.00', '500.00', '500.00', '600.00', '600.00', '700.00', '700.00', '800.00', '800.00', '900.00', '900.00', '400.00', '400.00', '500.00', '500.00', '600.00', '600.00', '700.00', '700.00', '800.00', '800.00', '900.00', '900.00', '400.00', '400.00', '500.00', '500.00', '600.00', '600.00', '700.00', '700.00', '800.00', '800.00', '900.00', '900.00', '400.00', '400.00', '500.00', '500.00', '600.00', '600.00', '700.00', '700.00', '800.00'], 8, '200.00'],
    'nohistory' => ['NOHISTORY', [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], [null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null], ['0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00'], 25, '750.00'],
    'mixedzero' => ['MIXEDZERO', [18, 0, 20, 0, 10, 0, 12, 0, 14, 0, 16, 0, 18, 0, 20, 0, 10, 0, 12, 0, 14, 0, 16, 0, 18, 0, 20, 0, 10, 0, 12, 0, 14, 0, 16, 0, 18, 0, 20, 0, 10, 0, 12, 0, 14, 0, 16, 0], ['1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null, '1000.00', null], ['18000.00', '0.00', '20000.00', '0.00', '10000.00', '0.00', '12000.00', '0.00', '14000.00', '0.00', '16000.00', '0.00', '18000.00', '0.00', '20000.00', '0.00', '10000.00', '0.00', '12000.00', '0.00', '14000.00', '0.00', '16000.00', '0.00', '18000.00', '0.00', '20000.00', '0.00', '10000.00', '0.00', '12000.00', '0.00', '14000.00', '0.00', '16000.00', '0.00', '18000.00', '0.00', '20000.00', '0.00', '10000.00', '0.00', '12000.00', '0.00', '14000.00', '0.00', '16000.00', '0.00'], 50, '1000.00'],
    'abrupt' => ['ABRUPT', [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 80, 5, 120, 8, 90, 6, 150, 12, 100, 4, 130, 9, 5, 5, 5, 5, 5, 5, 120, 120, 120, 120, 120, 120], ['1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00'], ['10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '15000.00', '15000.00', '15000.00', '15000.00', '15000.00', '15000.00', '15000.00', '15000.00', '15000.00', '15000.00', '15000.00', '15000.00', '80000.00', '5000.00', '120000.00', '8000.00', '90000.00', '6000.00', '150000.00', '12000.00', '100000.00', '4000.00', '130000.00', '9000.00', '5000.00', '5000.00', '5000.00', '5000.00', '5000.00', '5000.00', '120000.00', '120000.00', '120000.00', '120000.00', '120000.00', '120000.00'], 30, '1000.00'],
    'short' => ['SHORT', [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10], [null, null, null, null, null, null, null, null, null, null, null, null, null, '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00'], ['0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00'], 10, '1000.00'],
    'unknown' => ['UNKNOWN', [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10], ['1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00'], ['10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00'], 10, '1000.00'],
    'stale' => ['STALE', [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10], ['1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00'], ['10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00'], 10, '1000.00'],
    'gapped' => ['GAPPED', [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10], ['1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00', '1000.00'], ['10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00'], 10, '1000.00'],
]);
test('monthly product and category totals are exactly derivable from recorded sales', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    foreach (historicalSalesExpectedMonths() as $quarter) {
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
        expect($groups->flatten()->sum('quantity'))->toBe($quarter['quantity']);
    }
    expect(OrderItem::query()->sum('quantity'))->toBe(12074);
    expect(bcadd((string) Sale::query()->sum('amount'), '0.00', 2))->toBe('16413200.00');
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
    $this->assertDatabaseCount('sales', 96);
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
        ->expectsOutput('Synthetic development sales history: October 2022 - September 2026 (48 completed months).')
        ->assertSuccessful();

    $this->assertDatabaseCount('sales', 96);
});

test('history uses only completed months before the current quarter in the application timezone', function (string $reference, string $timezone, string $start, string $end) {
    config(['app.timezone' => $timezone]);
    $this->travelTo(CarbonImmutable::parse($reference, 'UTC'));

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    $dates = Sale::query()->orderBy('sale_date')->get()->pluck('sale_date');
    expect($dates->first()->toDateString())->toBe($start);
    expect($dates->last()->toDateString())->toBe($end);
    expect($dates->map(fn ($date) => $date->format('Y-m'))->unique())->toHaveCount(48);
    expect($dates)->toHaveCount(96);
    expect(Sale::query()->whereDate('sale_date', '>=', now($timezone)->startOfQuarter()->toDateString())->exists())->toBeFalse();
})->with([
    'before quarter closes' => ['2026-09-30 23:59:59', 'UTC', '2022-07-01', '2026-06-30'],
    'quarter closes' => ['2026-10-01 00:00:00', 'UTC', '2022-10-01', '2026-09-30'],
    'new year' => ['2027-01-01 00:00:00', 'UTC', '2023-01-01', '2026-12-31'],
    'leap day' => ['2024-02-29 12:00:00', 'UTC', '2020-01-01', '2023-12-31'],
    'completed leap quarter' => ['2024-04-01 00:00:00', 'UTC', '2020-04-01', '2024-03-31'],
    'application ahead of UTC' => ['2026-09-30 16:00:00', 'Asia/Shanghai', '2022-10-01', '2026-09-30'],
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
    expect($sales)->toHaveCount(96);
    expect($sales->first()->sale_date->toDateString())->toBe($expectedStart);
    expect($sales->last()->sale_date->toDateString())->toBe($expectedEnd);
    expect($sales->flatMap(fn ($sale) => $sale->order->items))->toHaveCount(547);
    expect($sales->flatMap(fn ($sale) => $sale->order->items)->sum('quantity'))->toBe(12074);
    expect($sales->reduce(fn (string $total, Sale $sale): string => bcadd($total, $sale->amount, 2), '0.00'))->toBe('16413200.00');
    $increasingId = Product::where('product_code', 'DEVHIST40INCREASING')->sole()->id;
    $sparseId = Product::where('product_code', 'DEVHIST40SPARSE')->sole()->id;
    foreach ($sales->chunk(2)->values() as $index => $quarterSales) {
        $items = $quarterSales->flatMap(fn ($sale) => $sale->order->items);
        expect($items->where('product_id', $increasingId)->sum('quantity'))->toBe([8, 10, 12, 16, 20, 24, 28, 26, 22, 18, 14, 12][$quarterSales->first()->sale_date->month - 1] + 2 * $index);
        expect($items->where('product_id', $sparseId)->sum('quantity'))->toBe(match ($quarterSales->first()->sale_date->month) {
            3 => 3, 9 => 9, default => 0
        });
        expect($quarterSales)->toHaveCount(2);
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
    'next quarter removes sparse items from retained orders' => ['2026-10-15', '2027-01-15', '2023-01-01', '2026-12-31'],
    'jump beyond entire window' => ['2026-10-15', '2029-04-15', '2025-04-01', '2029-03-31'],
    'backward reference' => ['2026-10-15', '2025-04-15', '2021-04-01', '2025-03-31'],
    'previous fixed period' => ['2026-01-15', '2026-10-15', '2022-10-01', '2026-09-30'],
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

test('explicit monthly preparation replaces owned legacy quarterly transactions without mixing histories', function () {
    $this->seed(LegacyQuarterlySalesFixtures::class);
    $legacyIds = Order::pluck('id')->all();
    $productIds = Product::pluck('id', 'product_code')->all();

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    expect(Order::whereIn('id', $legacyIds)->exists())->toBeFalse();
    expect(Order::where('recipient_name', 'like', 'Historical Sales Q%')->exists())->toBeFalse();
    foreach ($productIds as $code => $id) {
        expect(Product::where('product_code', $code)->sole()->id)->toBe($id);
    }
    $this->assertDatabaseCount('sales', 96);
    expect(OrderItem::sum('quantity'))->toBe(12074);
});

test('monthly history includes leap boundaries and annual profiles stay aligned across quarter rollover', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    expect(Sale::whereDate('sale_date', '2024-02-29')->count())->toBe(1);
    $product = Product::where('product_code', 'DEVHIST40REPEATING')->sole();
    $quantity = fn (string $start, string $end): int => $product->orderItems()
        ->whereHas('order.sale', fn ($query) => $query->whereDate('sale_date', '>=', $start)->whereDate('sale_date', '<', $end))->sum('quantity');
    expect($quantity('2026-01-01', '2026-02-01'))->toBe(8);
    expect($quantity('2026-07-01', '2026-08-01'))->toBe(28);

    $this->travelTo(CarbonImmutable::parse('2027-01-15'));
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    expect($quantity('2026-01-01', '2026-02-01'))->toBe(8);
    expect($quantity('2026-07-01', '2026-08-01'))->toBe(28);
});
