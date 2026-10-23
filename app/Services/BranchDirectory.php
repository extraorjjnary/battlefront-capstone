<?php

namespace App\Services;

use App\Models\Branch;

/**
 * @phpstan-type BranchReference array{
 *     id: int, name: string, address: string|null, city: string,
 *     contact_number: string|null, latitude: float|null, longitude: float|null,
 *     email: string|null, operating_hours: string, is_operational: bool, operational_notice: string|null
 * }
 */
class BranchDirectory
{
    /** @return array{label: string, branch: array{name: string, city: string, address: string, contact_number: string|null}|null, message: string} */
    public function returnInstructions(): array
    {
        $branch = Branch::query()->published()->operational()->first();

        return [
            'label' => 'Return instructions',
            'branch' => $branch !== null && filled($branch->address) ? [
                'name' => $branch->name, 'city' => $branch->city, 'address' => $branch->address,
                'contact_number' => $branch->contact_number,
            ] : null,
            'message' => 'Pack securely, including opened items, and include your order/request number. Use original packaging if available. Contact us before courier shipping (e.g. LBC) to confirm delivery and charges. Returns require inspection and approval.',
        ];
    }

    /**
     * Return confirmed branch information in customer display order.
     *
     * @return array<int, BranchReference>
     */
    public function all(): array
    {
        return Branch::query()
            ->published()
            ->get()
            ->sortBy([
                ['is_operational', 'desc'],
                ['city', 'asc'],
            ])
            ->values()
            ->map($this->present(...))
            ->all();
    }

    /**
     * @return BranchReference
     */
    private function present(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'address' => $branch->address,
            'city' => $branch->city,
            'contact_number' => $branch->contact_number,
            'latitude' => $branch->latitude !== null
                ? round((float) $branch->latitude, 7)
                : null,
            'longitude' => $branch->longitude !== null
                ? round((float) $branch->longitude, 7)
                : null,
            'email' => $branch->email,
            'operating_hours' => $branch->operating_hours ?? '',
            'is_operational' => $branch->is_operational,
            'operational_notice' => $branch->is_operational
                && $branch->city === config('battlefront.operational_branch_city')
                    ? config('battlefront.sagay_cod_notice')
                    : null,
        ];
    }
}
