<?php

namespace Tests;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;

class HistoricalSalesCatalogFixtures
{
    /** @return array<int|string, Product> */
    public static function createProducts(): array
    {
        $rows = [
            ['10003', 'KINGSTON 2.5 (960GB - SA400S37)', 'Storage', '2800.00', 14, 5],
            ['43720093', 'HDD SEAGATE BARRACUDA GREEN 1TB (ST1000DM010)', 'Storage', '2200.00', 14, 5],
            ['10035', '(SURPLUS) SAMSUNG LAPTOP RAM DDR4 2666 4GB', 'RAM', '900.00', 12, 5],
            ['6939084200089', 'RAM ST MAGIC DDR3 8GB 1600HZ', 'RAM', '750.00', 11, 5],
            ['6940056180506', 'RAPOO WIRED OPTICAL USB MOUSE N100', 'Peripherals', '180.00', 19, 8],
            ['87399741', 'MOUSEPAD SMALL MICROSOFT', 'Peripherals', '150.00', 17, 8],
            ['10196', 'USB 2.0 CABLE (AM TO AF) 1.5M (19153)', 'Cables & Adapters', '60.00', 22, 8],
            ['19986', 'VGA CABLE 1.5M SYBSUNG', 'Cables & Adapters', '100.00', 18, 8],
            ['10468', 'HDMI CABLE BLACK 1.5M ORIG.', 'Cables & Adapters', '100.00', 24, 8],
            ['10121', 'HDMI (Male) to VGA (Female) Adapter No Audio', 'Cables & Adapters', '150.00', 20, 8],
            ['10986', 'LAPTOP RAM ST MAGIC DDR3 8GB 1600HZ', 'RAM', '750.00', 13, 5],
            ['10214', 'CHARGER FOR LAPTOP HP 126P (4.5*3.0L)', 'Power Accessories', '450.00', 23, 8],
            ['10350', 'NOTEBOOK POWER ADAPTER', 'Power Accessories', '450.00', 24, 8],
            ['10463', 'THERMAL GREASE SMALL HY510', 'Cooling Components', '60.00', 10, 5],
            ['28909657', 'Computer Fan Small 80mm (SATA)', 'Cooling Components', '100.00', 11, 5],
        ];
        $categories = [];
        $products = [];
        foreach ($rows as [$code, $name, $category, $price, $quantity, $reorderLevel]) {
            $categories[$category] ??= Category::factory()->create(['name' => $category]);
            $product = Product::factory()->for($categories[$category])->create([
                'product_code' => $code, 'name' => $name, 'price' => $price, 'is_catalog_imported' => true,
            ]);
            Inventory::factory()->for($product)->create(['quantity' => $quantity, 'reorder_level' => $reorderLevel]);
            $products[$code] = $product;
        }

        return $products;
    }

    public static function unreadyProduct(string $scenario): Product
    {
        $product = Product::factory()->create(['price' => '10.00']);
        if ($scenario !== 'UNKNOWN') {
            $overrides = match ($scenario) {
                'SHORT' => ['start' => '2023-11-01'],
                'STALE' => ['end_exclusive' => '2026-09-01'],
                'GAPPED' => ['unavailable_months' => ['2026-04-01']],
                'SPARSE' => [],
                default => throw new \InvalidArgumentException('Unknown test history scenario.'),
            };
            config(['forecasting.operational_coverage.'.$product->product_code => array_replace([
                'granularity' => 'month', 'start' => '2023-10-01', 'end_exclusive' => '2026-10-01',
                'unavailable_months' => [], 'timezone' => 'UTC',
                'source_kind' => 'operational_prepared', 'sales_scope' => 'captured_system_transactions',
            ], $overrides)]);
        }
        $sale = Sale::factory()->create(['sale_date' => '2023-11-01', 'amount' => '10.00']);
        OrderItem::factory()->for($sale->order)->for($product)->create(['quantity' => 1, 'price_at_time' => '10.00']);

        return $product;
    }
}
