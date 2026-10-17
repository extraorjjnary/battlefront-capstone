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
        foreach (['audit_logs', 'inventory_movements', 'customer_refund_payments', 'sales'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $trigger = sprintf('%s_no_%s', $table, strtolower($operation));
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Posted history cannot be changed'); END");
                } else {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted history cannot be changed'");
                }
            }
        }
        foreach (['orders', 'order_items', 'inventory_adjustment_requests', 'damage_reports', 'customer_returns', 'customer_return_items', 'customer_refunds', 'supplier_returns'] as $table) {
            $trigger = "{$table}_no_delete";
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
            } else {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historical records cannot be deleted'");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['audit_logs', 'inventory_movements', 'customer_refund_payments', 'sales'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s_no_%s', $table, $operation));
            }
        }
        foreach (['orders', 'order_items', 'inventory_adjustment_requests', 'damage_reports', 'customer_returns', 'customer_return_items', 'customer_refunds', 'supplier_returns'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }
    }
};
