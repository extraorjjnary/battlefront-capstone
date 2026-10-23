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
        Schema::table('paymongo_attempts', function (Blueprint $table): void {
            $table->dropUnique(['payment_intent_id']);
            $table->unsignedSmallInteger('attempt_number')->default(1)->after('payment_intent_id');
            $table->unique(['paymongo_flow_id', 'attempt_number']);
            $table->index(['payment_intent_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasRepeatedIntent = DB::table('paymongo_attempts')
            ->select('payment_intent_id')
            ->groupBy('payment_intent_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasRepeatedIntent) {
            throw new RuntimeException('Repeated PayMongo payment attempts exist; this migration cannot be rolled back.');
        }

        Schema::table('paymongo_attempts', function (Blueprint $table): void {
            $table->dropUnique(['paymongo_flow_id', 'attempt_number']);
            $table->dropIndex(['payment_intent_id', 'status']);
            $table->dropColumn('attempt_number');
            $table->unique('payment_intent_id');
        });
    }
};
