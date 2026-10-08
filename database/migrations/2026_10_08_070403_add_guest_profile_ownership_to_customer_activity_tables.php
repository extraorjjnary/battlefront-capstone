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
        Schema::table('customer_searches', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('guest_recommendation_profile_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['guest_recommendation_profile_id', 'created_at'], 'customer_searches_guest_created_idx');
        });

        Schema::table('customer_product_views', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('guest_recommendation_profile_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['guest_recommendation_profile_id', 'product_id', 'created_at'], 'customer_views_guest_product_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('customer_product_views')->whereNotNull('guest_recommendation_profile_id')->delete();
        DB::table('customer_searches')->whereNotNull('guest_recommendation_profile_id')->delete();

        Schema::table('customer_product_views', function (Blueprint $table): void {
            $table->dropIndex('customer_views_guest_product_created_idx');
            $table->dropConstrainedForeignId('guest_recommendation_profile_id');
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::table('customer_searches', function (Blueprint $table): void {
            $table->dropIndex('customer_searches_guest_created_idx');
            $table->dropConstrainedForeignId('guest_recommendation_profile_id');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
