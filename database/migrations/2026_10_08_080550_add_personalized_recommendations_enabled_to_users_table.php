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
            $table->boolean('personalized_recommendations_enabled')->default(true);
        });

        DB::table('users')
            ->where('search_recommendations_enabled', false)
            ->orWhere('product_view_recommendations_enabled', false)
            ->update(['personalized_recommendations_enabled' => false]);

        $optedOutUsers = DB::table('users')
            ->where('personalized_recommendations_enabled', false)
            ->select('id');

        DB::table('customer_searches')->whereIn('user_id', $optedOutUsers)->delete();
        DB::table('customer_product_views')->whereIn('user_id', $optedOutUsers)->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('personalized_recommendations_enabled');
        });
    }
};
