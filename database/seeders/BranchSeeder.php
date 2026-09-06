<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branches = [
            [
                'name' => 'Battlefront Computer Trading',
                'address' => 'A, E Marañon St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122',
                'city' => 'Sagay City',
                'contact_number' => '0938 647 6046',
                'latitude' => null,
                'longitude' => null,
            ],
            [
                'name' => 'Battlefront Computer Trading',
                'address' => null,
                'city' => 'Escalante City',
                'contact_number' => null,
                'latitude' => null,
                'longitude' => null,
            ],
            [
                'name' => 'Battlefront Computer Trading',
                'address' => 'Carmona St., Brgy. V, San Carlos City, Negros Occidental, San Carlos City, Philippines 6127',
                'city' => 'San Carlos City',
                'contact_number' => null,
                'latitude' => null,
                'longitude' => null,
            ],
            [
                'name' => 'Battlefront Computer Trading',
                'address' => 'L&E Arcade, Larena St., Brgy. Poblacion, Guihulngan City, Guihulngan, Philippines 6214',
                'city' => 'Guihulngan City',
                'contact_number' => '0947 946 5723',
                'latitude' => null,
                'longitude' => null,
            ],
        ];

        foreach ($branches as $branch) {
            Branch::query()->updateOrCreate(
                ['city' => $branch['city']],
                $branch,
            );
        }
    }
}
