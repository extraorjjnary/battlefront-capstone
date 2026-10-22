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
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('product_name_at_time')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('order_items')->whereNotNull('product_name_at_time')->exists()) {
            throw new RuntimeException('Purchase name snapshots must not be discarded. Use a forward migration.');
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('product_name_at_time');
        });
    }
};
