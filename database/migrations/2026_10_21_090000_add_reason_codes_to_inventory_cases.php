<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customer_returns', 'damage_reports', 'supplier_returns', 'inventory_adjustment_requests'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN reason_code VARCHAR(40) NULL");
        }
    }

    public function down(): void
    {
        foreach (['inventory_adjustment_requests', 'supplier_returns', 'damage_reports', 'customer_returns'] as $table) {
            if (DB::table($table)->whereNotNull('reason_code')->exists()) {
                throw new RuntimeException('Inventory case reason codes exist; use a forward migration to preserve them.');
            }
            DB::statement("ALTER TABLE {$table} DROP COLUMN reason_code");
        }
    }
};
