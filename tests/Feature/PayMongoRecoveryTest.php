<?php

use App\Actions\PayMongo\RequestPaymentReconciliation;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Jobs\ReconcilePayMongoOrder;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayMongoFlow;
use App\Models\User;
use App\Repositories\Order\AdministratorOrderRepository;
use App\Services\AdministratorOrderPresenter;
use App\Services\CustomerOrderPresenter;
use App\Services\Notifications\OrderNotificationPublisher;
use App\Services\Order\OrderProcessingService;
use App\Services\PayMongo\ObserveHostedCheckout;
use App\Services\PayMongo\PaymentRecovery;
use App\Services\PayMongo\RecordPayMongoPayment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.paymongo.enabled' => true, 'services.paymongo.mode' => 'test', 'services.paymongo.secret_key' => 'sk_test_fake']);
    $this->actor = User::factory()->administrator()->create();
    $this->customer = User::factory()->customer()->create();
    $this->order = Order::factory()->for($this->customer)->create([
        'payment_method' => PaymentMethod::Online, 'payment_origin' => 'paymongo', 'total_amount' => '100.00',
        'assigned_handler_id' => $this->actor->id,
    ]);
    $this->stock = Inventory::factory()->create(['quantity' => 7]);
    OrderItem::factory()->for($this->order)->create(['product_id' => $this->stock->product_id, 'quantity' => 2, 'price_at_time' => '50.00']);
    $this->flow = PayMongoFlow::query()->create([
        'order_id' => $this->order->id, 'mode' => 'test', 'channel' => 'hosted_checkout',
        'status' => 'open', 'amount_minor' => 10000, 'currency' => 'PHP',
        'checkout_session_id' => 'cs_recovery', 'checkout_url' => 'https://checkout.paymongo.com/cs_recovery',
        'merchant_cutoff_at' => $this->order->created_at->copy()->addDay(),
    ]);
});

/** @return array<string, mixed> */
function recoverySession(string $status = 'active', array $payments = []): array
{
    return ['data' => ['id' => 'cs_recovery', 'attributes' => [
        'livemode' => false, 'status' => $status,
        'checkout_url' => 'https://checkout.paymongo.com/cs_recovery', 'payments' => $payments,
    ]]];
}

function runPaymentRecovery(PayMongoFlow $flow): void
{
    app()->call([new ReconcilePayMongoOrder($flow->id), 'handle']);
}

test('background observation finds failures without customer activity or replacement payments', function () {
    Http::fake(['*' => Http::response(recoverySession(payments: [['id' => 'pay_failed', 'attributes' => ['status' => 'failed']]]))]);
    runPaymentRecovery($this->flow);
    expect($this->flow->fresh()->recovery_outcome)->toBe('failed')
        ->and($this->flow->fresh()->last_checked_at)->not->toBeNull()
        ->and($this->stock->fresh()->quantity)->toBe(7)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Pending);
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('recovery remains available when new payment starts are disabled', function () {
    config(['services.paymongo.enabled' => false]);
    Http::fake(['*' => Http::response(recoverySession())]);
    runPaymentRecovery($this->flow);
    expect($this->flow->fresh()->recovery_outcome)->toBe('awaiting');
    Http::assertSentCount(1);
});

test('a safely closed flow can complete overdue cancellation and restore stock once', function () {
    $this->flow->update(['status' => 'closed', 'active_slot' => null, 'closed_at' => now(), 'merchant_cutoff_at' => now()->subMinute()]);
    runPaymentRecovery($this->flow);
    runPaymentRecovery($this->flow);
    expect($this->order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($this->stock->fresh()->quantity)->toBe(9);
    Http::assertNothingSent();
});

test('a historical closed flow does not authorize cancellation while a newer flow is uncertain', function () {
    $this->flow->update(['status' => 'closed', 'active_slot' => null]);
    $new = $this->flow->replicate(['checkout_session_id']);
    $new->fill(['status' => 'open', 'active_slot' => 'active', 'checkout_session_id' => 'cs_new'])->save();
    Http::fake(['*' => Http::response([], 503)]);
    expect(fn () => app(OrderProcessingService::class)->updateStatus($this->order, OrderStatus::Cancelled, $this->actor))
        ->toThrow(ValidationException::class);
    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($this->stock->fresh()->quantity)->toBe(7)
        ->and($this->order->fresh()->payment_closure_requested_at)->not->toBeNull();
    $customer = app(CustomerOrderPresenter::class)->detail($this->order->fresh());
    expect(data_get($customer, 'payment.action'))->toBeNull();
});

test('stale handler cancellation cannot call the provider or claim closure', function () {
    $other = User::factory()->administrator()->create();
    $this->actingAs($other)->patch(route('administration.orders.status.update', $this->order), ['status' => 'cancelled'])->assertForbidden();
    expect($this->order->fresh()->payment_closure_requested_at)->toBeNull();
    Http::assertNothingSent();
});

test('reconciliation action checks handler ownership and dispatches without provider calls', function () {
    Bus::fake();
    $other = User::factory()->administrator()->create();
    $this->actingAs($other)->post(route('administration.orders.payment-reconciliation.store', $this->order))->assertForbidden();
    Bus::assertNothingDispatched();
    $this->actingAs($this->actor)->post(route('administration.orders.payment-reconciliation.store', $this->order))->assertRedirect();
    $this->post(route('administration.orders.payment-reconciliation.store', $this->order))->assertRedirect();
    Bus::assertDispatchedTimes(ReconcilePayMongoOrder::class, 1);
    expect(DB::table('audit_logs')->where('action', 'payment.reconciliation_requested')->count())->toBe(1);
    Http::assertNothingSent();
});

test('rolled back reconciliation requests publish no payment jobs or audit changes', function () {
    config(['queue.default' => 'database']);
    $before = $this->flow->fresh()->getAttributes();

    expect(fn () => DB::transaction(function (): void {
        app(RequestPaymentReconciliation::class)->execute($this->actor, $this->order);
        throw new RuntimeException('Roll back reconciliation request');
    }))->toThrow(RuntimeException::class, 'Roll back reconciliation request');

    expect($this->flow->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    expect($this->stock->fresh()->quantity)->toBe(7);
    Http::assertNothingSent();
});

test('provider uncertainty escalates without resetting its age or releasing stock', function () {
    $this->freezeTime();
    Http::fake(['*' => Http::response([], 503)]);
    runPaymentRecovery($this->flow);
    $since = $this->flow->fresh()->exception_since;
    $this->travel(16)->minutes();
    runPaymentRecovery($this->flow);
    expect($this->flow->fresh()->requires_attention)->toBeTrue()
        ->and($this->flow->fresh()->exception_since->equalTo($since))->toBeTrue()
        ->and($this->stock->fresh()->quantity)->toBe(7)
        ->and(DB::table('audit_logs')->where('action', 'payment.recovery_exception')->count())->toBe(1);
});

test('successful later observation clears transient uncertainty', function () {
    app(PaymentRecovery::class)->uncertain($this->flow);
    Http::fake(['*' => Http::response(recoverySession())]);
    runPaymentRecovery($this->flow);
    expect($this->flow->fresh()->recovery_failures)->toBe(0)
        ->and($this->flow->fresh()->exception_since)->toBeNull()
        ->and($this->flow->fresh()->status)->toBe('open');
});

test('provider rate limits delay further work', function () {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '1800'])]);
    runPaymentRecovery($this->flow);
    expect($this->flow->fresh()->next_check_at->greaterThanOrEqualTo(now()->addMinutes(29)))->toBeTrue();
});

test('processing payment blocks expiry even when the session payments are stale', function () {
    $session = recoverySession();
    data_set($session, 'data.attributes.payment_intent', ['id' => 'pi_current']);
    Http::fake([
        '*/checkout_sessions/cs_recovery' => Http::response($session),
        '*/payment_intents/pi_current' => Http::response(['data' => ['id' => 'pi_current', 'attributes' => [
            'livemode' => false, 'amount' => 10000, 'currency' => 'PHP', 'status' => 'processing', 'payments' => [],
        ]]]),
    ]);
    expect(fn () => app(OrderProcessingService::class)->updateStatus($this->order, OrderStatus::Cancelled, $this->actor))->toThrow(ValidationException::class);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    expect($this->stock->fresh()->quantity)->toBe(7);
});

test('unknown payment evidence cannot authorize closure', function (mixed $payments) {
    $session = recoverySession();
    data_set($session, 'data.attributes.payments', $payments);
    Http::fake(['*' => Http::response($session)]);
    expect(fn () => app(OrderProcessingService::class)->updateStatus($this->order, OrderStatus::Cancelled, $this->actor))->toThrow(ValidationException::class);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    expect($this->stock->fresh()->quantity)->toBe(7);
})->with([null, [[['id' => 'pay_unknown', 'attributes' => ['status' => 'unknown']]]]]);

test('paid evidence winning cancellation verifies payment without restoring stock', function () {
    Http::fake(['*' => Http::response(recoverySession(payments: [[
        'id' => 'pay_success', 'attributes' => ['status' => 'paid', 'amount' => 10000, 'currency' => 'PHP', 'source' => ['type' => 'card']],
    ]]))]);
    expect(fn () => app(OrderProcessingService::class)->updateStatus($this->order, OrderStatus::Cancelled, $this->actor))->toThrow(ValidationException::class);
    expect($this->order->fresh()->payment_status->value)->toBe('verified')
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($this->stock->fresh()->quantity)->toBe(7);
});

test('late payment on a cancelled order remains a visible financial exception', function () {
    $this->order->update(['status' => 'cancelled']);
    $recorded = app(RecordPayMongoPayment::class)->execute($this->flow, [
        'id' => 'pay_late', 'status' => 'paid', 'amount' => 10000, 'currency' => 'PHP', 'net_amount' => null, 'method' => 'card',
    ], app(OrderNotificationPublisher::class));
    expect($recorded)->toBeFalse()->and($this->flow->fresh()->requires_attention)->toBeTrue();
    $this->actingAs($this->actor)->get(route('administration.orders.index', ['gateway_state' => 'needs_attention']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.payment.gateway.state', 'needs_attention'));
});

test('admin presentation selects the active flow and preserves historical exceptions', function () {
    $this->flow->update(['status' => 'closed', 'active_slot' => null, 'requires_attention' => true, 'recovery_reason' => 'financial_anomaly']);
    $new = $this->flow->replicate(['checkout_session_id']);
    $new->fill(['status' => 'open', 'active_slot' => 'active', 'checkout_session_id' => 'cs_latest', 'requires_attention' => false])->save();
    $order = app(AdministratorOrderRepository::class)->loadDetails($this->order);
    $data = app(AdministratorOrderPresenter::class)->detail($order, $this->actor);
    expect(data_get($data, 'order.payment.gateway.status'))->toBe('open')
        ->and(data_get($data, 'order.payment.gateway.state'))->toBe('needs_attention')
        ->and(data_get($data, 'order.payment.gateway.history.0.provider_reference'))->toBe('cs_latest')
        ->and(data_get($data, 'order.payment.method.requires_proof'))->toBeFalse();
});

test('gateway filters select the current flow rather than old failures', function () {
    $this->flow->update(['status' => 'closed', 'active_slot' => null, 'recovery_outcome' => 'failed']);
    $new = $this->flow->replicate(['checkout_session_id']);
    $new->fill(['status' => 'open', 'active_slot' => 'active', 'checkout_session_id' => 'cs_latest', 'recovery_outcome' => 'awaiting'])->save();
    $this->actingAs($this->actor)->get(route('administration.orders.index', ['gateway_state' => 'failed']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
    $this->get(route('administration.orders.index', ['gateway_state' => 'awaiting']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));
});

test('due batches advance past the first hundred unresolved records', function () {
    Bus::fake();
    for ($i = 0; $i < 101; $i++) {
        $flow = $this->flow->replicate(['checkout_session_id']);
        $flow->fill(['active_slot' => null, 'status' => 'closed', 'next_check_at' => null])->save();
    }
    $this->artisan('paymongo:reconcile-overdue')->assertSuccessful();
    $this->artisan('paymongo:reconcile-overdue')->assertSuccessful();
    Bus::assertDispatchedTimes(ReconcilePayMongoOrder::class, 102);
});

test('order coordination excludes another closure or retry worker', function () {
    $lock = Cache::lock('paymongo-order:'.$this->order->id, 120);
    $lock->get();
    try {
        expect(fn () => app(ObserveHostedCheckout::class)->execute($this->flow))->toThrow(RuntimeException::class);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

test('a confirmed old financial exception blocks a new customer payment even when its flow is closed', function () {
    $this->flow->update(['status' => 'closed', 'active_slot' => null, 'requires_attention' => true, 'recovery_reason' => 'financial_anomaly']);
    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertStatus(202);
    Http::assertNothingSent();
});

test('an order with missing provider history is visible in the exception queue', function () {
    $missing = Order::factory()->for($this->customer)->create(['payment_method' => PaymentMethod::Online, 'payment_origin' => 'paymongo']);
    $this->actingAs($this->actor)->get(route('administration.orders.index', ['gateway_state' => 'needs_attention']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.id', $missing->id));
});
