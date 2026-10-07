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
            $table->string('shipping_profile', 16)->default('standard');
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $event) {
                $operation = $event === 'insert' ? 'INSERT' : 'UPDATE OF shipping_profile';
                DB::statement(<<<SQL
                    CREATE TRIGGER products_shipping_profile_{$event}_check
                    BEFORE {$operation} ON products
                    WHEN NEW.shipping_profile NOT IN ('standard', 'fragile', 'bulky')
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid product shipping profile.');
                    END
                SQL);
            }

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_shipping_profile_valid CHECK (
                    CAST(shipping_profile AS BINARY) IN ('standard', 'fragile', 'bulky')
                )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER products_shipping_profile_insert_check');
            DB::statement('DROP TRIGGER products_shipping_profile_update_check');
        } else {
            DB::statement('ALTER TABLE products DROP CHECK products_shipping_profile_valid');
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('shipping_profile');
        });
    }
};
