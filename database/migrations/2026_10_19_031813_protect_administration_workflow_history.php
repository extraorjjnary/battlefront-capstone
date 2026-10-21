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
        foreach (['administration_evidence', 'customer_refund_payment_reviews', 'supplier_return_resolutions'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $trigger = sprintf('%s_no_%s', $table, strtolower($operation));
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Posted history cannot be changed'); END");
                } else {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted history cannot be changed'");
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['administration_evidence', 'customer_refund_payment_reviews', 'supplier_return_resolutions'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s_no_%s', $table, $operation));
            }
        }
    }
};
