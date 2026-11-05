<?php

use App\Actions\Order\DeletePaymentProof;
use App\Actions\Order\StorePaymentProof;
use App\Actions\Product\StoreProductImage;
use App\Enums\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\CatalogImagePipeline;
use App\Services\RealCatalogImportService;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Foundation\CloudBootstrapper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Exercise Cloud's installed bootstrapper and real S3 adapter without network access.
 *
 * @return object{objects: array<string, array<string, string>>, commands: list<CommandInterface>}
 */
function cloudFilesystemFixture(array $images = []): object
{
    $state = (object) ['objects' => ['public' => $images, 'private' => []], 'commands' => []];
    $previous = $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] ?? null;
    $previousDefault = $_SERVER['FILESYSTEM_DISK'] ?? null;
    try {
        $_SERVER['FILESYSTEM_DISK'] = 'private';
        $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = json_encode(array_map(fn (string $disk): array => [
            'disk' => $disk, 'bucket' => $disk, 'is_default' => $disk === 'private',
            'access_key_id' => 'testing-only', 'access_key_secret' => 'testing-only',
            'url' => $disk === 'public' ? 'https://images.example.invalid' : null,
            'endpoint' => 'https://storage.example.invalid',
        ], ['public', 'private']), JSON_THROW_ON_ERROR);
        CloudBootstrapper::configureDisks(app());
    } finally {
        unset($_SERVER['LARAVEL_CLOUD_DISK_CONFIG'], $_SERVER['FILESYSTEM_DISK']);
        if ($previous !== null) {
            $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = $previous;
        }
        if ($previousDefault !== null) {
            $_SERVER['FILESYSTEM_DISK'] = $previousDefault;
        }
    }

    $handler = function (CommandInterface $command) use ($state): PromiseInterface {
        $state->commands[] = $command;
        $bucket = $command['Bucket'];
        $key = $command['Key'];
        if (in_array($command->getName(), ['HeadObject', 'GetObject'], true) && ! isset($state->objects[$bucket][$key])) {
            return Create::rejectionFor(new S3Exception('Missing test object.', $command, [
                'code' => 'NoSuchKey', 'response' => new Response(404),
            ]));
        }

        $result = match ($command->getName()) {
            'PutObject' => (function () use ($state, $bucket, $key, $command): array {
                $state->objects[$bucket][$key] = (string) $command['Body'];

                return [];
            })(),
            'HeadObject' => ['ContentLength' => strlen($state->objects[$bucket][$key])],
            'GetObject' => ['Body' => Utils::streamFor($state->objects[$bucket][$key]), 'ContentType' => 'image/webp'],
            'ListObjectsV2' => (function () use ($state, $bucket, $command): array {
                $paths = array_values(array_filter(array_keys($state->objects[$bucket]), fn (string $path): bool => str_starts_with($path, $command['Prefix'] ?? '')));

                return $paths === [] ? [] : ['Contents' => array_map(fn (string $path): array => ['Key' => $path, 'Size' => strlen($state->objects[$bucket][$path])], $paths)];
            })(),
            'DeleteObject' => (function () use ($state, $bucket, $key): array {
                unset($state->objects[$bucket][$key]);

                return [];
            })(),
            default => throw new LogicException('Unexpected storage command: '.$command->getName()),
        };

        return Create::promiseFor(new Result($result));
    };
    config()->set('filesystems.disks.public.handler', $handler);
    config()->set('filesystems.disks.private.handler', $handler);
    (new AppServiceProvider(app()))->register();
    Storage::forgetDisk(['public', 'private', 'local']);

    return $state;
}

test('Herd keeps local protected files and storage product URLs without Cloud disks', function () {
    expect(Storage::disk('local'))->toBeInstanceOf(LocalFilesystemAdapter::class);
    expect(Storage::disk('local')->path('evidence.jpg'))->toBe(storage_path('app/private').DIRECTORY_SEPARATOR.'evidence.jpg');
    expect(Storage::disk('public'))->toBeInstanceOf(LocalFilesystemAdapter::class);
    expect(Storage::disk('public')->path('products/example.webp'))->toBe(storage_path('app/public').DIRECTORY_SEPARATOR.'products/example.webp');
    $product = new Product(['image_path' => 'products/example.webp']);

    expect($product->image_url)->toBe(rtrim(config('app.url'), '/').'/storage/products/example.webp');
});

test('Cloud uses its private default for legacy protected files and its public image URL', function () {
    cloudFilesystemFixture();

    expect(Storage::disk())->toBeInstanceOf(AwsS3V3Adapter::class);
    expect(Storage::disk('local'))->toBeInstanceOf(AwsS3V3Adapter::class);
    expect(Storage::disk('local')->getConfig()['bucket'])->toBe('private');
    expect(Storage::disk('public')->getConfig()['bucket'])->toBe('public');
    $product = new Product(['image_path' => 'products/graphics-card/A001.webp']);

    expect($product->image_url)->toBe('https://images.example.invalid/products/graphics-card/A001.webp');
});

test('an unrelated public default does not redirect the legacy protected disk', function () {
    config()->set('filesystems.default', 'public');
    config()->set('filesystems.disks.private', ['driver' => 's3', 'bucket' => 'private']);
    (new AppServiceProvider(app()))->register();

    expect(Storage::disk('local'))->toBeInstanceOf(LocalFilesystemAdapter::class);
});

test('a missing selected private disk cannot silently store protected files on the server', function () {
    config()->set('filesystems.default', 'private');
    config()->set('filesystems.disks.private', null);
    (new AppServiceProvider(app()))->register();
    Storage::forgetDisk('local');

    expect(fn () => app(StorePaymentProof::class)->execute(UploadedFile::fake()->image('proof.jpg'), PaymentMethod::GCash))
        ->toThrow(InvalidArgumentException::class, 'Disk [local] does not have a configured driver.');
});

test('Cloud product uploads use bucket visibility without a public object ACL', function () {
    $state = cloudFilesystemFixture();

    $stored = app(StoreProductImage::class)->execute(UploadedFile::fake()->image('product.png'), 42);

    expect($stored['path'])->toStartWith('products/admin/42/');
    expect($state->objects['public'])->toHaveKey($stored['path']);
    expect($state->objects['private'])->toBe([]);
    $uploads = array_values(array_filter($state->commands, fn (CommandInterface $command): bool => $command->getName() === 'PutObject'));
    expect($uploads)->toHaveCount(1);
    expect($uploads[0]['ACL'])->not->toBe('public-read');
});

test('Cloud payment proofs are stored and deleted only in the private bucket', function () {
    $state = cloudFilesystemFixture();
    $path = app(StorePaymentProof::class)->execute(UploadedFile::fake()->image('proof.jpg'), PaymentMethod::GCash);

    expect($state->objects['private'])->toHaveKey($path);
    expect($state->objects['public'])->toBe([]);

    app(DeletePaymentProof::class)->execute($path);

    expect($state->objects['private'])->toBe([]);
});

test('Cloud administration evidence retains authorized streaming and forbidden customer access', function () {
    $state = cloudFilesystemFixture();
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $product = Product::factory()->available()->create();
    $this->actingAs($staff)->post(route('administration.damage-reports.store'), [
        'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'handling_damage', 'reason' => 'Damaged packaging found',
    ])->assertSessionHasNoErrors();
    $damageId = DB::table('damage_reports')->where('product_id', $product->id)->value('id');
    $photo = UploadedFile::fake()->image('inspection.jpg');
    $bytes = file_get_contents($photo->getPathname());

    $this->post(route('administration.evidence.store', ['subjectType' => 'damage_report', 'subjectId' => $damageId]), [
        'files' => [$photo],
    ])->assertSessionHasNoErrors();

    $file = DB::table('administration_evidence')->sole();
    expect($state->objects['private'][$file->path])->toBe($bytes);
    expect($state->objects['public'])->toBe([]);
    $this->get(route('administration.evidence.show', $file->id))
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertStreamedContent($bytes);
    $this->actingAs(User::factory()->customer()->create())->get(route('administration.evidence.show', $file->id))->assertForbidden();
    unset($state->objects['private'][$file->path]);
    $this->actingAs($staff)->get(route('administration.evidence.show', $file->id))->assertNotFound();
});

test('Cloud catalog validation checks object bytes before any import', function (array $overrides, array $objects, ?string $error) {
    $source = UploadedFile::fake()->image('catalog.png', $objects['width'] ?? 1024, 1024);
    $bytes = file_get_contents($source->getPathname());
    if (! ($objects['png'] ?? false)) {
        $output = fopen('php://memory', 'w+b');
        imagewebp(imagecreatefromstring($bytes), $output, 85);
        rewind($output);
        $bytes = stream_get_contents($output);
        fclose($output);
    }
    $bytes = $objects['corrupt'] ?? $bytes;
    $entry = array_replace([
        'product_code' => 'A001', 'name' => 'Exact Product', 'category' => 'Graphics Card', 'category_slug' => 'graphics-card',
        'price' => '7500', 'quantity' => '5', 'image_path' => 'products/graphics-card/A001.webp', 'status' => 'complete',
        'image_sha256' => hash('sha256', $bytes), 'image_bytes' => strlen($bytes), 'image_width' => 1024, 'image_height' => 1024,
    ], $overrides);
    $entries = [$entry];
    $images = ($objects['missing'] ?? false) ? [] : [$entry['image_path'] => $bytes];
    if ($objects['duplicate'] ?? false) {
        $entries[] = array_replace($entry, ['product_code' => 'A002', 'image_path' => 'products/graphics-card/A002.webp']);
        $images['products/graphics-card/A002.webp'] = $bytes;
    }
    cloudFilesystemFixture($images);
    $manifest = tempnam(sys_get_temp_dir(), 'cloud-manifest-');
    $mapping = tempnam(sys_get_temp_dir(), 'cloud-catalog-');
    $manifestBytes = json_encode(['version' => 1, 'entries' => $entries], JSON_THROW_ON_ERROR);
    file_put_contents($manifest, $manifestBytes);
    file_put_contents($mapping, "product_code,product_name,category,brand,quantity_override,reorder_level\nA001,Exact Product,Graphics Card,Biostar,,3\n");
    $originalEnvironment = app()->environment();
    app()->instance('env', 'production');

    try {
        if ($error === null) {
            $rows = app(RealCatalogImportService::class)->inspectFiles($mapping, $manifest);
            expect($rows[0]['image_path'])->toBe('products/graphics-card/A001.webp');
            expect($rows[0]['quantity'])->toBe(5);
        } else {
            expect(fn () => app(RealCatalogImportService::class)->inspectFiles($mapping, $manifest))->toThrow(RuntimeException::class, $error);
        }
        expect(file_get_contents($manifest))->toBe($manifestBytes);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventories', 0);
    } finally {
        app()->instance('env', $originalEnvironment);
        unlink($manifest);
        unlink($mapping);
    }
})->with([
    'verified production source' => [[], [], null],
    'checksum mismatch' => [['image_sha256' => str_repeat('0', 64)], [], 'Every catalog image must pass the audit'],
    'byte count mismatch' => [['image_bytes' => 1], [], 'Every catalog image must pass the audit'],
    'wrong dimensions with matching checksum' => [[], ['width' => 512], 'Every catalog image must pass the audit'],
    'PNG with matching checksum' => [[], ['png' => true], 'Every catalog image must pass the audit'],
    'empty object' => [[], ['corrupt' => ''], 'Every catalog image must pass the audit'],
    'corrupt object' => [[], ['corrupt' => 'invalid image'], 'Every catalog image must pass the audit'],
    'missing object' => [[], ['missing' => true], 'Every catalog image must pass the audit'],
    'duplicate image content' => [[], ['duplicate' => true], 'Every catalog image must pass the audit'],
    'demo inventory remains forbidden in production' => [['quantity_source' => 'development_demo'], [], 'Demo catalog inventory is only allowed in local and testing environments.'],
]);

test('Cloud ingestion stops before opening or changing the private manifest', function () {
    $state = cloudFilesystemFixture();

    expect(fn () => app(CatalogImagePipeline::class)->ingest('A001', 'unused.png', 'unused-manifest.json'))
        ->toThrow(RuntimeException::class, 'Catalog image ingestion requires a local public disk.');
    expect($state->commands)->toBe([]);
});
