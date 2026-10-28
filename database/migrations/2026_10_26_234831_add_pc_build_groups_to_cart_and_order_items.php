<?php

use App\Models\Cart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pc_build_cart_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Cart::class)->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('mode', 16);
            $table->unsignedSmallInteger('ruleset_version');
            $table->json('configuration_snapshot');
            $table->timestamps();

            $table->index(['cart_id', 'created_at']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreignId('pc_build_cart_group_id')->nullable()->after('cart_id')
                ->constrained('pc_build_cart_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('pc_build_group_identity')->default(0);
            $table->index('cart_id', 'cart_items_cart_id_index');
            $table->dropUnique('cart_items_cart_id_product_id_unique');
            $table->unique(['cart_id', 'product_id', 'pc_build_group_identity'], 'cart_item_configuration_unique');
            $table->index(['cart_id', 'pc_build_cart_group_id']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->uuid('pc_build_group_uuid')->nullable();
            $table->string('pc_build_name_at_time', 120)->nullable();
            $table->json('pc_build_component_snapshot')->nullable();
            $table->index(['order_id', 'pc_build_group_uuid']);
        });
    }

    public function down(): void
    {
        if (DB::table('pc_build_cart_groups')->exists()
            || DB::table('order_items')->whereNotNull('pc_build_group_uuid')->exists()) {
            throw new LogicException('PC build cart or order history exists. Preserve it and use a forward migration.');
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_order_id_pc_build_group_uuid_index');
            $table->dropColumn(['pc_build_group_uuid', 'pc_build_name_at_time', 'pc_build_component_snapshot']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique('cart_item_configuration_unique');
            $table->dropIndex('cart_items_cart_id_pc_build_cart_group_id_index');
            $table->dropForeign(['pc_build_cart_group_id']);
            $table->dropColumn(['pc_build_group_identity', 'pc_build_cart_group_id']);
            $table->dropIndex('cart_items_cart_id_index');
            $table->unique(['cart_id', 'product_id']);
        });

        Schema::dropIfExists('pc_build_cart_groups');
    }
};
