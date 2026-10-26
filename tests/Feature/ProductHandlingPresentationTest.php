<?php

use App\Enums\ShippingProfile;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('catalog and administration expose configured handling without private classification metadata', function (string $profile) {
    config(["battlefront.delivery.profiles.{$profile}" => ['surcharge' => '73.25', 'preparation_days' => 4]]);
    $product = Product::factory()->available()->create(['shipping_profile' => $profile, 'shipping_profile_source' => 'catalog_review_v1']);
    $handling = ['profile' => $profile, 'label' => ucfirst($profile), 'surcharge' => '73.25', 'preparation_days' => 4];

    $this->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->where('products.data.0.shipping_profile', $profile)
        ->missing('products.data.0.shipping_profile_source'));
    $this->get(route('products.show', $product))->assertInertia(fn (Assert $page) => $page
        ->where('product.shipping_handling', $handling)->missing('product.shipping_profile_source'));
    $this->getJson(route('api.v1.products.show', $product))->assertOk()
        ->assertJsonMissingPath('data.shipping_profile')
        ->assertJsonMissingPath('data.shipping_handling');
    $this->actingAs(User::factory()->administrator()->create());
    $this->get(route('administration.products.index'))->assertInertia(fn (Assert $page) => $page->where('products.data.0.shipping_handling', $handling));
    $this->get(route('administration.products.show', $product))->assertInertia(fn (Assert $page) => $page->where('product.shipping_handling', $handling));
    foreach (['create', 'edit'] as $form) {
        $this->get(route('administration.products.'.$form, $form === 'edit' ? $product : []))
            ->assertInertia(fn (Assert $page) => $page
                ->has('shipping_profiles', 3)
                ->where('shipping_profiles.'.ShippingProfile::from($profile)->priority(), $handling)
                ->when($form === 'edit', fn (Assert $page) => $page->missing('product.shipping_profile_source')));
    }
})->with(['standard', 'fragile', 'bulky']);

test('saving unchanged handling preserves its source even with the retired review flag', function (?string $source) {
    $product = Product::factory()->create(['shipping_profile' => 'fragile', 'shipping_profile_source' => $source]);
    $payload = [...validPackageMeasurements(), ...$product->only(['product_code', 'name', 'category_id', 'price', 'is_featured']),
        'edit_version' => $product->edit_version,
        'shipping_profile' => 'fragile', 'shipping_profile_reviewed' => true, 'shipping_profile_source' => 'forged'];

    $this->actingAs(User::factory()->administrator()->create())
        ->put(route('administration.products.update', $product), $payload)->assertSessionHasNoErrors();

    expect($product->refresh()->shipping_profile_source)->toBe($source);
    $this->put(route('administration.products.update', $product), [...validPackageMeasurements(), ...$payload, 'edit_version' => $product->refresh()->edit_version, 'shipping_profile' => 'standard'])
        ->assertSessionHasNoErrors();
    expect($product->refresh()->shipping_profile)->toBe(ShippingProfile::Standard);
    expect($product->shipping_profile_source)->toBe('manual');
})->with(['unclassified' => [null], 'imported' => ['catalog_review_v1'], 'manual' => ['manual']]);

test('invalid handling cannot change a product assignment', function () {
    $product = Product::factory()->create(['shipping_profile' => 'bulky', 'shipping_profile_source' => 'catalog_review_v1']);

    $this->actingAs(User::factory()->administrator()->create())
        ->put(route('administration.products.update', $product), [...validPackageMeasurements(),
            ...$product->only(['product_code', 'name', 'category_id', 'price', 'is_featured']),
            'edit_version' => $product->edit_version,
            'shipping_profile' => 'oversized',
        ])->assertSessionHasErrors('shipping_profile');

    expect($product->refresh()->shipping_profile)->toBe(ShippingProfile::Bulky);
    expect($product->shipping_profile_source)->toBe('catalog_review_v1');
});
