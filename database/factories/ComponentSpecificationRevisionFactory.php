<?php

namespace Database\Factories;

use App\Models\ComponentSpecificationRevision;
use App\Models\ProductComponentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComponentSpecificationRevision>
 */
class ComponentSpecificationRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_component_profile_id' => ProductComponentProfile::factory(),
            'revision' => 1,
            'schema_version' => 1,
            'specifications' => [],
            'evidence' => [],
            'review_status' => 'draft',
            'reviewed_by_id' => null,
            'reviewed_at' => null,
        ];
    }
}
