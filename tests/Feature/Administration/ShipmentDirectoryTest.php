<?php

use App\Enums\OrderStatus;
use App\Enums\ShipmentArrangement;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Reporting\ShipmentExportService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** @param array<string, mixed> $attributes */
function directoryShipment(array $attributes = []): Shipment
{
    return Shipment::factory()->create($attributes);
}

/** @return list<list<string|null>> */
function shipmentCsv(string $content): array
{
    expect($content)->toStartWith("\xEF\xBB\xBF");
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, substr($content, 3));
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);

    return $rows;
}

test('shipment directory defaults to active records without changing the order queue', function () {
    $active = directoryShipment();
    directoryShipment(['status' => ShipmentStatus::Delivered]);
    directoryShipment(['status' => ShipmentStatus::Cancelled]);
    $legacyCancelled = directoryShipment();
    $legacyCancelled->order->update(['status' => OrderStatus::Cancelled]);
    Order::factory()->delivery()->create();
    Order::factory()->create();
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)->get(route('administration.orders.index', ['view' => 'shipments']))
        ->assertInertia(fn (Assert $page) => $page->component('Administration/Orders/Shipments')
            ->where('shipments.total', 1)->where('shipments.data.0.id', $active->id)
            ->where('shipments.data.0.weight', null)->where('shipments.data.0.carrier_cost', null)
            ->where('filters.shipment_status', 'active')->where('can_export', true));
    $this->get(route('administration.orders.index'))->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Orders/Index')->where('filters.status', 'active'));
    $this->get(route('administration.orders.index', ['view' => 'shipments', 'shipment_status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->where('shipments.total', 4));
    $this->get(route('administration.orders.index', ['view' => 'shipments', 'shipment_status' => 'cancelled']))
        ->assertInertia(fn (Assert $page) => $page->where('shipments.total', 2));
});

test('shipment search covers order recipient customer tracking and receipt', function (string $field) {
    $shipment = directoryShipment(['tracking_reference' => 'TRACK-UNIQUE', 'carrier_cost_reference' => 'RECEIPT-UNIQUE']);
    $order = $shipment->order;
    $order->update(['recipient_name' => 'Recipient Unique']);
    $order->user->update(['name' => 'Customer Unique']);
    directoryShipment();
    $search = match ($field) {
        'reference' => $order->reference, 'recipient' => 'Recipient Unique', 'customer' => 'Customer Unique',
        'tracking' => 'TRACK-UNIQUE', default => 'RECEIPT-UNIQUE',
    };

    $this->actingAs(User::factory()->administrator()->create())->get(route('administration.orders.index', ['view' => 'shipments', 'q' => $search]))
        ->assertInertia(fn (Assert $page) => $page->where('shipments.total', 1)->where('shipments.data.0.id', $shipment->id));
})->with(['reference', 'recipient', 'customer', 'tracking', 'receipt']);

test('shipment filters combine reconciliation status and inclusive Philippine handoff dates', function () {
    $match = directoryShipment(['status' => ShipmentStatus::InTransit, 'carrier_cost_recorded_at' => now(),
        'actual_carrier_cost' => '0.00', 'handed_to_carrier_at' => '2026-10-31 16:00:00']);
    directoryShipment(['status' => ShipmentStatus::InTransit, 'carrier_cost_recorded_at' => now(), 'handed_to_carrier_at' => '2026-11-01 16:00:00']);
    directoryShipment(['status' => ShipmentStatus::InTransit, 'carrier_cost_recorded_at' => now(), 'handed_to_carrier_at' => '2026-10-31 15:59:59']);
    directoryShipment(['status' => ShipmentStatus::InTransit, 'handed_to_carrier_at' => '2026-11-01 15:59:59']);
    directoryShipment(['carrier_cost_recorded_at' => now()]);
    $filters = ['view' => 'shipments', 'shipment_status' => 'in_transit', 'reconciliation' => 'confirmed', 'from' => '2026-11-01', 'to' => '2026-11-01'];

    $this->actingAs(User::factory()->administrator()->create())->get(route('administration.orders.index', $filters))
        ->assertInertia(fn (Assert $page) => $page->where('shipments.total', 1)->where('shipments.data.0.id', $match->id)
            ->where('shipments.data.0.carrier_cost', '0.00')->where('shipments.data.0.reconciliation', 'Confirmed'));
    $this->get(route('administration.orders.index', ['view' => 'shipments', 'reconciliation' => 'unconfirmed']))
        ->assertInertia(fn (Assert $page) => $page->where('shipments.total', 1)->where('shipments.data.0.reconciliation', 'Unconfirmed'));
    $this->get(route('administration.orders.index', [...$filters, 'reconciliation' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->where('shipments.total', 2));
});

test('shipment pagination has stable newest-first ordering and exports ignore the page', function () {
    $this->freezeTime();
    $shipments = Shipment::factory()->count(21)->create();
    $actor = User::factory()->administrator()->create();

    $this->actingAs($actor)->get(route('administration.orders.index', ['view' => 'shipments']))
        ->assertInertia(fn (Assert $page) => $page->has('shipments.data', 20)->where('shipments.data.0.id', $shipments->last()->id)
            ->where('shipments.last_page', 2));
    $this->get(route('administration.orders.index', ['view' => 'shipments', 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('shipments.data', 1)->where('shipments.data.0.id', $shipments->first()->id));
    $csv = $this->get(route('administration.orders.shipments.export', ['format' => 'csv', 'page' => 2]))->assertOk()->streamedContent();
    $rows = shipmentCsv($csv);
    expect($rows)->toHaveCount(22);
    expect($rows[1][0])->toBe("'".$shipments->last()->order->reference);
    expect($rows[21][0])->toBe("'".$shipments->first()->order->reference);
});

test('shipment directory and documents enforce administrator view and export permissions', function (string $format) {
    $url = route('administration.orders.shipments.export', ['format' => $format]);
    $this->get($url)->assertRedirectToRoute('login');
    $this->actingAs(User::factory()->customer()->create())->get($url)->assertForbidden();
    $staff = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id')]);
    $this->actingAs($staff)->get(route('administration.orders.index', ['view' => 'shipments']))
        ->assertInertia(fn (Assert $page) => $page->where('can_export', false));
    $this->get($url)->assertForbidden();
    $it = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', 'it')->value('id')]);
    $this->actingAs($it)->get(route('administration.orders.index', ['view' => 'shipments']))->assertForbidden();
})->with(['csv', 'pdf', 'print']);

test('invalid shipment filters fail on both directory and export requests', function (array $filters, string $error) {
    $this->actingAs(User::factory()->administrator()->create());
    $this->getJson(route('administration.orders.index', ['view' => 'shipments', ...$filters]))->assertUnprocessable()->assertJsonValidationErrors($error);
    $this->getJson(route('administration.orders.shipments.export', ['format' => 'csv', ...$filters]))->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    [['shipment_status' => 'unknown'], 'shipment_status'], [['reconciliation' => 'unknown'], 'reconciliation'],
    [['from' => '2026-02-30'], 'from'], [['from' => '2026-11-02', 'to' => '2026-11-01'], 'to'],
    [['q' => ['bad']], 'q'], [['q' => str_repeat('x', 256)], 'q'], [['page' => 0], 'page'],
]);

test('shipment documents use applied filters protect text and never mutate records', function (string $format) {
    $shipment = directoryShipment(['tracking_reference' => '000012345678901234', 'carrier_cost_reference' => '=RECEIPT',
        'status' => ShipmentStatus::InTransit, 'package_count' => 2, 'package_weight_kg' => '1.250',
        'actual_carrier_cost' => '0.00', 'carrier_cost_recorded_at' => now(), 'handed_to_carrier_at' => '2026-11-01 04:00:00']);
    $shipment->order->update(['recipient_name' => '=SUM(1,2)']);
    directoryShipment(['tracking_reference' => 'EXCLUDED']);
    $snapshot = $shipment->refresh()->getAttributes();
    $filters = ['shipment_status' => 'in_transit', 'reconciliation' => 'confirmed', 'q' => '000012345678901234', 'from' => '2026-11-01', 'to' => '2026-11-01'];

    $response = $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.orders.shipments.export', ['format' => $format, ...$filters]))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    if ($format === 'csv') {
        $rows = shipmentCsv($response->streamedContent());
        expect($rows)->toHaveCount(2);
        expect($rows[1][1])->toBe("'=SUM(1,2)");
        expect($rows[1][10])->toBe("'000012345678901234");
        expect($rows[1][12])->toBe('0.00');
        expect($rows[1][14])->toBe("'=RECEIPT");
        expect($rows[1][15])->toContain('2026-11-01 12:00:00');
    } elseif ($format === 'print') {
        $response->assertSee('000012345678901234')->assertSee('1 matching records')
            ->assertSee('window.print()', false)->assertDontSee('EXCLUDED')->assertSee('Carrier: 0.00');
    } else {
        $response->assertHeader('Content-Type', 'application/pdf');
        expect($response->getContent())->toStartWith('%PDF-')->toContain('%%EOF');
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $response->getContent(), $matches);
        $streams = implode('', array_map(static fn (string $stream): string => @gzuncompress($stream) ?: $stream, $matches[1]));
        expect($streams)->toContain(mb_convert_encoding('000012345678901234', 'UTF-16BE'))
            ->not->toContain(mb_convert_encoding('EXCLUDED', 'UTF-16BE'));
    }
    expect($shipment->refresh()->getAttributes())->toBe($snapshot);
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['csv', 'pdf', 'print']);

test('empty shipment documents retain headings and show no invented records', function (string $format) {
    $response = $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.orders.shipments.export', ['format' => $format]))->assertOk();
    if ($format === 'csv') {
        expect(shipmentCsv($response->streamedContent()))->toHaveCount(1);
    } elseif ($format === 'print') {
        $response->assertSee('No matching records.')->assertSee('0 matching records');
    } else {
        expect($response->getContent())->toStartWith('%PDF-');
    }
})->with(['csv', 'pdf', 'print']);

test('PDF and print reject selections above the limit while CSV streams every match', function () {
    Shipment::factory()->count(ShipmentExportService::DOCUMENT_LIMIT + 1)->create();
    $this->actingAs(User::factory()->administrator()->create());

    foreach (['pdf', 'print'] as $format) {
        $this->getJson(route('administration.orders.shipments.export', ['format' => $format]))
            ->assertUnprocessable()->assertJsonValidationErrors('export');
    }
    $csv = $this->get(route('administration.orders.shipments.export', ['format' => 'csv']))->assertOk()->streamedContent();
    expect(shipmentCsv($csv))->toHaveCount(ShipmentExportService::DOCUMENT_LIMIT + 2);
});

test('manually arranged historical shipments remain visible without invented measurements', function () {
    $order = Order::factory()->delivery()->create([
        'product_subtotal' => '100.00', 'total_amount' => '100.00', 'delivery_fee' => '0.00',
        'delivery_quote_metadata' => ['pricing_status' => 'not_configured'],
    ]);
    $shipment = Shipment::factory()->for($order)->create([
        'arrangement_type' => ShipmentArrangement::Manual, 'preparation_days' => null,
        'transit_min_days' => null, 'transit_max_days' => null, 'eta_min_days' => null, 'eta_max_days' => null,
    ]);

    $this->actingAs(User::factory()->administrator()->create())->get(route('administration.orders.index', ['view' => 'shipments']))
        ->assertInertia(fn (Assert $page) => $page->where('shipments.total', 1)->where('shipments.data.0.id', $shipment->id)
            ->where('shipments.data.0.package_count', null)->where('shipments.data.0.handed_at', null));
});

test('export permission alone does not grant shipment order access', function () {
    $role = DB::table('administration_roles')->where('code', 'manager')->value('id');
    DB::table('administration_role_permissions')->insertOrIgnore([
        'administration_role_id' => $role,
        'administration_permission_id' => DB::table('administration_permissions')->where('code', 'reports.export')->value('id'),
    ]);
    DB::table('administration_role_permissions')->where('administration_role_id', $role)
        ->where('administration_permission_id', DB::table('administration_permissions')->where('code', 'orders.view')->value('id'))->delete();
    $actor = User::factory()->administrator()->create(['administration_role_id' => $role]);
    expect($actor->can('reports.export'))->toBeTrue();

    $this->actingAs($actor)->get(route('administration.orders.shipments.export', ['format' => 'csv']))->assertForbidden();
});
