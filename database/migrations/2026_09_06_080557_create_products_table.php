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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('brand');
            $table->decimal('price', 12, 2);
            $table->boolean('is_featured')->default(false);
            $table->decimal('discount_price', 12, 2)->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        $this->addMoneyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }

    /**
     * Enforce the approved monetary rules on MySQL and the SQLite test database.
     */
    private function addMoneyConstraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER products_money_insert_check
                BEFORE INSERT ON products
                WHEN NEW.price < 0
                    OR NEW.discount_price < 0
                    OR NEW.discount_price >= NEW.price
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid product price or discount price.');
                END
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER products_money_update_check
                BEFORE UPDATE OF price, discount_price ON products
                WHEN NEW.price < 0
                    OR NEW.discount_price < 0
                    OR NEW.discount_price >= NEW.price
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid product price or discount price.');
                END
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_price_non_negative CHECK (price >= 0),
                ADD CONSTRAINT products_discount_price_valid CHECK (
                    discount_price IS NULL
                    OR (discount_price >= 0 AND discount_price < price)
                )
        SQL);
    }
};
