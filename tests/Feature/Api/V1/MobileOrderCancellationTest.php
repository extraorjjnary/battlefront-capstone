<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('mobile cancellation requires customer bearer authentication', function (string $access) {
    $order = Order::factory()->create();
    $customer = $order->user;
    if ($access === 'session') {
        $this->actingAs($customer);
    } elseif ($access === 'invalid') {
        $this->withToken('invalid-token');
    } elseif ($access === 'expired') {
        $this->withToken($customer->createToken('Phone', ['*'], now()->subMinute())->plainTextToken);
    } elseif ($access === 'revoked') {
        $token = $customer->createToken('Phone');
        $token->accessToken->delete();
        $this->withToken($token->plainTextToken);
    } elseif ($access === 'administrator') {
        $admin = User::factory()->administrator()->create();
        $this->withToken($admin->createToken('Phone')->plainTextToken);
    }
    $response = $this->postJson(route('api.v1.orders.cancel.store', $order));
    if ($access === 'administrator') {
        $response->assertForbidden()->assertJsonStructure(['message']);
    } else {
        $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    $this->assertDatabaseCount('notifications', 0);
})->with(['missing', 'session', 'invalid', 'expired', 'revoked', 'administrator']);

test('mobile cancellation returns the same safe order detail as web and restocks once', function () {
    $order = Order::factory()->create(['total_amount' => '20.30']);
    $inventory = Inventory::factory()->create(['quantity' => 3]);
    OrderItem::factory()->for($order)->for($inventory->product)->create(['quantity' => 2, 'price_at_time' => '10.15']);
    $this->withToken($order->user->createToken('Phone')->plainTextToken);
    $this->getJson(route('api.v1.orders.show', $order))->assertJsonPath('data.can_cancel', true);
    $response = $this->postJson(route('api.v1.orders.cancel.store', $order), ['payment_status' => 'verified'])
        ->assertOk()->assertJsonPath('data.can_cancel', false)->assertJsonPath('data.status.value', 'cancelled')
        ->assertJsonPath('data.payment.status.value', 'pending')->assertJsonPath('data.total', '20.30')
        ->assertJsonMissingPath('data.payment_proof_path')->assertJsonMissingPath('data.user_id');
    $detail = $response->json('data');
    $this->actingAs($order->user)->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->where('order', $detail));
    $this->postJson(route('api.v1.orders.cancel.store', $order))->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->getJson(route('api.v1.orders.index'))->assertJsonPath('data.0.status.value', 'cancelled');
    expect($inventory->refresh()->quantity)->toBe(5);
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
    expect($order->user->notifications()->count())->toBe(1);
});

test('mobile cancellation conceals foreign missing and nonnumeric orders with 404', function (string $target) {
    $order = Order::factory()->create();
    $customer = User::factory()->customer()->create();
    $id = match ($target) {
        'foreign' => $order->id,
        'missing' => $order->id + 100,
        'nonnumeric' => 'invalid',
    };
    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->postJson(route('api.v1.orders.cancel.store', $id))->assertNotFound()->assertJsonStructure(['message']);
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    $this->assertDatabaseCount('notifications', 0);
})->with(['foreign', 'missing', 'nonnumeric']);

test('submitted wallet evidence prevents mobile cancellation with native 422 errors', function () {
    $order = Order::factory()->paidWithMaya()->create()->refresh();
    $inventory = Inventory::factory()->create(['quantity' => 3]);
    OrderItem::factory()->for($order)->for($inventory->product)->create(['quantity' => 2]);
    $original = $order->getAttributes();
    $this->withToken($order->user->createToken('Phone')->plainTextToken)
        ->getJson(route('api.v1.orders.show', $order))->assertJsonPath('data.can_cancel', false);
    $this->postJson(route('api.v1.orders.cancel.store', $order))->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['status']])->assertJsonValidationErrors('status');
    expect($order->refresh()->getAttributes())->toBe($original);
    expect($inventory->refresh()->quantity)->toBe(3);
    $this->assertDatabaseCount('notifications', 0);
});
