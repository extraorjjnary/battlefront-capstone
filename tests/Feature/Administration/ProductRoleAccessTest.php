<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('staff can browse available storefront products but cannot access administration products', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $product = Product::factory()->available()->create();
    $inactive = Product::factory()->inactive()->available()->create();

    $this->actingAs($staff)->get(route('products.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Products/Index')
        ->where('auth.can.browseProductCatalog', true)
        ->where('auth.can.manageProducts', false)
        ->where('products.data.0.id', $product->id));
    $this->actingAs($staff)->get(route('products.show', $product))->assertOk();
    $this->actingAs($staff)->get(route('products.show', $inactive))->assertNotFound();
    $this->actingAs($staff)->get(route('administration.products.index'))->assertForbidden();
    $this->actingAs($staff)->get(route('administration.products.show', $product))->assertForbidden();
    $this->actingAs($staff)->get(route('administration.products.create'))->assertForbidden();
    $this->actingAs($staff)->get(route('administration.products.edit', $product))->assertForbidden();
    $this->actingAs($staff)->post(route('administration.products.store'), [])->assertForbidden();
    $this->actingAs($staff)->patch(route('administration.products.activation.update', $product), [
        'is_active' => true,
    ])->assertForbidden();
    $this->actingAs($staff)->delete(route('administration.products.destroy', $product))->assertForbidden();
    expect($product->fresh()->is_active)->toBeTrue();
});

test('manager can manage products while owner retains price and deletion controls', function () {
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->create(['price' => '500.00']);

    $this->actingAs($manager)->get(route('administration.products.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.can.browseProductCatalog', true)
        ->where('auth.can.manageProducts', true)
        ->where('auth.can.priceProducts', false)
        ->where('auth.can.deleteCatalog', false));
    $this->actingAs($manager)->get(route('administration.products.edit', $product))->assertOk();
    $this->actingAs($manager)->get(route('administration.products.create'))->assertForbidden();
    $this->actingAs($manager)->delete(route('administration.products.destroy', $product))->assertForbidden();
    $this->actingAs($manager)->put(route('administration.products.update', $product), [
        'edit_version' => $product->edit_version,
        'product_code' => $product->product_code,
        'name' => $product->name,
        'category_id' => $product->category_id,
        'price' => '999.00',
        'is_featured' => false,
    ])->assertForbidden();
    expect($product->fresh()->price)->toBe('500.00');

    $this->actingAs($owner)->get(route('administration.products.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.can.browseProductCatalog', true)
        ->where('auth.can.priceProducts', true)
        ->where('auth.can.deleteCatalog', true));
    $this->actingAs($owner)->get(route('administration.products.create'))->assertOk();
    $this->actingAs($manager)->get(route('products.index'))->assertOk();
    $this->actingAs($owner)->get(route('products.index'))->assertOk();
});

test('IT and customers do not receive administrator catalog navigation', function () {
    $it = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'it')->value('id'),
    ]);
    $customer = User::factory()->customer()->create();

    $this->actingAs($it)->get(route('administration.products.index'))->assertForbidden();
    $this->actingAs($customer)->get(route('administration.products.index'))->assertForbidden();
    $this->actingAs($it)->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.can.browseProductCatalog', false));
    $this->actingAs($customer)->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.can.browseProductCatalog', false));
});
