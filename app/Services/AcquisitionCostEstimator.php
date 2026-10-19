<?php

namespace App\Services;

class AcquisitionCostEstimator
{
    /**
     * @return numeric-string|null
     */
    public function forProduct(string $category, string $regularPrice): ?string
    {
        $percentage = match ($category) {
            'Processor', 'Graphics Card', 'Laptops & Desktops' => '0.92',
            'Motherboard', 'Monitor', 'Printer' => '0.88',
            'RAM', 'Storage' => '0.85',
            'Power Supply', 'PC Case', 'Networking', 'CCTV & Security' => '0.82',
            'Cooling Components', 'Peripherals', 'Audio Equipment', 'Power Accessories' => '0.75',
            'Cables & Adapters', 'Laptop Bags & Stands', 'Cleaning & Maintenance Supplies' => '0.65',
            'Service' => null,
            default => '0.80',
        };

        return $percentage === null
            ? null
            : bcadd(bcmul($this->numericAmount($regularPrice), $percentage, 4), '0.005', 2);
    }

    /** @return numeric-string */
    private function numericAmount(string $amount): string
    {
        if (! is_numeric($amount)) {
            throw new \InvalidArgumentException('The product price must be numeric.');
        }

        return $amount;
    }
}
