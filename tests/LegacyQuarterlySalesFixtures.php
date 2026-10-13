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
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Retain quarterly sales observations for reporting regression tests. */
class LegacyQuarterlySalesFixtures extends Seeder
{
    public function run(): void
    {
        if (! App::environment('testing')) {
            throw new RuntimeException('Legacy quarterly fixtures are test-only. No existing records were changed.');
        }
        if (User::query()->where('email', 'historical-sales@example.test')->exists()) {
            throw new RuntimeException('Legacy quarterly fixtures require an unused test identity. No existing records were changed.');
        }

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
}
