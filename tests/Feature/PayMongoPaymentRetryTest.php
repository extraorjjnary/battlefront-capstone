<?php

use App\Enums\PaymentMethod;
use App\Jobs\ProcessPayMongoWebhook;
use App\Jobs\ReconcilePayMongoOrder;
use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Models\PayMongoWebhookInbox;
use App\Models\User;
use App\Services\CustomerOrderPresenter;
use App\Services\PayMongo\ReconcilePayMongoReturn;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.paymongo.enabled' => true, 'services.paymongo.mode' => 'test', 'services.paymongo.secret_key' => 'sk_test_fake']);
    $this->customer = User::factory()->customer()->create();
    $this->order = Order::factory()->for($this->customer)->create([
        'payment_method' => PaymentMethod::Online, 'payment_origin' => 'paymongo', 'total_amount' => '100.00',
    ]);
    $this->flow = PayMongoFlow::query()->create([
        'order_id' => $this->order->id, 'active_slot' => 'active', 'mode' => 'test',
        'channel' => 'hosted_checkout', 'status' => 'open', 'amount_minor' => 10000, 'currency' => 'PHP',
        'checkout_session_id' => 'cs_old', 'checkout_url' => 'https://checkout.paymongo.com/cs_old',
        'merchant_cutoff_at' => $this->order->created_at->copy()->addDay(),
    ]);
});

test('retry resumes active sessions and replaces confirmed expired sessions', function (string $status) {
    Http::fake([
        'https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
            'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => $status,
                'checkout_url' => 'https://checkout.paymongo.com/cs_old', 'payments' => []],
        ]]),
        'https://api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => [
            'id' => 'cs_new', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_new'],
        ]]),
    ]);
    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))
        ->assertOk()->assertJsonPath('payment.checkout_url', 'https://checkout.paymongo.com/'.($status === 'active' ? 'cs_old' : 'cs_new'));
    expect(Order::query()->count())->toBe(1)
        ->and(PayMongoFlow::query()->where('active_slot', 'active')->count())->toBe(1);
    Http::assertSentCount($status === 'active' ? 1 : 2);
    if ($status === 'expired') {
        expect($this->flow->fresh()->active_slot)->toBeNull()
            ->and(PayMongoFlow::query()->latest('id')->first()->merchant_cutoff_at->equalTo($this->flow->merchant_cutoff_at))->toBeTrue();
    }
})->with(['active', 'expired']);

test('uncertain provider state never creates a replacement', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'processing']]])]);
    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertStatus(202);
    Http::assertSentCount(1);
    expect(PayMongoFlow::query()->count())->toBe(1);
});

test('ineligible orders cannot retry', function (string $state) {
    $this->order->forceFill(match ($state) {
        'paid' => ['payment_status' => 'verified'],
        'cancelled' => ['status' => 'cancelled'],
        'overdue' => ['created_at' => now()->subDay()],
    })->save();
    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertStatus(202);
    Http::assertNothingSent();
})->with(['paid', 'cancelled', 'overdue']);

test('foreign customers cannot retry', function () {
    $this->actingAs(User::factory()->customer()->create())->postJson(route('orders.paymongo.retry', $this->order))->assertNotFound();
    Http::assertNothingSent();
});

test('overlapping retries do not call the provider', function () {
    $lock = Cache::lock('paymongo-retry:'.$this->order->id, 120);
    $lock->get();
    try {
        $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertStatus(202);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

test('provider retrieval failures keep the existing flow', function () {
    Http::fake(['*' => Http::response([], 500)]);
    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertStatus(202);
    expect($this->flow->fresh()->active_slot)->toBe('active');
    Http::assertSentCount(1);
});

test('expired sessions with unresolved payment evidence cannot be replaced', function (mixed $payments) {
    Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
        'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'expired', 'payments' => $payments],
    ]])]);

    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertStatus(202);

    expect($this->flow->fresh()->active_slot)->toBe('active')
        ->and(PayMongoFlow::query()->count())->toBe(1);
    Http::assertSentCount(1);
})->with([
    'pending' => [[['attributes' => ['status' => 'pending']]]],
    'processing' => [[['attributes' => ['status' => 'processing']]]],
    'unknown' => [[['attributes' => ['status' => 'unknown']]]],
    'malformed' => ['invalid'],
]);

test('failed payments reuse a still active checkout session', function () {
    Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
        'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'active',
            'checkout_url' => 'https://checkout.paymongo.com/cs_old',
            'payments' => [['id' => 'pay_failed', 'attributes' => ['status' => 'failed']]]],
    ]])]);

    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))
        ->assertOk()->assertJsonPath('payment.checkout_url', 'https://checkout.paymongo.com/cs_old');
    Http::assertSentCount(1);
});

test('background reconciliation never generates a replacement session', function () {
    $this->flow->update(['status' => 'reconciliation_required']);
    Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
        'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'expired', 'payments' => []],
    ]])]);

    app()->call([new ReconcilePayMongoOrder($this->flow->id), 'handle']);

    expect($this->flow->fresh()->status)->toBe('closed')
        ->and(PayMongoFlow::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

test('inactive unresolved history blocks another attempt', function () {
    $this->flow->update(['active_slot' => null, 'status' => 'reconciliation_required']);

    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertStatus(202);

    Http::assertNothingSent();
    expect(PayMongoFlow::query()->count())->toBe(1);
});

test('definitive setup failure can be retried without deleting history', function () {
    $this->flow->update(['status' => 'failed', 'checkout_session_id' => null, 'checkout_url' => null]);
    $this->flow->operations()->create([
        'operation_id' => (string) Str::uuid(), 'kind' => 'create_checkout_session',
        'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64), 'status' => 'failed',
    ]);
    Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => [
        'id' => 'cs_new', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_new'],
    ]])]);

    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertOk();

    expect($this->flow->fresh()->status)->toBe('closed')
        ->and(PayMongoFlow::query()->count())->toBe(2);
    Http::assertSentCount(1);
});

test('payment confirmed during retrieval prevents replacement', function () {
    Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_old' => function () {
        $this->order->update(['payment_status' => 'verified']);
        $this->flow->update(['status' => 'paid']);

        return Http::response(['data' => [
            'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'expired', 'payments' => []],
        ]]);
    }]);

    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertStatus(202);

    expect($this->flow->fresh()->status)->toBe('paid')
        ->and(PayMongoFlow::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

test('hosted recovery lock prevents overlapping provider operations', function () {
    $lock = Cache::lock('paymongo-hosted:'.$this->order->id, 120);
    $lock->get();
    try {
        $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertStatus(202);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

test('expired session with an ongoing intent cannot be replaced', function () {
    Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
        'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'expired', 'payments' => [],
            'payment_intent' => ['attributes' => ['status' => 'processing']]],
    ]])]);

    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertStatus(202);

    expect($this->flow->fresh()->active_slot)->toBe('active');
    Http::assertSentCount(1);
});

test('ambiguous setup errors retain their original operation', function (int $status) {
    $this->flow->update(['active_slot' => null, 'status' => 'closed']);
    Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::response([], $status)]);

    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))->assertStatus(202);

    $flow = PayMongoFlow::query()->latest('id')->firstOrFail();
    expect($flow->status)->toBe('reconciliation_required')
        ->and($flow->operations()->firstOrFail()->status)->toBe('unknown');
    Http::assertSentCount(1);
})->with([408, 409, 429]);

test('confirmed failure shows retry and successful resume switches back to continue', function () {
    Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
        'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'active',
            'checkout_url' => 'https://checkout.paymongo.com/cs_old',
            'payments' => [['id' => 'pay_failed', 'attributes' => ['status' => 'failed']]]],
    ]])]);
    app(ReconcilePayMongoReturn::class)->refreshHosted($this->order);
    $presenter = app(CustomerOrderPresenter::class);
    $payment = $presenter->detail($this->order->fresh())['payment'];
    expect($payment['action'])->toBe('retry')->and($payment['checkout_url'])->toBeNull()
        ->and($payment['can_retry_hosted'])->toBeTrue();

    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))
        ->assertOk()->assertJsonPath('data.payment.action', 'continue')
        ->assertJsonPath('data.payment.can_retry_hosted', false)
        ->assertJsonPath('payment.checkout_url', 'https://checkout.paymongo.com/cs_old');

    $this->travel(6)->seconds();
    app(ReconcilePayMongoReturn::class)->refreshHosted($this->order->fresh());
    expect($presenter->detail($this->order->fresh())['payment']['action'])->toBe('continue');
    expect(PayMongoFlow::query()->count())->toBe(1);
    Http::assertSentCount(3);
});

test('hosted actions are exclusive and eligibility removes stale links', function (string $state, ?string $action) {
    if ($state === 'paid') {
        $this->order->update(['payment_status' => 'verified']);
    } elseif ($state === 'cancelled') {
        $this->order->update(['status' => 'cancelled']);
    } elseif ($state === 'overdue') {
        $this->order->forceFill(['created_at' => now()->subDays(2)])->save();
    } else {
        $this->flow->update(['status' => $state]);
    }
    $payment = app(CustomerOrderPresenter::class)->detail($this->order->fresh())['payment'];
    expect($payment['action'])->toBe($action)
        ->and($payment['can_retry_hosted'])->toBe($action === 'retry')
        ->and($payment['checkout_url'])->toBe($action === 'continue' ? 'https://checkout.paymongo.com/cs_old' : null);
    Http::assertNothingSent();
})->with([
    ['open', 'continue'], ['closed', 'retry'], ['reconciliation_required', null],
    ['creating', null], ['failed', null], ['paid', null], ['cancelled', null], ['overdue', null],
]);

test('provider expiry found during refresh enables retry without creating payment', function () {
    Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
        'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'expired', 'payments' => []],
    ]])]);
    app(ReconcilePayMongoReturn::class)->refreshHosted($this->order);
    $payment = app(CustomerOrderPresenter::class)->detail($this->order->fresh())['payment'];
    expect($payment['action'])->toBe('retry')->and($payment['checkout_url'])->toBeNull();
    Http::assertSentCount(1);
});

test('processing evidence hides both payment actions', function () {
    Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
        'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'active',
            'payments' => [['id' => 'pay_processing', 'attributes' => ['status' => 'processing']]]],
    ]])]);
    app(ReconcilePayMongoReturn::class)->refreshHosted($this->order);
    $payment = app(CustomerOrderPresenter::class)->detail($this->order->fresh())['payment'];
    expect($payment['action'])->toBeNull()->and($payment['checkout_url'])->toBeNull()
        ->and($payment['can_retry_hosted'])->toBeFalse();
    Http::assertSentCount(1);
});

test('hosted failure webhook requests reconciliation without guessing a retry state', function () {
    Bus::fake([ReconcilePayMongoOrder::class]);
    $this->flow->update(['payment_intent_id' => 'pi_current']);
    $inbox = PayMongoWebhookInbox::query()->create([
        'mode' => 'test', 'event_id' => 'evt_failure', 'event_type' => 'payment.failed',
        'resource_id' => 'pay_failed', 'status' => 'received', 'received_at' => now(),
        'verified_data' => ['attributes' => ['payment_intent_id' => 'pi_current', 'payments' => []]],
    ]);
    app()->call([new ProcessPayMongoWebhook($inbox->id), 'handle']);

    expect($this->flow->fresh()->status)->toBe('reconciliation_required');
    Bus::assertDispatched(ReconcilePayMongoOrder::class,
        fn ($job): bool => $job->flowId === $this->flow->id);
    Http::assertNothingSent();
});

test('order page refresh discovers confirmed failure and exposes only retry', function () {
    config(['inertia.ssr.enabled' => false]);
    Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
        'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'active',
            'checkout_url' => 'https://checkout.paymongo.com/cs_old',
            'payments' => [['id' => 'pay_failed', 'attributes' => ['status' => 'failed']]]],
    ]])]);
    $this->actingAs($this->customer)->get(route('orders.show', $this->order))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Orders/Show')->where('order.payment.action', 'retry')
        ->where('order.payment.checkout_url', null)->where('order.payment.can_retry_hosted', true));
    Http::assertSentCount(1);
});

test('verified QR expiry enables retry and resumes the same active session', function (string $format) {
    $this->freezeTime();
    $this->flow->update(['hosted_payment_method' => 'qrph']);
    $this->order->update(['hosted_payment_method' => 'qrph']);
    Http::fake([
        'https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
            'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'active', 'payments' => [],
                'checkout_url' => 'https://checkout.paymongo.com/cs_old', 'payment_intent' => ['id' => 'pi_qr']],
        ]]),
        'https://api.paymongo.com/v1/payment_intents/pi_qr' => Http::response(['data' => [
            'id' => 'pi_qr', 'attributes' => ['livemode' => false, 'amount' => 10000, 'currency' => 'PHP',
                'status' => 'awaiting_next_action', 'next_action' => ['code' => ['expires_at' => $format === 'iso'
                    ? now()->subMinute()->toISOString() : now()->subMinute()->timestamp]]],
        ]]),
    ]);

    app(ReconcilePayMongoReturn::class)->refreshHosted($this->order);
    expect(app(CustomerOrderPresenter::class)->detail($this->order->fresh())['payment']['action'])->toBe('retry');
    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))
        ->assertOk()->assertJsonPath('data.payment.action', 'continue');
    expect(PayMongoFlow::query()->count())->toBe(1);
    Http::assertSentCount(4);
})->with(['timestamp', 'iso']);

test('current failed intent overrides stale checkout data and can resume', function (bool $expanded, string $evidence) {
    $intent = ['id' => 'pi_failed', 'attributes' => [
        'livemode' => false, 'amount' => 10000, 'currency' => 'PHP',
        'status' => 'awaiting_payment_method', 'updated_at' => 1234567890,
        'last_payment_error' => $evidence === 'error' ? ['code' => 'payment_failed', 'failed_message' => 'Test payment failed'] : null,
        'payments' => $evidence === 'payment' ? [['id' => 'pay_failed_current', 'attributes' => ['status' => 'failed']]] : [],
    ]];
    $staleIntent = $intent;
    $staleIntent['attributes']['status'] = 'awaiting_next_action';
    $staleIntent['attributes']['last_payment_error'] = null;
    $staleIntent['attributes']['payments'] = [];
    Http::fake([
        'https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
            'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'active', 'payments' => [],
                'checkout_url' => 'https://checkout.paymongo.com/cs_old',
                'payment_intent' => $expanded ? $staleIntent : ['id' => 'pi_failed']],
        ]]),
        'https://api.paymongo.com/v1/payment_intents/pi_failed' => Http::response(['data' => $intent]),
    ]);

    app(ReconcilePayMongoReturn::class)->refreshHosted($this->order);
    $payment = app(CustomerOrderPresenter::class)->detail($this->order->fresh())['payment'];
    expect($payment['action'])->toBe('retry')->and($payment['checkout_url'])->toBeNull();
    $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order))
        ->assertOk()->assertJsonPath('data.payment.action', 'continue');
    expect(PayMongoFlow::query()->count())->toBe(1);
    Http::assertSentCount(4);
})->with([[true, 'error'], [false, 'error'], [true, 'payment'], [false, 'payment']]);

test('QR test source failure selects retry while its intent still awaits action', function (string $status, array $overrides = []) {
    $this->flow->update(['hosted_payment_method' => 'qrph']);
    $this->order->update(['hosted_payment_method' => 'qrph']);
    Http::fake([
        'https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::response(['data' => [
            'id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'active', 'payments' => [],
                'checkout_url' => 'https://checkout.paymongo.com/cs_old', 'payment_intent' => ['id' => 'pi_test']],
        ]]),
        'https://api.paymongo.com/v1/payment_intents/pi_test' => Http::response(['data' => [
            'id' => 'pi_test', 'attributes' => ['livemode' => false, 'amount' => 10000, 'currency' => 'PHP',
                'status' => 'awaiting_next_action', 'last_payment_error' => null, 'payments' => [],
                'next_action' => ['code' => ['id' => 'qr_current', 'expires_at' => now()->addMinutes(20)->toISOString(),
                    'test_url' => 'https://secure-authentication.paymongo.com/?id=src_current&code_id=qr_current']]],
        ]]),
        'https://api.paymongo.com/v1/sources/src_current' => Http::response(['data' => [
            'id' => 'src_current', 'attributes' => array_replace(['status' => $status, 'livemode' => false,
                'type' => 'qrph', 'amount' => 10000, 'currency' => 'PHP'], $overrides),
        ]]),
    ]);

    app(ReconcilePayMongoReturn::class)->refreshHosted($this->order);
    $payment = app(CustomerOrderPresenter::class)->detail($this->order->fresh())['payment'];
    $uncertain = $overrides !== [] || in_array($status, ['consumed', 'processing'], true);
    $failed = ! $uncertain && in_array($status, ['failed', 'expired', 'cancelled'], true);
    expect($payment['action'])->toBe($uncertain ? null : ($failed ? 'retry' : 'continue'))
        ->and($payment['can_retry_hosted'])->toBe($failed);
    if ($failed) {
        expect($payment['checkout_url'])->toBeNull();
    }
    expect(PayMongoFlow::query()->count())->toBe(1);
    Http::assertSentCount(3);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with([
    ['failed'], ['expired'], ['cancelled'], ['pending'], ['chargeable'], ['consumed'], ['processing'],
    ['failed', ['currency' => 'USD']], ['failed', ['amount' => 9999]], ['failed', ['livemode' => true]],
]);

test('terminal QR test sources require confirmed session closure before a fresh retry', function (string $sourceStatus, string $closure) {
    $this->flow->update(['hosted_payment_method' => 'qrph']);
    $this->order->update(['hosted_payment_method' => 'qrph']);
    $session = ['id' => 'cs_old', 'attributes' => ['livemode' => false, 'status' => 'active', 'payments' => [],
        'checkout_url' => 'https://checkout.paymongo.com/cs_old', 'payment_intent' => ['id' => 'pi_test']]];
    $intent = ['id' => 'pi_test', 'attributes' => ['livemode' => false, 'amount' => 10000, 'currency' => 'PHP',
        'status' => 'awaiting_next_action', 'payments' => [],
        'next_action' => ['code' => ['id' => 'qr_current',
            'test_url' => 'https://secure-authentication.paymongo.com/?id=src_current&code_id=qr_current']]]];
    $closingIntent = $intent;
    if ($closure === 'processing') {
        $closingIntent['attributes']['status'] = 'processing';
    }
    $closedSession = $session;
    $closedIntent = $intent;
    if ($closure === 'closed') {
        $closedSession['attributes']['status'] = 'expired';
        $closedIntent['attributes']['status'] = 'cancelled';
    }
    Http::fake([
        'https://api.paymongo.com/v1/checkout_sessions/cs_old' => Http::sequence()
            ->push(['data' => $session])->push(['data' => $session])->push(['data' => $closedSession]),
        'https://api.paymongo.com/v1/payment_intents/pi_test' => Http::sequence()
            ->push(['data' => $intent])->push(['data' => $closingIntent])->push(['data' => $closedIntent]),
        'https://api.paymongo.com/v1/sources/src_current' => Http::response(['data' => [
            'id' => 'src_current', 'attributes' => ['status' => $sourceStatus, 'livemode' => false,
                'type' => 'qrph', 'amount' => 10000, 'currency' => 'PHP'],
        ]]),
        'https://api.paymongo.com/v1/checkout_sessions/cs_old/expire' => Http::response([], $closure === 'uncertain' ? 400 : 200),
        'https://api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => [
            'id' => 'cs_new', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_new'],
        ]]),
    ]);

    $response = $this->actingAs($this->customer)->postJson(route('orders.paymongo.retry', $this->order));

    if ($closure === 'closed') {
        $response->assertOk()->assertJsonPath('payment.checkout_url', 'https://checkout.paymongo.com/cs_new');
        expect($this->flow->fresh()->status)->toBe('closed')->and($this->flow->fresh()->active_slot)->toBeNull()
            ->and(PayMongoFlow::query()->count())->toBe(2);
        $replacement = PayMongoFlow::query()->where('active_slot', 'active')->sole();
        expect($replacement->hosted_payment_method)->toBe('qrph')
            ->and($replacement->merchant_cutoff_at->equalTo($this->flow->merchant_cutoff_at))->toBeTrue();
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/cs_old/expire'));
    } else {
        $response->assertStatus(202);
        expect($this->flow->fresh()->active_slot)->toBe('active')->and(PayMongoFlow::query()->count())->toBe(1);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v2/checkout_sessions'));
        if ($closure === 'processing') {
            Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        }
    }
    expect($this->order->fresh()->status->value)->toBe('pending')
        ->and($this->order->fresh()->payment_status->value)->toBe('pending');

    if ($closure === 'closed') {
        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions/cs_new' => Http::response(['data' => [
                'id' => 'cs_new', 'attributes' => ['livemode' => false, 'status' => 'active', 'payments' => [
                    ['id' => 'pay_retry', 'attributes' => ['status' => 'paid', 'amount' => 10000,
                        'net_amount' => 9800, 'currency' => 'PHP', 'source' => ['type' => 'qrph']]],
                ]],
            ]]),
        ]);
        $this->travel(6)->seconds();
        app(ReconcilePayMongoReturn::class)->refreshHosted($this->order->fresh());
        expect($this->order->fresh()->payment_status->value)->toBe('verified')
            ->and($replacement->fresh()->status)->toBe('paid')
            ->and($replacement->fresh()->payment_id)->toBe('pay_retry')
            ->and($this->flow->fresh()->status)->toBe('closed');
    }
})->with([
    ['expired', 'closed'], ['failed', 'closed'], ['cancelled', 'closed'],
    ['expired', 'uncertain'], ['expired', 'processing'],
]);
