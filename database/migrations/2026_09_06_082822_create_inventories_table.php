<?php

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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Product::class)->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('reorder_level');
            $table->timestamp('last_updated')->useCurrent()->useCurrentOnUpdate();
        });

        $this->addStockConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }

    /**
     * Enforce non-negative inventory values on MySQL and the SQLite test database.
     */
    private function addStockConstraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER inventories_stock_insert_check
                BEFORE INSERT ON inventories
                WHEN NEW.quantity < 0 OR NEW.reorder_level < 0
                BEGIN
                    SELECT RAISE(ABORT, 'Inventory values cannot be negative.');
                END
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER inventories_stock_update_check
                BEFORE UPDATE OF quantity, reorder_level ON inventories
                WHEN NEW.quantity < 0 OR NEW.reorder_level < 0
                BEGIN
                    SELECT RAISE(ABORT, 'Inventory values cannot be negative.');
                END
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE inventories
                ADD CONSTRAINT inventories_quantity_non_negative CHECK (quantity >= 0),
                ADD CONSTRAINT inventories_reorder_level_non_negative CHECK (reorder_level >= 0)
        SQL);
    }
};
