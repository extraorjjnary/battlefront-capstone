<?php

namespace Tests;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/** Retain quarterly sales observations for reporting regression tests. */
class LegacyQuarterlySalesFixtures extends Seeder
{
    public function run(): void
    {
        $end = CarbonImmutable::now(config('app.timezone'))->startOfQuarter();
        $start = $end->subQuarters(8);
        $customer = User::firstOrCreate(['email' => 'historical-sales@example.test'], [
            'name' => 'Historical Sales Development Account', 'role' => 'customer', 'password' => 'password',
        ]);
        $components = Category::firstOrCreate(['name' => 'Historical Sales Components'], ['is_active' => false]);
        $peripherals = Category::firstOrCreate(['name' => 'Historical Sales Peripherals'], ['is_active' => false]);
        $description = 'Development-only historical sales fixture for forecasting validation. Not part of the verified Battlefront catalog. Synthetic data; does not represent actual Battlefront historical sales.';
        $components->update(['description' => $description]);
        $peripherals->update(['description' => $description]);
        $patterns = [
            'STABLE' => [10, 10, 10, 10, 10, 10, 10, 10],
            'INCREASING' => [5, 10, 15, 20, 25, 30, 35, 40],
            'DECLINING' => [40, 35, 30, 25, 20, 15, 10, 5],
            'REPEATING' => [4, 8, 12, 20, 4, 8, 12, 20],
            'SPARSE' => [0, 0, 3, 0, 0, 6, 0, 9],
            'PRICE' => [4, 4, 4, 4, 4, 4, 4, 4],
            'NOHISTORY' => [0, 0, 0, 0, 0, 0, 0, 0],
        ];
        $prices = ['STABLE' => '1000.00', 'INCREASING' => '2000.00', 'DECLINING' => '1500.00', 'REPEATING' => '500.00', 'SPARSE' => '2500.00', 'PRICE' => '200.00', 'NOHISTORY' => '750.00'];
        $stocks = ['STABLE' => 100, 'INCREASING' => 50, 'DECLINING' => 20, 'REPEATING' => 15, 'SPARSE' => 0, 'PRICE' => 8, 'NOHISTORY' => 25];
        $products = [];
        $entries = [];
        foreach ($patterns as $code => $quantities) {
            $category = in_array($code, ['STABLE', 'INCREASING', 'DECLINING'], true) ? $components : $peripherals;
            $products[$code] = Product::firstOrCreate(['product_code' => 'DEVHIST40'.$code], [
                'name' => $code.' Reference Product', 'description' => $description, 'category_id' => $category->id,
                'price' => $prices[$code], 'is_active' => false, 'is_catalog_imported' => false,
            ]);
            Inventory::firstOrCreate(['product_id' => $products[$code]->id], ['quantity' => $stocks[$code], 'reorder_level' => 10]);
            $entries['DEVHIST40'.$code] = [
                'start' => $start->toDateString(), 'end_exclusive' => $end->toDateString(),
                'unavailable_quarters' => [], 'timezone' => config('app.timezone'),
                'source_kind' => 'synthetic_development', 'sales_scope' => 'development_fixture_transactions',
            ];
        }
        $customer->orders()->each(function (Order $order): void {
            $order->sale()->delete();
            $order->delete();
        });
        foreach (range(0, 7) as $index) {
            $quarter = $start->addQuarters($index);
            foreach ([$quarter, $quarter->endOfQuarter()] as $boundary => $date) {
                $order = Order::factory()->for($customer)->create([
                    'recipient_name' => 'Historical Sales Q'.$quarter->quarter.' '.$quarter->year,
                    'created_at' => $date->toDateTimeString(), 'status' => OrderStatus::Completed, 'total_amount' => '0.00',
                ]);
                $total = '0.00';
                foreach ($patterns as $code => $quantities) {
                    if ($code !== 'STABLE' && (($boundary === 0) !== in_array($code, ['INCREASING', 'DECLINING'], true))) {
                        continue;
                    }
                    $quantity = $code === 'STABLE' ? ($boundary === 0 ? 4 : 6) : $quantities[$index];
                    if ($quantity === 0) {
                        continue;
                    }
                    $price = $code === 'PRICE' ? ['100.00', '100.00', '125.00', '125.00', '150.00', '150.00', '175.00', '175.00'][$index] : $prices[$code];
                    OrderItem::factory()->for($order)->for($products[$code])->create(['quantity' => $quantity, 'price_at_time' => $price]);
                    $total = bcadd($total, bcmul($price, (string) $quantity, 2), 2);
                }
                $order->update(['total_amount' => $total]);
                Sale::factory()->for($order)->create(['amount' => $total, 'sale_date' => $date->toDateString()]);
            }
        }
        Storage::disk('local')->put(config('forecasting.development_manifest'), json_encode(['version' => 1, 'products' => $entries], JSON_THROW_ON_ERROR));
    }

    /** Isolate accepted quarterly consumer behavior while the real repository becomes monthly-only. */
    public static function bindCoverage(): void
    {
        $mock = \Mockery::mock(SalesHistoryCoverageRepository::class)->makePartial();
        app()->instance(SalesHistoryCoverageRepository::class, $mock);
        $mock->shouldReceive('forProductCode')->andReturnUsing(function (string $code): ?array {
            $operational = config('forecasting.operational_coverage', []);
            $contents = Storage::disk('local')->get(config('forecasting.development_manifest'));
            $development = app()->environment(['local', 'testing']) && $contents !== null ? (json_decode($contents, true)['products'] ?? []) : [];
            if (isset($operational[$code], $development[$code])) {
                return null;
            }
            $entry = $operational[$code] ?? $development[$code] ?? null;
            if (! is_array($entry) || ($entry['timezone'] ?? null) !== config('app.timezone')
                || ! in_array($entry['source_kind'] ?? null, ['operational_prepared', 'synthetic_development'], true)
                || ! in_array($entry['sales_scope'] ?? null, ['all_sagay_sales', 'captured_system_transactions', 'development_fixture_transactions'], true)) {
                return null;
            }
            foreach (['start', 'end_exclusive'] as $key) {
                if (! self::quarterDate($entry[$key] ?? null)) {
                    return null;
                }
            }
            $gaps = $entry['unavailable_quarters'] ?? [];
            if ($entry['start'] > $entry['end_exclusive'] || ! is_array($gaps) || (array_key_exists('unavailable_quarters', $entry) && $entry['unavailable_quarters'] === null)) {
                return null;
            }
            foreach ($gaps as $gap) {
                if (! self::quarterDate($gap) || $gap < $entry['start'] || $gap >= $entry['end_exclusive']) {
                    return null;
                }
            }

            return $entry;
        });
    }

    private static function quarterDate(mixed $date): bool
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return false;
        }
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'));

            return $parsed !== null && $parsed->toDateString() === $date && $parsed->equalTo($parsed->startOfQuarter());
        } catch (\Throwable) {
            return false;
        }
    }
}
