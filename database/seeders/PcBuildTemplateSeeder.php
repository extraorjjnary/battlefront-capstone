<?php

namespace Database\Seeders;

use App\Models\PcBuildTemplate;
use Illuminate\Database\Seeder;

class PcBuildTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach ([
            '91000000-0000-4000-8000-000000000001' => 'Budget Gaming PC',
            '91000000-0000-4000-8000-000000000002' => 'Mid-Range Gaming PC',
            '91000000-0000-4000-8000-000000000003' => 'High-End Gaming PC',
            '91000000-0000-4000-8000-000000000004' => 'Office PC',
            '91000000-0000-4000-8000-000000000005' => 'Student PC',
            '91000000-0000-4000-8000-000000000006' => 'Editing and Productivity PC',
        ] as $uuid => $name) {
            if (PcBuildTemplate::query()->where('uuid', $uuid)->exists()) {
                continue;
            }

            $template = new PcBuildTemplate;
            $template->uuid = $uuid;
            $template->fill([
                'name' => $name,
                'description' => 'Add active catalog products with reviewed specifications before publishing this template.',
                'budget' => null,
                'is_active' => false,
                'edit_version' => 1,
            ])->save();
        }
    }
}
