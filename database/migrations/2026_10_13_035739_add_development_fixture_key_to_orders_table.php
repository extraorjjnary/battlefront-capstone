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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('development_fixture_key', 64)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('development_fixture_key')->exists()) {
            throw new RuntimeException('Cannot discard retained development fixture identities. Use a forward migration instead.');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['development_fixture_key']);
            $table->dropColumn('development_fixture_key');
        });
    }
};
