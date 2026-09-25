<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RealCatalogImportService
{
    public function __construct(private readonly CatalogImagePipeline $images) {}

    public function writeTemplate(string $outputPath, ?string $manifestPath = null): int
    {
        $entries = $this->images->entries($manifestPath);
        if (! is_dir(dirname($outputPath))) {
            throw new RuntimeException('The verified details template directory does not exist.');
        }

        $handle = @fopen($outputPath, 'x');
        if ($handle === false) {
            throw new RuntimeException('The verified details template already exists or cannot be created.');
        }

        try {
            if (fputcsv($handle, ['product_code', 'product_name', 'category', 'brand', 'quantity_override', 'reorder_level'], ',', '"', '') === false) {
                throw new RuntimeException('The verified details template could not be written.');
            }
            foreach ($entries as $entry) {
                if (fputcsv($handle, [$entry['product_code'], $entry['name'], $entry['category'], '', '', ''], ',', '"', '') === false) {
                    throw new RuntimeException('The verified details template could not be written.');
                }
            }
            if (! fflush($handle)) {
                throw new RuntimeException('The verified details template could not be flushed.');
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            unlink($outputPath);

            throw $exception;
        }
        fclose($handle);

        return count($entries);
    }

    /**
     * Validate all images and verified supplemental fields before any database write.
     *
     * @return list<array<string, mixed>>
     */
    public function inspect(string $mappingPath, ?string $manifestPath = null): array
    {
        $entries = $this->images->importEntries($manifestPath);
        $mapping = $this->readMapping($mappingPath);
        $prepared = [];

        foreach ($entries as $entry) {
            $code = $entry['product_code'];
            if (! isset($mapping[$code])) {
                throw new RuntimeException("Verified product details are missing for code $code.");
            }

            $details = $mapping[$code];
            $name = $entry['name'] ?? null;
            $category = $entry['category'] ?? null;
            $price = $entry['price'] ?? null;
            $sourceQuantity = $entry['quantity'] ?? null;
            if (! is_string($name) || $name === '' || mb_strlen($name) > 255
                || ! is_string($category) || $category === '' || mb_strlen($category) > 255
                || ! is_string($price) || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $price) || ! is_numeric($price)
                || bccomp($price, '9999999999.99', 2) > 0) {
                throw new RuntimeException("Invalid catalog fields for code $code.");
            }
            if ($details['name'] !== $name || $details['category'] !== $category) {
                throw new RuntimeException("Verified details do not match the exact source name and category for code $code.");
            }

            if ($sourceQuantity === null || $sourceQuantity === '') {
                if ($details['quantity_override'] === '') {
                    throw new RuntimeException("Verified quantity is required for code $code.");
                }
                $quantity = $details['quantity_override'];
            } else {
                if ($details['quantity_override'] !== '') {
                    throw new RuntimeException("Quantity override is not allowed for code $code.");
                }
                $quantity = $sourceQuantity;
            }

            if (! $this->isUnsignedInventoryValue($quantity)) {
                throw new RuntimeException("Invalid quantity for code $code.");
            }

            $tagNames = [];
            foreach (['price_tier_tags', 'use_case_tags', 'special_traits_tags'] as $field) {
                foreach (explode(',', (string) ($entry[$field] ?? '')) as $tag) {
                    $tag = trim($tag);
                    if ($tag !== '' && ! in_array($tag, $tagNames, true)) {
                        if (mb_strlen($tag) > 255) {
                            throw new RuntimeException("Tag is too long for code $code.");
                        }
                        $tagNames[] = $tag;
                    }
                }
            }

            $prepared[] = [
                'product_code' => $code,
                'name' => $name,
                'category' => $category,
                'brand' => $details['brand'],
                'price' => $price,
                'quantity' => (int) $quantity,
                'reorder_level' => (int) $details['reorder_level'],
                'image_path' => $entry['image_path'],
                'tags' => $tagNames,
            ];
            unset($mapping[$code]);
        }

        if ($mapping !== []) {
            throw new RuntimeException('Verified product details contain codes absent from the catalog manifest.');
        }

        return $prepared;
    }

    /**
     * @return array{created: int, updated: int}
     */
    public function execute(string $mappingPath, ?string $manifestPath = null): array
    {
        $prepared = $this->inspect($mappingPath, $manifestPath);

        return DB::transaction(function () use ($prepared): array {
            $created = 0;
            $updated = 0;
            foreach ($prepared as $row) {
                $category = Category::query()->firstOrCreate(['name' => $row['category']]);
                $product = Product::query()->firstOrNew(['product_code' => $row['product_code']]);
                $isNew = ! $product->exists;
                $product->fill([
                    'name' => $row['name'],
                    'category_id' => $category->id,
                    'brand' => $row['brand'],
                    'price' => $row['price'],
                    'image_path' => $row['image_path'],
                ]);
                $product->save();

                Inventory::query()->firstOrCreate(
                    ['product_id' => $product->id],
                    ['quantity' => $row['quantity'], 'reorder_level' => $row['reorder_level']],
                );

                $tagIds = [];
                foreach ($row['tags'] as $tagName) {
                    $tagIds[] = Tag::query()->firstOrCreate(['name' => $tagName])->id;
                }
                $product->tags()->sync($tagIds);

                if ($isNew) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * @return array<string, array{name: string, category: string, brand: string, quantity_override: string, reorder_level: string}>
     */
    private function readMapping(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('The verified product details CSV is not readable.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false) {
                throw new RuntimeException('The verified product details CSV is empty.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            if ($header !== ['product_code', 'product_name', 'category', 'brand', 'quantity_override', 'reorder_level']) {
                throw new RuntimeException('The verified product details CSV has unexpected columns.');
            }

            $mapping = [];
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                if (count($cells) !== 6 || ! is_string($cells[0]) || ! is_string($cells[1]) || ! is_string($cells[2]) || ! is_string($cells[3]) || ! is_string($cells[4]) || ! is_string($cells[5])) {
                    throw new RuntimeException('The verified product details CSV contains an invalid row.');
                }

                [$code, $name, $category, $brand, $quantityOverride, $reorderLevel] = $cells;
                $brand = trim($brand);
                if (! preg_match('/^[A-Za-z0-9]{1,64}$/D', $code) || isset($mapping[$code])
                    || $brand === '' || mb_strlen($brand) > 255
                    || ! $this->isUnsignedInventoryValue($reorderLevel)
                    || ($quantityOverride !== '' && ! $this->isUnsignedInventoryValue($quantityOverride))) {
                    throw new RuntimeException("Invalid or duplicate verified product details for code $code.");
                }

                $mapping[$code] = [
                    'name' => $name,
                    'category' => $category,
                    'brand' => $brand,
                    'quantity_override' => $quantityOverride,
                    'reorder_level' => $reorderLevel,
                ];
            }

            return $mapping;
        } finally {
            fclose($handle);
        }
    }

    private function isUnsignedInventoryValue(string $value): bool
    {
        return preg_match('/^\d+$/D', $value) === 1 && strlen($value) <= 10 && (float) $value <= 4294967295;
    }
}
