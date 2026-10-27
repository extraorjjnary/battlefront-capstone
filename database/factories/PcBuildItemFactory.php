<?php

namespace Database\Factories;

use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PcBuildItem>
 */
class PcBuildItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pc_build_id' => PcBuild::factory(),
            'item_key' => (string) Str::uuid(),
            'product_id' => Product::factory(),
            'component_specification_revision_id' => null,
            'component_type' => 'processor',
            'slot_key' => 'processor',
            'quantity' => 1,
            'position' => 0,
            'configuration' => null,
        ];
    }
}
