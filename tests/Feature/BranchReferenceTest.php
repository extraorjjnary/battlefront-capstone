<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('the branch schema follows the manuscript ERD', function () {
    expect(Schema::getColumnListing('branches'))->toEqualCanonicalizing([
        'id',
        'name',
        'address',
        'city',
        'contact_number',
        'latitude',
        'longitude',
    ])->and(Schema::hasColumn('users', 'branch_id'))->toBeTrue();
});

test('a user may belong to a branch', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->for($branch)->create();

    expect($user->branch->is($branch))->toBeTrue()
        ->and($branch->users->first()->is($user))->toBeTrue()
        ->and(fn () => $branch->delete())->toThrow(QueryException::class);
});

test('branch reference data can be seeded repeatedly', function () {
    $this->seed();
    Branch::query()->where('city', 'Sagay City')->update(['contact_number' => 'outdated']);

    $this->seed();

    expect(Branch::query()->count())->toBe(4)
        ->and(User::query()->where('email', 'test@example.com')->count())->toBe(1);
    $this->assertDatabaseHas('branches', [
        'city' => 'Sagay City',
        'contact_number' => '0938 647 6046',
    ]);
    $this->assertDatabaseHas('branches', [
        'city' => 'San Carlos City',
        'contact_number' => null,
    ]);
    $this->assertDatabaseHas('branches', [
        'city' => 'Guihulngan City',
        'contact_number' => '0947 946 5723',
    ]);
    $this->assertDatabaseHas('branches', [
        'city' => 'Escalante City',
        'address' => null,
        'contact_number' => null,
    ]);
});

test('branches expose configured reference information', function () {
    $this->seed();

    $branches = Branch::query()->get()->keyBy('city');

    expect(Branch::operational()->sole()->city)->toBe('Sagay City')
        ->and($branches['Sagay City']->toArray())->toMatchArray([
            'email' => 'battlefrontcomputertrading@gmail.com',
            'operating_hours' => '8:00 AM–6:00 PM',
            'is_operational' => true,
        ])
        ->and($branches['Sagay City']->is_operational)->toBeTrue()
        ->and($branches['San Carlos City']->is_operational)->toBeFalse()
        ->and($branches->pluck('operating_hours')->unique()->all())->toBe(['8:00 AM–6:00 PM'])
        ->and($branches['Sagay City']->email)->toBe('battlefrontcomputertrading@gmail.com')
        ->and($branches['Guihulngan City']->email)->toBe('battlefrontcomputertrading@gmail.com')
        ->and($branches['San Carlos City']->email)->toBeNull()
        ->and($branches['Escalante City']->email)->toBeNull();
});
