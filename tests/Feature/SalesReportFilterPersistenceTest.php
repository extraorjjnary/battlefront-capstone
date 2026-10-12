<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('applied selections survive navigation refresh and pagination while reports regenerate', function () {
    $this->travelTo('2026-10-13 10:00:00');
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();
    $filters = ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month', 'product_id' => $product->id, 'category_id' => $category->id];
    $key = 'administration.sales_report_filters.'.$administrator->id;

    $this->actingAs($administrator)->get(route('administration.reports.sales', $filters + ['page' => 2]))
        ->assertSessionHas($key, $filters);
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('administration.reports.sales'))
        ->assertInertia(fn (Assert $page) => $page->where('filters', $filters)->where('products.current_page', 1)->where('kpis.total_sales', 0));

    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified, 'total_amount' => '125.50']);
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2, 'price_at_time' => '62.75']);
    Sale::factory()->for($order)->create(['sale_date' => '2026-09-12', 'amount' => '125.50']);

    $this->get(route('administration.reports.sales'))
        ->assertInertia(fn (Assert $page) => $page->where('filters', $filters)->where('kpis.total_revenue', '125.50')->where('timeline.revenue', [125.5]));
    $this->get(route('administration.reports.sales', ['page' => 2]))
        ->assertSessionHas($key, $filters)
        ->assertInertia(fn (Assert $page) => $page->where('filters', $filters)->where('products.current_page', 2)->where('kpis.total_sales', 1));
    $this->get(route('administration.reports.sales'))
        ->assertInertia(fn (Assert $page) => $page->where('products.current_page', 1));
});

test('explicit filter URLs replace saved selections and omissions and empty values clear cross filters', function (bool $sendEmptyValues) {
    $this->travelTo('2026-10-13 10:00:00');
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $key = 'administration.sales_report_filters.'.$administrator->id;
    $this->actingAs($administrator)->get(route('administration.reports.sales', [
        'from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month', 'product_id' => $product->id, 'category_id' => $product->category_id,
    ]));
    $query = ['from' => '2026-10-01', 'to' => '2026-10-12', 'period' => 'week'];
    if ($sendEmptyValues) {
        $query += ['product_id' => '', 'category_id' => ''];
    }
    $expected = ['from' => '2026-10-01', 'to' => '2026-10-12', 'period' => 'week', 'product_id' => null, 'category_id' => null];

    $this->get(route('administration.reports.sales', $query))
        ->assertSessionHas($key, $expected)
        ->assertInertia(fn (Assert $page) => $page->where('filters', $expected)->where('active_filters', ['product' => null, 'category' => null]));
    $this->get(route('administration.reports.sales'))
        ->assertInertia(fn (Assert $page) => $page->where('filters', $expected));

    $this->get(route('administration.reports.sales', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-09-14')->where('filters.to', '2026-10-13')
            ->where('filters.period', 'day')->where('filters.product_id', $product->id)->where('filters.category_id', null));
})->with(['omitted IDs' => false, 'empty IDs' => true]);

test('chart cross filtering remembers each new selection and clearing one preserves the other', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $range = ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month'];
    $this->actingAs($administrator)->get(route('administration.reports.sales', $range + ['product_id' => $product->id, 'category_id' => $product->category_id]));

    $this->get(route('administration.reports.sales', $range + ['category_id' => $product->category_id]));
    $this->get(route('administration.reports.sales'))
        ->assertInertia(fn (Assert $page) => $page->where('filters.product_id', null)->where('filters.category_id', $product->category_id));
    $this->get(route('administration.reports.sales', $range + ['product_id' => $product->id]));
    $this->get(route('administration.reports.sales'))
        ->assertInertia(fn (Assert $page) => $page->where('filters.product_id', $product->id)->where('filters.category_id', null));
});

test('invalid explicit filters do not overwrite the remembered selection', function () {
    $administrator = User::factory()->administrator()->create();
    $filters = ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month', 'product_id' => null, 'category_id' => null];
    $this->actingAs($administrator)->get(route('administration.reports.sales', $filters));

    $this->getJson(route('administration.reports.sales', ['from' => '2026-10-01']))
        ->assertUnprocessable()->assertInvalid(['to' => 'Select both a start and end date.'])
        ->assertSessionHas('administration.sales_report_filters.'.$administrator->id, $filters);
    $this->get(route('administration.reports.sales'))->assertInertia(fn (Assert $page) => $page->where('filters', $filters));
});

test('administrators have isolated selections and reset clears only the current administrators filters', function () {
    $this->travelTo('2026-10-13 10:00:00');
    $first = User::factory()->administrator()->create();
    $second = User::factory()->administrator()->create();
    $firstFilters = ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month', 'product_id' => null, 'category_id' => null];
    $secondFilters = ['from' => '2026-10-01', 'to' => '2026-10-10', 'period' => 'week', 'product_id' => null, 'category_id' => null];
    $defaults = ['from' => '2026-09-14', 'to' => '2026-10-13', 'period' => 'day', 'product_id' => null, 'category_id' => null];
    $this->actingAs($first)->get(route('administration.reports.sales', $firstFilters));
    $this->actingAs($second)->get(route('administration.reports.sales'))
        ->assertInertia(fn (Assert $page) => $page->where('filters', $defaults));
    $this->get(route('administration.reports.sales', $secondFilters));
    $this->actingAs($first)->get(route('administration.reports.sales'))
        ->assertInertia(fn (Assert $page) => $page->where('filters', $firstFilters));

    $this->post(route('administration.reports.sales.reset'))
        ->assertRedirectToRoute('administration.reports.sales')
        ->assertSessionMissing('administration.sales_report_filters.'.$first->id)
        ->assertSessionHas('administration.sales_report_filters.'.$second->id, $secondFilters);
    $this->get(route('administration.reports.sales'))->assertInertia(fn (Assert $page) => $page->where('filters', $defaults));
    $this->actingAs($second)->get(route('administration.reports.sales'))->assertInertia(fn (Assert $page) => $page->where('filters', $secondFilters));
});

test('reset returns the server default range and clears product category and period selections', function () {
    $this->travelTo('2026-10-13 10:00:00');
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $this->actingAs($administrator)->get(route('administration.reports.sales', [
        'from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month', 'product_id' => $product->id, 'category_id' => $product->category_id,
    ]));

    $this->post(route('administration.reports.sales.reset'))->assertRedirectToRoute('administration.reports.sales');
    $this->get(route('administration.reports.sales'))
        ->assertSessionMissing('administration.sales_report_filters.'.$administrator->id)
        ->assertInertia(fn (Assert $page) => $page->where('filters', [
            'from' => '2026-09-14', 'to' => '2026-10-13', 'period' => 'day', 'product_id' => null, 'category_id' => null,
        ]));
    $this->travel(1)->days();
    $this->get(route('administration.reports.sales'))->assertInertia(fn (Assert $page) => $page->where('filters.to', '2026-10-14'));
});

test('stale remembered filters fall back to defaults without changing preferences through exports', function (array $saved) {
    $this->travelTo('2026-10-13 10:00:00');
    $administrator = User::factory()->administrator()->create();
    $key = 'administration.sales_report_filters.'.$administrator->id;
    $saved += ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month', 'product_id' => null, 'category_id' => null];
    $this->actingAs($administrator)->withSession([$key => $saved]);

    $this->get(route('administration.reports.sales.print'))
        ->assertSessionHas($key, $saved)->assertViewHas('filters.to', '2026-10-13');
    $this->get(route('administration.reports.sales'))
        ->assertSessionMissing($key)
        ->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-09-14')->where('filters.period', 'day')->where('filters.product_id', null));
})->with(['removed product' => [['product_id' => 999999]], 'invalid range' => [['to' => '2026-08-01']]]);

test('guests and customers cannot reset administrator report preferences', function () {
    $key = 'administration.sales_report_filters.123';
    $this->withSession([$key => ['period' => 'month']])->post(route('administration.reports.sales.reset'))
        ->assertRedirectToRoute('login')->assertSessionHas($key, ['period' => 'month']);
    $this->actingAs(User::factory()->customer()->create())->post(route('administration.reports.sales.reset'))
        ->assertForbidden()->assertSessionHas($key, ['period' => 'month']);
});
