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
        Schema::table('shipments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('package_count')->nullable();
            $table->decimal('package_weight_kg', 8, 3)->nullable();
            $table->decimal('package_length_cm', 8, 2)->nullable();
            $table->decimal('package_width_cm', 8, 2)->nullable();
            $table->decimal('package_height_cm', 8, 2)->nullable();
            $table->decimal('declared_value', 12, 2)->nullable();
            $table->string('handoff_mode', 24)->nullable();
            $table->decimal('actual_carrier_cost', 12, 2)->nullable();
            $table->string('carrier_cost_reference', 100)->nullable();
            $table->timestamp('carrier_cost_recorded_at')->nullable();
            $table->string('shipment_exception_code', 40)->nullable();
            $table->text('shipment_exception_note')->nullable();
            $table->timestamp('shipment_exception_reported_at')->nullable();
            $table->timestamp('shipment_exception_resolved_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shipments') && DB::table('shipments')->where(function ($query): void {
            $query->whereNotNull('package_count')
                ->orWhereNotNull('actual_carrier_cost')
                ->orWhereNotNull('shipment_exception_reported_at');
        })->exists()) {
            throw new RuntimeException('Cannot discard saved shipment operation or reconciliation records. Use a forward migration.');
        }

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn([
                'package_count', 'package_weight_kg', 'package_length_cm', 'package_width_cm', 'package_height_cm',
                'declared_value', 'handoff_mode', 'actual_carrier_cost', 'carrier_cost_reference',
                'carrier_cost_recorded_at', 'shipment_exception_code', 'shipment_exception_note',
                'shipment_exception_reported_at', 'shipment_exception_resolved_at',
            ]);
        });
    }
};
