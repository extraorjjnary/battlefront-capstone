<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('package_weight_kg', 8, 3)->nullable();
            $table->decimal('package_length_cm', 8, 2)->nullable();
            $table->decimal('package_width_cm', 8, 2)->nullable();
            $table->decimal('package_height_cm', 8, 2)->nullable();
            $table->string('package_measurement_source', 16)->default('estimated');
        });

        $tiny = ['package_weight_kg' => '0.300', 'package_length_cm' => '18.00', 'package_width_cm' => '12.00', 'package_height_cm' => '7.00'];
        $estimates = [
            'Processor' => $tiny, 'RAM' => $tiny, 'Storage' => $tiny, 'Cables & Adapters' => $tiny,
            'DIY Electronics & Microcontrollers' => $tiny, 'Cleaning & Maintenance Supplies' => $tiny,
            'Office & Packaging Supplies' => $tiny, 'Service' => $tiny,
            'Power Supply' => ['package_weight_kg' => '1.600', 'package_length_cm' => '32.00', 'package_width_cm' => '22.00', 'package_height_cm' => '16.00'],
            'Graphics Card' => ['package_weight_kg' => '1.400', 'package_length_cm' => '35.00', 'package_width_cm' => '25.00', 'package_height_cm' => '12.00'],
            'Motherboard' => ['package_weight_kg' => '1.700', 'package_length_cm' => '36.00', 'package_width_cm' => '30.00', 'package_height_cm' => '12.00'],
            'Cooling Components' => ['package_weight_kg' => '1.000', 'package_length_cm' => '30.00', 'package_width_cm' => '22.00', 'package_height_cm' => '12.00'],
            'Peripherals' => ['package_weight_kg' => '0.800', 'package_length_cm' => '35.00', 'package_width_cm' => '22.00', 'package_height_cm' => '10.00'],
            'Laptop Bags & Stands' => ['package_weight_kg' => '1.500', 'package_length_cm' => '45.00', 'package_width_cm' => '35.00', 'package_height_cm' => '10.00'],
            'Networking' => ['package_weight_kg' => '1.000', 'package_length_cm' => '28.00', 'package_width_cm' => '22.00', 'package_height_cm' => '12.00'],
            'CCTV & Security' => ['package_weight_kg' => '2.000', 'package_length_cm' => '32.00', 'package_width_cm' => '26.00', 'package_height_cm' => '18.00'],
            'Audio Equipment' => ['package_weight_kg' => '2.500', 'package_length_cm' => '40.00', 'package_width_cm' => '30.00', 'package_height_cm' => '22.00'],
            'Power Accessories' => ['package_weight_kg' => '1.500', 'package_length_cm' => '32.00', 'package_width_cm' => '25.00', 'package_height_cm' => '15.00'],
            'Vending & Coin-Op Machine Parts' => ['package_weight_kg' => '1.800', 'package_length_cm' => '35.00', 'package_width_cm' => '28.00', 'package_height_cm' => '20.00'],
            'Solar & Lighting Products' => ['package_weight_kg' => '2.200', 'package_length_cm' => '45.00', 'package_width_cm' => '25.00', 'package_height_cm' => '18.00'],
            'Furniture & Hardware Fixtures' => ['package_weight_kg' => '5.000', 'package_length_cm' => '60.00', 'package_width_cm' => '40.00', 'package_height_cm' => '30.00'],
            'Bundles & Packages' => ['package_weight_kg' => '8.000', 'package_length_cm' => '55.00', 'package_width_cm' => '45.00', 'package_height_cm' => '35.00'],
            'PC Case' => ['package_weight_kg' => '6.000', 'package_length_cm' => '55.00', 'package_width_cm' => '30.00', 'package_height_cm' => '50.00'],
            'Monitor' => ['package_weight_kg' => '7.000', 'package_length_cm' => '72.00', 'package_width_cm' => '22.00', 'package_height_cm' => '48.00'],
            'Printer' => ['package_weight_kg' => '6.000', 'package_length_cm' => '50.00', 'package_width_cm' => '45.00', 'package_height_cm' => '30.00'],
            'Consumer Electronics' => ['package_weight_kg' => '1.200', 'package_length_cm' => '28.00', 'package_width_cm' => '22.00', 'package_height_cm' => '12.00'],
        ];
        $fallback = ['package_weight_kg' => '1.000', 'package_length_cm' => '25.00', 'package_width_cm' => '20.00', 'package_height_cm' => '10.00'];

        DB::table('products')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->select(['products.id', 'products.name', 'categories.name as category_name'])
            ->orderBy('products.id')
            ->chunk(500, function ($products) use ($estimates, $fallback): void {
                foreach ($products as $product) {
                    $estimate = $estimates[$product->category_name] ?? $fallback;
                    if ($product->category_name === 'Laptops & Desktops') {
                        $isLaptop = preg_match('/\\b(laptop|notebook)\\b/i', $product->name) === 1;
                        $estimate = $isLaptop
                            ? ['package_weight_kg' => '3.000', 'package_length_cm' => '45.00', 'package_width_cm' => '35.00', 'package_height_cm' => '12.00']
                            : ['package_weight_kg' => '9.000', 'package_length_cm' => '55.00', 'package_width_cm' => '45.00', 'package_height_cm' => '35.00'];
                    }
                    DB::table('products')->where('id', $product->id)->update([
                        ...$estimate,
                        'package_measurement_source' => 'estimated',
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['package_weight_kg', 'package_length_cm', 'package_width_cm', 'package_height_cm', 'package_measurement_source']);
        });
    }
};
