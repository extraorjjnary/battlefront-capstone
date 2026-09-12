<?php

use App\Models\Cart;
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
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Cart::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Product::class)->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unique(['cart_id', 'product_id']);
        });

        $this->addQuantityConstraint();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }

    /**
     * Enforce positive cart quantities on MySQL and the SQLite test database.
     */
    private function addQuantityConstraint(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER cart_items_quantity_insert_check
                BEFORE INSERT ON cart_items
                WHEN NEW.quantity <= 0
                BEGIN
                    SELECT RAISE(ABORT, 'Cart item quantity must be greater than zero.');
                END
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER cart_items_quantity_update_check
                BEFORE UPDATE OF quantity ON cart_items
                WHEN NEW.quantity <= 0
                BEGIN
                    SELECT RAISE(ABORT, 'Cart item quantity must be greater than zero.');
                END
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE cart_items
                ADD CONSTRAINT cart_items_quantity_positive CHECK (quantity > 0)
        SQL);
    }
};
