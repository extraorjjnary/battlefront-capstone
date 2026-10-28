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
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('hosted_payment_method', 32)->nullable()->after('payment_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('hosted_payment_method')->exists()) {
            throw new RuntimeException('Cannot remove saved hosted payment method selections.');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('hosted_payment_method');
        });
    }
};
