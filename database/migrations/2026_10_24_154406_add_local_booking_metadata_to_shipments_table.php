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
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('booking_source', 32)->nullable()->after('tracking_reference');
            $table->string('booking_reference', 32)->nullable()->unique()->after('booking_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shipments') && DB::table('shipments')->whereNotNull('booking_reference')->exists()) {
            throw new RuntimeException('Cannot discard persisted local booking references. Use a forward migration.');
        }

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique(['booking_reference']);
            $table->dropColumn(['booking_reference', 'booking_source']);
        });
    }
};
