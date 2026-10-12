<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function codPaymentMethodMigration(): Migration
{
    return require database_path('migrations/2026_10_12_153550_allow_cod_payment_method_on_orders.php');
}

test('forward COD migration preserves existing methods rows and unrelated order constraints', function () {
    $migration = codPaymentMethodMigration();
    $migration->down();
    $orders = Order::factory()->count(4)->sequence(
        ['payment_method' => 'cash'], ['payment_method' => 'card_at_store'],
        ['payment_method' => 'gcash'], ['payment_method' => 'maya'],
    )->create();
    $before = $orders->map(fn (Order $order): array => $order->refresh()->getAttributes())->all();
    expect(fn () => Order::factory()->cashOnDelivery()->create())->toThrow(QueryException::class);

    $migration->up();
    $cod = Order::factory()->cashOnDelivery()->create();

    $this->assertModelExists($cod);
    expect($orders->map(fn (Order $order): array => $order->refresh()->getAttributes())->all())->toBe($before);
    expect(fn () => DB::table('orders')->where('id', $cod->id)->update(['total_amount' => -1]))->toThrow(QueryException::class);
    expect(fn () => DB::table('orders')->where('id', $cod->id)->update(['status' => 'returned']))->toThrow(QueryException::class);
    expect(fn () => DB::table('orders')->where('id', $cod->id)->update(['payment_status' => 'paid']))->toThrow(QueryException::class);
});

test('COD constraints reject invalid insertion and updates', function (array $invalid) {
    $order = Order::factory()->cashOnDelivery()->create();
    $before = $order->refresh()->getAttributes();
    $attributes = array_replace($before, $invalid);
    unset($attributes['id']);

    expect(fn () => DB::table('orders')->insert($attributes))->toThrow(QueryException::class);
    expect(fn () => DB::table('orders')->where('id', $order->id)->update($invalid))->toThrow(QueryException::class);

    expect($order->refresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('orders', 1);
})->with([
    'unknown method' => [['payment_method' => 'unsupported']],
    'uppercase' => [['payment_method' => 'COD']],
    'padded' => [['payment_method' => 'cod ']],
    'pickup' => [['fulfillment_method' => 'pickup', 'delivery_address' => null]],
    'proof' => [['payment_proof_path' => 'payment-proofs/invented.jpg']],
]);

test('COD constraints reject pickup even without commercial quote snapshots', function () {
    $order = Order::factory()->create();

    expect(fn () => DB::table('orders')->where('id', $order->id)->update(['payment_method' => 'cod']))->toThrow(QueryException::class);

    expect($order->refresh()->payment_method->value)->toBe('cash');
});

test('COD rollback refuses to narrow payment methods while COD orders exist', function () {
    $order = Order::factory()->cashOnDelivery()->create();
    $before = $order->refresh()->getAttributes();

    expect(fn () => codPaymentMethodMigration()->down())->toThrow(RuntimeException::class, 'Cannot roll back payment methods while COD orders exist.');

    expect($order->refresh()->getAttributes())->toBe($before);
    $this->assertModelExists(Order::factory()->cashOnDelivery()->create());
});

test('rollback without COD preserves orders and can safely be reapplied', function () {
    $order = Order::factory()->create();
    $before = $order->refresh()->getAttributes();
    $migration = codPaymentMethodMigration();

    $migration->down();

    expect(fn () => Order::factory()->cashOnDelivery()->create())->toThrow(QueryException::class);
    expect($order->refresh()->getAttributes())->toBe($before);
    $migration->up();
    $this->assertModelExists(Order::factory()->cashOnDelivery()->create());
});
