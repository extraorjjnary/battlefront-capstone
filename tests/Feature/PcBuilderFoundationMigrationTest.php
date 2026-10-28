<?php

use App\Models\ComponentSpecificationRevision;
use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\ProductComponentProfile;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the foundation migration resumes partially created tables without losing existing records', function () {
    $profile = ProductComponentProfile::factory()->create();
    $revision = ComponentSpecificationRevision::factory()->for($profile, 'profile')->create();
    $build = PcBuild::factory()->create(['share_token' => '91000000-0000-4000-8000-000000000001']);
    $originalBuild = (array) DB::table('pc_builds')->where('id', $build->id)->first();

    Schema::drop('pc_build_events');
    Schema::drop('pc_build_items');
    Schema::table('pc_builds', function (Blueprint $table): void {
        $table->dropUnique(['uuid']);
        $table->dropUnique(['share_token']);
        $table->dropIndex(['user_id', 'updated_at']);
        $table->dropForeign(['user_id']);
    });

    $migration = require database_path('migrations/2026_10_26_233428_create_pc_builder_foundation_tables.php');
    $migration->up();

    $this->assertModelExists($profile);
    $this->assertModelExists($revision);
    expect((array) DB::table('pc_builds')->where('id', $build->id)->first())->toBe($originalBuild);
    expect(Schema::hasTable('pc_build_events'))->toBeTrue();

    $item = PcBuildItem::factory()->for($build, 'build')->create([
        'product_id' => $profile->product_id,
        'component_specification_revision_id' => $revision->id,
    ]);
    $this->assertModelExists($item);

    foreach (['uuid', 'share_token'] as $column) {
        $duplicate = $originalBuild;
        unset($duplicate['id']);
        $duplicate['uuid'] = $column === 'uuid' ? $build->uuid : '91000000-0000-4000-8000-000000000002';
        $duplicate['share_token'] = $column === 'share_token' ? $build->share_token : null;

        expect(fn () => DB::table('pc_builds')->insert($duplicate))->toThrow(QueryException::class);
    }

    DB::table('users')->where('id', $build->user_id)->delete();

    $this->assertDatabaseMissing('pc_builds', ['id' => $build->id]);
    $this->assertDatabaseMissing('pc_build_items', ['id' => $item->id]);
});

test('the foundation migration can be retried after completion while preserving saved build items', function () {
    $item = PcBuildItem::factory()->create();
    $originalItem = (array) DB::table('pc_build_items')->where('id', $item->id)->first();
    $migration = require database_path('migrations/2026_10_26_233428_create_pc_builder_foundation_tables.php');

    $migration->up();
    $migration->up();

    expect((array) DB::table('pc_build_items')->where('id', $item->id)->first())->toBe($originalItem);
    $this->assertDatabaseCount('pc_build_items', 1);
});

test('the foundation migration refuses incompatible existing tables without discarding records', function () {
    $build = PcBuild::factory()->create();
    Schema::drop('pc_build_events');
    Schema::create('pc_build_events', function (Blueprint $table): void {
        $table->id();
    });
    $migration = require database_path('migrations/2026_10_26_233428_create_pc_builder_foundation_tables.php');

    expect(fn () => $migration->up())->toThrow(LogicException::class, 'Existing PC Builder table [pc_build_events] has unexpected columns.');

    $this->assertModelExists($build);
    expect(Schema::getColumnListing('pc_build_events'))->toBe(['id']);
});

test('the foundation migration preserves duplicate records when a missing unique index cannot be restored', function () {
    $build = PcBuild::factory()->create();
    Schema::table('pc_builds', function (Blueprint $table): void {
        $table->dropUnique(['uuid']);
    });
    $duplicate = (array) DB::table('pc_builds')->where('id', $build->id)->first();
    unset($duplicate['id']);
    DB::table('pc_builds')->insert($duplicate);
    $migration = require database_path('migrations/2026_10_26_233428_create_pc_builder_foundation_tables.php');

    expect(fn () => $migration->up())->toThrow(QueryException::class);

    expect(DB::table('pc_builds')->where('uuid', $build->uuid)->count())->toBe(2);
});

test('MySQL resumes the pending builder migrations from partial foundation tables and preserves existing data', function () {
    if (getenv('BATTLEFRONT_PC_BUILDER_MYSQL_TESTS') !== '1') {
        $this->markTestSkipped('Enable BATTLEFRONT_PC_BUILDER_MYSQL_TESTS=1 to use a disposable MySQL database.');
    }

    $originalConnection = DB::getDefaultConnection();
    $originalMysql = config('database.connections.mysql');
    $database = 'battlefront_pc_migration_test_'.bin2hex(random_bytes(6));
    $connection = $originalMysql;
    $connection['url'] = null;
    $connection['database'] = null;
    config(['database.connections.pc_migration_server' => $connection]);
    $server = DB::connection('pc_migration_server');
    $server->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    try {
        $connection['database'] = $database;
        config(['database.connections.mysql' => $connection]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        $pendingMigrations = [
            '2026_10_26_233428_create_pc_builder_foundation_tables',
            '2026_10_26_234831_add_pc_build_groups_to_cart_and_order_items',
            '2026_10_27_000247_create_pc_build_templates_table',
            '2026_10_27_085729_restore_cart_item_sqlite_quantity_constraints',
        ];
        $baselinePaths = array_values(array_filter(
            glob(database_path('migrations/*.php')) ?: [],
            fn (string $path): bool => ! in_array(pathinfo($path, PATHINFO_FILENAME), $pendingMigrations, true),
        ));
        Artisan::call('migrate', [
            '--database' => 'mysql', '--path' => $baselinePaths, '--realpath' => true,
            '--force' => true, '--no-interaction' => true,
        ]);
        $foundation = require database_path('migrations/'.$pendingMigrations[0].'.php');
        $foundation->up();

        $profile = ProductComponentProfile::factory()->create();
        $revision = ComponentSpecificationRevision::factory()->for($profile, 'profile')->create();
        $build = PcBuild::factory()->create();
        $originalProduct = (array) DB::table('products')->where('id', $profile->product_id)->first();
        $originalBuild = (array) DB::table('pc_builds')->where('id', $build->id)->first();

        Schema::drop('pc_build_events');
        Schema::drop('pc_build_items');
        Schema::table('pc_builds', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropUnique(['share_token']);
        });

        $result = Artisan::call('migrate', ['--database' => 'mysql', '--force' => true, '--no-interaction' => true]);

        expect($result)->toBe(0);
        $this->assertModelExists($profile);
        $this->assertModelExists($revision);
        expect((array) DB::table('products')->where('id', $profile->product_id)->first())->toBe($originalProduct);
        expect((array) DB::table('pc_builds')->where('id', $build->id)->first())->toBe($originalBuild);
        expect(DB::table('migrations')->whereIn('migration', $pendingMigrations)->count())->toBe(4);
        expect(Schema::hasIndex('pc_builds', ['uuid'], 'unique'))->toBeTrue();
        expect(Schema::hasIndex('pc_builds', ['share_token'], 'unique'))->toBeTrue();
        expect(Schema::hasTable('pc_build_template_items'))->toBeTrue();
        expect(Schema::hasColumn('cart_items', 'pc_build_cart_group_id'))->toBeTrue();
        expect(Schema::hasColumn('order_items', 'pc_build_component_snapshot'))->toBeTrue();

        PcBuildItem::factory()->for($build, 'build')->create([
            'product_id' => $profile->product_id,
            'component_specification_revision_id' => $revision->id,
        ]);
    } finally {
        DB::purge('mysql');
        config(['database.connections.mysql' => $originalMysql]);
        DB::setDefaultConnection($originalConnection);
        if (preg_match('/\Abattlefront_pc_migration_test_[a-f0-9]{12}\z/', $database) !== 1) {
            throw new LogicException('Refusing to clean up an unexpected database name.');
        }
        $server->statement('DROP DATABASE `'.$database.'`');
        DB::purge('pc_migration_server');
    }
});
