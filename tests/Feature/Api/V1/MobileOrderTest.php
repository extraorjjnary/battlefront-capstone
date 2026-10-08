<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Order\CustomerOrderRepository;
use App\Services\CustomerOrderPresenter;
use App\Services\Order\OrderProcessingService;

test('mobile checkout and order routes require customer bearer authentication', function (string $method, string $path, string $access) {
    $customer = User::factory()->customer()->create();
    if ($access === 'session') {
        $this->actingAs($customer);
    } elseif ($access === 'invalid') {
        $this->withToken('invalid-token');
    } elseif ($access === 'revoked') {
        $token = $customer->createToken('Phone');
        $token->accessToken->delete();
        $this->withToken($token->plainTextToken);
    } elseif ($access === 'expired') {
        $this->withToken($customer->createToken('Phone', ['*'], now()->subMinute())->plainTextToken);
    } elseif ($access === 'administrator') {
        $admin = User::factory()->administrator()->create();
        $this->withToken($admin->createToken('Phone')->plainTextToken);
    }

    $response = $this->{$method}($path);
    if ($access === 'administrator') {
        $response->assertForbidden()->assertJsonStructure(['message']);
    } else {
        $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }
    $this->assertDatabaseCount('orders', 0);
})->with([
    ['get', '/api/v1/checkout'],
    ['post', '/api/v1/orders'],
    ['get', '/api/v1/orders'],
    ['get', '/api/v1/orders/1'],
    ['post', '/api/v1/orders/1/payment-proof'],
])->with(['missing', 'invalid', 'revoked', 'expired', 'session', 'administrator']);

test('mobile history is owned newest first paginated and uses the shared summaries', function () {
    $customer = User::factory()->customer()->create();
    $older = Order::factory()->for($customer)->create(['created_at' => now()->subDay()]);
    $recent = Order::factory()->for($customer)->count(10)->create(['created_at' => now()]);
    $newest = $recent->last();
    OrderItem::factory()->for($newest)->create(['quantity' => 3]);
    Order::factory()->create(['created_at' => now()->addDay()]);
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    $this->get('/api/v1/orders')->assertOk()
        ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'per_page', 'total']])
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 11)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('data.0', app(CustomerOrderPresenter::class)->summary(
            $newest->loadCount('items')->loadSum('items as total_quantity', 'quantity'),
        ))
        ->assertJsonPath('data.0.item_count', 1)
        ->assertJsonPath('data.0.total_quantity', 3)
        ->assertJsonMissingPath('data.0.recipient')
        ->assertJsonMissingPath('data.0.user_id');

    $this->get('/api/v1/orders?page=2')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $older->id);
});

test('mobile history returns an empty paginated collection for a new customer', function () {
    $customer = User::factory()->customer()->create();
    Order::factory()->create();

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->get('/api/v1/orders')->assertOk()
        ->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});

test('mobile detail uses persisted prices shared status labels and safe payment feedback', function () {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->delivery()->paidWithGCash()->withRejectedPaymentProof()->create([
        'status' => OrderStatus::Processing, 'total_amount' => '20.30',
        'payment_proof_path' => 'payment-proofs/private.png',
    ]);
    $product = Product::factory()->inactive()->create(['price' => '999.00']);
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2, 'price_at_time' => '10.15']);

    $response = $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->get('/api/v1/orders/'.$order->id)->assertOk()
        ->assertExactJson(['data' => app(CustomerOrderPresenter::class)->detail(
            app(CustomerOrderRepository::class)->find($customer, $order->id),
        )])
        ->assertJsonPath('data.status.label', 'Processing')
        ->assertJsonPath('data.items.0.unit_price', '10.15')
        ->assertJsonPath('data.items.0.line_total', '20.30')
        ->assertJsonPath('data.payment.can_resubmit_proof', true)
        ->assertJsonMissingPath('data.user_id')
        ->assertJsonMissingPath('data.payment_proof_path');

    expect($response->getContent())->not->toContain('payment-proofs/');
});

test('mobile order detail conceals foreign missing and nonnumeric order identifiers', function (string $target) {
    $customer = User::factory()->customer()->create();
    $foreign = Order::factory()->create();
    $id = match ($target) {
        'foreign' => $foreign->id,
        'missing' => $foreign->id + 100,
        'nonnumeric' => 'invalid',
    };

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->get('/api/v1/orders/'.$id)->assertNotFound()->assertJsonStructure(['message']);
})->with(['foreign', 'missing', 'nonnumeric']);

test('mobile reads of cancelled orders do not restore inventory again', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $stock = Inventory::factory()->for($product)->create(['quantity' => 3]);
    $order = Order::factory()->for($customer)->create();
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2]);
    app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Cancelled);
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    $this->get('/api/v1/orders/'.$order->id)->assertOk()->assertJsonPath('data.status.value', 'cancelled');
    $this->get('/api/v1/orders')->assertOk()->assertJsonPath('data.0.status.value', 'cancelled');

    expect($stock->refresh()->quantity)->toBe(5)
        ->and($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
});
