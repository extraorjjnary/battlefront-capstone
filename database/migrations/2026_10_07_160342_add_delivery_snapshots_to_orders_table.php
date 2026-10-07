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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_destination')->nullable();
            $table->decimal('delivery_base_fee', 12, 2)->nullable();
            $table->string('shipping_profile', 16)->nullable();
            $table->decimal('handling_surcharge', 12, 2)->nullable();
            $table->decimal('delivery_fee', 12, 2)->default('0.00');
            $table->decimal('product_subtotal', 12, 2)->nullable();
            $table->string('delivery_origin_city')->nullable();
            $table->boolean('delivery_is_demo')->nullable();
            $table->string('delivery_assumption_label')->nullable();
        });

        $this->addSnapshotConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('delivery_destination')->exists()) {
            throw new RuntimeException('Cannot discard persisted delivery snapshots. Use a forward migration.');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER orders_delivery_snapshot_insert_check');
            DB::statement('DROP TRIGGER orders_delivery_snapshot_update_check');
        } else {
            DB::statement('ALTER TABLE orders DROP CHECK orders_delivery_snapshot_valid');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_destination', 'delivery_base_fee', 'shipping_profile', 'handling_surcharge',
                'delivery_fee', 'product_subtotal', 'delivery_origin_city', 'delivery_is_demo', 'delivery_assumption_label',
            ]);
        });
    }

    private function addSnapshotConstraints(): void
    {
        $profileColumn = DB::getDriverName() === 'sqlite' ? 'shipping_profile' : 'CAST(shipping_profile AS BINARY)';
        $condition = <<<SQL
            delivery_fee >= 0 AND delivery_fee <= 9999999999.99
            AND (delivery_base_fee IS NULL OR (delivery_base_fee >= 0 AND delivery_base_fee <= 9999999999.99))
            AND (handling_surcharge IS NULL OR (handling_surcharge >= 0 AND handling_surcharge <= 9999999999.99))
            AND (product_subtotal IS NULL OR (
                product_subtotal >= 0 AND product_subtotal <= 9999999999.99
                AND total_amount <= 9999999999.99
                AND total_amount = ROUND(product_subtotal + delivery_fee, 2)
            ))
            AND (
                (
                    delivery_destination IS NULL AND delivery_base_fee IS NULL
                    AND shipping_profile IS NULL AND handling_surcharge IS NULL
                    AND delivery_origin_city IS NULL AND delivery_is_demo IS NULL
                    AND delivery_assumption_label IS NULL AND delivery_fee = 0
                ) OR (
                    fulfillment_method = 'delivery' AND delivery_destination IS NOT NULL
                    AND LENGTH(delivery_destination) > 0 AND product_subtotal IS NOT NULL
                    AND delivery_base_fee IS NOT NULL AND handling_surcharge IS NOT NULL
                    AND shipping_profile IS NOT NULL AND {$profileColumn} IN ('standard', 'fragile', 'bulky')
                    AND delivery_origin_city IS NOT NULL AND delivery_is_demo IS NOT NULL
                    AND delivery_assumption_label IS NOT NULL
                    AND delivery_fee = ROUND(delivery_base_fee + handling_surcharge, 2)
                )
            )
        SQL;

        if (DB::getDriverName() === 'sqlite') {
            $columns = [
                'delivery_destination', 'delivery_base_fee', 'shipping_profile', 'handling_surcharge',
                'delivery_fee', 'product_subtotal', 'delivery_origin_city', 'delivery_is_demo', 'delivery_assumption_label',
                'fulfillment_method', 'total_amount',
            ];
            $condition = strtr($condition, array_combine($columns, array_map(fn (string $column): string => "NEW.{$column}", $columns)));

            foreach (['insert', 'update'] as $event) {
                $operation = $event === 'insert' ? 'INSERT' : 'UPDATE';
                DB::statement(<<<SQL
                    CREATE TRIGGER orders_delivery_snapshot_{$event}_check
                    BEFORE {$operation} ON orders
                    WHEN NOT ({$condition})
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid order delivery snapshot.');
                    END
                SQL);
            }

            return;
        }

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_delivery_snapshot_valid CHECK ({$condition})");
    }
};
