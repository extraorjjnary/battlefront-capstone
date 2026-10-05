<?php

namespace Database\Seeders;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevelopmentHistoricalSalesSeeder extends Seeder
{
    private const DESCRIPTION = 'Development-only historical sales fixture for forecasting validation. Not part of the verified Battlefront catalog. Synthetic data; does not represent actual Battlefront historical sales.';

    /**
     * Seed synthetic sales history. Customer ownership satisfies Order persistence
     * only; customer identity is not a forecasting input.
     */
    public function run(SalesHistoryCoverageRepository $coverage): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('Historical sales development data is only allowed in local and testing environments.');
        }

        $historyEnd = CarbonImmutable::instance(now(config('app.timezone')))->startOfQuarter();
        $firstMonth = $historyEnd->subMonths(48);

        $coverage->clearDevelopment();
        $productCodes = DB::transaction(function () use ($firstMonth, $historyEnd): array {
            $customer = $this->seedCustomer($firstMonth->subDay());
            $products = $this->seedProducts($firstMonth->subDay());
            $this->removeObsoleteHistory($customer, $products, $firstMonth, $historyEnd);

            foreach (range(0, 47) as $monthIndex) {
                $start = $firstMonth->addMonths($monthIndex);
                $calendarIndex = $start->month - 1;
                $seasonalQuantity = [8, 10, 12, 16, 20, 24, 28, 26, 22, 18, 14, 12][$calendarIndex];
                $label = 'Historical Sales '.$start->format('F Y');

                $this->seedOrder($customer, $label, $start, [
                    ['product' => $products['STABLE'], 'quantity' => 4, 'price' => '1000.00'],
                    ['product' => $products['INCREASING'], 'quantity' => $seasonalQuantity + 2 * $monthIndex, 'price' => '2000.00'],
                    ['product' => $products['DECLINING'], 'quantity' => 120 - 2 * $monthIndex, 'price' => '1500.00'],
                ]);

                $closingItems = [
                    ['product' => $products['STABLE'], 'quantity' => 6, 'price' => '1000.00'],
                    ['product' => $products['REPEATING'], 'quantity' => $seasonalQuantity, 'price' => '500.00'],
                    ['product' => $products['PRICE'], 'quantity' => 4, 'price' => ['100.00', '100.00', '125.00', '125.00', '150.00', '150.00', '175.00', '175.00', '200.00', '200.00', '225.00', '225.00'][$calendarIndex]],
                ];
                $closingItems[] = ['product' => $products['ABRUPT'], 'quantity' => [...array_fill(0, 12, 10), ...array_fill(0, 12, 15), 80, 5, 120, 8, 90, 6, 150, 12, 100, 4, 130, 9, ...array_fill(0, 6, 5), ...array_fill(0, 6, 120)][$monthIndex], 'price' => '1000.00'];
                foreach (['UNKNOWN', 'STALE', 'GAPPED'] as $scenario) {
                    $closingItems[] = ['product' => $products[$scenario], 'quantity' => 10, 'price' => '1000.00'];
                }
                if ($monthIndex >= 13) {
                    $closingItems[] = ['product' => $products['SHORT'], 'quantity' => 10, 'price' => '1000.00'];
                }
                $mixedQuantity = [0, 10, 0, 12, 0, 14, 0, 16, 0, 18, 0, 20][$calendarIndex];
                if ($mixedQuantity > 0) {
                    $closingItems[] = ['product' => $products['MIXEDZERO'], 'quantity' => $mixedQuantity, 'price' => '1000.00'];
                }
                $sparseQuantity = match ($start->month) {
                    3 => 3,
                    9 => 9,
                    default => 0,
                };
                if ($sparseQuantity > 0) {
                    $closingItems[] = ['product' => $products['SPARSE'], 'quantity' => $sparseQuantity, 'price' => '2500.00'];
                }

                $this->seedOrder($customer, $label, $start->endOfMonth(), $closingItems);
            }

            return array_map(fn (Product $product): string => $product->product_code, $products);
        });

        $entries = [];
        foreach ($productCodes as $scenario => $code) {
            if ($scenario === 'UNKNOWN') {
                continue;
            }
            $entries[$code] = [
                'granularity' => 'month',
                'start' => ($scenario === 'SHORT' ? $historyEnd->subMonths(35) : $firstMonth)->toDateString(),
                'end_exclusive' => ($scenario === 'STALE' ? $historyEnd->subMonth() : $historyEnd)->toDateString(),
                'unavailable_months' => $scenario === 'GAPPED' ? [$historyEnd->subMonths(6)->toDateString()] : [],
                'timezone' => config('app.timezone'),
                'source_kind' => 'synthetic_development',
                'sales_scope' => 'development_fixture_transactions',
            ];
        }
        $coverage->writeDevelopment($entries);

        $this->command->info('Synthetic development sales history: '.$firstMonth->format('F Y').' - '.$historyEnd->subMonth()->format('F Y').' (48 completed months).');
    }

    private function seedCustomer(CarbonImmutable $createdAt): User
    {
        $customer = User::query()->where('email', 'historical-sales@example.test')->lockForUpdate()->first()
            ?? new User;

        if ($customer->exists && ($customer->name !== 'Historical Sales Development Account' || $customer->role !== UserRole::Customer)) {
            throw new RuntimeException('Historical sales customer identity conflicts with an existing account.');
        }

        $customer->forceFill([
            'email' => 'historical-sales@example.test',
            'name' => 'Historical Sales Development Account',
            'role' => UserRole::Customer,
            'email_verified_at' => $customer->created_at?->min($createdAt) ?? $createdAt,
            'password' => $customer->exists ? $customer->password : 'password',
            'created_at' => $customer->created_at?->min($createdAt) ?? $createdAt,
        ])->save();

        return $customer;
    }

    /**
     * @return array<string, Product>
     */
    private function seedProducts(CarbonImmutable $createdAt): array
    {
        $categories = [];
        foreach (['Components', 'Peripherals'] as $name) {
            $category = Category::query()->firstOrNew(['name' => 'Historical Sales '.$name]);
            if ($category->exists && $category->description !== self::DESCRIPTION) {
                throw new RuntimeException('Historical sales category identity conflicts with an existing category.');
            }
            $category->fill(['description' => self::DESCRIPTION, 'is_active' => false])->save();
            $categories[$name] = $category;
        }

        /** @var list<array{code: string, name: string, category: string, price: string, stock: int}> $scenarios */
        $scenarios = [
            ['code' => 'STABLE', 'name' => 'Stable Demand Reference Product', 'category' => 'Components', 'price' => '1000.00', 'stock' => 100],
            ['code' => 'INCREASING', 'name' => 'Increasing Demand Reference Product', 'category' => 'Components', 'price' => '2000.00', 'stock' => 50],
            ['code' => 'DECLINING', 'name' => 'Declining Demand Reference Product', 'category' => 'Components', 'price' => '1500.00', 'stock' => 20],
            ['code' => 'REPEATING', 'name' => 'Repeating Demand Reference Product', 'category' => 'Peripherals', 'price' => '500.00', 'stock' => 15],
            ['code' => 'SPARSE', 'name' => 'Sparse Demand Reference Product', 'category' => 'Peripherals', 'price' => '2500.00', 'stock' => 0],
            ['code' => 'PRICE', 'name' => 'Price Snapshot Reference Product', 'category' => 'Peripherals', 'price' => '200.00', 'stock' => 8],
            ['code' => 'NOHISTORY', 'name' => 'No History Reference Product', 'category' => 'Peripherals', 'price' => '750.00', 'stock' => 25],
            ['code' => 'MIXEDZERO', 'name' => 'Eligible Mixed Zero Reference Product', 'category' => 'Peripherals', 'price' => '1000.00', 'stock' => 50],
            ['code' => 'ABRUPT', 'name' => 'Abrupt Change Reference Product', 'category' => 'Peripherals', 'price' => '1000.00', 'stock' => 30],
            ['code' => 'SHORT', 'name' => 'Short History Reference Product', 'category' => 'Components', 'price' => '1000.00', 'stock' => 10],
            ['code' => 'UNKNOWN', 'name' => 'Unavailable History Reference Product', 'category' => 'Components', 'price' => '1000.00', 'stock' => 10],
            ['code' => 'STALE', 'name' => 'Stale Coverage Reference Product', 'category' => 'Components', 'price' => '1000.00', 'stock' => 10],
            ['code' => 'GAPPED', 'name' => 'Gapped Coverage Reference Product', 'category' => 'Components', 'price' => '1000.00', 'stock' => 10],
        ];

        $products = [];
        foreach ($scenarios as $scenario) {
            $category = $categories[$scenario['category']];
            $product = Product::query()->firstOrNew(['product_code' => 'DEVHIST40'.$scenario['code']]);

            if ($product->exists && ($product->is_catalog_imported || $product->description !== self::DESCRIPTION || $product->category_id !== $category->id)) {
                throw new RuntimeException('Historical sales product code conflicts with an existing product.');
            }

            $product->forceFill([
                'name' => $scenario['name'],
                'description' => self::DESCRIPTION,
                'category_id' => $category->id,
                'brand' => null,
                'price' => $scenario['price'],
                'is_catalog_imported' => false,
                'is_active' => false,
                'is_featured' => false,
                'discount_price' => null,
                'image_path' => null,
                'created_at' => $product->exists ? $product->created_at->min($createdAt) : $createdAt,
            ])->save();
            $product->tags()->sync([]);

            $inventory = Inventory::query()->firstOrNew(['product_id' => $product->id]);
            $inventory->forceFill([
                'quantity' => $scenario['stock'],
                'reorder_level' => 10,
            ])->save();
            $products[$scenario['code']] = $product;
        }

        return $products;
    }

    /** @param array<string, Product> $products */
    private function removeObsoleteHistory(User $customer, array $products, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $productIds = array_map(fn (Product $product): int => $product->id, $products);
        $orders = $customer->orders()->with('items')->lockForUpdate()->get();
        $ownedOrders = [];
        $identities = [];

        foreach ($orders as $order) {
            $itemProductIds = $order->items->pluck('product_id')->all();
            if (! str_starts_with($order->recipient_name, 'Historical Sales ') && array_intersect($itemProductIds, $productIds) === []) {
                continue;
            }

            $date = CarbonImmutable::instance($order->created_at);
            $legacy = $order->recipient_name === "Historical Sales Q{$date->quarter} {$date->year}";
            $label = $legacy ? "Historical Sales Q{$date->quarter} {$date->year}" : 'Historical Sales '.$date->format('F Y');
            $boundaries = $legacy
                ? [$date->startOfQuarter()->toDateTimeString(), $date->endOfQuarter()->toDateTimeString()]
                : [$date->startOfMonth()->toDateTimeString(), $date->endOfMonth()->toDateTimeString()];
            $identity = $label.' '.$date->toDateTimeString();
            if ($order->recipient_name !== $label
                || ! in_array($date->toDateTimeString(), $boundaries, true)
                || isset($identities[$identity])
                || $itemProductIds === []
                || array_diff($itemProductIds, $productIds) !== []
                || count($itemProductIds) !== count(array_unique($itemProductIds))) {
                throw new RuntimeException('Historical sales order ownership is ambiguous or contains unrelated or duplicate items.');
            }

            $identities[$identity] = true;
            $ownedOrders[] = $order;
        }

        foreach ($ownedOrders as $order) {
            if (preg_match('/^Historical Sales Q[1-4] [0-9]{4}$/D', $order->recipient_name) === 1 || $order->created_at->lt($start) || $order->created_at->gte($end)) {
                $order->sale()->delete();
                $order->delete();
            }
        }
    }

    /**
     * Persist historical snapshots without invoking live checkout or its stock deduction.
     *
     * @param  list<array{product: Product, quantity: int, price: numeric-string}>  $items
     */
    private function seedOrder(User $customer, string $label, CarbonImmutable $date, array $items): void
    {
        $matchingOrders = Order::query()->whereBelongsTo($customer)
            ->where('recipient_name', $label)
            ->where('created_at', $date->toDateTimeString())
            ->get();
        if ($matchingOrders->count() > 1) {
            throw new RuntimeException('Historical sales order identity is ambiguous.');
        }
        $order = $matchingOrders->first() ?? new Order;

        $productIds = array_map(fn (array $item): int => $item['product']->id, $items);
        if ($order->exists) {
            $order->items()->whereNotIn('product_id', $productIds)->delete();
        }

        $total = '0.00';
        foreach ($items as $item) {
            $total = bcadd($total, bcmul($item['price'], (string) $item['quantity'], 2), 2);
        }

        $order->forceFill([
            'user_id' => $customer->id,
            'recipient_name' => $label,
            'contact_number' => '09000000000',
            'fulfillment_method' => FulfillmentMethod::Pickup,
            'delivery_address' => null,
            'total_amount' => $total,
            'status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Verified,
            'payment_method' => PaymentMethod::Cash,
            'payment_proof_path' => null,
            'payment_rejection_reason' => null,
            'payment_rejection_note' => null,
            'created_at' => $date->toDateTimeString(),
        ])->save();

        foreach ($items as $item) {
            $order->items()->updateOrCreate(['product_id' => $item['product']->id], [
                'quantity' => $item['quantity'],
                'price_at_time' => $item['price'],
            ]);
        }
        $order->sale()->updateOrCreate([], ['amount' => $total, 'sale_date' => $date->toDateString()]);
    }
}
