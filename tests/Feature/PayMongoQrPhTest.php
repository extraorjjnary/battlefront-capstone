<?php

use App\Jobs\ProcessPayMongoWebhook;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Models\PayMongoWebhookInbox;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Notifications\OrderNotificationPublisher;
use App\Services\PayMongo\CloseHostedCheckout;
use App\Services\PayMongo\RecordPayMongoPayment;
use App\Services\PayMongo\StartDirectQrPh;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function qrPhAttachResponse(string $intentId = 'pi_test_qr'): array
{
    return ['data' => [
        'id' => $intentId,
        'attributes' => [
            'livemode' => false,
            'status' => 'awaiting_next_action',
            'next_action' => ['code' => ['image_url' => 'data:image/png;base64,'.base64_encode('qr-image')]],
        ],
    ]];
}

function configureQrPhTestPayment(): void
{
    config([
        'services.paymongo.enabled' => true,
        'services.paymongo.mode' => 'test',
        'services.paymongo.secret_key' => 'sk_test_fake',
        'services.paymongo.public_key' => 'pk_test_fake',
        'services.paymongo.hosted_methods' => ['qrph', 'card', 'gcash', 'paymaya'],
    ]);
}

it('starts QR Ph through Hosted Checkout and leaves the customer on the saved order page', function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => [
            'id' => 'cs_test_checkout_qr',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_checkout_qr#key'],
        ]]),
    ]);
    configureQrPhTestPayment();
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['price' => '100.00']);
    Inventory::factory()->for($product)->create(['quantity' => 3]);
    $item = app(CartService::class)->add($customer, $product->id, 1);

    $response = $this->actingAs($customer)->post(route('orders.store'), [
        'cart_item_ids' => [$item->id],
        'recipient_name' => $customer->name,
        'contact_number' => '09171234567',
        'fulfillment_method' => 'pickup',
        'payment_method' => 'online',
        'checkout_submission_key' => (string) Str::uuid(),
        'payment_channel' => 'hosted_checkout',
        'hosted_payment_method' => 'qrph',
    ]);

    $order = Order::query()->firstOrFail();
    $response->assertRedirect(route('orders.show', $order));
    expect(PayMongoFlow::query()->where('order_id', $order->id)->value('channel'))->toBe('hosted_checkout');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
        && $request['data']['attributes']['payment_method_types'] === ['qrph']);

    $this->actingAs($customer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Show')
            ->where('order.payment.checkout_url', 'https://checkout.paymongo.com/cs_test_checkout_qr#key')
            ->where('order.payment.hosted_payment_method', 'qrph'));
});

it('rejects the retired direct QR Ph request channel', function () {
    configureQrPhTestPayment();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson(route('orders.store'), [
            'cart_item_ids' => [PHP_INT_MAX],
            'recipient_name' => $customer->name,
            'contact_number' => '09171234567',
            'fulfillment_method' => 'pickup',
            'payment_method' => 'online',
            'checkout_submission_key' => (string) Str::uuid(),
            'payment_channel' => 'direct_qrph',
            'hosted_payment_method' => 'card',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_channel']);
});

it('returns a Hosted Checkout URL to mobile orders when the selected method is QR Ph', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => [
            'id' => 'cs_test_mobile_qr',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_mobile_qr#key'],
        ]]),
    ]);
    configureQrPhTestPayment();
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['price' => '100.00']);
    Inventory::factory()->for($product)->create(['quantity' => 2]);
    $item = app(CartService::class)->add($customer, $product->id, 1);

    $response = $this->actingAs($customer, 'sanctum')->postJson(route('api.v1.orders.store'), [
        'cart_item_ids' => [$item->id],
        'recipient_name' => $customer->name,
        'contact_number' => '09171234567',
        'fulfillment_method' => 'pickup',
        'payment_method' => 'online',
        'checkout_submission_key' => (string) Str::uuid(),
        'payment_channel' => 'hosted_checkout',
        'hosted_payment_method' => 'qrph',
    ]);

    $response->assertCreated()
        ->assertJsonPath('payment.redirect_url', 'https://checkout.paymongo.com/cs_test_mobile_qr#key');
    expect($response->getContent())->not->toContain('pi_test_mobile_qr_client')
        ->and($response->getContent())->not->toContain('pk_test_fake');
});

it('recovers a missed QR expiry and creates a fresh QR only after PayMongo confirms expiry', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.paymongo.com/v1/payment_intents/pi_test_qr_retry' => Http::response(['data' => [
            'id' => 'pi_test_qr_retry',
            'attributes' => [
                'livemode' => false,
                'amount' => 25000,
                'currency' => 'PHP',
                'payment_method_allowed' => ['qrph'],
                'client_key' => 'pi_test_qr_retry_client',
                'status' => 'awaiting_payment_method',
            ],
        ]]),
        'https://api.paymongo.com/v1/payment_methods' => Http::response(['data' => ['id' => 'pm_test_qr_retry']]),
        'https://api.paymongo.com/v1/payment_intents/pi_test_qr_retry/attach' => Http::response(qrPhAttachResponse('pi_test_qr_retry')),
    ]);
    configureQrPhTestPayment();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => 'online',
        'payment_origin' => 'paymongo',
        'hosted_payment_method' => 'qrph',
        'total_amount' => '250.00',
    ]);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id,
        'mode' => 'test',
        'channel' => 'direct_qrph',
        'status' => 'open',
        'payment_intent_id' => 'pi_test_qr_retry',
        'qr_code_image_url' => 'data:image/png;base64,'.base64_encode('expired'),
        'qr_expires_at' => now()->subSecond(),
        'amount_minor' => 25000,
        'currency' => 'PHP',
        'merchant_cutoff_at' => now()->addHour(),
    ]);

    app(StartDirectQrPh::class)->execute($order);

    expect($flow->fresh()->status)->toBe('open')
        ->and($flow->fresh()->qr_code_image_url)->toBe('data:image/png;base64,'.base64_encode('qr-image'))
        ->and($flow->fresh()->qr_expires_at->isFuture())->toBeTrue();
    $this->assertDatabaseHas('paymongo_operations', [
        'paymongo_flow_id' => $flow->id,
        'kind' => 'attach_qr_method_1',
        'status' => 'completed',
    ]);
});

it('clears an expired QR after a signed provider event', function () {
    Bus::fake();
    configureQrPhTestPayment();
    config(['services.paymongo.webhook_secret' => 'whsk_test_fake']);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => 'online',
        'payment_origin' => 'paymongo',
        'hosted_payment_method' => 'qrph',
    ]);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id,
        'mode' => 'test',
        'channel' => 'direct_qrph',
        'status' => 'open',
        'payment_intent_id' => 'pi_test_expired_qr',
        'qr_code_image_url' => 'data:image/png;base64,'.base64_encode('expired'),
        'qr_expires_at' => now()->addMinutes(20),
        'amount_minor' => 10000,
        'currency' => 'PHP',
        'merchant_cutoff_at' => now()->addDay(),
    ]);
    $timestamp = (string) now()->timestamp;
    $payload = json_encode(['data' => [
        'id' => 'evt_test_qr_expired',
        'type' => 'event',
        'attributes' => [
            'type' => 'qrph.expired',
            'livemode' => false,
            'data' => [
                'id' => 'pi_test_expired_qr',
                'type' => 'payment_intent',
                'attributes' => ['status' => 'awaiting_payment_method'],
            ],
        ],
    ]], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsk_test_fake');

    $this->call('POST', '/api/paymongo/webhook', [], [], [], [
        'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature},li=",
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertAccepted();
    $inbox = PayMongoWebhookInbox::query()->where('event_id', 'evt_test_qr_expired')->firstOrFail();
    app(ProcessPayMongoWebhook::class, ['inboxId' => $inbox->id])->handle(
        app(OrderNotificationPublisher::class),
        app(RecordPayMongoPayment::class),
    );

    expect($flow->fresh()->status)->toBe('awaiting_payment_method')
        ->and($flow->fresh()->qr_code_image_url)->toBeNull()
        ->and($flow->fresh()->qr_expires_at)->toBeNull()
        ->and($inbox->fresh()->status)->toBe('processed');
});

it('releases an unpaid QR Ph order only after PayMongo confirms intent cancellation', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.paymongo.com/v1/payment_intents/pi_test_cancel_qr' => Http::sequence()
            ->push(['data' => ['id' => 'pi_test_cancel_qr', 'attributes' => [
                'livemode' => false,
                'amount' => 10000,
                'currency' => 'PHP',
                'status' => 'awaiting_next_action',
                'payments' => [],
            ]]])
            ->push(['data' => ['id' => 'pi_test_cancel_qr', 'attributes' => [
                'livemode' => false,
                'amount' => 10000,
                'currency' => 'PHP',
                'status' => 'cancelled',
                'payments' => [],
            ]]]),
        'https://api.paymongo.com/v1/payment_intents/pi_test_cancel_qr/cancel' => Http::response([
            'data' => ['id' => 'pi_test_cancel_qr', 'attributes' => ['status' => 'cancelled']],
        ]),
    ]);
    configureQrPhTestPayment();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => 'online',
        'payment_origin' => 'paymongo',
        'hosted_payment_method' => 'qrph',
    ]);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id,
        'mode' => 'test',
        'channel' => 'direct_qrph',
        'status' => 'open',
        'payment_intent_id' => 'pi_test_cancel_qr',
        'qr_code_image_url' => 'data:image/png;base64,'.base64_encode('qr-image'),
        'qr_expires_at' => now()->addMinutes(20),
        'amount_minor' => 10000,
        'currency' => 'PHP',
        'merchant_cutoff_at' => now()->subMinute(),
    ]);

    expect(app(CloseHostedCheckout::class)->execute($order->id))->toBeTrue()
        ->and($flow->fresh()->status)->toBe('closed');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.paymongo.com/v1/payment_intents/pi_test_cancel_qr/cancel');
});
