<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Services\PcBuilder\ComponentSpecificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PcBuilderCatalogDraftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $json = file_get_contents(resource_path('data/pc-builder-catalog.json'));
        if ($json === false) {
            throw new \RuntimeException('The reviewed Builder catalog mapping is unavailable.');
        }
        $candidates = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($candidates): void {
            foreach ($candidates as $candidate) {
                $matches = Product::query()->customerEligible()->where('name', $candidate['name'])
                    ->whereHas('category', fn ($query) => $query->where('name', $candidate['category']))
                    ->lockForUpdate()->get();
                if ($matches->count() !== 1) {
                    continue;
                }
                $profile = $matches->first()->componentProfile()->with('revisions')->first();
                if ($profile !== null) {
                    $revision = $profile->revisions->firstWhere('revision', $profile->current_revision);
                    if (! isset($candidate['evidence']) || $profile->review_status !== 'draft'
                        || $profile->manufacturer_part_number !== null || $profile->hardware_revision !== null
                        || $profile->current_revision !== 1 || $profile->edit_version !== 2
                        || $revision?->specifications !== [] || $revision->evidence !== []) {
                        continue;
                    }
                }

                app(ComponentSpecificationService::class)->saveDraft([
                    'product_id' => $matches->first()->id,
                    'component_type' => $candidate['component_type'],
                    'manufacturer_part_number' => $candidate['manufacturer_part_number'] ?? null,
                    'hardware_revision' => null,
                    'specifications' => $candidate['specifications'] ?? [],
                    'evidence' => $candidate['evidence'] ?? [],
                    ...($profile === null ? [] : ['edit_version' => $profile->edit_version]),
                ]);
            }
        });
    }
}
