<?php

use App\Models\Branch;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('branch management migration preserves existing records and imports configured reference details', function () {
    $this->seed(BranchSeeder::class);
    DB::table('branches')->where('city', 'Sagay City')->update(['name' => 'Edited name', 'contact_number' => '0999 123 4567']);
    $columns = ['id', 'name', 'city', 'address', 'contact_number', 'latitude', 'longitude'];
    $before = DB::table('branches')->select($columns)->orderBy('id')->get()->toArray();
    Schema::table('branches', function (Blueprint $table) {
        $table->dropUnique('branches_seed_key_unique');
        $table->dropColumn(['email', 'operating_hours', 'is_published', 'seed_key']);
    });
    config(['battlefront.operating_hours' => 'Verified custom hours']);
    $migration = require database_path('migrations/2026_10_20_092842_add_branch_management_fields_to_branches_table.php');

    $migration->up();

    expect(DB::table('branches')->select($columns)->orderBy('id')->get()->toArray())->toEqual($before);
    $this->assertDatabaseHas('branches', ['city' => 'Sagay City', 'seed_key' => 'sagay-city', 'is_published' => true,
        'email' => 'battlefrontcomputertrading@gmail.com', 'operating_hours' => 'Verified custom hours']);
    $this->assertDatabaseHas('branches', ['city' => 'Escalante City', 'email' => null, 'is_published' => true]);
    $newId = DB::table('branches')->insertGetId(['name' => 'Later record', 'city' => 'New City']);
    $this->assertDatabaseHas('branches', ['id' => $newId, 'is_published' => false, 'email' => null, 'operating_hours' => null]);
});

test('reseed preserves edited and renamed seed records and unpublished choices', function () {
    $this->seed(BranchSeeder::class);
    $branch = Branch::query()->where('city', 'Bacolod City')->sole();
    $branch->update(['name' => 'Admin name', 'city' => 'Corrected City', 'email' => 'admin@example.test',
        'operating_hours' => '10 AM–3 PM', 'latitude' => null, 'longitude' => null, 'is_published' => false]);
    $before = $branch->refresh()->getAttributes();

    $this->seed(BranchSeeder::class);

    expect($branch->refresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('branches', 5);
    $this->assertDatabaseMissing('branches', ['city' => 'Bacolod City']);
});

test('seeding an occupied reference city preserves administrator data and publication', function () {
    $branch = Branch::factory()->unpublished()->create(['city' => 'Bacolod City', 'email' => 'admin@example.test']);

    $this->seed(BranchSeeder::class);

    expect($branch->refresh()->seed_key)->toBe('bacolod-city');
    expect($branch->is_published)->toBeFalse();
    expect($branch->email)->toBe('admin@example.test');
    $this->assertDatabaseCount('branches', 5);
});

test('rollback refuses to discard administrator-owned branch data', function () {
    $branch = Branch::factory()->create(['email' => 'admin@example.test']);
    $before = $branch->refresh()->toArray();
    $migration = require database_path('migrations/2026_10_20_092842_add_branch_management_fields_to_branches_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Use a forward migration');

    expect($branch->refresh()->toArray())->toBe($before);
});
