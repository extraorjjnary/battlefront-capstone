<?php

use App\Models\Order;
use App\Models\Product;
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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Order::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Product::class)->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('price_at_time', 12, 2);
        });

        $this->addOrderItemConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }

    /**
     * Enforce approved purchase-time values on MySQL and the SQLite test database.
     */
    private function addOrderItemConstraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER order_items_values_insert_check
                BEFORE INSERT ON order_items
                WHEN NEW.quantity <= 0 OR NEW.price_at_time < 0
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid order item values.');
                END
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER order_items_values_update_check
                BEFORE UPDATE OF quantity, price_at_time ON order_items
                WHEN NEW.quantity <= 0 OR NEW.price_at_time < 0
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid order item values.');
                END
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE order_items
                ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT order_items_price_at_time_non_negative CHECK (price_at_time >= 0)
        SQL);
    }
};
