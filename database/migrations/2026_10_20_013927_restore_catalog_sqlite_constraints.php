<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared("CREATE TRIGGER IF NOT EXISTS products_money_insert_check BEFORE INSERT ON products WHEN NEW.price < 0 OR NEW.discount_price < 0 OR NEW.discount_price >= NEW.price BEGIN SELECT RAISE(ABORT, 'Invalid product price or discount price.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS products_money_update_check BEFORE UPDATE OF price, discount_price ON products WHEN NEW.price < 0 OR NEW.discount_price < 0 OR NEW.discount_price >= NEW.price BEGIN SELECT RAISE(ABORT, 'Invalid product price or discount price.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS products_shipping_profile_insert_check BEFORE INSERT ON products WHEN NEW.shipping_profile NOT IN ('standard', 'fragile', 'bulky') BEGIN SELECT RAISE(ABORT, 'Invalid product shipping profile.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS products_shipping_profile_update_check BEFORE UPDATE OF shipping_profile ON products WHEN NEW.shipping_profile NOT IN ('standard', 'fragile', 'bulky') BEGIN SELECT RAISE(ABORT, 'Invalid product shipping profile.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS products_name_key_insert_check BEFORE INSERT ON products WHEN NEW.name_key IS NULL OR length(NEW.name_key) != 64 BEGIN SELECT RAISE(ABORT, 'A catalog name key is required.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS products_name_key_update_check BEFORE UPDATE ON products WHEN NEW.name_key IS NULL OR length(NEW.name_key) != 64 BEGIN SELECT RAISE(ABORT, 'A catalog name key is required.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS categories_name_key_insert_check BEFORE INSERT ON categories WHEN NEW.name_key IS NULL OR length(NEW.name_key) != 64 BEGIN SELECT RAISE(ABORT, 'A catalog name key is required.'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS categories_name_key_update_check BEFORE UPDATE ON categories WHEN NEW.name_key IS NULL OR length(NEW.name_key) != 64 BEGIN SELECT RAISE(ABORT, 'A catalog name key is required.'); END");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
