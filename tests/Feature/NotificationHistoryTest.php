<?php

use App\Events\OrderNotificationOccurred;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderUpdateNotification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function historyNotification(User $recipient, string $kind = 'payment.verified', ?Order $order = null): DatabaseNotification
{
    $order ??= Order::factory()->create();
    $recipient->notify(new OrderUpdateNotification(new OrderNotificationOccurred(
        (string) Str::uuid(), $kind, $order->id, $order->user_id, $order->reference, now()->toIso8601String(),
    )));

    return $recipient->notifications()->latest()->first();
}

test('web summary polling returns only scoped notification data with three bounded queries', function (string $role) {
    $user = $role === 'administrator' ? User::factory()->administrator()->create() : User::factory()->customer()->create();
    $other = $role === 'administrator' ? User::factory()->administrator()->create() : User::factory()->customer()->create();
    $order = $role === 'administrator' ? Order::factory()->create() : Order::factory()->for($user)->create();
    $kind = $role === 'administrator' ? 'order.placed' : 'payment.verified';
    foreach (range(1, 7) as $index) {
        historyNotification($user, $kind, $order);
    }
    historyNotification($other, $kind);
    historyNotification($user, $role === 'administrator' ? 'payment.verified' : 'order.placed');
    $this->actingAs($user);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->getJson(route(($role === 'administrator' ? 'administration.' : '').'notifications.summary'))
        ->assertOk()->assertJsonPath('data.unread_count', 7)->assertJsonCount(5, 'data.recent')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(array_keys($response->json()))->toBe(['data', 'meta']);
    $response->assertJsonPath('meta.user_id', $user->id)->assertJsonPath('meta.audience', $role);
    expect(array_keys($response->json('data')))->toBe(['unread_count', 'recent']);
    expect($queries)->toHaveCount(3);
    expect($user->unreadNotifications()->count())->toBe(8);
})->with(['customer', 'administrator']);

test('notification summary polling enforces web session and role boundaries', function (string $area) {
    $prefix = $area === 'administrator' ? 'administration.' : '';
    $this->getJson(route($prefix.'notifications.summary'))->assertUnauthorized();
    $user = $area === 'administrator' ? User::factory()->customer()->create() : User::factory()->administrator()->create();

    $this->actingAs($user)->getJson(route($prefix.'notifications.summary'))->assertForbidden();
})->with(['customer', 'administrator']);

test('summary polling cannot authenticate a mobile bearer token as a web session', function (string $area) {
    $user = $area === 'administrator' ? User::factory()->administrator()->create() : User::factory()->customer()->create();
    $this->withToken($user->createToken('Phone')->plainTextToken);

    $this->getJson(route(($area === 'administrator' ? 'administration.' : '').'notifications.summary'))->assertUnauthorized();
})->with(['customer', 'administrator']);

test('notification API returns 401 for missing invalid expired and revoked sessions', function (string $session) {
    $user = User::factory()->customer()->create();
    if ($session !== 'missing') {
        $token = $user->createToken('Phone', ['*'], $session === 'expired' ? now()->subMinute() : now()->addDay());
        if ($session === 'revoked') {
            $token->accessToken->delete();
        }
        $this->withToken($session === 'invalid' ? 'invalid-token' : $token->plainTextToken);
    }

    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    $this->patchJson('/api/v1/notifications/read-all')->assertUnauthorized();
})->with(['missing', 'invalid', 'expired', 'revoked']);

test('administrators cannot use customer notification or device API endpoints', function (string $endpoint, string $method) {
    $administrator = User::factory()->administrator()->create();
    $this->withToken($administrator->createToken('Admin test')->plainTextToken);

    $this->{$method}('/api/v1/'.$endpoint)->assertForbidden();
})->with([
    ['notifications', 'getJson'], ['notifications/unread-count', 'getJson'],
    ['notifications/read-all', 'patchJson'], ['notifications/'.Str::uuid().'/read', 'patchJson'],
    ['push-devices/'.Str::uuid(), 'putJson'], ['push-devices/'.Str::uuid(), 'deleteJson'],
]);

test('web notifications enforce both role boundaries', function (string $role, string $area) {
    $user = $role === 'administrator' ? User::factory()->administrator()->create() : User::factory()->customer()->create();
    $this->actingAs($user);
    $prefix = $area === 'administrator' ? 'administration.' : '';

    $this->get(route($prefix.'notifications.index'))->assertForbidden();
    $this->patch(route($prefix.'notifications.read-all'))->assertForbidden();
    $this->patch(route($prefix.'notifications.update', (string) Str::uuid()))->assertForbidden();
})->with([['customer', 'administrator'], ['administrator', 'customer']]);

test('web notifications redirect guests to login', function (string $area) {
    $this->get(route($area.'notifications.index'))->assertRedirectToRoute('login');
    $this->patch(route($area.'notifications.read-all'))->assertRedirectToRoute('login');
})->with(['', 'administration.']);

test('mobile history paginates only owned customer notifications with safe order metadata', function () {
    $customer = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create();
    foreach (range(1, 11) as $index) {
        historyNotification($customer, 'payment.verified', $order);
    }
    historyNotification($other);
    historyNotification($customer, 'order.placed');
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    $this->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 11)->assertJsonPath('meta.unread_count', 11)
        ->assertJsonPath('data.0.order.id', $order->id)
        ->assertJsonPath('data.0.order.deep_link', ['screen' => 'order_detail', 'order_id' => $order->id])
        ->assertJsonPath('data.0.order.api_url', route('api.v1.orders.show', $order))
        ->assertJsonStructure(['links', 'meta', 'data' => [['id', 'event', 'title', 'body', 'occurred_at', 'created_at', 'read_at', 'is_read', 'order']]]);
    $this->getJson('/api/v1/notifications?page=2')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/notifications/unread-count')->assertExactJson(['data' => ['unread_count' => 11]]);
});

test('individual and all read state is shared across web and mobile and preserves previous read timestamps', function () {
    $this->freezeTime();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create();
    $first = historyNotification($customer, 'payment.verified', $order);
    $second = historyNotification($customer, 'shipment.preparing', $order);
    $other = historyNotification(User::factory()->customer()->create());
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    $this->patchJson('/api/v1/notifications/'.$first->id.'/read')->assertOk()->assertJsonPath('data.is_read', true)->assertJsonPath('meta.unread_count', 1);
    $timestamp = $first->refresh()->read_at;
    $this->travel(1)->minutes();
    $this->patchJson('/api/v1/notifications/'.$first->id.'/read')->assertOk();
    expect($first->refresh()->read_at->eq($timestamp))->toBeTrue();

    $this->actingAs($customer)->patch(route('notifications.read-all'))->assertRedirect();
    $this->getJson('/api/v1/notifications/unread-count')->assertExactJson(['data' => ['unread_count' => 0]]);
    expect($second->refresh()->read_at)->not->toBeNull();
    expect($other->refresh()->read_at)->toBeNull();
});

test('notification read routes conceal foreign and missing notification IDs', function (string $area) {
    $owner = $area === 'administration.' ? User::factory()->administrator()->create() : User::factory()->customer()->create();
    $viewer = $area === 'administration.' ? User::factory()->administrator()->create() : User::factory()->customer()->create();
    $notification = historyNotification($owner, $area === 'administration.' ? 'order.placed' : 'payment.verified');
    $this->actingAs($viewer);

    $this->patch(route($area.'notifications.update', $notification->id))->assertNotFound();
    $this->patch(route($area.'notifications.update', (string) Str::uuid()))->assertNotFound();
    expect($notification->refresh()->read_at)->toBeNull();
})->with(['', 'administration.']);

test('customer notification API cannot mark foreign or administrator audience records read', function () {
    $customer = User::factory()->customer()->create();
    $foreign = historyNotification(User::factory()->customer()->create());
    $wrongAudience = historyNotification($customer, 'order.placed');
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    foreach ([$foreign, $wrongAudience] as $notification) {
        $this->patchJson('/api/v1/notifications/'.$notification->id.'/read')->assertNotFound();
        expect($notification->refresh()->read_at)->toBeNull();
    }
});

test('customer and administrator layouts expose their own summary and shared history page', function (string $role) {
    $user = $role === 'administrator' ? User::factory()->administrator()->create() : User::factory()->customer()->create();
    $order = $role === 'administrator' ? Order::factory()->create() : Order::factory()->for($user)->create();
    $kind = $role === 'administrator' ? 'order.placed' : 'payment.verified';
    foreach (range(1, 6) as $index) {
        historyNotification($user, $kind, $order);
    }
    historyNotification(User::factory()->customer()->create());
    $area = $role === 'administrator' ? 'administration.' : '';

    $this->actingAs($user)->get(route($area.'notifications.index'))
        ->assertInertia(fn (Assert $page) => $page->component('Notifications/Index')
            ->where('notificationSummary.unread_count', 6)->has('notificationSummary.recent', 5)
            ->has('notifications.data', 6)->where('notifications.data.0.order.web_url', route($area.'orders.show', $order)));
    $this->patch(route($area.'notifications.read-all'))->assertRedirect();
    expect($user->unreadNotifications()->count())->toBe(0);
})->with(['customer', 'administrator']);

test('notification metadata cannot supply access to another customers order', function () {
    $customer = User::factory()->customer()->create();
    $foreignOrder = Order::factory()->create();
    historyNotification($customer, 'payment.verified', $foreignOrder);
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.order', null);
    $this->getJson('/api/v1/orders/'.$foreignOrder->id)->assertNotFound();
    $this->actingAs($customer)->get(route('orders.show', $foreignOrder))->assertNotFound();
});

test('guests do not receive a previous accounts notification summary', function () {
    historyNotification(User::factory()->customer()->create());

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('notificationSummary', null));
});
