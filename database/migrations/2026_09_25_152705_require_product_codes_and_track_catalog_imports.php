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
        $demoNames = [
            '[DEMO] NVIDIA Atlas Graphics Card', '[DEMO] AMD Nova Graphics Card',
            '[DEMO] Intel Horizon Processor', '[DEMO] AMD Forge Processor',
            '[DEMO] Logitech Pulse Wireless Mouse', '[DEMO] Keychron Studio Mechanical Keyboard',
            '[DEMO] Samsung Sprint NVMe SSD', '[DEMO] Crucial Archive SATA SSD',
        ];
        $seenCodes = [];
        $backfill = [];
        foreach (DB::table('products')->orderBy('id')->get(['id', 'name', 'description', 'product_code']) as $product) {
            $code = $product->product_code;
            if ($code === null) {
                if (! in_array($product->name, $demoNames, true)
                    || $product->description !== 'Synthetic development sample only; not a Battlefront stocked product.') {
                    throw new RuntimeException("Product {$product->id} needs a verified product code before migration.");
                }
                $code = 'LEGACY'.$product->id;
                $backfill[$product->id] = $code;
            }
            if (! preg_match('/^[A-Za-z0-9]{1,64}$/D', $code) || isset($seenCodes[strtolower($code)])) {
                throw new RuntimeException("Invalid or duplicate product code: $code.");
            }
            $seenCodes[strtolower($code)] = true;
        }
        DB::transaction(function () use ($backfill): void {
            foreach ($backfill as $id => $code) {
                DB::table('products')->where('id', $id)->update(['product_code' => $code]);
            }
        });

        $this->changeProductCode(false);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->changeProductCode(true);
    }

    private function changeProductCode(bool $nullable): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        Schema::table('products', function (Blueprint $table) use ($nullable, $sqlite): void {
            if ($nullable) {
                $table->dropColumn('is_catalog_imported');
            } else {
                $table->boolean('is_catalog_imported')->default(false);
            }
            $table->string('product_code', 64)->nullable($nullable)
                ->collation($sqlite ? ($nullable ? 'BINARY' : 'NOCASE') : ($nullable ? 'utf8mb4_bin' : 'utf8mb4_unicode_ci'))->change();
        });

        if ($sqlite) {
            $this->restoreMoneyConstraints();
        }
    }

    /**
     * SQLite rebuilds the table for column changes, discarding its existing triggers.
     */
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
