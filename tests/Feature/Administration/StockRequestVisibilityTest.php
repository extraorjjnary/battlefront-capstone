<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

test('staff only see their own requests regardless of the requested filter', function (?string $view) {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $other = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Matching stock product']);
    $ids = [];
    foreach ([[$staff->id, 'pending'], [$staff->id, 'approved'], [$other->id, 'pending']] as [$requester, $status]) {
        $ids[] = DB::table('inventory_adjustment_requests')->insertGetId([
            'product_id' => $product->id, 'requester_id' => $requester,
            'observed_quantity' => 0, 'target_quantity' => 2,
            'status' => $status, 'reason' => 'Matching stock reason',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->actingAs($staff)->get(route('administration.inventory-adjustments.index', ['view' => $view, 'q' => 'Matching']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can_view_all_requests', false)
            ->where('filters.view', 'mine')
            ->where('requests.total', 2)
            ->where('requests.data.0.id', $ids[0])
            ->where('requests.data.1.id', $ids[1]));

    $this->get(route('administration.inventory-adjustments.index', ['view' => $view, 'case' => $ids[2]]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('requests.total', 0));
})->with([null, 'mine', 'all', 'to_review']);

test('manager and owner filters select reviewable own or all requests', function (string $role, ?string $view, array $indexes, string $selected) {
    $viewer = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', $role)->value('id'),
    ]);
    $other = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $ids = [];
    foreach ([[$viewer->id, 'pending'], [$viewer->id, 'withdrawn'], [$other->id, 'pending'], [$other->id, 'rejected']] as [$requester, $status]) {
        $ids[] = DB::table('inventory_adjustment_requests')->insertGetId([
            'product_id' => $product->id, 'requester_id' => $requester,
            'observed_quantity' => 0, 'target_quantity' => 2,
            'status' => $status, 'reason' => 'Physical count correction',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $expected = array_map(fn (int $index): int => $ids[$index], $indexes);

    $this->actingAs($viewer)->get(route('administration.inventory-adjustments.index', ['view' => $view]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can_view_all_requests', true)
            ->where('filters.view', $selected)
            ->where('requests.total', count($expected))
            ->where('requests.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === $expected));

    $this->get(route('administration.inventory-adjustments.index', ['case' => $ids[1]]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requests.total', 1)->where('requests.data.0.id', $ids[1]));
})->with(['manager', 'owner'])->with([
    'default' => [null, [2], 'to_review'],
    'review' => ['to_review', [2], 'to_review'],
    'own' => ['mine', [0, 1], 'mine'],
    'all' => ['all', [0, 1, 2, 3], 'all'],
]);

test('stock request search and pagination retain the selected view', function () {
    $viewer = User::factory()->administrator()->create();
    $other = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    foreach (range(1, 22) as $number) {
        DB::table('inventory_adjustment_requests')->insert([
            'product_id' => $product->id, 'requester_id' => $other->id,
            'observed_quantity' => 0, 'target_quantity' => 2, 'status' => 'pending',
            'reason' => $number === 22 ? 'Unrelated correction' : 'Matching count correction',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->actingAs($viewer)->get(route('administration.inventory-adjustments.index', ['view' => 'all', 'q' => 'Matching', 'page' => 2]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.view', 'all')->where('filters.q', 'Matching')
            ->where('requests.total', 21)->where('requests.current_page', 2)
            ->has('requests.data', 1)
            ->where('requests.prev_page_url', fn (string $url): bool => str_contains($url, 'view=all') && str_contains($url, 'q=Matching')));
});

test('to review is empty when a manager lacks approval permission', function () {
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    DB::table('administration_role_permissions')->where('administration_role_id', $manager->administration_role_id)
        ->where('administration_permission_id', DB::table('administration_permissions')->where('code', 'inventory.approve')->value('id'))->delete();
    $other = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    DB::table('inventory_adjustment_requests')->insert([
        'product_id' => $product->id, 'requester_id' => $other->id,
        'observed_quantity' => 0, 'target_quantity' => 2, 'status' => 'pending',
        'reason' => 'Physical count correction', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($manager)->get(route('administration.inventory-adjustments.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('requests.total', 0));
    $this->get(route('administration.inventory-adjustments.index', ['view' => 'all']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('requests.total', 1));
});

test('a requester cannot approve or reject their own request from any view', function (string $decision) {
    $viewer = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $id = DB::table('inventory_adjustment_requests')->insertGetId([
        'product_id' => $product->id, 'requester_id' => $viewer->id,
        'observed_quantity' => 0, 'target_quantity' => 2, 'status' => 'pending',
        'reason' => 'Physical count correction', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($viewer)->post(route('administration.inventory-adjustments.decide', $id), [
        'decision' => $decision, 'reason' => 'Self review attempt',
    ])->assertSessionHasErrors('decision');

    $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $id, 'status' => 'pending', 'reviewer_id' => null]);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['approve', 'reject']);
