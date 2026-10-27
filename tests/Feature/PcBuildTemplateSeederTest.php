<?php

use App\Models\PcBuildTemplate;
use Database\Seeders\PcBuildTemplateSeeder;
use Illuminate\Support\Facades\DB;

test('local seeding creates unpublished template drafts without invented build data', function () {
    $this->seed(PcBuildTemplateSeeder::class);

    expect(PcBuildTemplate::query()->count())->toBe(6)
        ->and(PcBuildTemplate::query()->where('is_active', true)->count())->toBe(0)
        ->and(DB::table('pc_build_template_items')->count())->toBe(0)
        ->and(PcBuildTemplate::query()->whereNull('budget')->count())->toBe(6);
});

test('re-seeding preserves administrator edits to managed draft templates', function () {
    $this->seed(PcBuildTemplateSeeder::class);
    $template = PcBuildTemplate::query()->where('name', 'Student PC')->firstOrFail();
    $template->update(['name' => 'My reviewed student template', 'description' => 'Administrator notes']);

    $this->seed(PcBuildTemplateSeeder::class);

    expect(PcBuildTemplate::query()->count())->toBe(6)
        ->and($template->refresh()->name)->toBe('My reviewed student template')
        ->and($template->description)->toBe('Administrator notes');
});

test('template drafts are not seeded in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    app(PcBuildTemplateSeeder::class)->run();

    expect(PcBuildTemplate::query()->count())->toBe(0);
});
