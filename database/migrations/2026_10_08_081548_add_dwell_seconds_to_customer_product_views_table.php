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
        Schema::table('customer_product_views', function (Blueprint $table) {
            $table->unsignedSmallInteger('dwell_seconds')->default(0)->after('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_product_views', function (Blueprint $table) {
            $table->dropColumn('dwell_seconds');
        });
    }
};
