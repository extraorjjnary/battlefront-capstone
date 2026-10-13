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
        $this->dropSnapshotConstraint();

        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('arrangement_type', 32)->default('configured_rate');
            $table->unsignedInteger('preparation_days')->nullable()->change();
            $table->unsignedInteger('transit_min_days')->nullable()->change();
            $table->unsignedInteger('transit_max_days')->nullable()->change();
            $table->unsignedInteger('eta_min_days')->nullable()->change();
            $table->unsignedInteger('eta_max_days')->nullable()->change();
        });

        $this->replaceSnapshotConstraint(true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasManualOrUnknownSnapshots = DB::table('shipments')
            ->where('arrangement_type', 'manual')
            ->orWhereNull('preparation_days')
            ->orWhereNull('transit_min_days')
            ->orWhereNull('transit_max_days')
            ->orWhereNull('eta_min_days')
            ->orWhereNull('eta_max_days')
            ->exists();

        if ($hasManualOrUnknownSnapshots) {
            throw new RuntimeException('Cannot remove manual or incomplete shipment snapshots. Use a forward migration.');
        }

        $this->dropSnapshotConstraint();

        Schema::table('shipments', function (Blueprint $table): void {
            $table->unsignedInteger('preparation_days')->nullable(false)->change();
            $table->unsignedInteger('transit_min_days')->nullable(false)->change();
            $table->unsignedInteger('transit_max_days')->nullable(false)->change();
            $table->unsignedInteger('eta_min_days')->nullable(false)->change();
            $table->unsignedInteger('eta_max_days')->nullable(false)->change();
            $table->dropColumn('arrangement_type');
        });

        $this->replaceSnapshotConstraint(false);
    }

    private function dropSnapshotConstraint(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $event) {
                DB::statement("DROP TRIGGER shipments_snapshot_{$event}_check");
            }

            return;
        }

        DB::statement('ALTER TABLE shipments DROP CHECK shipments_snapshot_valid');
    }

    private function replaceSnapshotConstraint(bool $supportsManual): void
    {
        $statusColumn = DB::getDriverName() === 'sqlite' ? 'status' : 'CAST(status AS BINARY)';
        $arrangementColumn = DB::getDriverName() === 'sqlite'
            ? 'arrangement_type'
            : 'CAST(arrangement_type AS BINARY)';
        $statuses = "'awaiting_preparation', 'preparing', 'ready_for_dispatch', 'handed_to_lbc', 'in_transit', 'out_for_delivery', 'delivered', 'cancelled'";
        $configured = <<<SQL
            {$arrangementColumn} = 'configured_rate'
            AND preparation_days IS NOT NULL AND preparation_days > 0
            AND transit_min_days IS NOT NULL AND transit_min_days >= 0
            AND transit_max_days IS NOT NULL AND transit_max_days >= transit_min_days
            AND eta_min_days = preparation_days + transit_min_days
            AND eta_max_days = preparation_days + transit_max_days
        SQL;

        $condition = "LENGTH(carrier) > 0 AND {$statusColumn} IN ({$statuses}) AND ({$configured})";

        if ($supportsManual) {
            $condition = <<<SQL
                LENGTH(carrier) > 0 AND {$statusColumn} IN ({$statuses}) AND (
                    ({$configured})
                    OR (
                        {$arrangementColumn} = 'manual'
                        AND carrier = 'lbc'
                        AND preparation_days IS NULL
                        AND transit_min_days IS NULL
                        AND transit_max_days IS NULL
                        AND (
                            (eta_min_days IS NULL AND eta_max_days IS NULL)
                            OR (eta_min_days >= 0 AND eta_max_days >= eta_min_days)
                        )
                    )
                )
            SQL;
        }

        if (DB::getDriverName() === 'sqlite') {
            $columns = [
                'carrier', 'status', 'arrangement_type', 'preparation_days', 'transit_min_days',
                'transit_max_days', 'eta_min_days', 'eta_max_days',
            ];
            $condition = strtr(
                $condition,
                array_combine($columns, array_map(fn (string $column): string => "NEW.{$column}", $columns)),
            );

            foreach (['insert', 'update'] as $event) {
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

        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_snapshot_valid CHECK ({$condition})");
    }
};
