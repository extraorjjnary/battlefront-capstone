<?php

use App\Actions\Inventory\HandleCustomerReturn;
use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CustomerReturnRequest;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\SerializableClosure\SerializableClosure;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (getenv('BATTLEFRONT_RETURNS_MYSQL_TESTS') !== '1') {
        $this->markTestSkipped('Enable BATTLEFRONT_RETURNS_MYSQL_TESTS=1 for disposable MySQL return concurrency tests.');
    }
    $this->returnOriginalConnection = DB::getDefaultConnection();
    $this->returnDatabase = 'battlefront_returns_test_'.bin2hex(random_bytes(6));
    $this->returnDatabaseCreated = false;
    $connection = config('database.connections.mysql');
    $connection['url'] = null;
    $connection['database'] = null;
    $connection['isolation_level'] = 'REPEATABLE READ';
    config(['database.connections.return_mysql_server' => $connection]);
    DB::connection('return_mysql_server')->statement('CREATE DATABASE `'.$this->returnDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $this->returnDatabaseCreated = true;
    $connection['database'] = $this->returnDatabase;
    config(['database.connections.mysql' => $connection]);
    DB::purge('mysql');
    DB::setDefaultConnection('mysql');
    Artisan::call('migrate', ['--database' => 'mysql', '--force' => true, '--no-interaction' => true]);
});

afterEach(function () {
    if (! ($this->returnDatabaseCreated ?? false)) {
        return;
    }
    DB::purge('mysql');
    DB::setDefaultConnection($this->returnOriginalConnection);
    if (preg_match('/\Abattlefront_returns_test_[a-f0-9]{12}\z/', $this->returnDatabase) !== 1) {
        throw new RuntimeException('Refusing to clean up an unexpected database name.');
    }
    DB::connection('return_mysql_server')->statement('DROP DATABASE `'.$this->returnDatabase.'`');
    DB::purge('return_mysql_server');
});

function waitForReturnWorker(Closure $ready): void
{
    $deadline = microtime(true) + 20;
    while (! $ready()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for return concurrency barrier.');
        }
        usleep(1000);
    }
}

/** @param array<string, mixed> $connection */
function startReturnWorker(array $connection, int $orderId, int $itemId, int $actorId, ?int $requestId, string $operation, ?string $barrier = null): Process
{
    $task = function () use ($connection, $orderId, $itemId, $actorId, $requestId, $operation, $barrier): string {
        config(['database.connections.mysql' => $connection]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        if (DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level !== 'REPEATABLE-READ') {
            throw new RuntimeException('Return concurrency requires REPEATABLE READ.');
        }
        $actor = User::query()->findOrFail($actorId);
        if ($barrier !== null) {
            DB::beginTransaction();
            Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
        }
        try {
            try {
                $requests = app(HandleCustomerReturnRequest::class);
                match ($operation) {
                    'submit' => $requests->submit($actor, $orderId, ['order_item_id' => $itemId, 'quantity' => 1, 'reason_code' => 'defective']),
                    'withdraw' => $requests->withdraw($actor, $orderId, $requestId),
                    'receive' => $requests->receive($actor, $requestId, 'opened'),
                    'legacy' => app(HandleCustomerReturn::class)->receive($actor, $itemId, 1, 'opened', 'Defective', 'defective'),
                };
                $outcome = 'accepted';
            } catch (ValidationException) {
                $outcome = 'rejected';
            }
            if ($barrier !== null) {
                file_put_contents($barrier.'/locked', 'locked');
                $deadline = microtime(true) + 20;
                while (! is_file($barrier.'/release')) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Timed out releasing return worker.');
                    }
                    usleep(1000);
                }
                DB::commit();
            }

            return $outcome;
        } catch (Throwable $exception) {
            if ($barrier !== null) {
                DB::rollBack();
            }
            throw $exception;
        }
    };
    $process = new Process([PHP_BINARY, base_path('artisan'), 'invoke-serialized-closure', '--no-interaction'], base_path(), [
        'APP_ENV' => 'testing', 'DB_URL' => '',
        'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task))),
    ]);
    $process->setTimeout(35);
    $process->start();

    return $process;
}

test('overlapping return operations preserve reservations and exactly once receipt on MySQL', function (string $firstOperation, string $secondOperation, int $expectedReturns) {
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    $inventory = Inventory::factory()->create(['quantity' => 0, 'quarantined_quantity' => 0]);
    $item = OrderItem::factory()->for($order)->for($inventory->product)->create(['quantity' => 1, 'price_at_time' => '100.00']);
    $order->sale()->create(['amount' => '100.00', 'sale_date' => today()]);
    $staff = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id')]);
    $requestId = null;
    if (in_array($firstOperation, ['receive', 'withdraw'], true)) {
        $requestId = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'defective'])->id;
    }
    $actor = fn (string $operation): int => in_array($operation, ['submit', 'withdraw'], true) ? $order->user_id : $staff->id;
    $barrier = storage_path('framework/testing/returns-'.bin2hex(random_bytes(6)));
    mkdir($barrier, 0777, true);
    $first = $second = null;
    try {
        $connection = config('database.connections.mysql');
        $first = startReturnWorker($connection, $order->id, $item->id, $actor($firstOperation), $requestId, $firstOperation, $barrier);
        waitForReturnWorker(fn (): bool => is_file($barrier.'/locked'));
        $second = startReturnWorker($connection, $order->id, $item->id, $actor($secondOperation), $requestId, $secondOperation);
        waitForReturnWorker(fn (): bool => (int) DB::connection('return_mysql_server')->selectOne(<<<'SQL'
            SELECT COUNT(*) AS blocked
            FROM performance_schema.data_lock_waits AS waits
            JOIN performance_schema.data_locks AS locks ON waits.BLOCKING_ENGINE_LOCK_ID = locks.ENGINE_LOCK_ID
            WHERE locks.OBJECT_SCHEMA = ? AND locks.OBJECT_NAME = 'orders'
            SQL, [$this->returnDatabase])->blocked > 0);
        file_put_contents($barrier.'/release', 'release');
        foreach ([$first, $second] as $worker) {
            $worker->wait();
            expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
        }
        $outcomes = array_map(function (Process $worker): string {
            $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($result['successful'])->toBeTrue();

            return unserialize($result['result']);
        }, [$first, $second]);
        expect($outcomes)->toBe(['accepted', 'rejected']);
        $this->assertDatabaseCount('customer_returns', $expectedReturns);
        $this->assertDatabaseCount('customer_return_items', $expectedReturns);
        $this->assertDatabaseCount('inventory_movements', $expectedReturns);
        expect($inventory->refresh()->quarantined_quantity)->toBe($expectedReturns);
        expect($inventory->quantity)->toBe(0);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('customer_refunds', 0);
        expect($order->refresh()->status)->toBe(OrderStatus::Completed);
        expect($order->payment_status)->toBe(PaymentStatus::Verified);
        expect(CustomerReturnRequest::query()->whereNotNull('customer_return_id')->count())->toBe($firstOperation === 'receive' ? 1 : 0);
        if ($firstOperation === 'submit') {
            $this->assertDatabaseCount('customer_return_requests', 1);
        }
    } finally {
        file_put_contents($barrier.'/release', 'release');
        $first?->stop();
        $second?->stop();
        foreach (['locked', 'release'] as $name) {
            if (is_file($barrier.'/'.$name)) {
                unlink($barrier.'/'.$name);
            }
        }
        rmdir($barrier);
    }
})->with([
    'two submissions' => ['submit', 'submit', 0],
    'two receipts' => ['receive', 'receive', 1],
    'withdrawal before receipt' => ['withdraw', 'receive', 0],
    'receipt before withdrawal' => ['receive', 'withdraw', 1],
    'reservation before legacy receipt' => ['submit', 'legacy', 0],
    'legacy receipt before reservation' => ['legacy', 'submit', 1],
    'two legacy receipts recheck committed quantities' => ['legacy', 'legacy', 1],
]);
