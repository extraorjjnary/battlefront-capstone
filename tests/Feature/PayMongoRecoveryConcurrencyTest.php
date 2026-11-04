<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Jobs\ReconcilePayMongoOrder;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayMongoFlow;
use App\Services\Notifications\OrderNotificationPublisher;
use App\Services\Order\OrderProcessingService;
use App\Services\PayMongo\ObserveHostedCheckout;
use App\Services\PayMongo\RecordPayMongoPayment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\SerializableClosure\SerializableClosure;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (getenv('BATTLEFRONT_PAYMONGO_MYSQL_TESTS') !== '1') {
        $this->markTestSkipped('Enable BATTLEFRONT_PAYMONGO_MYSQL_TESTS=1 to create a disposable MySQL database for PayMongo integration tests.');
    }

    $this->paymentRecoveryOriginalConnection = DB::getDefaultConnection();
    $this->paymentRecoveryDatabase = 'battlefront_paymentRecovery_test_'.bin2hex(random_bytes(6));
    $this->paymentRecoveryDatabaseCreated = false;
    $connection = config('database.connections.mysql');
    $connection['url'] = null;
    $connection['database'] = null;
    config(['database.connections.paymentRecovery_mysql_server' => $connection]);
    DB::connection('paymentRecovery_mysql_server')->statement('CREATE DATABASE `'.$this->paymentRecoveryDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $this->paymentRecoveryDatabaseCreated = true;
    $connection['database'] = $this->paymentRecoveryDatabase;
    config(['database.connections.mysql' => $connection]);
    DB::purge('mysql');
    DB::setDefaultConnection('mysql');
    Artisan::call('migrate', ['--database' => 'mysql', '--force' => true, '--no-interaction' => true]);
});

afterEach(function () {
    if (! ($this->paymentRecoveryDatabaseCreated ?? false)) {
        return;
    }
    DB::purge('mysql');
    DB::setDefaultConnection($this->paymentRecoveryOriginalConnection);
    if (preg_match('/\Abattlefront_paymentRecovery_test_[a-f0-9]{12}\z/', $this->paymentRecoveryDatabase) !== 1) {
        throw new RuntimeException('Refusing to clean up an unexpected database name.');
    }
    DB::connection('paymentRecovery_mysql_server')->statement('DROP DATABASE `'.$this->paymentRecoveryDatabase.'`');
    DB::purge('paymentRecovery_mysql_server');
});

function waitForPaymentWorker(Closure $ready): void
{
    $deadline = microtime(true) + 20;
    while (! $ready()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Payment concurrency barrier timed out.');
        }
        usleep(1000);
    }
}

/** @param array<string, mixed> $connection */
function paymentConcurrentTask(array $connection, int $orderId, int $flowId, string $operation, ?string $lockedPath = null, ?string $releasePath = null): Closure
{
    return function () use ($connection, $orderId, $flowId, $operation, $lockedPath, $releasePath): string {
        config(['database.connections.mysql' => $connection, 'cache.default' => 'array', 'queue.default' => 'sync']);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        Http::preventStrayRequests();
        DB::beginTransaction();
        try {
            if ($lockedPath !== null) {
                Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            }
            try {
                if ($operation === 'paid') {
                    $accepted = app(RecordPayMongoPayment::class)->execute(PayMongoFlow::query()->findOrFail($flowId), [
                        'id' => 'pay_race', 'status' => 'paid', 'amount' => 10000,
                        'currency' => 'PHP', 'net_amount' => null, 'method' => 'card',
                    ], app(OrderNotificationPublisher::class));
                    $outcome = $accepted ? 'accepted' : 'rejected';
                } else {
                    app(OrderProcessingService::class)->updateStatus(Order::query()->findOrFail($orderId), OrderStatus::Cancelled);
                    $outcome = 'accepted';
                }
            } catch (ValidationException) {
                $outcome = 'rejected';
            }
            if ($lockedPath !== null) {
                file_put_contents($lockedPath, 'locked');
                $deadline = microtime(true) + 20;
                while (! is_file($releasePath)) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Payment transaction release timed out.');
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

function startPaymentWorker(Closure $task): Process
{
    $process = new Process([PHP_BINARY, base_path('artisan'), 'invoke-serialized-closure', '--no-interaction'], base_path(), [
        'APP_ENV' => 'testing', 'DB_URL' => '',
        'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task))),
    ]);
    $process->setTimeout(30);
    $process->start();

    return $process;
}

test('MySQL serializes payment and cancellation without releasing paid stock or restoring twice', function (string $firstOperation, string $secondOperation, string $status, int $stock, bool $exception) {
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Online, 'payment_origin' => 'paymongo', 'total_amount' => '100.00']);
    $inventory = Inventory::factory()->create(['quantity' => 7]);
    OrderItem::factory()->for($order)->create(['product_id' => $inventory->product_id, 'quantity' => 2, 'price_at_time' => '50.00']);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id, 'channel' => 'hosted_checkout', 'mode' => 'test', 'status' => 'closed',
        'amount_minor' => 10000, 'currency' => 'PHP', 'merchant_cutoff_at' => now()->subMinute(),
    ]);
    $connection = config('database.connections.mysql');
    $barrier = storage_path('framework/testing/payment-'.bin2hex(random_bytes(6)));
    mkdir($barrier, 0777, true);
    $lockedPath = $barrier.'/locked';
    $releasePath = $barrier.'/release';
    $first = $second = null;
    try {
        $first = startPaymentWorker(paymentConcurrentTask($connection, $order->id, $flow->id, $firstOperation, $lockedPath, $releasePath));
        waitForPaymentWorker(fn (): bool => is_file($lockedPath));
        $second = startPaymentWorker(paymentConcurrentTask($connection, $order->id, $flow->id, $secondOperation));
        waitForPaymentWorker(fn (): bool => (int) DB::connection('paymentRecovery_mysql_server')->selectOne(
            'SELECT COUNT(*) AS blocked FROM performance_schema.data_lock_waits AS waits JOIN performance_schema.data_locks AS locks ON waits.BLOCKING_ENGINE_LOCK_ID = locks.ENGINE_LOCK_ID WHERE locks.OBJECT_SCHEMA = ? AND locks.OBJECT_NAME = ?',
            [$this->paymentRecoveryDatabase, 'orders']
        )->blocked > 0);
        file_put_contents($releasePath, 'release');
        $first->wait();
        $second->wait();
        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
            ->and($order->fresh()->status->value)->toBe($status)
            ->and($inventory->fresh()->quantity)->toBe($stock)
            ->and($flow->fresh()->requires_attention)->toBe($exception);
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
    'payment wins' => ['paid', 'cancel', 'pending', 7, false],
    'cancellation wins' => ['cancel', 'paid', 'cancelled', 9, true],
    'two cancellations' => ['cancel', 'cancel', 'cancelled', 9, false],
]);

/** @param array<string, mixed> $connection */
function paymentQueueTask(array $connection, int $flowId, ?string $barrier = null): Closure
{
    return function () use ($connection, $flowId, $barrier): int {
        config([
            'database.connections.mysql' => $connection,
            'queue.default' => 'database', 'queue.connections.database.connection' => 'mysql',
            'queue.connections.database.retry_after' => 90,
            'cache.default' => 'database', 'cache.prefix' => 'payment-worker-tests-',
            'cache.stores.database.connection' => 'mysql', 'cache.stores.database.lock_connection' => 'mysql',
            'services.paymongo.mode' => 'test', 'services.paymongo.secret_key' => 'sk_test_worker',
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        Http::preventStrayRequests();
        $flow = PayMongoFlow::query()->findOrFail($flowId);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/'.$flow->checkout_session_id => function () use ($barrier, $flow) {
            if ($barrier !== null) {
                file_put_contents($barrier, 'provider observation started');
                sleep(120);
                throw new RuntimeException('The worker timeout was not enforced.');
            }

            return Http::response(['data' => ['id' => $flow->checkout_session_id, 'attributes' => [
                'livemode' => false, 'status' => 'active', 'payments' => [[
                    'id' => 'pay_worker_recovered', 'attributes' => ['status' => 'paid',
                        'amount' => 10000, 'currency' => 'PHP', 'source' => ['type' => 'card']],
                ]],
            ]]]);
        }]);

        return Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => 'payments', '--stop-when-empty' => true,
            '--max-jobs' => 1, '--sleep' => 0, '--timeout' => 70, '--tries' => 3, '--no-interaction' => true,
        ]);
    };
}

test('Linux payment worker timeout retains reserved stock and recovery processes payment once with shared locks', function () {
    expect(PHP_OS_FAMILY)->toBe('Linux');
    expect(extension_loaded('pcntl'))->toBeTrue();
    expect(extension_loaded('posix'))->toBeTrue();
    config([
        'queue.default' => 'database', 'queue.connections.database.connection' => 'mysql',
        'cache.default' => 'database', 'cache.prefix' => 'payment-worker-tests-',
        'cache.stores.database.connection' => 'mysql', 'cache.stores.database.lock_connection' => 'mysql',
    ]);
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Online, 'payment_origin' => 'paymongo', 'total_amount' => '100.00']);
    $inventory = Inventory::factory()->create(['quantity' => 7]);
    OrderItem::factory()->for($order)->create(['product_id' => $inventory->product_id, 'quantity' => 2, 'price_at_time' => '50.00']);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id, 'channel' => 'hosted_checkout', 'mode' => 'test', 'status' => 'open',
        'checkout_session_id' => 'cs_worker_recovery', 'amount_minor' => 10000, 'currency' => 'PHP',
        'merchant_cutoff_at' => now()->addDay(),
    ]);
    ReconcilePayMongoOrder::dispatch($flow->id)->onQueue('payments');
    $connection = config('database.connections.mysql');
    $barrierDirectory = storage_path('framework/testing/payment-timeout-'.bin2hex(random_bytes(6)));
    mkdir($barrierDirectory, 0777, true);
    $barrier = $barrierDirectory.'/observing';
    $worker = $recoveryWorker = null;
    try {
        $worker = startPaymentWorker(paymentQueueTask($connection, $flow->id, $barrier));
        $worker->setTimeout(75);
        waitForPaymentWorker(fn (): bool => is_file($barrier));
        expect(Cache::lock('paymongo-order:'.$order->id, 120)->get())->toBeFalse();
        ReconcilePayMongoOrder::dispatch($flow->id)->onQueue('payments');
        expect(DB::table('jobs')->where('queue', 'payments')->count())->toBe(1);
        $started = microtime(true);
        $worker->wait();
        expect($worker->isSuccessful())->toBeFalse();
        expect($worker->getTermSignal())->toBe(SIGKILL);
        expect(microtime(true) - $started)->toBeLessThan(70);
        expect($order->fresh()->payment_status->value)->toBe('pending')
            ->and($order->fresh()->status->value)->toBe('pending')
            ->and($inventory->fresh()->quantity)->toBe(7);
        expect(DB::table('jobs')->where('queue', 'payments')->count())->toBe(1);

        $lockKey = Cache::store('database')->getStore()->getPrefix().'paymongo-order:'.$order->id;
        expect(DB::table('cache_locks')->where('key', $lockKey)->update(['expiration' => time() - 1]))->toBe(1);
        DB::table('jobs')->where('queue', 'payments')->update(['reserved_at' => time() - 91]);
        $recoveryWorker = startPaymentWorker(paymentQueueTask($connection, $flow->id));
        $recoveryWorker->wait();
        expect($recoveryWorker->isSuccessful())->toBeTrue($recoveryWorker->getErrorOutput());
        expect($order->fresh()->payment_status->value)->toBe('verified')
            ->and($order->fresh()->status->value)->toBe('pending')
            ->and($flow->fresh()->payment_id)->toBe('pay_worker_recovered')
            ->and($inventory->fresh()->quantity)->toBe(7);
        app()->call([new ReconcilePayMongoOrder($flow->id), 'handle']);
        expect(DB::table('jobs')->where('queue', 'payments')->count())->toBe(0);
        expect($order->user->notifications()->where('data->event', 'payment.verified')->count())->toBe(1);
        expect(DB::table('sales')->count())->toBe(0);
    } finally {
        $worker?->stop();
        $recoveryWorker?->stop();
        if (is_file($barrier)) {
            unlink($barrier);
        }
        rmdir($barrierDirectory);
    }
});

test('MySQL repeated unchanged QR observations still allow a fresh customer retry', function () {
    $this->freezeTime();
    Http::preventStrayRequests();
    config(['services.paymongo.enabled' => true, 'services.paymongo.mode' => 'test', 'services.paymongo.secret_key' => 'sk_test_fake']);
    $order = Order::factory()->create(['payment_method' => PaymentMethod::Online, 'payment_origin' => 'paymongo',
        'hosted_payment_method' => 'qrph', 'total_amount' => '100.00']);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id, 'active_slot' => 'active', 'mode' => 'test', 'channel' => 'hosted_checkout',
        'hosted_payment_method' => 'qrph', 'status' => 'open', 'amount_minor' => 10000, 'currency' => 'PHP',
        'checkout_session_id' => 'cs_old', 'checkout_url' => 'https://checkout.paymongo.com/cs_old',
        'merchant_cutoff_at' => $order->created_at->copy()->addDay(),
    ]);
    $closed = false;
    Http::fake(function ($request) use (&$closed) {
        if (str_ends_with($request->url(), '/cs_old/expire')) {
            $closed = true;

            return Http::response([]);
        }
        if (str_ends_with($request->url(), '/v2/checkout_sessions')) {
            expect($closed)->toBeTrue();

            return Http::response(['data' => ['id' => 'cs_new', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_new']]]);
        }
        if (str_ends_with($request->url(), '/checkout_sessions/cs_old')) {
            return Http::response(['data' => ['id' => 'cs_old', 'attributes' => [
                'livemode' => false, 'status' => $closed ? 'expired' : 'active', 'payments' => [],
                'checkout_url' => 'https://checkout.paymongo.com/cs_old', 'payment_intent' => ['id' => 'pi_old'],
            ]]]);
        }
        if (str_ends_with($request->url(), '/payment_intents/pi_old')) {
            return Http::response(['data' => ['id' => 'pi_old', 'attributes' => [
                'livemode' => false, 'amount' => 10000, 'currency' => 'PHP', 'payments' => [],
                'status' => $closed ? 'cancelled' : 'awaiting_next_action',
                'next_action' => ['code' => ['id' => 'qr_current',
                    'test_url' => 'https://secure-authentication.paymongo.com/?id=src_current&code_id=qr_current']],
            ]]]);
        }
        if (str_ends_with($request->url(), '/sources/src_current')) {
            return Http::response(['data' => ['id' => 'src_current', 'attributes' => [
                'livemode' => false, 'type' => 'qrph', 'amount' => 10000, 'currency' => 'PHP', 'status' => 'expired',
            ]]]);
        }
        throw new RuntimeException('Unexpected provider request.');
    });

    foreach (range(1, 2) as $observation) {
        $result = app(ObserveHostedCheckout::class)->execute($flow->fresh());
        expect($result->requiresFreshTestSession)->toBeTrue()->and($flow->fresh()->status)->toBe('open');
    }
    $this->actingAs($order->user)->postJson(route('orders.paymongo.retry', $order))
        ->assertOk()->assertJsonPath('payment.checkout_url', 'https://checkout.paymongo.com/cs_new');
    expect($flow->fresh()->status)->toBe('closed')->and($flow->fresh()->active_slot)->toBeNull()
        ->and(PayMongoFlow::query()->where('order_id', $order->id)->where('active_slot', 'active')->count())->toBe(1)
        ->and($order->fresh()->payment_status->value)->toBe('pending');
});
