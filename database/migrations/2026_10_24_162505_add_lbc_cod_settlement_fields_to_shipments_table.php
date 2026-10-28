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
            $table->decimal('carrier_cod_collected_amount', 12, 2)->nullable();
            $table->string('carrier_cod_collection_reference', 100)->nullable();
            $table->timestamp('carrier_cod_collected_at')->nullable();
            $table->decimal('carrier_cod_remitted_amount', 12, 2)->nullable();
            $table->string('carrier_cod_remittance_reference', 100)->nullable();
            $table->timestamp('carrier_cod_remitted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shipments') && DB::table('shipments')->whereNotNull('carrier_cod_collected_at')->exists()) {
            throw new RuntimeException('Cannot discard recorded LBC COD collection or remittance evidence. Use a forward migration.');
        }

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn([
                'carrier_cod_collected_amount', 'carrier_cod_collection_reference', 'carrier_cod_collected_at',
                'carrier_cod_remitted_amount', 'carrier_cod_remittance_reference', 'carrier_cod_remitted_at',
            ]);
        });
    }
};
