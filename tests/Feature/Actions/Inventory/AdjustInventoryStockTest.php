<?php

use App\Actions\Inventory\AdjustInventoryStock;
use App\Models\Inventory;

test('stock levels can be set to zero', function () {
    $inventory = Inventory::factory()->create([
        'quantity' => 10,
        'reorder_level' => 5,
    ]);
    $action = new AdjustInventoryStock;

    $updatedInventory = $action->set($inventory, 0, 0);

    expect($updatedInventory->quantity)->toBe(0)
        ->and($updatedInventory->reorder_level)->toBe(0);
    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 0,
        'reorder_level' => 0,
    ]);
});

test('stock can be increased atomically', function () {
    $inventory = Inventory::factory()->create(['quantity' => 10]);
    $action = new AdjustInventoryStock;

    $updatedInventory = $action->increase($inventory, 4);

    expect($updatedInventory->quantity)->toBe(14);
    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 14,
    ]);
});

test('stock can be decreased exactly to zero', function () {
    $inventory = Inventory::factory()->create(['quantity' => 4]);
    $action = new AdjustInventoryStock;

    $updatedInventory = $action->decrease($inventory, 4);

    expect($updatedInventory->quantity)->toBe(0);
    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 0,
    ]);
});

test('stale stock cannot be decreased below zero', function () {
    $inventory = Inventory::factory()->create(['quantity' => 3]);
    $staleInventory = Inventory::query()->findOrFail($inventory->id);
    $action = new AdjustInventoryStock;

    $action->decrease($inventory, 2);

    expect(fn () => $action->decrease($staleInventory, 2))
        ->toThrow(DomainException::class, 'Insufficient inventory stock.');
    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 1,
    ]);
});

test('invalid stock changes are rejected without changing inventory', function (string $operation, array $arguments) {
    $inventory = Inventory::factory()->create([
        'quantity' => 10,
        'reorder_level' => 5,
    ]);
    $action = new AdjustInventoryStock;

    expect(fn () => $action->{$operation}($inventory, ...$arguments))
        ->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 10,
        'reorder_level' => 5,
    ]);
})->with([
    'negative quantity' => ['set', [-1, 5]],
    'negative reorder level' => ['set', [10, -1]],
    'zero increase' => ['increase', [0]],
    'negative increase' => ['increase', [-1]],
    'zero decrease' => ['decrease', [0]],
    'negative decrease' => ['decrease', [-1]],
]);

test('successful stock changes update the inventory timestamp', function () {
    $this->travelTo('2026-09-06 08:00:00');
    $inventory = Inventory::factory()->create(['quantity' => 10]);
    $originalTimestamp = $inventory->last_updated;
    $this->travelTo('2026-09-06 08:05:00');

    $updatedInventory = (new AdjustInventoryStock)->increase($inventory, 1);

    expect($updatedInventory->last_updated->equalTo('2026-09-06 08:05:00'))->toBeTrue()
        ->and($updatedInventory->last_updated->greaterThan($originalTimestamp))->toBeTrue();
});
