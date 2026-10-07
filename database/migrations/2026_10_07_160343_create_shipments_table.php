<?php

use App\Models\Order;
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
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Order::class)->unique()->constrained()->restrictOnDelete();
            $table->string('carrier', 32);
            $table->string('status', 32)->default('awaiting_preparation');
            $table->unsignedInteger('preparation_days');
            $table->unsignedInteger('transit_min_days');
            $table->unsignedInteger('transit_max_days');
            $table->unsignedInteger('eta_min_days');
            $table->unsignedInteger('eta_max_days');
            $table->string('tracking_reference')->nullable();
            $table->timestamp('handed_to_carrier_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        $this->addShipmentConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('shipments')->exists()) {
            throw new RuntimeException('Cannot discard persisted shipments. Use a forward migration.');
        }

        Schema::dropIfExists('shipments');
    }

    private function addShipmentConstraints(): void
    {
        $statusColumn = DB::getDriverName() === 'sqlite' ? 'status' : 'CAST(status AS BINARY)';
        $condition = <<<SQL
            LENGTH(carrier) > 0 AND {$statusColumn} = 'awaiting_preparation'
            AND preparation_days > 0 AND transit_min_days > 0 AND transit_max_days >= transit_min_days
            AND eta_min_days = preparation_days + transit_min_days
            AND eta_max_days = preparation_days + transit_max_days
        SQL;

        if (DB::getDriverName() === 'sqlite') {
            $columns = ['carrier', 'status', 'preparation_days', 'transit_min_days', 'transit_max_days', 'eta_min_days', 'eta_max_days'];
            $condition = strtr($condition, array_combine($columns, array_map(fn (string $column): string => "NEW.{$column}", $columns)));

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
