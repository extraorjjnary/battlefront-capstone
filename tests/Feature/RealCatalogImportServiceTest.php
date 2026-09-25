<?php

use App\Models\Product;
use App\Services\RealCatalogImportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

function realCatalogManifest(array $overrides = []): string
{
    $disk = Storage::disk('public');
    $path = 'products/graphics-card/00123.webp';
    $disk->makeDirectory(dirname($path));
    $image = imagecreatetruecolor(1024, 1024);
    imagefill($image, 0, 0, imagecolorallocate($image, 25, 25, 25));
    imagewebp($image, $disk->path($path), 85);
    $bytes = file_get_contents($disk->path($path));

    $manifest = tempnam(sys_get_temp_dir(), 'real-catalog-manifest-');
    $entry = array_replace([
        'product_code' => '00123',
        'name' => 'Exact Product™',
        'category' => 'Graphics Card',
        'category_slug' => 'graphics-card',
        'quantity' => '5',
        'price' => '7500',
        'price_tier_tags' => 'Mid-Range',
        'use_case_tags' => 'Gaming, Productivity',
        'special_traits_tags' => '',
        'image_path' => $path,
        'status' => 'complete',
        'image_sha256' => hash('sha256', $bytes),
        'image_bytes' => strlen($bytes),
        'image_width' => 1024,
        'image_height' => 1024,
    ], $overrides);
    file_put_contents($manifest, json_encode(['version' => 1, 'entries' => [$entry]], JSON_THROW_ON_ERROR));

    return $manifest;
}

function realCatalogMapping(array $rows = [['00123', 'Exact Product™', 'Graphics Card', 'Biostar', '', '3']]): string
{
    $path = tempnam(sys_get_temp_dir(), 'real-catalog-mapping-');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['product_code', 'product_name', 'category', 'brand', 'quantity_override', 'reorder_level']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

test('details template lists exact product codes without inventing missing fields or overwriting a review', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest(['status' => 'pending']);
    $template = sys_get_temp_dir().'/verified-details-'.bin2hex(random_bytes(8)).'.csv';

    try {
        $count = app(RealCatalogImportService::class)->writeTemplate($template, $manifest);
        $rows = array_map('str_getcsv', file($template));

        expect($count)->toBe(1)
            ->and($rows)->toBe([
                ['product_code', 'product_name', 'category', 'brand', 'quantity_override', 'reorder_level'],
                ['00123', 'Exact Product™', 'Graphics Card', '', '', ''],
            ])
            ->and(fn () => app(RealCatalogImportService::class)->writeTemplate($template, $manifest))
            ->toThrow(RuntimeException::class);
    } finally {
        unlink($manifest);
        if (is_file($template)) {
            unlink($template);
        }
    }
});

test('dry run validates complete images and verified details without changing product data', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping();

    try {
        $status = Artisan::call('catalog:import-real', [
            'mapping' => $mapping,
            '--manifest' => $manifest,
        ]);

        expect($status)->toBe(0);
        $this->assertDatabaseCount('products', 0);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('apply imports exact names and image paths by code without resetting existing stock', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping();

    try {
        $first = app(RealCatalogImportService::class)->execute($mapping, $manifest);
        $product = Product::query()->where('product_code', '00123')->firstOrFail();
        $product->inventory()->update(['quantity' => 2]);
        $second = app(RealCatalogImportService::class)->execute($mapping, $manifest);

        expect($first)->toBe(['created' => 1, 'updated' => 0])
            ->and($second)->toBe(['created' => 0, 'updated' => 1])
            ->and($product->refresh()->name)->toBe('Exact Product™')
            ->and($product->brand)->toBe('Biostar')
            ->and($product->image_path)->toBe('products/graphics-card/00123.webp')
            ->and($product->category->name)->toBe('Graphics Card')
            ->and($product->inventory->quantity)->toBe(2)
            ->and($product->inventory->reorder_level)->toBe(3)
            ->and($product->tags->pluck('name')->sort()->values()->all())->toBe(['Gaming', 'Mid-Range', 'Productivity']);
        $this->assertDatabaseCount('products', 1);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('missing verified quantity blocks an import before any product is created', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest(['quantity' => null]);
    $mapping = realCatalogMapping();

    try {
        expect(fn () => app(RealCatalogImportService::class)->execute($mapping, $manifest))
            ->toThrow(RuntimeException::class, 'Verified quantity is required for code 00123.');
        $this->assertDatabaseCount('products', 0);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('verified details cannot replace the exact spreadsheet product name', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping([['00123', 'Changed Product Name', 'Graphics Card', 'Biostar', '', '3']]);

    try {
        expect(fn () => app(RealCatalogImportService::class)->execute($mapping, $manifest))
            ->toThrow(RuntimeException::class, 'Verified details do not match the exact source name and category for code 00123.');
        $this->assertDatabaseCount('products', 0);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('verified quantity fills a blank spreadsheet cell without changing the source name', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest(['quantity' => null]);
    $mapping = realCatalogMapping([['00123', 'Exact Product™', 'Graphics Card', 'Biostar', '7', '3']]);

    try {
        app(RealCatalogImportService::class)->execute($mapping, $manifest);

        $product = Product::query()->where('product_code', '00123')->firstOrFail();
        expect($product->name)->toBe('Exact Product™')
            ->and($product->inventory->quantity)->toBe(7);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});
