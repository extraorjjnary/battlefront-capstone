<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('paymongo_flows', function (Blueprint $table): void {
            $table->string('recovery_outcome', 24)->nullable();
            $table->string('recovery_reason', 64)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamp('exception_since')->nullable();
            $table->unsignedInteger('recovery_failures')->default(0);
            $table->boolean('requires_attention')->default(false)->index();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('payment_closure_requested_at')->nullable();
            $table->unsignedBigInteger('payment_closure_requested_by')->nullable();
            $table->string('payment_closure_initiator', 24)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Payment recovery history is retained. Roll forward instead of dropping financial evidence.');
    }
};
