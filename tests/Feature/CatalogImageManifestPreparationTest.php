<?php

use Symfony\Component\Process\Process;

test('the private category workbooks prepare one stable job per source row without reference images', function () {
    if (PHP_OS_FAMILY !== 'Windows' || ! is_file(storage_path('app/imports/product_catalog/Processor.xlsx'))) {
        $this->markTestSkipped('The private Windows catalog workbooks are unavailable.');
    }

    $temporaryDirectory = sys_get_temp_dir().'/battlefront-preparation-'.bin2hex(random_bytes(8));
    $scriptDirectory = $temporaryDirectory.'/database';
    mkdir($scriptDirectory, 0777, true);
    $scriptPath = $scriptDirectory.'/PrepareCatalogImageManifest.ps1';
    copy(base_path('database/PrepareCatalogImageManifest.ps1'), $scriptPath);
    $manifestPath = $temporaryDirectory.'/manifest.json';

    try {
        $command = new Process([
            'powershell',
            '-NoProfile',
            '-File',
            $scriptPath,
            '-InputDirectory',
            storage_path('app/imports/product_catalog'),
            '-ManifestPath',
            $manifestPath,
        ]);
        $command->run();

        expect($command->isSuccessful())->toBeTrue();
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $jobs = collect($manifest['entries'])->keyBy('product_code');

        expect($jobs)->toHaveCount(654)
            ->and($jobs['SIX001601001999888']['category'])->toBe('Networking')
            ->and($jobs['150']['image_path'])->toBe('products/service/150.webp')
            ->and($jobs['80520996']['import_issues'])->toBe(['missing_quantity'])
            ->and($jobs['10041']['name'])->toContain('™');

        foreach ($jobs as $job) {
            expect($job)->not->toHaveKey('reference_image');
            expect($job['prompt'])->not->toContain('demo-products')
                ->toContain($job['name'], $job['category'], 'dark black background with restrained Battlefront red accent lighting');
        }
    } finally {
        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }
        unlink($scriptPath);
        rmdir($scriptDirectory);
        rmdir($temporaryDirectory);
    }
});

test('preparation preserves progress when the private workbooks have not changed', function () {
    if (PHP_OS_FAMILY !== 'Windows' || ! is_file(storage_path('app/imports/product_catalog/Processor.xlsx'))) {
        $this->markTestSkipped('The private Windows catalog workbooks are unavailable.');
    }

    $manifestPath = sys_get_temp_dir().'/battlefront-manifest-'.bin2hex(random_bytes(8)).'.json';
    $arguments = [
        'powershell',
        '-NoProfile',
        '-File',
        base_path('database/PrepareCatalogImageManifest.ps1'),
        '-ManifestPath',
        $manifestPath,
    ];

    try {
        $first = new Process($arguments);
        $first->run();
        expect($first->isSuccessful())->toBeTrue();

        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['entries'][0]['status'] = 'complete';
        $manifest['entries'][0]['source_quantity'] = $manifest['entries'][0]['quantity'];
        $manifest['entries'][0]['quantity_source'] = 'development_demo';
        $manifest['entries'][0]['quantity'] = '11';
        $manifest['entries'][0]['image_sha256'] = str_repeat('a', 64);
        $manifest['entries'][0]['source_image_sha256'] = str_repeat('b', 64);
        $manifest['entries'][0]['image_bytes'] = 1234;
        $manifest['entries'][0]['image_width'] = 1024;
        $manifest['entries'][0]['image_height'] = 1024;
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        $manifestBytes = file_get_contents($manifestPath);

        $second = new Process($arguments);
        $second->run();

        expect($second->isSuccessful())->toBeTrue()
            ->and(file_get_contents($manifestPath))->toBe($manifestBytes);
    } finally {
        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }
    }
});

test('preparation refuses a manifest whose source fingerprint has changed', function () {
    if (PHP_OS_FAMILY !== 'Windows' || ! is_file(storage_path('app/imports/product_catalog/Processor.xlsx'))) {
        $this->markTestSkipped('The private Windows catalog workbooks are unavailable.');
    }

    $manifestPath = sys_get_temp_dir().'/battlefront-manifest-'.bin2hex(random_bytes(8)).'.json';
    $arguments = [
        'powershell',
        '-NoProfile',
        '-File',
        base_path('database/PrepareCatalogImageManifest.ps1'),
        '-ManifestPath',
        $manifestPath,
    ];

    try {
        $first = new Process($arguments);
        $first->run();
        expect($first->isSuccessful())->toBeTrue();

        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['entries'][0]['source_sha256'] = str_repeat('0', 64);
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $second = new Process($arguments);
        $second->run();

        expect($second->isSuccessful())->toBeFalse()
            ->and(json_decode(file_get_contents($manifestPath), true)['entries'][0]['source_sha256'])->toBe(str_repeat('0', 64));
    } finally {
        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }
    }
});
