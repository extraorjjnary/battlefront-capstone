<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Services\Order\OrderProcessingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\SerializableClosure\SerializableClosure;
use Symfony\Component\Process\Process;

/** Keep MySQL fixtures committed separately from Feature's shared SQLite transaction. */
beforeEach(function () {
    if (getenv('BATTLEFRONT_COD_MYSQL_TESTS') !== '1') {
        $this->markTestSkipped('Enable BATTLEFRONT_COD_MYSQL_TESTS=1 to create a disposable MySQL database for COD integration tests.');
    }

    $this->codOriginalConnection = DB::getDefaultConnection();
    $this->codDatabase = 'battlefront_cod_test_'.bin2hex(random_bytes(6));
    $this->codDatabaseCreated = false;
    $connection = config('database.connections.mysql');
    $connection['url'] = null;
    $connection['database'] = null;
    config(['database.connections.cod_mysql_server' => $connection]);
    DB::connection('cod_mysql_server')->statement('CREATE DATABASE `'.$this->codDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $this->codDatabaseCreated = true;
    $connection['database'] = $this->codDatabase;
    config(['database.connections.mysql' => $connection]);
    DB::purge('mysql');
    DB::setDefaultConnection('mysql');
    Artisan::call('migrate', ['--database' => 'mysql', '--force' => true, '--no-interaction' => true]);
});

afterEach(function () {
    if (! ($this->codDatabaseCreated ?? false)) {
        return;
    }
    DB::purge('mysql');
    DB::setDefaultConnection($this->codOriginalConnection);
    if (preg_match('/\Abattlefront_cod_test_[a-f0-9]{12}\z/', $this->codDatabase) !== 1) {
        throw new RuntimeException('Refusing to clean up an unexpected database name.');
    }
    DB::connection('cod_mysql_server')->statement('DROP DATABASE `'.$this->codDatabase.'`');
    DB::purge('cod_mysql_server');
});

function waitForCodWorker(Closure $ready): void
{
    $deadline = microtime(true) + 15;
    while (! $ready()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the COD concurrency barrier.');
        }
        usleep(1000);
    }
}

/** @param array<string, mixed> $connection */
function codConcurrentTask(array $connection, int $orderId, string $operation, ?string $lockedPath = null, ?string $releasePath = null): Closure
{
    return function () use ($connection, $orderId, $operation, $lockedPath, $releasePath): string {
        config(['database.connections.mysql' => $connection]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        $processing = app(OrderProcessingService::class);
        DB::beginTransaction();
        try {
            if ($lockedPath !== null) {
                Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            }
            $order = Order::query()->with('shipment')->findOrFail($orderId);
            try {
                if ($operation === 'delivered') {
                    $processing->updateShipmentStatus($order, ShipmentStatus::Delivered);
                } else {
                    $processing->confirmCodCollection($order, true);
                }
                $outcome = 'accepted';
            } catch (ValidationException) {
                $outcome = 'rejected';
            }
            if ($lockedPath !== null) {
                file_put_contents($lockedPath, 'locked');
                $deadline = microtime(true) + 15;
                while (! is_file($releasePath)) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Timed out waiting to release the COD transaction.');
                    }
                    usleep(1000);
                }
            }
            DB::commit();

            return $outcome;
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    };
}

function startCodWorker(Closure $task): Process
{
    $process = new Process([PHP_BINARY, base_path('artisan'), 'invoke-serialized-closure', '--no-interaction'], base_path(), [
        'APP_ENV' => 'testing', 'DB_URL' => '',
        'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task))),
    ]);
    $process->setTimeout(25);
    $process->start();

    return $process;
}

test('real MySQL overlap serializes delivery and COD collection without duplicate sales', function (string $firstOperation, string $secondOperation, ShipmentStatus $initial, array $expectedOutcomes, int $expectedSales) {
    $order = Order::factory()->cashOnDelivery()->create(['status' => OrderStatus::Processing]);
    Shipment::factory()->for($order)->create(['status' => $initial, 'delivered_at' => $initial === ShipmentStatus::Delivered ? now() : null]);
    $stock = Inventory::factory()->create(['quantity' => 8]);
    OrderItem::factory()->for($order)->create(['product_id' => $stock->product_id, 'quantity' => 2, 'price_at_time' => '50.00']);
    $connection = config('database.connections.mysql');
    $barrier = storage_path('framework/testing/cod-'.bin2hex(random_bytes(6)));
    mkdir($barrier, 0777, true);
    $lockedPath = $barrier.'/locked';
    $releasePath = $barrier.'/release';
    $first = $second = null;

    try {
        $first = startCodWorker(codConcurrentTask($connection, $order->id, $firstOperation, $lockedPath, $releasePath));
        waitForCodWorker(fn (): bool => is_file($lockedPath));
        $second = startCodWorker(codConcurrentTask($connection, $order->id, $secondOperation));
        waitForCodWorker(fn (): bool => (int) DB::connection('cod_mysql_server')->selectOne(<<<'SQL'
            SELECT COUNT(*) AS blocked
            FROM performance_schema.data_lock_waits AS waits
            JOIN performance_schema.data_locks AS locks
                ON waits.BLOCKING_ENGINE_LOCK_ID = locks.ENGINE_LOCK_ID
            WHERE locks.OBJECT_SCHEMA = ? AND locks.OBJECT_NAME = 'orders'
        SQL, [$this->codDatabase])->blocked > 0);
        file_put_contents($releasePath, 'release');
        $first->wait();
        $second->wait();
        $outcomes = [];
        foreach ([$first, $second] as $worker) {
            expect($worker->getExitCode())->toBe(0);
            $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($result['successful'])->toBeTrue();
            $outcomes[] = unserialize($result['result']);
        }
        expect($outcomes)->toBe($expectedOutcomes);
        $this->assertDatabaseCount('sales', $expectedSales);
        expect($order->refresh()->shipment->status)->toBe(ShipmentStatus::Delivered);
        expect($order->payment_status)->toBe($expectedSales === 1 ? PaymentStatus::Verified : PaymentStatus::Pending);
        expect($order->status)->toBe($expectedSales === 1 ? OrderStatus::Completed : OrderStatus::Processing);
        expect($order->user->notifications()->where('data->event', 'payment.verified')->count())->toBe($expectedSales);
        expect($stock->refresh()->quantity)->toBe(8);
        if ($expectedSales === 0) {
            app(OrderProcessingService::class)->confirmCodCollection($order, true);
        }
        expect(fn () => app(OrderProcessingService::class)->confirmCodCollection($order, true))->toThrow(ValidationException::class);
        $this->assertDatabaseCount('sales', 1);
    } finally {
        file_put_contents($releasePath, 'release');
        $first?->stop();
        $second?->stop();
        foreach ([$lockedPath, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($barrier);
    }
})->with([
    'delivery obtains the lock first' => ['delivered', 'confirm', ShipmentStatus::OutForDelivery, ['accepted', 'accepted'], 1],
    'premature confirmation obtains the lock first' => ['confirm', 'delivered', ShipmentStatus::OutForDelivery, ['rejected', 'accepted'], 0],
    'two confirmations overlap' => ['confirm', 'confirm', ShipmentStatus::Delivered, ['accepted', 'rejected'], 1],
]);

test('MySQL COD migration preserves existing rows and enforces canonical delivery-only payment values', function () {
    $migration = require database_path('migrations/2026_10_12_153550_allow_cod_payment_method_on_orders.php');
    $migration->down();
    $legacy = Order::factory()->create()->refresh();
    $before = $legacy->getAttributes();
    expect(fn () => Order::factory()->cashOnDelivery()->create())->toThrow(QueryException::class);

    $migration->up();
    $order = Order::factory()->cashOnDelivery()->create();

    expect($legacy->refresh()->getAttributes())->toBe($before);
    foreach ([['payment_method' => 'COD'], ['payment_method' => 'cod '], ['payment_proof_path' => 'payment-proofs/invented.jpg']] as $invalid) {
        expect(fn () => DB::table('orders')->where('id', $order->id)->update($invalid))->toThrow(QueryException::class);
    }
    expect(fn () => DB::table('orders')->where('id', $legacy->id)->update(['payment_method' => 'cod']))->toThrow(QueryException::class);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot roll back payment methods while COD orders exist.');
});
