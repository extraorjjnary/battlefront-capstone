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
                'latitude' => 10.893690028077906,
                'longitude' => 123.41372677628254,
            ],
            [
                'name' => 'Battlefront Computer Trading',
                'address' => null,
                'city' => 'Escalante City',
                'contact_number' => null,
                'latitude' => 10.84283002809064,
                'longitude' => 123.49853947111953,
            ],
            [
                'name' => 'Battlefront Computer Trading',
                'address' => 'Carmona St., Brgy. V, San Carlos City, Negros Occidental, San Carlos City, Philippines 6127',
                'city' => 'San Carlos City',
                'contact_number' => null,
                'latitude' => 10.482947521818934,
                'longitude' => 123.42169185526996,
            ],
            [
                'name' => 'Battlefront Computer Trading',
                'address' => 'L&E Arcade, Larena St., Brgy. Poblacion, Guihulngan City, Guihulngan, Philippines 6214',
                'city' => 'Guihulngan City',
                'contact_number' => '0947 946 5723',
                'latitude' => 10.119597,
                'longitude' => 123.273872,
            ],
            [
                'name' => 'Battlefront Computer Trading',
                'address' => 'Downtown, Along SKG Shopping Center, Beside Ukay-Ukayan 58 Lizares St. Brgy. 13, Bacolod CIty, Philippines, 6100',
                'city' => 'Bacolod City',
                'contact_number' => '0961 176 4608',
                'latitude' => 10.671754246079693,
                'longitude' => 122.9470409276533,
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
