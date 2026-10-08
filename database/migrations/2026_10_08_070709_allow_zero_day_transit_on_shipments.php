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
        $this->replaceSnapshotConstraint(0);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('shipments')->where('transit_min_days', 0)->exists()) {
            throw new RuntimeException('Cannot restore positive transit minima while zero-day snapshots exist. Use a forward migration.');
        }

        $this->replaceSnapshotConstraint(1);
    }

    private function replaceSnapshotConstraint(int $minimumTransit): void
    {
        $statusColumn = DB::getDriverName() === 'sqlite' ? 'status' : 'CAST(status AS BINARY)';
        $condition = <<<SQL
            LENGTH(carrier) > 0 AND {$statusColumn} IN ('awaiting_preparation', 'preparing', 'ready_for_dispatch', 'handed_to_lbc', 'in_transit', 'out_for_delivery', 'delivered', 'cancelled')
            AND preparation_days > 0 AND transit_min_days >= {$minimumTransit} AND transit_max_days >= transit_min_days
            AND eta_min_days = preparation_days + transit_min_days
            AND eta_max_days = preparation_days + transit_max_days
        SQL;

        if (DB::getDriverName() === 'sqlite') {
            $columns = ['carrier', 'status', 'preparation_days', 'transit_min_days', 'transit_max_days', 'eta_min_days', 'eta_max_days'];
            $condition = strtr($condition, array_combine($columns, array_map(fn (string $column): string => "NEW.{$column}", $columns)));
            foreach (['insert', 'update'] as $event) {
                DB::statement("DROP TRIGGER shipments_snapshot_{$event}_check");
                $operation = $event === 'insert' ? 'INSERT' : 'UPDATE';
                DB::statement(<<<SQL
                    CREATE TRIGGER shipments_snapshot_{$event}_check
                    BEFORE {$operation} ON shipments
                    WHEN NOT ({$condition})
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid shipment snapshot.');
                    END
                SQL);
            }

            return;
        }

        DB::statement("ALTER TABLE shipments DROP CHECK shipments_snapshot_valid, ADD CONSTRAINT shipments_snapshot_valid CHECK ({$condition})");
    }
};
