<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const COLUMNS = [
        'preparing_at', 'ready_for_dispatch_at', 'in_transit_at', 'out_for_delivery_at', 'cancelled_at',
        'eta_anchor_date', 'eta_timezone', 'estimated_delivery_start', 'estimated_delivery_end',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            foreach (['preparing_at', 'ready_for_dispatch_at', 'in_transit_at', 'out_for_delivery_at', 'cancelled_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->date('eta_anchor_date')->nullable();
            $table->string('eta_timezone', 64)->nullable();
            $table->date('estimated_delivery_start')->nullable();
            $table->date('estimated_delivery_end')->nullable();
        });

        $this->replaceSnapshotConstraint(true);

        DB::table('shipments')
            ->whereIn('order_id', DB::table('orders')->select('id')->where('status', 'cancelled'))
            ->update(['status' => 'cancelled']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasWorkflowData = DB::table('shipments')->where(function ($query): void {
            $query->where('status', '!=', 'awaiting_preparation');
            foreach (self::COLUMNS as $column) {
                $query->orWhereNotNull($column);
            }
        })->exists();

        if ($hasWorkflowData) {
            throw new RuntimeException('Cannot discard shipment workflow history. Use a forward migration.');
        }

        $this->replaceSnapshotConstraint(false);
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn(self::COLUMNS));
    }

    private function replaceSnapshotConstraint(bool $includeWorkflow): void
    {
        $statuses = "'awaiting_preparation'";
        if ($includeWorkflow) {
            $statuses .= ", 'preparing', 'ready_for_dispatch', 'handed_to_lbc', 'in_transit', 'out_for_delivery', 'delivered', 'cancelled'";
        }
        $statusColumn = DB::getDriverName() === 'sqlite' ? 'status' : 'CAST(status AS BINARY)';
        $condition = <<<SQL
            LENGTH(carrier) > 0 AND {$statusColumn} IN ({$statuses})
            AND preparation_days > 0 AND transit_min_days > 0 AND transit_max_days >= transit_min_days
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
