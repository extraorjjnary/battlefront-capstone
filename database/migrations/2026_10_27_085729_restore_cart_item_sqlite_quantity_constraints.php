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

        DB::statement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS cart_items_quantity_insert_check
            BEFORE INSERT ON cart_items
            WHEN NEW.quantity <= 0
            BEGIN
                SELECT RAISE(ABORT, 'Cart item quantity must be greater than zero.');
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS cart_items_quantity_update_check
            BEFORE UPDATE OF quantity ON cart_items
            WHEN NEW.quantity <= 0
            BEGIN
                SELECT RAISE(ABORT, 'Cart item quantity must be greater than zero.');
            END
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
