<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Sale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the sale schema follows the approved ERD decisions', function () {
    expect(Schema::getColumnListing('sales'))->toEqualCanonicalizing([
        'id',
        'order_id',
        'amount',
        'sale_date',
    ]);
});

test('a sale persists approved values through reciprocal order relationships', function () {
    $order = Order::factory()->create([
        'total_amount' => '38999.90',
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    $sale = Sale::factory()->for($order)->create([
        'amount' => '38999.90',
        'sale_date' => '2026-09-17',
    ]);

    $this->assertModelExists($sale);
    expect($sale->amount)->toBe('38999.90')
        ->and($sale->sale_date->toDateString())->toBe('2026-09-17')
        ->and($sale->order->is($order))->toBeTrue()
        ->and($order->sale->is($sale))->toBeTrue();
});

test('an order cannot have duplicate sales', function () {
    $sale = Sale::factory()->create();

    expect(fn () => Sale::factory()->for($sale->order)->create())
        ->toThrow(QueryException::class);
    expect(Sale::query()->count())->toBe(1);
});

test('negative sale amounts are rejected on insert and update', function () {
    expect(fn () => Sale::factory()->create(['amount' => '-0.01']))
        ->toThrow(QueryException::class);

    $sale = Sale::factory()->create(['amount' => '100.00']);

    expect(fn () => $sale->update(['amount' => '-0.01']))
        ->toThrow(QueryException::class);
    expect($sale->refresh()->amount)->toBe('100.00');
});

test('required sale fields cannot be omitted', function (string $missingField) {
    $order = Order::factory()->create();
    $attributes = [
        'order_id' => $order->id,
        'amount' => '100.00',
        'sale_date' => '2026-09-17',
    ];
    unset($attributes[$missingField]);

    expect(fn () => DB::table('sales')->insert($attributes))
        ->toThrow(QueryException::class);
})->with([
    'order' => 'order_id',
    'amount' => 'amount',
    'sale date' => 'sale_date',
]);
