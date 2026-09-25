<?php

use App\Services\CatalogImagePipeline;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

function catalogImageManifest(array $overrides = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'catalog-manifest-');
    $entry = array_replace([
        'product_code' => 'A001',
        'name' => 'Exact Model Name',
        'category' => 'Graphics Card',
        'category_slug' => 'graphics-card',
        'source_file' => 'Graphics_Card.xlsx',
        'source_row' => 4,
        'reference_image' => 'images/demo-products/graphics-card.png',
        'prompt' => 'One exact graphics card on a dark studio background.',
        'image_path' => 'products/graphics-card/A001.webp',
        'status' => 'pending',
        'attempts' => 0,
        'last_error' => null,
        'image_sha256' => null,
        'image_bytes' => null,
        'image_width' => null,
        'image_height' => null,
    ], $overrides);

    file_put_contents($path, json_encode(['version' => 1, 'entries' => [$entry]], JSON_THROW_ON_ERROR));

    return $path;
}

function catalogImageSource(int $width = 1024, int $height = 1024): string
{
    $path = tempnam(sys_get_temp_dir(), 'catalog-source-');
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 20, 20, 20));
    imagerectangle($image, 100, 100, $width - 100, $height - 100, imagecolorallocate($image, 175, 20, 20));
    imagepng($image, $path);

    return $path;
}

test('a reviewed source creates one mapped WebP and completed work is skipped on resume', function () {
    Storage::fake('public');
    $manifest = catalogImageManifest();
    $source = catalogImageSource();

    try {
        $path = app(CatalogImagePipeline::class)->ingest('A001', $source, $manifest);
        $firstHash = hash_file('sha256', Storage::disk('public')->path($path));
        $resumePath = app(CatalogImagePipeline::class)->ingest('A001', $source, $manifest);
        $audit = app(CatalogImagePipeline::class)->audit($manifest);
        $importRows = app(CatalogImagePipeline::class)->importEntries($manifest);

        expect($path)->toBe('products/graphics-card/A001.webp')
            ->and($resumePath)->toBe($path)
            ->and(hash_file('sha256', Storage::disk('public')->path($path)))->toBe($firstHash)
            ->and(getimagesize(Storage::disk('public')->path($path))['mime'])->toBe('image/webp')
            ->and($audit)->toBe(['total' => 1, 'complete' => 1, 'pending' => 0, 'failed' => 0, 'issues' => []])
            ->and($importRows[0]['product_code'])->toBe('A001');
    } finally {
        unlink($source);
        unlink($manifest);
        unlink($manifest.'.lock');
    }
});

test('incomplete images block catalog import', function () {
    Storage::fake('public');
    $manifest = catalogImageManifest();

    try {
        expect(fn () => app(CatalogImagePipeline::class)->importEntries($manifest))
            ->toThrow(RuntimeException::class, 'Every catalog image must pass the audit before product import.');
    } finally {
        unlink($manifest);
    }
});

test('a failed generation is recorded, reported, and can be retried', function () {
    Storage::fake('public');
    $manifest = catalogImageManifest();
    $source = catalogImageSource();

    try {
        $pipeline = app(CatalogImagePipeline::class);
        $pipeline->fail('A001', 'Image tool timed out', $manifest);
        $failedAudit = $pipeline->audit($manifest);
        $retryJob = $pipeline->next(null, $manifest);
        $pipeline->ingest('A001', $source, $manifest);

        expect($failedAudit)->toBe([
            'total' => 1,
            'complete' => 0,
            'pending' => 0,
            'failed' => 1,
            'issues' => ['Failed generation for A001: Image tool timed out'],
        ])
            ->and($retryJob['product_code'])->toBe('A001')
            ->and($pipeline->audit($manifest)['failed'])->toBe(0);
    } finally {
        unlink($source);
        unlink($manifest);
        unlink($manifest.'.lock');
    }
});

test('an interrupted manifest update can recover an identical saved image without overwriting it', function () {
    Storage::fake('public');
    $manifest = catalogImageManifest();
    $source = catalogImageSource();

    try {
        $path = app(CatalogImagePipeline::class)->ingest('A001', $source, $manifest);
        $savedHash = hash_file('sha256', Storage::disk('public')->path($path));
        $state = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $state['entries'][0]['status'] = 'pending';
        file_put_contents($manifest, json_encode($state, JSON_THROW_ON_ERROR));

        app(CatalogImagePipeline::class)->ingest('A001', $source, $manifest);

        expect(json_decode(file_get_contents($manifest), true)['entries'][0]['status'])->toBe('complete')
            ->and(hash_file('sha256', Storage::disk('public')->path($path)))->toBe($savedHash);
    } finally {
        unlink($source);
        unlink($manifest);
        unlink($manifest.'.lock');
    }
});

test('ingest refuses to overwrite an image at a pending product path', function () {
    Storage::fake('public');
    $manifest = catalogImageManifest();
    $source = catalogImageSource();
    Storage::disk('public')->put('products/graphics-card/A001.webp', 'unrelated image');

    try {
        expect(fn () => app(CatalogImagePipeline::class)->ingest('A001', $source, $manifest))
            ->toThrow(RuntimeException::class, 'A different image already occupies this product path.');
        expect(json_decode(file_get_contents($manifest), true)['entries'][0]['status'])->toBe('pending');
    } finally {
        unlink($source);
        unlink($manifest);
        unlink($manifest.'.lock');
    }
});

test('ingest center crops and upscales a non-square source', function () {
    Storage::fake('public');
    $manifest = catalogImageManifest();
    $source = catalogImageSource(800, 600);

    try {
        $path = app(CatalogImagePipeline::class)->ingest('A001', $source, $manifest);
        $details = getimagesize(Storage::disk('public')->path($path));
        expect([$details[0], $details[1], $details['mime']])->toBe([1024, 1024, 'image/webp']);
        expect(app(CatalogImagePipeline::class)->audit($manifest)['issues'])->toBe([]);
    } finally {
        unlink($source);
        unlink($manifest);
        unlink($manifest.'.lock');
    }
});

test('audit reports a changed completed image and an unmapped catalog image', function () {
    Storage::fake('public');
    $manifest = catalogImageManifest();
    $source = catalogImageSource();

    try {
        app(CatalogImagePipeline::class)->ingest('A001', $source, $manifest);
        Storage::disk('public')->put('products/graphics-card/A001.webp', 'changed');
        Storage::disk('public')->put('products/graphics-card/OTHER.webp', 'stray');
        Storage::disk('public')->put('products/graphics-card/OTHER.jpg', 'extra');

        $audit = app(CatalogImagePipeline::class)->audit($manifest);

        expect($audit['issues'])->toBe([
            'Missing or invalid completed image: products/graphics-card/A001.webp',
            'Unmapped catalog image: products/graphics-card/OTHER.jpg',
            'Unmapped catalog image: products/graphics-card/OTHER.webp',
        ]);
    } finally {
        unlink($source);
        unlink($manifest);
        unlink($manifest.'.lock');
    }
});

test('audit detects the same generated image assigned to two product codes', function () {
    Storage::fake('public');
    $manifest = catalogImageManifest();
    $source = catalogImageSource();
    $state = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
    $second = $state['entries'][0];
    $second['product_code'] = 'A002';
    $second['image_path'] = 'products/graphics-card/A002.webp';
    $state['entries'][] = $second;
    file_put_contents($manifest, json_encode($state, JSON_THROW_ON_ERROR));

    try {
        $pipeline = app(CatalogImagePipeline::class);
        $pipeline->ingest('A001', $source, $manifest);
        $pipeline->ingest('A002', $source, $manifest);

        expect($pipeline->audit($manifest)['issues'])->toBe([
            'Duplicate catalog image content: products/graphics-card/A001.webp and products/graphics-card/A002.webp',
        ]);
    } finally {
        unlink($source);
        unlink($manifest);
        unlink($manifest.'.lock');
    }
});

test('the ingest command requires explicit visual approval', function () {
    Storage::fake('public');
    $manifest = catalogImageManifest();
    $source = catalogImageSource();

    try {
        $status = Artisan::call('catalog-images:ingest', [
            'code' => 'A001',
            'source' => $source,
            '--manifest' => $manifest,
        ]);

        expect($status)->toBe(1);
        Storage::disk('public')->assertMissing('products/graphics-card/A001.webp');
    } finally {
        unlink($source);
        unlink($manifest);
    }
});

test('the manifest cannot claim the reserved admin image namespace', function () {
    Storage::fake('public');
    $manifest = catalogImageManifest(['category_slug' => 'admin', 'image_path' => 'products/admin/A001.webp']);

    try {
        expect(fn () => app(CatalogImagePipeline::class)->audit($manifest))
            ->toThrow(RuntimeException::class, 'unsafe product image path');
    } finally {
        unlink($manifest);
    }
});

test('audit ignores admin uploads while continuing to verify manifest images', function () {
    Storage::fake('public');
    $manifest = catalogImageManifest();
    $source = catalogImageSource();

    try {
        app(CatalogImagePipeline::class)->ingest('A001', $source, $manifest);
        Storage::disk('public')->put('products/admin/1/'.str_repeat('a', 64).'.webp', 'independent admin upload');
        Storage::disk('public')->put('products/legacy.jpg', 'legacy admin upload');

        expect(app(CatalogImagePipeline::class)->audit($manifest)['issues'])->toBe([]);
    } finally {
        unlink($source);
        unlink($manifest);
        unlink($manifest.'.lock');
    }
});
