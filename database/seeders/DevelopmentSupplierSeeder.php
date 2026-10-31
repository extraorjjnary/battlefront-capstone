<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevelopmentSupplierSeeder extends Seeder
{
    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('Supplier demonstration data is only allowed in local and testing environments.');
        }

        DB::transaction(function (): void {
            $codes = array_merge(...array_map(fn (array $supplier): array => array_keys($supplier['products']), self::catalog()));
            $products = Product::query()->whereIn('product_code', $codes)->lockForUpdate()->get()->keyBy('product_code');
            foreach (self::catalog() as $supplier) {
                foreach ($supplier['products'] as $code => $name) {
                    if (! $products->has($code)) {
                        throw new RuntimeException("Demo supplier setup requires existing product {$code}. No records were changed.");
                    }
                }
            }

            foreach (self::catalog() as $supplier) {
                $existing = DB::table('suppliers')->where('name', $supplier['name'])->lockForUpdate()->first();
                if ($existing !== null && $existing->email !== $supplier['email']) {
                    throw new RuntimeException('Demo supplier identity conflicts with existing data. No records were changed.');
                }
                $supplierId = $existing?->id;
                if ($supplierId === null) {
                    $supplierId = DB::table('suppliers')->insertGetId([
                        'name' => $supplier['name'],
                        'email' => $supplier['email'],
                        'contact_name' => 'Fictional demo contact',
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                foreach (array_keys($supplier['products']) as $code) {
                    DB::table('product_supplier')->insertOrIgnore([
                        'supplier_id' => $supplierId,
                        'product_id' => $products->get($code)->id,
                    ]);
                }
            }
        });

        $this->command?->info('Demo suppliers ready: 3 suppliers with saved relationships to 6 existing catalog products. Products and stock preserved. Selecting a supplier filters its linked products.');
        foreach (self::catalog() as $supplier) {
            $this->command?->info($supplier['name'].': '.implode(', ', array_keys($supplier['products'])));
        }
    }

    /** @return list<array{name: string, email: string, products: array<int|string, string>}> */
    public static function catalog(): array
    {
        return [
            ['name' => 'Demo Supplier - Components', 'email' => 'demo.components@example.test', 'products' => [
                '10035' => '(SURPLUS) SAMSUNG LAPTOP RAM DDR4 2666 4GB',
                '11026' => 'SSD ESGAMING 256GB 2.5 SATA',
            ]],
            ['name' => 'Demo Supplier - Peripherals', 'email' => 'demo.peripherals@example.test', 'products' => [
                '40705128' => 'Gaming Keyboard HP K300',
                '11070' => 'GAMING MOUSE MSI DS102 V3 RGB MOUSE (WHITE/BLACK)',
            ]],
            ['name' => 'Demo Supplier - Accessories', 'email' => 'demo.accessories@example.test', 'products' => [
                '10661' => 'USB HUB 3.0 4PORTS #303',
                '10468' => 'HDMI CABLE BLACK 1.5M ORIG.',
            ]],
        ];
    }
}
