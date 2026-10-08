<?php

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Repositories\Reporting\MonthlySalesRepository;

test('aggregates only the selected product in chronological month groups in one query', function () {
    $product = Product::factory()->create(['is_active' => false]);
    $other = Product::factory()->create();
    foreach ([['2024-03-31', 7], ['2024-02-29', 5], ['2024-02-01', 3], ['2024-01-31', 2], ['2024-04-01', 900]] as [$date, $quantity]) {
        $sale = Sale::factory()->create(['sale_date' => $date, 'amount' => '1.00']);
        OrderItem::factory()->for($sale->order)->for($product)->create(['quantity' => $quantity, 'price_at_time' => '0.01']);
        OrderItem::factory()->for($sale->order)->for($other)->create(['quantity' => 100, 'price_at_time' => '9.99']);
    }
    $months = [
        ['start' => '2024-01-01', 'end_exclusive' => '2024-02-01'],
        ['start' => '2024-02-01', 'end_exclusive' => '2024-03-01'],
        ['start' => '2024-03-01', 'end_exclusive' => '2024-04-01'],
    ];
    $this->expectsDatabaseQueryCount(1);

    $rows = app(MonthlySalesRepository::class)->aggregateProduct($product->id, $months);

    expect($rows)->toBe([
        ['start' => '2024-01-01', 'quantity_sold' => 2],
        ['start' => '2024-02-01', 'quantity_sold' => 8],
        ['start' => '2024-03-01', 'quantity_sold' => 7],
    ]);
});

test('returns sparse recorded groups and leaves zero filling to trusted preparation', function () {
    $product = Product::factory()->create();
    $sale = Sale::factory()->create(['sale_date' => '2024-02-01', 'amount' => '1.00']);
    OrderItem::factory()->for($sale->order)->for($product)->create(['quantity' => 2, 'price_at_time' => '0.01']);

    $rows = app(MonthlySalesRepository::class)->aggregateProduct($product->id, [
        ['start' => '2024-01-01', 'end_exclusive' => '2024-02-01'],
        ['start' => '2024-02-01', 'end_exclusive' => '2024-03-01'],
    ]);

    expect($rows)->toBe([['start' => '2024-02-01', 'quantity_sold' => 2]]);
});

test('returns no recorded groups for a product without sales', function () {
    $product = Product::factory()->create();
    $this->expectsDatabaseQueryCount(1);

    expect(app(MonthlySalesRepository::class)->aggregateProduct($product->id, [
        ['start' => '2024-01-01', 'end_exclusive' => '2024-02-01'],
    ]))->toBe([]);
});

test('keeps integer totals exact above the individual item range', function () {
    $product = Product::factory()->create();
    $sale = Sale::factory()->create(['sale_date' => '2024-01-01', 'amount' => '1.00']);
    OrderItem::factory()->count(2)->for($sale->order)->for($product)->create(['quantity' => 4294967295, 'price_at_time' => '0.01']);

    $rows = app(MonthlySalesRepository::class)->aggregateProduct($product->id, [
        ['start' => '2024-01-01', 'end_exclusive' => '2024-02-01'],
    ]);

    expect($rows)->toBe([['start' => '2024-01-01', 'quantity_sold' => 8589934590]]);
});

test('rejects missing product identifiers or month intervals before querying', function (int $productId, array $months) {
    $this->expectsDatabaseQueryCount(0);

    expect(fn () => app(MonthlySalesRepository::class)->aggregateProduct($productId, $months))->toThrow(InvalidArgumentException::class);
})->with([
    'missing product' => [0, [['start' => '2024-01-01', 'end_exclusive' => '2024-02-01']]],
    'empty window' => [1, []],
]);

test('batch aggregation isolates products and months in one query without fabricating zero groups', function () {
    $products = Product::factory()->count(3)->create();
    foreach ([['2024-01-01', 2, 10], ['2024-02-29', 3, 20], ['2024-02-01', 4, 30], ['2024-03-01', 900, 900]] as [$date, $first, $second]) {
        $sale = Sale::factory()->create(['sale_date' => $date]);
        OrderItem::factory()->for($sale->order)->for($products[0])->create(['quantity' => $first]);
        OrderItem::factory()->for($sale->order)->for($products[1])->create(['quantity' => $second]);
        OrderItem::factory()->for($sale->order)->create(['quantity' => 999]);
    }
    $months = [
        ['start' => '2024-01-01', 'end_exclusive' => '2024-02-01'],
        ['start' => '2024-02-01', 'end_exclusive' => '2024-03-01'],
    ];
    $this->expectsDatabaseQueryCount(1);

    $rows = app(MonthlySalesRepository::class)->aggregateProducts($products->modelKeys(), $months);

    expect($rows)->toBe([
        $products[0]->id => [['start' => '2024-01-01', 'quantity_sold' => 2], ['start' => '2024-02-01', 'quantity_sold' => 7]],
        $products[1]->id => [['start' => '2024-01-01', 'quantity_sold' => 10], ['start' => '2024-02-01', 'quantity_sold' => 50]],
    ]);
});

test('batch aggregation rejects invalid product lists before querying', function (array $ids) {
    $this->expectsDatabaseQueryCount(0);

    expect(fn () => app(MonthlySalesRepository::class)->aggregateProducts($ids, [
        ['start' => '2024-01-01', 'end_exclusive' => '2024-02-01'],
    ]))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [[]],
    'invalid identifier among valid identifiers' => [[1, 0]],
]);
