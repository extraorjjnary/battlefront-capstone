<?php

namespace Database\Factories;

use App\Models\PcBuildTemplate;
use App\Models\PcBuildTemplateItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PcBuildTemplateItem>
 */
class PcBuildTemplateItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pc_build_template_id' => PcBuildTemplate::factory(),
            'product_id' => Product::factory(),
            'component_type' => 'processor',
            'slot_key' => 'processor-main',
            'quantity' => 1,
            'position' => 0,
            'configuration' => null,
        ];
    }
}
