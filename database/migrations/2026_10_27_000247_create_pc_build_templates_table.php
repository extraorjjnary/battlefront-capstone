<?php

use App\Models\Product;
use App\Models\User;
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
        Schema::create('pc_build_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->decimal('budget', 12, 2)->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('edit_version')->default(1);
            $table->foreignIdFor(User::class, 'created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'updated_at']);
        });

        Schema::create('pc_build_template_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pc_build_template_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Product::class)->constrained()->restrictOnDelete();
            $table->string('component_type', 32);
            $table->string('slot_key', 64);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedSmallInteger('position')->default(0);
            $table->json('configuration')->nullable();
            $table->timestamps();
            $table->unique(['pc_build_template_id', 'slot_key']);
            $table->index(['pc_build_template_id', 'component_type'], 'pc_template_items_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('pc_build_template_items') && DB::table('pc_build_template_items')->exists()) {
            throw new LogicException('PC build templates reference catalog products. Preserve them and use a forward migration.');
        }
        if (Schema::hasTable('pc_build_templates') && DB::table('pc_build_templates')->exists()) {
            throw new LogicException('PC build templates exist. Preserve them and use a forward migration.');
        }
        Schema::dropIfExists('pc_build_template_items');
        Schema::dropIfExists('pc_build_templates');
    }
};
