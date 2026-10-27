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

        $candidates = [
            'PROCESSOR AMD ATHLON 3000G VEGA 3 SOCKET AM4 GRAPHIC' => ['Processor', 'processor'],
            'PROCESSOR RYZEN 3 2200G 3.5GHZ' => ['Processor', 'processor'],
            'PROCESSOR AMD RYZEN™ 5 4500 (6CORES 12THREADS)' => ['Processor', 'processor'],
            'PROCESSOR AMD RYZEN™ 5 5600X (6CORES 12THREADS)' => ['Processor', 'processor'],
            'GIGABYTE B450M DS3H V2' => ['Motherboard', 'motherboard'],
            'MSI B450M-A PRO MAX II' => ['Motherboard', 'motherboard'],
            'FASPEED RAM DDR4 3200MHZ (8GB - ZS4-8G-PAB)' => ['RAM', 'memory'],
            'KINGSTON 2.5 (960GB - SA400S37)' => ['Storage', 'storage'],
            'SSD DATO DS700 256GB' => ['Storage', 'storage'],
            'Biostar EXtreme Gaming GTX 1660 Super' => ['Graphics Card', 'graphics_card'],
            'Inplay Ultra Series GS650 PSU 80+ Bronze' => ['Power Supply', 'power_supply'],
            'KEYTECH PSU GTS-550 80+ GOLD' => ['Power Supply', 'power_supply'],
            'INPLAY META A200 MICRO ATX PC CASE WITH 3 FANS-WHITE' => ['PC Case', 'case'],
            'CPU CASE KEYTECH ROBIN MINI (BLACK)' => ['PC Case', 'case'],
        ];

        DB::transaction(function () use ($candidates): void {
            foreach ($candidates as $name => [$category, $type]) {
                $matches = Product::query()->customerEligible()->where('name', $name)
                    ->whereHas('category', fn ($query) => $query->where('name', $category))
                    ->lockForUpdate()->get();
                if ($matches->count() !== 1 || $matches->first()->componentProfile()->exists()) {
                    continue;
                }

                $isMsi = $name === 'MSI B450M-A PRO MAX II';
                app(ComponentSpecificationService::class)->saveDraft([
                    'product_id' => $matches->first()->id,
                    'component_type' => $type,
                    'manufacturer_part_number' => $isMsi ? 'B450M-A PRO MAX II' : null,
                    'hardware_revision' => null,
                    'specifications' => $isMsi ? [
                        'socket' => 'AM4', 'chipset' => 'B450', 'memory_generations' => ['DDR4'],
                        'dimm_slots' => 2, 'maximum_memory_gb' => 64, 'form_factor' => 'mATX',
                    ] : [],
                    'evidence' => $isMsi ? [[
                        'url' => 'https://www.msi.com/Motherboard/B450M-A-PRO-MAX-II/Specification',
                        'title' => 'MSI B450M-A PRO MAX II specifications',
                        'section' => 'Chipset, CPU, Memory, PCB Info. CPU/BIOS support and conflicting lower-page legacy details require manual review.',
                        'verified_at' => '2026-10-27', 'variant' => 'B450M-A PRO MAX II',
                    ]] : [],
                ]);
            }
        });
    }
}
