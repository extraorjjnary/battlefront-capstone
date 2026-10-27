<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_component_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Product::class)->unique()->constrained()->restrictOnDelete();
            $table->string('component_type', 32);
            $table->string('manufacturer_part_number', 128)->nullable();
            $table->string('hardware_revision', 80)->nullable();
            $table->unsignedInteger('current_revision')->nullable();
            $table->string('review_status', 24)->default('draft');
            $table->unsignedInteger('edit_version')->default(1);
            $table->timestamps();

            $table->index(['component_type', 'review_status']);
        });

        Schema::create('component_specification_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_component_profile_id')
                ->constrained(indexName: 'component_spec_revision_profile_fk')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('specifications');
            $table->json('evidence')->nullable();
            $table->string('review_status', 24)->default('draft');
            $table->foreignIdFor(User::class, 'reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['product_component_profile_id', 'revision'], 'component_spec_profile_revision_unique');
            $table->index(['review_status', 'updated_at']);
        });

        Schema::create('pc_builds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('share_token')->nullable()->unique();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('mode', 16)->default('simple');
            $table->decimal('budget', 12, 2)->nullable();
            $table->unsignedInteger('edit_version')->default(1);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('pc_build_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pc_build_id')->constrained()->cascadeOnDelete();
            $table->uuid('item_key')->unique();
            $table->foreignIdFor(Product::class)->constrained()->restrictOnDelete();
            $table->foreignId('component_specification_revision_id')->nullable()
                ->constrained()->restrictOnDelete();
            $table->string('component_type', 32);
            $table->string('slot_key', 64);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedSmallInteger('position')->default(0);
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(['pc_build_id', 'slot_key'], 'pc_build_items_build_slot_unique');
            $table->index(['pc_build_id', 'component_type']);
        });

        Schema::create('pc_build_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('pc_build_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 32);
            $table->char('configuration_fingerprint', 64)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['event_type', 'occurred_at']);
            $table->index(['pc_build_id', 'event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'pc_build_events',
            'pc_build_items',
            'pc_builds',
            'component_specification_revisions',
            'product_component_profiles',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new LogicException('PC Builder records exist. Preserve them and use a forward migration.');
            }
        }

        Schema::dropIfExists('pc_build_events');
        Schema::dropIfExists('pc_build_items');
        Schema::dropIfExists('pc_builds');
        Schema::dropIfExists('component_specification_revisions');
        Schema::dropIfExists('product_component_profiles');
    }
};
