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
        Schema::table('users', function (Blueprint $table) {
            $table->json('default_delivery_address_components')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('users')->whereNotNull('default_delivery_address_components')->exists()) {
            throw new RuntimeException('Cannot remove saved structured delivery addresses. Use a forward migration instead.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('default_delivery_address_components');
        });
    }
};
