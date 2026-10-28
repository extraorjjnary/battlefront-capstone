<?php

namespace App\Services\PcBuilder;

use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComponentSpecificationService
{
    /**
     * @param  array{product_id: int, component_type: string, manufacturer_part_number: string|null, hardware_revision: string|null, edit_version?: int, specifications: array<string, mixed>, evidence: list<array<string, string>>}  $data
     */
    public function saveDraft(array $data): ProductComponentProfile
    {
        return DB::transaction(function () use ($data): ProductComponentProfile {
            Product::query()->whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            $profile = ProductComponentProfile::query()
                ->where('product_id', $data['product_id'])
                ->lockForUpdate()
                ->first();

            if ($profile !== null) {
                if (! isset($data['edit_version']) || $profile->edit_version !== $data['edit_version']) {
                    throw ValidationException::withMessages(['edit_version' => 'This specification changed in another session. Reload before saving.']);
                }
                if ($profile->component_type !== $data['component_type']) {
                    throw ValidationException::withMessages(['component_type' => 'A product’s component category cannot be changed after its profile has been created.']);
                }
            } else {
                $profile = ProductComponentProfile::query()->create([
                    'product_id' => $data['product_id'],
                    'component_type' => $data['component_type'],
                    'manufacturer_part_number' => $data['manufacturer_part_number'] ?? null,
                    'hardware_revision' => $data['hardware_revision'] ?? null,
                    'review_status' => 'draft',
                    'edit_version' => 1,
                ]);
            }

            $nextRevision = (int) $profile->revisions()->max('revision') + 1;
            $revision = $profile->revisions()->create([
                'revision' => $nextRevision,
                'schema_version' => 1,
                'specifications' => $data['specifications'],
                'evidence' => $data['evidence'],
                'review_status' => 'draft',
            ]);
            $profile->forceFill([
                'manufacturer_part_number' => $data['manufacturer_part_number'] ?? null,
                'hardware_revision' => $data['hardware_revision'] ?? null,
                'current_revision' => $revision->revision,
                'review_status' => 'draft',
                'edit_version' => $profile->edit_version + 1,
            ])->save();

            return $profile->load(['product', 'revisions']);
        }, attempts: 3);
    }

    public function approve(ProductComponentProfile $profile, User $reviewer, int $editVersion): ProductComponentProfile
    {
        return DB::transaction(function () use ($profile, $reviewer, $editVersion): ProductComponentProfile {
            $profile = ProductComponentProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            if ($profile->edit_version !== $editVersion) {
                throw ValidationException::withMessages(['edit_version' => 'This specification changed in another session. Reload before approving.']);
            }

            $draft = $profile->revisions()->where('revision', $profile->current_revision)->firstOrFail();
            $partNumber = trim((string) $profile->manufacturer_part_number);
            $hasExactEvidence = collect($draft->evidence ?? [])->contains(
                fn (array $evidence): bool => isset($evidence['variant'])
                    && mb_strtolower(trim($evidence['variant'])) === mb_strtolower($partNumber),
            );

            if ($partNumber === '' || $draft->specifications === [] || ! $hasExactEvidence) {
                throw ValidationException::withMessages([
                    'review' => 'Approval requires a part number, technical attributes, and a source that identifies the exact product variant.',
                ]);
            }

            $approved = $profile->revisions()->create([
                'revision' => (int) $profile->revisions()->max('revision') + 1,
                'schema_version' => $draft->schema_version,
                'specifications' => $draft->specifications,
                'evidence' => $draft->evidence,
                'review_status' => 'approved',
                'reviewed_by_id' => $reviewer->id,
                'reviewed_at' => now(),
            ]);
            $profile->forceFill([
                'current_revision' => $approved->revision,
                'review_status' => 'approved',
                'edit_version' => $profile->edit_version + 1,
            ])->save();

            return $profile->load(['product', 'revisions']);
        }, attempts: 3);
    }
}
