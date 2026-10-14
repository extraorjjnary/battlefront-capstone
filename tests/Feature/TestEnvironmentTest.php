<?php

use App\Services\Order\PsgcDeliveryLocations;
use Illuminate\Foundation\Exceptions\Renderer\Renderer;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

test('HTTP errors avoid the browser debug renderer while still reporting failures', function () {
    expect(config('app.debug'))->toBeFalse();
    $this->mock(Renderer::class)->shouldNotReceive('render');
    Exceptions::fake();
    Route::get('/_test/server-error', fn () => throw new RuntimeException('Expected test failure'));

    $this->get('/_test/missing-page')->assertNotFound();
    $this->get('/_test/server-error')->assertInternalServerError()
        ->assertDontSee('Expected test failure');

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Expected test failure');
});

test('test teardown releases unreachable cycles holding delivery reference data', function () {
    gc_collect_cycles();
    $garbageCollectorWasEnabled = gc_enabled();
    gc_disable();

    try {
        $locations = app(PsgcDeliveryLocations::class);
        $locations->geography();
        $cachedCycle = new stdClass;
        $cachedCycle->locations = $locations;
        $cachedCycle->self = $cachedCycle;
        $reference = WeakReference::create($cachedCycle);
        unset($cachedCycle, $locations);

        $this->tearDown();

        expect($reference->get())->toBeNull();
    } finally {
        if ($garbageCollectorWasEnabled) {
            gc_enable();
        }
    }
});

test('independent test processes cannot clear or overwrite each others fake storage', function (?string $workerToken) {
    $diskName = 'storage-isolation-probe-'.getmypid();
    $disk = Storage::fake($diskName);
    $contents = '{"version":2,"products":{}}';
    $disk->put('reference.json', $contents);
    $process = new Process([
        PHP_BINARY, '-r',
        <<<'PHP'
        require $argv[1];
        class StorageIsolationProbe extends Tests\TestCase {
            public function testProbe(): void {}
        }
        $test = new StorageIsolationProbe("testProbe");
        $app = $test->createApplication();
        $disk = Illuminate\Support\Facades\Storage::fake($argv[2]);
        $disk->put("reference.json", "child contents");
        echo json_encode([
            "root" => $disk->path(""), "contents" => $disk->get("reference.json"),
            "token" => $app->make(Illuminate\Testing\ParallelTesting::class)->token(), "pid" => getmypid(),
        ]);
        PHP,
        base_path('vendor/autoload.php'), $diskName,
    ], base_path(), ['APP_ENV' => 'testing', 'TEST_TOKEN' => $workerToken ?? false]);

    $process->mustRun();

    $child = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($child['root'])->not->toBe($disk->path(''));
    expect($child['contents'])->toBe('child contents');
    expect($child['token'])->toBe($workerToken ?? 'process-'.$child['pid']);
    expect($disk->get('reference.json'))->toBe($contents);
})->with(['ordinary runner' => [null], 'existing worker token' => ['storage-worker-'.getmypid()]]);
