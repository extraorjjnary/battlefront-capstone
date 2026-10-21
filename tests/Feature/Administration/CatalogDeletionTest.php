<?php

use App\Actions\Cart\BuildCartViewData;
use App\Actions\Category\DeleteCategory;
use App\Actions\Product\DeleteProduct;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CustomerProductView;
use App\Models\Forecast;
use App\Models\GuestRecommendationProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecommendationInteraction;
use App\Models\Sale;
use App\Models\Tag;
use App\Models\User;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\HistoricalSalesCatalogFixtures;

function emptyProductInventory(Product $product): void
{
    $product->inventory?->update(['quantity' => 0, 'quarantined_quantity' => 0]);
}

test('administrators can search deleted products by name and catalog code', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create([
        'name' => 'Deleted search target',
        'product_code' => 'DEL-SEARCH-01',
    ]);
    $product->delete();

    $this->actingAs($administrator)
        ->get(route('administration.products.index', ['deleted' => 1, 'q' => 'DEL-SEARCH-01']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'DEL-SEARCH-01')
            ->where('products.total', 1)
            ->where('products.data.0.name', 'Deleted search target'));
});

test('product deletion retains linked inventory and activity for restoration', function (bool $imported) {
    Storage::fake('local');
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create(['is_catalog_imported' => $imported]);
    emptyProductInventory($product);
    $inventory = $product->inventory;
    $tag = Tag::factory()->create();
    $product->tags()->attach($tag);
    $view = CustomerProductView::factory()->for($product)->create();
    $guestView = CustomerProductView::factory()->for($product)->create([
        'user_id' => null, 'guest_recommendation_profile_id' => GuestRecommendationProfile::factory()->create()->id,
    ]);
    $interaction = RecommendationInteraction::factory()->for($product)->create();
    $unrelated = Product::factory()->available()->create();

    $this->actingAs($administrator)->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->trashed())->toBeTrue()
        ->and($product->fresh()->is_active)->toBeFalse()
        ->and($product->fresh()->deleted_by_id)->toBe($administrator->id);
    $this->assertModelExists($inventory);
    $this->assertModelExists($view);
    $this->assertModelExists($guestView);
    $this->assertModelExists($interaction);
    $this->assertDatabaseHas('product_tag', ['product_id' => $product->id]);
    $this->assertModelExists($tag);
    $this->assertModelExists($unrelated);
    $this->assertModelExists($unrelated->inventory);
    $this->assertModelExists($product->category);
    $this->assertModelExists($view->user);
    $this->assertModelExists($guestView->guestRecommendationProfile);
})->with(['manual' => false, 'imported' => true]);

test('soft product deletion preserves its owned image for restoration', function (bool $imported) {
    Storage::fake('public');
    Storage::fake('local');
    $product = Product::factory()->create(['is_catalog_imported' => $imported]);
    $path = 'products/admin/'.$product->id.'/'.str_repeat('a', 64).'.webp';
    $product->update(['image_path' => $path]);
    Storage::disk('public')->put($path, 'owned bytes');

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->trashed())->toBeTrue();
    Storage::disk('public')->assertExists($path);
})->with(['manual' => false, 'imported with administrator upload' => true]);

test('product deletion preserves shared manifest and unrelated images', function (string $path, bool $shared) {
    Storage::fake('public');
    Storage::fake('local');
    $product = Product::factory()->create(['image_path' => $path]);
    Storage::disk('public')->put($path, 'preserved bytes');
    if ($shared) {
        Product::factory()->create(['image_path' => $path]);
    }

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors();

    expect(Storage::disk('public')->get($path))->toBe('preserved bytes');
})->with([
    'manifest' => ['products/graphics-card/00123.webp', false],
    'shared legacy upload' => ['products/shared.jpg', true],
    'unrelated namespace' => ['unrelated/image.webp', false],
    'another product owner' => ['products/admin/999/'.str_repeat('a', 64).'.webp', false],
]);

test('product deletion retains order history when orders are terminal', function (string $status) {
    $product = Product::factory()->available()->create();
    emptyProductInventory($product);
    $order = Order::factory()->create(['status' => $status]);
    $item = OrderItem::factory()->for($order)->for($product)->create();

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->trashed())->toBeTrue();
    $this->assertModelExists($product->inventory);
    $this->assertModelExists($item);
})->with(['completed', 'cancelled']);

test('product deletion is blocked while a customer order is unfinished', function (string $status) {
    $product = Product::factory()->create();
    $order = Order::factory()->create(['status' => $status]);
    OrderItem::factory()->for($order)->for($product)->create();

    $this->actingAs(User::factory()->administrator()->create())
        ->delete(route('administration.products.destroy', $product))
        ->assertSessionHasErrors('deletion');

    expect($product->fresh()->trashed())->toBeFalse();
})->with(['pending', 'processing']);

test('product deletion is blocked while sellable or quarantined stock remains', function (string $column) {
    $product = Product::factory()->available()->create();
    $product->inventory()->update([$column => 1]);

    $this->actingAs(User::factory()->administrator()->create())
        ->delete(route('administration.products.destroy', $product))
        ->assertSessionHasErrors('deletion');

    expect($product->fresh()->trashed())->toBeFalse();
})->with(['quantity', 'quarantined_quantity']);

test('product deletion retains recorded sales and forecasts', function (string $reference) {
    $product = Product::factory()->create(['is_catalog_imported' => true]);
    if ($reference === 'sale') {
        $sale = Sale::factory()->create();
        $record = OrderItem::factory()->for($sale->order)->for($product)->create();
    } else {
        $record = Forecast::factory()->for($product)->create(['method' => $reference]);
    }

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->trashed())->toBeTrue();
    $this->assertModelExists($record);
    if (isset($sale)) {
        $this->assertModelExists($sale);
    }
})->with(['sale', 'additive_holt_winters', 'moving_average', 'linear_trend']);

test('product deletion retains existing cart items', function () {
    $product = Product::factory()->available()->create(['is_catalog_imported' => true]);
    $item = CartItem::factory()->for($product)->create();
    emptyProductInventory($product);
    $product->update(['is_active' => false]);

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->trashed())->toBeTrue();
    $this->assertModelExists($item);
    expect(app(BuildCartViewData::class)->execute($item->cart->user)['items'][0]['availability']['status'])
        ->toBe('product_ineligible');
});

test('soft deleting seeded products retains historical transactions and inventory', function (bool $keepCoverage) {
    Storage::fake('local');
    $this->travelTo('2026-10-15 12:00:00');
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    if (! $keepCoverage) {
        app(SalesHistoryCoverageRepository::class)->clearDevelopment();
    }
    $product = $products['10003'];
    emptyProductInventory($product);
    $inventory = $product->inventory->toArray();
    $items = $product->orderItems()->with('order.sale')->get()->toArray();

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->trashed())->toBeTrue();
    expect($product->inventory->refresh()->toArray())->toBe($inventory);
    expect($product->orderItems()->with('order.sale')->get()->toArray())->toBe($items);
    $this->assertDatabaseCount('products', 15);
    $this->assertDatabaseCount('sales', 259);
})->with(['orders and coverage' => true, 'orders without coverage' => false]);

test('soft deleting a product preserves historical coverage metadata', function (bool $development) {
    Storage::fake('local');
    $product = Product::factory()->create();
    if ($development) {
        Storage::disk('local')->put(config('forecasting.development_manifest'), json_encode([
            'version' => 2, 'products' => [$product->product_code => ['stale' => true]],
        ], JSON_THROW_ON_ERROR));
        $original = Storage::disk('local')->get(config('forecasting.development_manifest'));
    } else {
        config(['forecasting.operational_coverage' => [$product->product_code => ['stale' => true]]]);
    }

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->trashed())->toBeTrue();
    if ($development) {
        expect(Storage::disk('local')->get(config('forecasting.development_manifest')))->toBe($original);
    }
})->with(['operational' => false, 'development' => true]);

test('a concurrent order item remains attached when a product is soft deleted', function () {
    Storage::fake('public');
    $product = Product::factory()->available()->create(['image_path' => 'products/owned.jpg']);
    emptyProductInventory($product);
    emptyProductInventory($product);
    Storage::disk('public')->put($product->image_path, 'owned bytes');
    $tag = Tag::factory()->create();
    $product->tags()->attach($tag);
    $order = Order::factory()->create(['status' => 'completed']);
    $event = 'eloquent.deleting: '.Product::class;
    Event::listen($event, function (Product $deleting) use ($order): void {
        OrderItem::factory()->for($order)->for($deleting, 'product')->create();
    });

    try {
        $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
            ->assertSessionHasNoErrors();
    } finally {
        Event::forget($event);
    }

    expect($product->fresh()->trashed())->toBeTrue();
    $this->assertModelExists($product->inventory);
    $this->assertDatabaseHas('product_tag', ['product_id' => $product->id, 'tag_id' => $tag->id]);
    $this->assertDatabaseHas('order_items', ['product_id' => $product->id]);
    Storage::disk('public')->assertExists($product->image_path);
});

test('unreadable history metadata does not block recoverable product deletion', function () {
    Storage::fake('local');
    $product = Product::factory()->available()->create();
    emptyProductInventory($product);
    Storage::disk('local')->put(config('forecasting.development_manifest'), '{invalid json');

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->trashed())->toBeTrue();
    $this->assertModelExists($product->inventory);
    expect(Storage::disk('local')->get(config('forecasting.development_manifest')))->toBe('{invalid json');
});

test('rollback never removes an owned product image', function () {
    Storage::fake('public');
    $product = Product::factory()->create(['image_path' => 'products/owned.jpg']);
    Storage::disk('public')->put($product->image_path, 'owned bytes');
    DB::beginTransaction();

    try {
        app(DeleteProduct::class)->execute($product);
        Storage::disk('public')->assertExists($product->image_path);
    } finally {
        DB::rollBack();
    }

    $this->assertModelExists($product);
    Storage::disk('public')->assertExists($product->image_path);
});

test('soft deletion retains image and commits its audit record', function () {
    Storage::fake('public', ['throw' => true]);
    $product = Product::factory()->create(['image_path' => 'products/soft-delete-audit.jpg']);
    expect(Storage::disk('public')->put($product->image_path, 'owned image'))->toBeTrue();
    Storage::disk('public')->assertExists($product->image_path, 'owned image');

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors()->assertRedirect(route('administration.products.index'));

    expect($product->fresh()->trashed())->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'product.deleted', 'subject_id' => $product->id]);
    Storage::disk('public')->assertExists($product->image_path, 'owned image');
});

test('unrelated database update failures are not disguised as protected references', function () {
    $product = Product::factory()->create();
    DB::unprepared("CREATE TRIGGER fail_product_delete BEFORE UPDATE OF deleted_at ON products BEGIN SELECT RAISE(ABORT, 'Storage constraint failure'); END");

    expect(fn () => app(DeleteProduct::class)->execute($product))->toThrow(QueryException::class);

    $this->assertModelExists($product);
});

test('administrators can soft delete empty active or inactive categories', function (bool $active) {
    $category = Category::factory()->create(['is_active' => $active]);
    $otherProduct = Product::factory()->create();

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.categories.destroy', $category))
        ->assertSessionHasNoErrors()->assertRedirect(route('administration.categories.index'));

    expect($category->fresh()->trashed())->toBeTrue()
        ->and($category->fresh()->is_active)->toBeFalse();
    $this->assertDatabaseHas('audit_logs', ['action' => 'category.deleted', 'subject_id' => $category->id]);
    $this->assertModelExists($otherProduct);
    $this->assertModelExists($otherProduct->category);
})->with(['active' => true, 'inactive' => false]);

test('active and inactive products prevent category deletion', function (bool $active) {
    $product = Product::factory()->create(['is_active' => $active]);

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.categories.destroy', $product->category))
        ->assertSessionHasErrors(['deletion' => DeleteCategory::PROTECTED_MESSAGE]);

    $this->assertModelExists($product);
    $this->assertModelExists($product->category);
})->with(['active' => true, 'inactive' => false]);

test('a concurrently attached product remains linked to a soft deleted category', function () {
    $category = Category::factory()->create();
    $event = 'eloquent.deleting: '.Category::class;
    Event::listen($event, fn (Category $deleting) => Product::factory()->for($deleting)->create());

    try {
        $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.categories.destroy', $category))
            ->assertSessionHasNoErrors();
    } finally {
        Event::forget($event);
    }

    expect($category->fresh()->trashed())->toBeTrue();
    $this->assertDatabaseCount('products', 1);
});

test('guests and customers cannot delete administrator catalog records', function (string $resource, bool $authenticated) {
    $record = $resource === 'products' ? Product::factory()->create() : Category::factory()->create();
    if ($authenticated) {
        $this->actingAs(User::factory()->customer()->create());
    }

    $response = $this->delete(route('administration.'.$resource.'.destroy', $record));

    if ($authenticated) {
        $response->assertForbidden();
    } else {
        $response->assertRedirect(route('login'));
    }
    $this->assertModelExists($record);
})->with([
    'guest product' => ['products', false], 'customer product' => ['products', true],
    'guest category' => ['categories', false], 'customer category' => ['categories', true],
]);

test('administrator product views expose import origin for deletion confirmation', function () {
    $product = Product::factory()->create(['is_catalog_imported' => true]);
    $this->actingAs(User::factory()->administrator()->create());

    $this->get(route('administration.products.index'))->assertInertia(fn (Assert $page) => $page
        ->where('products.data.0.is_catalog_imported', true));
    $this->get(route('administration.products.show', $product))->assertInertia(fn (Assert $page) => $page
        ->where('product.is_catalog_imported', true));
});
