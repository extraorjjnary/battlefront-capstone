<?php

namespace App\Services;

use App\Models\Branch;

/**
 * @phpstan-type BranchReference array{
 *     id: int, name: string, address: string|null, city: string,
 *     contact_number: string|null, latitude: float|null, longitude: float|null,
 *     email: string|null, operating_hours: string, is_operational: bool
 * }
 */
class BranchDirectory
{
    /**
     * Return confirmed branch information in customer display order.
     *
     * @return array<int, BranchReference>
     */
    public function all(): array
    {
        return Branch::query()
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
            'operating_hours' => $branch->operating_hours,
            'is_operational' => $branch->is_operational,
        ];
    }
}
