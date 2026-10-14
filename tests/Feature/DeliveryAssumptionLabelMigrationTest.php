<?php

use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('expanded labels preserve existing snapshots and delivery constraints', function () {
    $historical = Order::factory()->withDeliverySnapshot()->create()->refresh();
    $pickup = Order::factory()->create()->refresh();
    $historicalBefore = $historical->getAttributes();
    $pickupBefore = $pickup->getAttributes();
    $migration = require database_path('migrations/2026_10_14_024009_expand_delivery_assumption_label_on_orders_table.php');
    $migration->down();

    $migration->up();

    expect(Schema::getColumnType('orders', 'delivery_assumption_label'))->toBe('text');
    expect($historical->refresh()->getAttributes())->toBe($historicalBefore);
    expect($pickup->refresh()->getAttributes())->toBe($pickupBefore);
    $label = 'Synthetic historical delivery; no payment evidence or carrier milestones retained. '.config('battlefront.delivery.assumption_label');
    expect(mb_strlen($label))->toBeGreaterThan(255);
    $current = Order::factory()->withDeliverySnapshot()->create(['delivery_assumption_label' => $label]);
    $this->assertDatabaseHas('orders', ['id' => $current->id, 'delivery_assumption_label' => $label]);
    expect(fn () => DB::table('orders')->where('id', $current->id)->update(['delivery_fee' => '0.00']))
        ->toThrow(QueryException::class);
});

test('rollback refuses to truncate retained assumption labels', function (string $character) {
    $order = Order::factory()->withDeliverySnapshot()->create([
        'delivery_assumption_label' => str_repeat($character, 256),
    ])->refresh();
    $before = $order->getAttributes();
    $migration = require database_path('migrations/2026_10_14_024009_expand_delivery_assumption_label_on_orders_table.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot shorten retained delivery assumption labels. Use a forward migration.');

    expect($order->refresh()->getAttributes())->toBe($before);
    expect(Schema::getColumnType('orders', 'delivery_assumption_label'))->toBe('text');
})->with(['ASCII' => 'a', 'Unicode' => '配']);

test('rollback preserves labels that fit the original character limit and nullable pickup snapshots', function (string $character) {
    $order = Order::factory()->withDeliverySnapshot()->create([
        'delivery_assumption_label' => str_repeat($character, 255),
    ])->refresh();
    $pickup = Order::factory()->create()->refresh();
    $before = $order->getAttributes();
    $pickupBefore = $pickup->getAttributes();
    $migration = require database_path('migrations/2026_10_14_024009_expand_delivery_assumption_label_on_orders_table.php');

    $migration->down();

    expect(Schema::getColumnType('orders', 'delivery_assumption_label'))->toBe('varchar');
    expect($order->refresh()->getAttributes())->toBe($before);
    expect($pickup->refresh()->getAttributes())->toBe($pickupBefore);
    expect(fn () => DB::table('orders')->where('id', $order->id)->update(['delivery_fee' => '0.00']))
        ->toThrow(QueryException::class);
    $migration->up();
})->with(['ASCII' => 'a', 'Unicode' => '配']);
