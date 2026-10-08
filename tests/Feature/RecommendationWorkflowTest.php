<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $attributes
 */
function createWorkflowProduct(Category $category, array $attributes = []): Product
{
    $product = Product::factory()->for($category)->create($attributes);
    Inventory::factory()->for($product)->create(['quantity' => 8, 'reorder_level' => 2]);

    return $product;
}

test('guests see popular completed-order products and customers see purchase-based recommendations', function () {
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $purchased = createWorkflowProduct($category);
    $companion = createWorkflowProduct($category);
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();

    $customerOrder = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($customerOrder)->for($purchased)->create();

    $relatedOrder = Order::factory()->for($otherCustomer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($relatedOrder)->for($purchased)->create();
    OrderItem::factory()->for($relatedOrder)->for($companion)->create();

    $this->get(route('recommendations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recommendations/Index')
            ->where('is_personalized', false)
            ->has('recommendations', 2)
            ->where('recommendations.0.reasons.0.code', 'popular_with_customers'));

    $this->actingAs($customer)->get(route('recommendations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recommendations/Index')
            ->where('is_personalized', true)
            ->has('recommendations', 1)
            ->where('recommendations.0.product.id', $companion->id)
            ->where('recommendations.0.reasons.0.code', 'bought_with_purchase_history'));
});

test('customers see recommendations based on products in their cart', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $category = Category::factory()->create();
    $cartAnchor = createWorkflowProduct($category);
    $companion = createWorkflowProduct($category);
    $cart = Cart::factory()->for($customer)->create();
    CartItem::factory()->for($cart)->for($cartAnchor)->create();
    $completedOrder = Order::factory()->for($otherCustomer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($completedOrder)->for($cartAnchor)->create();
    OrderItem::factory()->for($completedOrder)->for($companion)->create();

    $this->actingAs($customer)->get(route('recommendations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('is_personalized', true)
            ->has('recommendations', 1)
            ->where('recommendations.0.product.id', $companion->id)
            ->where('recommendations.0.reasons.0.code', 'bought_with_cart_products'));
});

test('guests and customers are authorized to use recommendations while administrators are denied', function () {
    $customer = User::factory()->customer()->create();
    $administrator = User::factory()->administrator()->create();

    expect(Gate::forUser(null)->allows('use-recommendations'))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('use-recommendations'))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('use-recommendations'))->toBeFalse();

    $this->actingAs($administrator)
        ->get(route('recommendations.index'))
        ->assertForbidden();
});

test('recommendation page shows an empty state when there are no completed orders', function () {
    $this->get(route('recommendations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recommendations/Index')
            ->where('is_personalized', false)
            ->where('recommendations', []));
});

test('customers without recommendation signals see a non-personalized empty state', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)->get(route('recommendations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recommendations/Index')
            ->where('is_personalized', false)
            ->where('has_featured_fallback', false)
            ->where('recommendations', []));
});

test('home page shows popular recommendations to guests', function () {
    $category = Category::factory()->create();
    $popularProduct = createWorkflowProduct($category);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($order)->for($popularProduct)->create();

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('is_personalized', false)
            ->has('recommendations', 1)
            ->where('recommendations.0.product.id', $popularProduct->id));
});

test('product page recommendations exclude the product currently being viewed', function () {
    $category = Category::factory()->create();
    $popularProduct = createWorkflowProduct($category);
    $currentProduct = createWorkflowProduct($category);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($order)->for($popularProduct)->create();
    OrderItem::factory()->for($order)->for($currentProduct)->create();

    $this->get(route('products.show', $currentProduct))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Show')
            ->where('is_personalized', false)
            ->has('recommendations', 1)
            ->where('recommendations.0.product.id', $popularProduct->id));
});

test('criteria-based web results route is no longer available', function () {
    $this->get('/recommendations/results?budget=500.00&intended_use=gaming')
        ->assertNotFound();
});
