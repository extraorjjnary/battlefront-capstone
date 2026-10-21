<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('acquisition_cost', 12, 2)->nullable();
            $table->string('acquisition_cost_source', 40)->nullable();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('acquisition_cost_at_time', 12, 2)->nullable();
            $table->string('acquisition_cost_source', 40)->nullable();
        });
        Schema::table('customer_return_items', function (Blueprint $table) {
            $table->decimal('sales_return_amount', 12, 2)->nullable();
            $table->decimal('cost_recovered_amount', 12, 2)->nullable();
            $table->timestamp('financial_recognized_at')->nullable();
        });

        $this->initializeProductCosts();
        $this->initializeHistoricalItemCosts();
        $this->initializeHistoricalReturnValues();
        $this->addPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissionIds = DB::table('administration_permissions')
            ->whereIn('code', ['products.cost', 'reports.profit'])->pluck('id');
        DB::table('administration_role_permissions')->whereIn('administration_permission_id', $permissionIds)->delete();
        DB::table('administration_permissions')->whereIn('id', $permissionIds)->delete();

        Schema::table('customer_return_items', function (Blueprint $table) {
            $table->dropColumn(['sales_return_amount', 'cost_recovered_amount', 'financial_recognized_at']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['acquisition_cost_at_time', 'acquisition_cost_source']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['acquisition_cost', 'acquisition_cost_source']);
        });
        $this->restoreSqliteTriggers();
    }

    private function initializeProductCosts(): void
    {
        DB::table('products')->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereNull('products.acquisition_cost')->where('categories.name', '!=', 'Service')
            ->select('products.id as id', 'products.price', 'categories.name as category_name')
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    DB::table('products')->where('id', $product->id)->update([
                        'acquisition_cost' => $this->estimateCost((string) $product->price, $product->category_name),
                        'acquisition_cost_source' => 'category_policy_v1',
                    ]);
                }
            }, 'products.id', 'id');
    }

    private function initializeHistoricalItemCosts(): void
    {
        DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereNull('order_items.acquisition_cost_at_time')->where('categories.name', '!=', 'Service')
            ->select('order_items.id as id', 'order_items.price_at_time', 'categories.name as category_name')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    DB::table('order_items')->where('id', $item->id)->update([
                        'acquisition_cost_at_time' => $this->estimateCost((string) $item->price_at_time, $item->category_name),
                        'acquisition_cost_source' => 'category_policy_v1',
                    ]);
                }
            }, 'order_items.id', 'id');
    }

    /** @return numeric-string */
    private function costRate(string $category): string
    {
        return match ($category) {
            'Processor', 'Graphics Card', 'Laptops & Desktops' => '0.92',
            'Motherboard', 'Monitor', 'Printer' => '0.88',
            'RAM', 'Storage', 'Bundles & Packages' => '0.85',
            'Power Supply', 'PC Case', 'Networking', 'CCTV & Security' => '0.82',
            'Cooling Components', 'Peripherals', 'Audio Equipment', 'Power Accessories' => '0.75',
            'Cables & Adapters', 'Laptop Bags & Stands', 'Cleaning & Maintenance Supplies' => '0.65',
            default => '0.80',
        };
    }

    private function estimateCost(string $price, string $category): string
    {
        return bcadd(bcmul($this->numericAmount($price), $this->costRate($category), 4), '0.005', 2);
    }

    /** @return numeric-string */
    private function numericAmount(string $amount): string
    {
        if (! is_numeric($amount)) {
            throw new RuntimeException('The saved product price is not numeric.');
        }

        return $amount;
    }

    private function addPermissions(): void
    {
        foreach (['products.cost', 'reports.profit'] as $code) {
            DB::table('administration_permissions')->insertOrIgnore(['code' => $code, 'name' => $code]);
        }
        foreach (['owner' => ['products.cost', 'reports.profit'], 'manager' => ['reports.profit']] as $role => $permissions) {
            $roleId = DB::table('administration_roles')->where('code', $role)->value('id');
            foreach ($permissions as $permission) {
                DB::table('administration_role_permissions')->insertOrIgnore([
                    'administration_role_id' => $roleId,
                    'administration_permission_id' => DB::table('administration_permissions')->where('code', $permission)->value('id'),
                ]);
            }
        }
    }

    private function initializeHistoricalReturnValues(): void
    {
        DB::table('customer_return_items as return_items')
            ->join('customer_returns as returns', 'returns.id', '=', 'return_items.customer_return_id')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->leftJoin('customer_refunds as refunds', 'refunds.customer_return_id', '=', 'returns.id')
            ->where('returns.status', 'accepted')
            ->whereNull('return_items.financial_recognized_at')
            ->select([
                'return_items.id', 'return_items.quantity', 'return_items.disposition',
                'order_items.acquisition_cost_at_time', 'returns.updated_at',
            ])
            ->selectRaw('COALESCE(refunds.approved_amount, 0.00) as sales_return_amount')
            ->orderBy('return_items.id')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    $costRecovered = $item->disposition === 'restock' && $item->acquisition_cost_at_time !== null
                        ? bcmul($this->numericAmount((string) $item->quantity), $this->numericAmount((string) $item->acquisition_cost_at_time), 2)
                        : '0.00';
                    DB::table('customer_return_items')->where('id', $item->id)->update([
                        'sales_return_amount' => $item->sales_return_amount,
                        'cost_recovered_amount' => $costRecovered,
                        'financial_recognized_at' => $item->updated_at,
                    ]);
                }
            }, 'return_items.id', 'id');
    }

    private function restoreSqliteTriggers(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared("CREATE TRIGGER IF NOT EXISTS products_money_insert_check BEFORE INSERT ON products WHEN NEW.price < 0 OR NEW.discount_price < 0 OR NEW.discount_price >= NEW.price BEGIN SELECT RAISE(ABORT, 'Invalid product price or discount price.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS products_money_update_check BEFORE UPDATE OF price, discount_price ON products WHEN NEW.price < 0 OR NEW.discount_price < 0 OR NEW.discount_price >= NEW.price BEGIN SELECT RAISE(ABORT, 'Invalid product price or discount price.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS products_shipping_profile_insert_check BEFORE INSERT ON products WHEN NEW.shipping_profile NOT IN ('standard', 'fragile', 'bulky') BEGIN SELECT RAISE(ABORT, 'Invalid product shipping profile.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS products_shipping_profile_update_check BEFORE UPDATE OF shipping_profile ON products WHEN NEW.shipping_profile NOT IN ('standard', 'fragile', 'bulky') BEGIN SELECT RAISE(ABORT, 'Invalid product shipping profile.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS order_items_values_insert_check BEFORE INSERT ON order_items WHEN NEW.quantity <= 0 OR NEW.price_at_time < 0 BEGIN SELECT RAISE(ABORT, 'Invalid order item values.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS order_items_values_update_check BEFORE UPDATE OF quantity, price_at_time ON order_items WHEN NEW.quantity <= 0 OR NEW.price_at_time < 0 BEGIN SELECT RAISE(ABORT, 'Invalid order item values.'); END");
        foreach (['order_items', 'customer_return_items'] as $table) {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
        }
    }
};
