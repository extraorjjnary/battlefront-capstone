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
        if ($this->tableNeedsCreation('product_component_profiles', [
            'id', 'product_id', 'component_type', 'manufacturer_part_number', 'hardware_revision',
            'current_revision', 'review_status', 'edit_version', 'created_at', 'updated_at',
        ])) {
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
        }

        if ($this->tableNeedsCreation('component_specification_revisions', [
            'id', 'product_component_profile_id', 'revision', 'schema_version', 'specifications',
            'evidence', 'review_status', 'reviewed_by_id', 'reviewed_at', 'created_at', 'updated_at',
        ])) {
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
        }

        if ($this->tableNeedsCreation('pc_builds', [
            'id', 'uuid', 'share_token', 'user_id', 'name', 'mode', 'budget', 'edit_version', 'created_at', 'updated_at',
        ])) {
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
        }

        if ($this->tableNeedsCreation('pc_build_items', [
            'id', 'pc_build_id', 'item_key', 'product_id', 'component_specification_revision_id',
            'component_type', 'slot_key', 'quantity', 'position', 'configuration', 'created_at', 'updated_at',
        ])) {
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
        }

        if ($this->tableNeedsCreation('pc_build_events', [
            'id', 'event_uuid', 'pc_build_id', 'user_id', 'event_type', 'configuration_fingerprint',
            'context', 'occurred_at', 'created_at', 'updated_at',
        ])) {
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

        $this->completeConstraints();
    }

    /**
     * MySQL commits table creation before later index or foreign-key statements can fail.
     *
     * @param  list<string>  $columns
     */
    private function tableNeedsCreation(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return true;
        }

        if (! Schema::hasColumns($table, $columns)) {
            throw new LogicException("Existing PC Builder table [{$table}] has unexpected columns. Preserve its data and reconcile the schema before retrying.");
        }

        return false;
    }

    private function completeConstraints(): void
    {
        $indexes = [
            ['product_component_profiles', ['product_id'], 'product_component_profiles_product_id_unique', true],
            ['product_component_profiles', ['component_type', 'review_status'], 'product_component_profiles_component_type_review_status_index', false],
            ['component_specification_revisions', ['product_component_profile_id', 'revision'], 'component_spec_profile_revision_unique', true],
            ['component_specification_revisions', ['review_status', 'updated_at'], 'component_specification_revisions_review_status_updated_at_index', false],
            ['pc_builds', ['uuid'], 'pc_builds_uuid_unique', true],
            ['pc_builds', ['share_token'], 'pc_builds_share_token_unique', true],
            ['pc_builds', ['user_id', 'updated_at'], 'pc_builds_user_id_updated_at_index', false],
            ['pc_build_items', ['item_key'], 'pc_build_items_item_key_unique', true],
            ['pc_build_items', ['pc_build_id', 'slot_key'], 'pc_build_items_build_slot_unique', true],
            ['pc_build_items', ['pc_build_id', 'component_type'], 'pc_build_items_pc_build_id_component_type_index', false],
            ['pc_build_events', ['event_uuid'], 'pc_build_events_event_uuid_unique', true],
            ['pc_build_events', ['event_type', 'occurred_at'], 'pc_build_events_event_type_occurred_at_index', false],
            ['pc_build_events', ['pc_build_id', 'event_type', 'occurred_at'], 'pc_build_events_pc_build_id_event_type_occurred_at_index', false],
        ];

        foreach ($indexes as [$table, $columns, $name, $unique]) {
            if (! Schema::hasIndex($table, $columns, $unique ? 'unique' : null)) {
                Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $unique): void {
                    if ($unique) {
                        $blueprint->unique($columns, $name);
                    } else {
                        $blueprint->index($columns, $name);
                    }
                });
            }
        }

        $foreignKeys = [
            ['product_component_profiles', 'product_id', 'products', 'restrict', 'product_component_profiles_product_id_foreign'],
            ['component_specification_revisions', 'product_component_profile_id', 'product_component_profiles', 'restrict', 'component_spec_revision_profile_fk'],
            ['component_specification_revisions', 'reviewed_by_id', 'users', 'set null', 'component_specification_revisions_reviewed_by_id_foreign'],
            ['pc_builds', 'user_id', 'users', 'cascade', 'pc_builds_user_id_foreign'],
            ['pc_build_items', 'pc_build_id', 'pc_builds', 'cascade', 'pc_build_items_pc_build_id_foreign'],
            ['pc_build_items', 'product_id', 'products', 'restrict', 'pc_build_items_product_id_foreign'],
            ['pc_build_items', 'component_specification_revision_id', 'component_specification_revisions', 'restrict', 'pc_build_items_component_specification_revision_id_foreign'],
            ['pc_build_events', 'pc_build_id', 'pc_builds', 'set null', 'pc_build_events_pc_build_id_foreign'],
            ['pc_build_events', 'user_id', 'users', 'set null', 'pc_build_events_user_id_foreign'],
        ];

        foreach ($foreignKeys as [$table, $column, $parent, $onDelete, $name]) {
            $existing = collect(Schema::getForeignKeys($table))->firstWhere('columns', [$column]);
            if ($existing !== null) {
                if ($existing['foreign_table'] !== $parent || $existing['foreign_columns'] !== ['id'] || $existing['on_delete'] !== $onDelete) {
                    throw new LogicException("Existing PC Builder foreign key [{$table}.{$column}] differs from the required relationship. Preserve its data and reconcile the schema before retrying.");
                }

                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column, $parent, $onDelete, $name): void {
                $blueprint->foreign($column, $name)->references('id')->on($parent)->onDelete($onDelete);
            });
        }
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
