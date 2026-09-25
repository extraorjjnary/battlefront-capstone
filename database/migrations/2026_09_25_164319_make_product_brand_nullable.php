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
        $this->changeBrand(true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('products')->whereNull('brand')->exists()) {
            throw new RuntimeException('Products with unknown brands must be resolved before reversing this migration.');
        }

        $this->changeBrand(false);
    }

    private function changeBrand(bool $nullable): void
    {
        Schema::table('products', function (Blueprint $table) use ($nullable): void {
            $table->string('brand')->nullable($nullable)->change();
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->restoreMoneyConstraints();
        }
    }

    private function restoreMoneyConstraints(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS products_money_insert_check
            BEFORE INSERT ON products
            WHEN NEW.price < 0 OR NEW.discount_price < 0 OR NEW.discount_price >= NEW.price
            BEGIN
                SELECT RAISE(ABORT, 'Invalid product price or discount price.');
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS products_money_update_check
            BEFORE UPDATE OF price, discount_price ON products
            WHEN NEW.price < 0 OR NEW.discount_price < 0 OR NEW.discount_price >= NEW.price
            BEGIN
                SELECT RAISE(ABORT, 'Invalid product price or discount price.');
            END
        SQL);
    }
};
