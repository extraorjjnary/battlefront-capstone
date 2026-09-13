<?php

use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the order item schema follows the approved ERD decisions', function () {
    expect(Schema::getColumnListing('order_items'))->toEqualCanonicalizing([
        'id',
        'order_id',
        'product_id',
        'quantity',
        'price_at_time',
    ]);
});

test('an order item persists purchase-time values through reciprocal relationships', function () {
    $item = OrderItem::factory()->create([
        'quantity' => 3,
        'price_at_time' => '1299.90',
    ]);

    $this->assertModelExists($item);
    expect($item->quantity)->toBe(3)
        ->and($item->quantity)->toBeInt()
        ->and($item->price_at_time)->toBe('1299.90')
        ->and($item->order->items->sole()->is($item))->toBeTrue()
        ->and($item->product->orderItems->sole()->is($item))->toBeTrue();
});

test('purchase-time prices remain unchanged when the product price changes', function () {
    $product = Product::factory()->create(['price' => '100.00']);
    $item = OrderItem::factory()->for($product)->create(['price_at_time' => '100.00']);

    $product->update(['price' => '150.00']);

    expect($item->refresh()->price_at_time)->toBe('100.00')
        ->and($item->product->price)->toBe('150.00');
});

test('non-positive order item quantities are rejected when inserted', function (int $quantity) {
    expect(fn () => OrderItem::factory()->create(['quantity' => $quantity]))
        ->toThrow(QueryException::class);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

test('non-positive order item quantities are rejected when updated', function (int $quantity) {
    $item = OrderItem::factory()->create(['quantity' => 3]);

    expect(fn () => $item->update(['quantity' => $quantity]))
        ->toThrow(QueryException::class);
    expect($item->refresh()->quantity)->toBe(3);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

test('negative purchase-time prices are rejected on insert and update', function () {
    expect(fn () => OrderItem::factory()->create(['price_at_time' => '-0.01']))
        ->toThrow(QueryException::class);

    $item = OrderItem::factory()->create(['price_at_time' => '100.00']);

    expect(fn () => $item->update(['price_at_time' => '-0.01']))
        ->toThrow(QueryException::class);
    expect($item->refresh()->price_at_time)->toBe('100.00');
});

test('required order item fields cannot be omitted', function (string $missingField) {
    $item = OrderItem::factory()->create();
    $attributes = [
        'order_id' => $item->order_id,
        'product_id' => $item->product_id,
        'quantity' => 1,
        'price_at_time' => '100.00',
    ];
    unset($attributes[$missingField]);

    expect(fn () => DB::table('order_items')->insert($attributes))
        ->toThrow(QueryException::class);
})->with([
    'order' => 'order_id',
    'product' => 'product_id',
    'quantity' => 'quantity',
    'purchase-time price' => 'price_at_time',
]);

test('products referenced by order items cannot be deleted', function () {
    $item = OrderItem::factory()->create();

    expect(fn () => $item->product->delete())->toThrow(QueryException::class);
    $this->assertModelExists($item);
});

test('deleting an order removes its dependent items', function () {
    $item = OrderItem::factory()->create();

    $item->order->delete();

    $this->assertModelMissing($item);
});
