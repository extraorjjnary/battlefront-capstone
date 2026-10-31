<?php

use App\Actions\Inventory\HandleCustomerReturn;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

it('exports every matching stock request regardless of the current page and escapes formulas', function () {
    $viewer = User::factory()->administrator()->create();
    $other = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => '=SUM(1,2)']);
    foreach (range(1, 23) as $number) {
        DB::table('inventory_adjustment_requests')->insert([
            'product_id' => $product->id, 'requester_id' => $other->id,
            'observed_quantity' => 0, 'target_quantity' => 2, 'status' => 'pending',
            'reason' => 'Matching correction '.$number, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $response = $this->actingAs($viewer)->get(route('administration.operations.export', ['feature' => 'stock-requests', 'format' => 'csv', 'view' => 'all', 'page' => 2, 'q' => 'Matching']));
    $response->assertOk()->assertDownload();
    $csv = $response->streamedContent();
    expect(substr_count($csv, "'=SUM"))->toBe(23);
    expect($csv)->toContain('Matching correction 23', 'Matching correction 1');
});

it('requires export permission and feature access', function (string $feature, string $format) {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $this->actingAs($staff)->get(route('administration.operations.export', ['feature' => $feature, 'format' => $format]))->assertForbidden();
})->with(['stock-requests', 'supplier-returns', 'customer-returns', 'customer-requests', 'damage-reports'])->with(['csv', 'print']);

it('renders private printable reports with an empty state and print controls', function (string $feature) {
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.operations.export', ['feature' => $feature, 'format' => 'print']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('Battlefront Computer Trading')
        ->assertSee('No matching records.')
        ->assertSee('window.print()', false)
        ->assertSee('A4 landscape', false);
})->with(['stock-requests', 'supplier-returns', 'customer-returns', 'customer-requests', 'damage-reports']);

it('prints matching stock requests across pages or a selected case without changing records', function () {
    $viewer = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => '<script>alert(1)</script>']);
    $ids = [];
    foreach (range(1, 23) as $number) {
        $ids[] = DB::table('inventory_adjustment_requests')->insertGetId([
            'product_id' => $product->id, 'requester_id' => $viewer->id,
            'observed_quantity' => 0, 'target_quantity' => 2, 'status' => 'pending',
            'reason' => 'Print correction '.$number, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $before = DB::table('inventory_adjustment_requests')->orderBy('id')->get()->toJson();
    $parameters = ['feature' => 'stock-requests', 'format' => 'print', 'view' => 'all', 'q' => 'Print correction', 'page' => 2];
    $response = $this->actingAs($viewer)->get(route('administration.operations.export', $parameters));
    $response->assertOk()->assertSee('23 records')->assertSee('Print correction 23')->assertSee('Print correction 1')
        ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->get(route('administration.operations.export', [...$parameters, 'case' => $ids[0]]))
        ->assertOk()->assertSee('1 records')->assertSee('Print correction 1')->assertDontSee('Print correction 23');
    expect(DB::table('inventory_adjustment_requests')->orderBy('id')->get()->toJson())->toBe($before);
});

it('downloads empty csv and pdf reports', function (string $feature, string $format) {
    $viewer = User::factory()->administrator()->create();
    $response = $this->actingAs($viewer)->get(route('administration.operations.export', ['feature' => $feature, 'format' => $format]));
    $response->assertOk()->assertDownload();
    if ($format === 'pdf') {
        expect($response->getContent())->toStartWith('%PDF-');
    } else {
        expect($response->streamedContent())->toContain('Id');
    }
})->with(['stock-requests', 'supplier-returns', 'customer-returns', 'customer-requests', 'damage-reports'])->with(['csv', 'pdf']);

it('validates export filters', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->getJson(route('administration.operations.export', ['feature' => 'stock-requests', 'format' => 'csv', 'view' => 'invalid']))
        ->assertUnprocessable()->assertJsonValidationErrors('view');
});

it('exports supplier receipt with an outstanding outcome and preserves overdue filtering', function () {
    $viewer = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Export supplier hardware']);
    $supplier = DB::table('suppliers')->insertGetId(['name' => 'Export supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('supplier_returns')->insert(['supplier_id' => $supplier, 'product_id' => $product->id, 'requester_id' => $viewer->id, 'quantity' => 2, 'status' => 'closed', 'reason' => 'Returned hardware', 'created_at' => now()->subDays(45), 'updated_at' => now()]);
    $response = $this->actingAs($viewer)->get(route('administration.operations.export', ['feature' => 'supplier-returns', 'format' => 'csv', 'q' => 'Export supplier', 'overdue' => 1]));
    $response->assertOk();
    expect($response->streamedContent())->toContain('Received by supplier - outcome pending', 'Export supplier hardware');
});

it('exports received customer returns with confirmed refund totals', function () {
    Storage::fake('local');
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    $order->sale()->create(['amount' => '200.00', 'sale_date' => today()]);
    $stock = Inventory::factory()->create(['quantity' => 0, 'quarantined_quantity' => 0]);
    $item = OrderItem::factory()->for($order)->for($stock->product)->create(['quantity' => 2, 'price_at_time' => '100.00']);
    $staff = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id')]);
    $manager = User::factory()->administrator()->create();
    $id = app(HandleCustomerReturn::class)->receive($staff, $item->id, 1, 'opened', 'Returned for inspection', 'changed_mind', photos: [UploadedFile::fake()->image('receipt.jpg')]);
    app(HandleCustomerReturn::class)->decide($id, $manager, true, 'restock', '100.00', 'Inspection confirmed suitable for sale', inspectionConfirmed: true, resolution: ['type' => 'refund', 'summary' => 'Refund approved', 'inspection_notes' => 'Tested fit for sale']);
    $response = $this->actingAs($manager)->get(route('administration.operations.export', ['feature' => 'customer-returns', 'format' => 'csv', 'case' => $id]));
    $response->assertOk();
    $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($response->streamedContent())));
    expect($rows[1][9])->toBe('100.00');
    expect($rows[1][10])->toBe('0.00');
    expect($rows[1][11])->toBe('100.00');
});

it('retains staff ownership scope even when export permission is granted', function (string $format) {
    $staff = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id')]);
    Gate::define('reports.export', fn (User $user): bool => true);
    $other = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    foreach ([[$staff->id, 'Own correction'], [$other->id, 'Other correction']] as [$requester, $reason]) {
        DB::table('inventory_adjustment_requests')->insert(['product_id' => $product->id, 'requester_id' => $requester, 'observed_quantity' => 0, 'target_quantity' => 2, 'status' => 'pending', 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
    }
    $response = $this->actingAs($staff)->get(route('administration.operations.export', ['feature' => 'stock-requests', 'format' => $format, 'view' => 'all']));
    $response->assertOk();
    expect($format === 'csv' ? $response->streamedContent() : $response->getContent())->toContain('Own correction')->not->toContain('Other correction');
})->with(['csv', 'print']);

it('exports all matching overdue damage reports across pages and selected cases', function () {
    $viewer = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Export damaged hardware']);
    $ids = [];
    foreach (range(1, 23) as $number) {
        $ids[] = DB::table('damage_reports')->insertGetId(['product_id' => $product->id, 'requester_id' => $viewer->id, 'quantity' => 2, 'status' => 'pending', 'reason' => 'Export damage '.$number, 'created_at' => now()->subDays(5), 'updated_at' => now()]);
    }
    DB::table('damage_reports')->insert(['product_id' => $product->id, 'requester_id' => $viewer->id, 'quantity' => 1, 'status' => 'pending', 'reason' => 'Recent report', 'created_at' => now(), 'updated_at' => now()]);
    $parameters = ['feature' => 'damage-reports', 'format' => 'csv', 'q' => 'Export damaged', 'overdue' => 1, 'page' => 2];
    $response = $this->actingAs($viewer)->get(route('administration.operations.export', $parameters));
    $response->assertOk();
    $csv = $response->streamedContent();
    expect(substr_count($csv, 'Export damaged hardware'))->toBe(23);
    expect($csv)->not->toContain('Recent report')->toContain('Yes');
    $selected = $this->get(route('administration.operations.export', [...$parameters, 'case' => $ids[0]]));
    expect(substr_count($selected->streamedContent(), 'Export damaged hardware'))->toBe(1);
    $this->get(route('administration.operations.export', [...$parameters, 'format' => 'print']))
        ->assertOk()->assertSee('23 records')->assertDontSee('Recent report');
    $this->get(route('administration.operations.export', [...$parameters, 'format' => 'print', 'case' => $ids[0]]))
        ->assertOk()->assertSee('1 records');
});

it('requires feature access even when print export permission is granted', function (string $feature, string $ability) {
    Gate::define('reports.export', fn (User $user): bool => true);
    Gate::define($ability, fn (User $user): bool => false);
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.operations.export', ['feature' => $feature, 'format' => 'print']))
        ->assertForbidden();
})->with([
    ['stock-requests', 'inventory.view'],
    ['damage-reports', 'inventory.view'],
    ['supplier-returns', 'inventory.view'],
    ['customer-returns', 'orders.view'],
    ['customer-requests', 'orders.view'],
]);

it('exports damage held quantities from the supplier return lifecycle', function (string $status, int $held) {
    $viewer = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $supplier = DB::table('suppliers')->insertGetId(['name' => 'Damage supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $id = DB::table('damage_reports')->insertGetId(['product_id' => $product->id, 'requester_id' => $viewer->id, 'quantity' => 2, 'status' => 'approved', 'disposition' => 'supplier_return', 'reason' => 'Damage follow up', 'created_at' => now()->subDays(5), 'updated_at' => now()]);
    DB::table('supplier_returns')->insert(['supplier_id' => $supplier, 'product_id' => $product->id, 'requester_id' => $viewer->id, 'damage_report_id' => $id, 'quantity' => 2, 'status' => $status, 'reason' => 'Linked supplier return', 'created_at' => now(), 'updated_at' => now()]);
    $response = $this->actingAs($viewer)->get(route('administration.operations.export', ['feature' => 'damage-reports', 'format' => 'csv', 'case' => $id]));
    $response->assertOk();
    $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($response->streamedContent())));
    expect($rows[1][7])->toBe((string) $held);
    expect($rows[1][9])->toBe('No');
})->with([['pending', 2], ['dispatched', 0], ['closed', 0]]);
