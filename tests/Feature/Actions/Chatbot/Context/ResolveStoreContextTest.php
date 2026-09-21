<?php

use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Models\Branch;
use Database\Seeders\BranchSeeder;

test('returns confirmed configured data for a named branch', function () {
    $this->seed(BranchSeeder::class);

    $context = (new ResolveStoreContext)->execute('Where is the Sagay store?');

    expect($context['branches'])->toHaveCount(1)
        ->and($context['branches'][0])->toBe([
            'name' => 'Battlefront Computer Trading',
            'city' => 'Sagay City',
            'address' => "A, E Mara\u{00F1}on St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122",
            'contact_number' => '0938 647 6046',
            'email' => 'battlefrontcomputertrading@gmail.com',
            'operating_hours' => config('battlefront.operating_hours'),
            'is_operational' => true,
        ]);
});

test('returns the branch directory with the operational branch first for a general inquiry', function () {
    $this->seed(BranchSeeder::class);

    $context = (new ResolveStoreContext)->execute('What are your store hours?');

    expect($context['branches'])->toHaveCount(Branch::query()->count())
        ->and($context['branches'][0]['city'])->toBe('Sagay City')
        ->and(array_keys($context['branches'][0]))->toBe([
            'name',
            'city',
            'address',
            'contact_number',
            'email',
            'operating_hours',
            'is_operational',
        ]);
});

test('returns explicit empty store context when no branch data exists', function () {
    $context = (new ResolveStoreContext)->execute('Where are your branches?');

    expect($context)->toBe(['branches' => []]);
});
