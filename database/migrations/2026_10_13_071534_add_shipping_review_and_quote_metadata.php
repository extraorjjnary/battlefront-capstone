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
        Schema::table('products', function (Blueprint $table): void {
            $table->string('shipping_profile_source')->nullable();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('delivery_quote_metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('delivery_quote_metadata')->exists()
            || DB::table('products')->whereNotNull('shipping_profile_source')->exists()) {
            throw new RuntimeException('Cannot discard shipping review or delivery snapshot metadata. Use a forward migration.');
        }
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivery_quote_metadata'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('shipping_profile_source'));
    }
};
