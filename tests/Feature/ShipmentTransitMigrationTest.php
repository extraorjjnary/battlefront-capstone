<?php

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('zero-day transit migration preserves historical snapshots and accepts new Sagay shipments', function () {
    config(['battlefront.delivery.destinations.Sagay City.transit_min_days' => 1]);
    $historical = Shipment::factory()->create(['status' => ShipmentStatus::Preparing])->refresh();
    $shipmentBefore = $historical->getAttributes();
    $orderBefore = $historical->order->getAttributes();
    $migration = require database_path('migrations/2026_10_08_070709_allow_zero_day_transit_on_shipments.php');
    $migration->down();

    $migration->up();
    config(['battlefront.delivery.destinations.Sagay City.transit_min_days' => 0]);
    $current = Shipment::factory()->create();

    expect($historical->refresh()->getAttributes())->toBe($shipmentBefore);
    expect($historical->order->refresh()->getAttributes())->toBe($orderBefore);
    expect($current)->preparation_days->toBe(1)->transit_min_days->toBe(0)->transit_max_days->toBe(1)
        ->eta_min_days->toBe(1)->eta_max_days->toBe(2);
});

test('zero-day transit migration rollback protects historical zero-day snapshots', function () {
    $shipment = Shipment::factory()->create()->refresh();
    $before = $shipment->getAttributes();
    $migration = require database_path('migrations/2026_10_08_070709_allow_zero_day_transit_on_shipments.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot restore positive transit minima while zero-day snapshots exist.');

    expect($shipment->refresh()->getAttributes())->toBe($before);
});

test('zero-day transit migration can roll back when only positive historical snapshots exist', function () {
    config(['battlefront.delivery.destinations.Sagay City.transit_min_days' => 1]);
    $shipment = Shipment::factory()->create()->refresh();
    $before = $shipment->getAttributes();
    $migration = require database_path('migrations/2026_10_08_070709_allow_zero_day_transit_on_shipments.php');

    $migration->down();

    expect($shipment->refresh()->getAttributes())->toBe($before);
    expect(fn () => DB::table('shipments')->where('id', $shipment->id)->update(['transit_min_days' => 0, 'eta_min_days' => 1]))
        ->toThrow(QueryException::class);
    $migration->up();
});
