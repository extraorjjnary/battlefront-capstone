<?php

use App\Enums\RecommendationIntendedUse;
use App\Http\Controllers\RecommendationController;
use App\Http\Requests\RecommendationInputRequest;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\CatalogProductPresenter;
use App\Services\Recommendation\RecommendationEngine;
use App\Services\Recommendation\RecommendedProduct;
use Illuminate\Support\Facades\Http;

/**
 * @param  list<int>  $tagIds
 */
function createRecommendationTestProduct(
    Category $category,
    array $tagIds,
    string $price,
    ?int $quantity,
    ?string $brand = null,
    ?string $discountPrice = null,
    bool $active = true,
): Product {
    $product = Product::factory()->for($category)->create([
        'price' => $price,
        'discount_price' => $discountPrice,
        'brand' => $brand,
        'is_active' => $active,
    ]);
    $product->tags()->attach($tagIds);

    if ($quantity !== null) {
        Inventory::factory()->for($product)->create([
            'quantity' => $quantity,
            'reorder_level' => 2,
        ]);
    }

    return $product;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function recommendationTestCriteria(array $overrides = []): array
{
    return array_replace([
        'budget' => '100.00',
        'intended_use' => RecommendationIntendedUse::Gaming,
        'preferred_brand' => null,
        'category_id' => null,
        'tag_ids' => [],
    ], $overrides);
}

test('budget includes the boundary and uses current discount price', function () {
    $category = Category::factory()->create();
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $atBudget = createRecommendationTestProduct($category, [$gaming->id], '100.00', 5);
    createRecommendationTestProduct($category, [$gaming->id], '100.01', 5);
    $discountedToBudget = createRecommendationTestProduct($category, [$gaming->id], '120.00', 5, discountPrice: '100.00');
    createRecommendationTestProduct($category, [$gaming->id], '120.00', 5, discountPrice: '100.01');

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria());

    expect($results->map(fn (RecommendedProduct $result): int => $result->product->id)->all())
        ->toBe([$atBudget->id, $discountedToBudget->id]);
    expect($results[1]->effectivePrice)->toBe('100.00');
});

test('intended use requires a persisted category or tag and ranks multiple signals first', function () {
    $security = Category::factory()->create(['name' => 'CCTV & Security']);
    $other = Category::factory()->create();
    $homeSecurity = Tag::factory()->create(['name' => 'Home Security']);
    $categoryOnly = createRecommendationTestProduct($security, [], '50.00', 5);
    $tagOnly = createRecommendationTestProduct($other, [$homeSecurity->id], '50.00', 5);
    $both = createRecommendationTestProduct($security, [$homeSecurity->id], '50.00', 5);
    createRecommendationTestProduct($other, [], '50.00', 5);

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria([
        'intended_use' => RecommendationIntendedUse::HomeSecurity,
    ]));

    expect($results->map(fn (RecommendedProduct $result): int => $result->product->id)->all())
        ->toBe([$both->id, $categoryOnly->id, $tagOnly->id]);
    expect($results[0]->intendedUseMatchCount)->toBe(2);
    expect($results[1]->intendedUseMatchCount)->toBe(1);
});

test('selected category requires an exact persisted relationship', function () {
    $networking = Category::factory()->create(['name' => 'Networking']);
    $vending = Category::factory()->create(['name' => 'Vending & Coin-Op Machine Parts']);
    $matching = createRecommendationTestProduct($networking, [], '50.00', 5);
    createRecommendationTestProduct($vending, [], '50.00', 5);

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria([
        'intended_use' => RecommendationIntendedUse::NetworkingPisoWifi,
        'category_id' => $networking->id,
    ]));

    expect($results->map(fn (RecommendedProduct $result): int => $result->product->id)->all())
        ->toBe([$matching->id]);
    expect($results[0]->reasons)->toContain(['code' => 'selected_category', 'value' => 'Networking']);
});

test('conflicting selected category and intended use return no products', function () {
    $networking = Category::factory()->create(['name' => 'Networking']);
    createRecommendationTestProduct($networking, [], '50.00', 5);

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria([
        'category_id' => $networking->id,
    ]));

    expect($results)->toBeEmpty();
});

test('selected persisted tags improve rank without excluding other use matches', function () {
    $category = Category::factory()->create();
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $budgetFriendly = Tag::factory()->create(['name' => 'Budget-Friendly']);
    $valuePick = Tag::factory()->create(['name' => 'Value Pick']);
    $noPreferredTags = createRecommendationTestProduct($category, [$gaming->id], '50.00', 5);
    $onePreferredTag = createRecommendationTestProduct($category, [$gaming->id, $budgetFriendly->id], '50.00', 5);
    $bothPreferredTags = createRecommendationTestProduct($category, [$gaming->id, $budgetFriendly->id, $valuePick->id], '50.00', 5);

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria([
        'tag_ids' => [$budgetFriendly->id, $valuePick->id],
    ]));

    expect($results->map(fn (RecommendedProduct $result): int => $result->product->id)->all())
        ->toBe([$bothPreferredTags->id, $onePreferredTag->id, $noPreferredTags->id]);
    expect($results->pluck('preferredTagMatchCount')->all())->toBe([2, 1, 0]);
});

test('null brands remain eligible unless a preferred brand is requested', function () {
    $category = Category::factory()->create();
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $withoutBrand = createRecommendationTestProduct($category, [$gaming->id], '50.00', 5);
    $preferredBrand = createRecommendationTestProduct($category, [$gaming->id], '50.00', 5, brand: 'TP-LINK');
    createRecommendationTestProduct($category, [$gaming->id], '50.00', 5, brand: 'Other Brand');
    $engine = app(RecommendationEngine::class);

    $withoutPreference = $engine->recommend(recommendationTestCriteria());
    $withPreference = $engine->recommend(recommendationTestCriteria(['preferred_brand' => 'tp-link']));

    expect($withoutPreference->contains(fn (RecommendedProduct $result): bool => $result->product->id === $withoutBrand->id))
        ->toBeTrue();
    expect($withPreference->map(fn (RecommendedProduct $result): int => $result->product->id)->all())
        ->toBe([$preferredBrand->id]);
});

test('omitted optional preferences still rank matching available products', function () {
    $category = Category::factory()->create();
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $product = createRecommendationTestProduct($category, [$gaming->id], '50.00', 5);

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria());

    expect($results)->toHaveCount(1);
    expect($results[0]->product->id)->toBe($product->id);
    expect($results[0]->reasons)->toBe([
        ['code' => 'within_budget', 'value' => '50.00'],
        ['code' => 'sagay_stock', 'value' => 'available'],
        ['code' => 'intended_use_tag', 'value' => 'Gaming'],
    ]);
});

test('zero or missing stock and inactive catalog records are excluded while low stock remains eligible', function () {
    $category = Category::factory()->create();
    $inactiveCategory = Category::factory()->inactive()->create();
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $lowStock = createRecommendationTestProduct($category, [$gaming->id], '50.00', 1);
    createRecommendationTestProduct($category, [$gaming->id], '50.00', 0);
    createRecommendationTestProduct($category, [$gaming->id], '50.00', null);
    createRecommendationTestProduct($category, [$gaming->id], '50.00', 5, active: false);
    createRecommendationTestProduct($inactiveCategory, [$gaming->id], '50.00', 5);

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria());

    expect($results->map(fn (RecommendedProduct $result): int => $result->product->id)->all())
        ->toBe([$lowStock->id]);
});

test('equal matches sort by lower effective price then product ID consistently', function () {
    $category = Category::factory()->create();
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $higherFirstId = createRecommendationTestProduct($category, [$gaming->id], '80.00', 5);
    $lowerPrice = createRecommendationTestProduct($category, [$gaming->id], '60.00', 5);
    $higherLastId = createRecommendationTestProduct($category, [$gaming->id], '80.00', 5);
    $engine = app(RecommendationEngine::class);

    $first = $engine->recommend(recommendationTestCriteria());
    $second = $engine->recommend(recommendationTestCriteria());

    $expected = [$lowerPrice->id, $higherFirstId->id, $higherLastId->id];
    expect($first->map(fn (RecommendedProduct $result): int => $result->product->id)->all())->toBe($expected);
    expect($second->map(fn (RecommendedProduct $result): int => $result->product->id)->all())->toBe($expected);
});

test('match evidence names every applied rule using current catalog data', function () {
    $networking = Category::factory()->create(['name' => 'Networking']);
    $business = Tag::factory()->create(['name' => 'Business/Enterprise']);
    $product = createRecommendationTestProduct($networking, [$business->id], '800.00', 5, 'TP-LINK', '700.00');

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria([
        'budget' => '750.00',
        'intended_use' => RecommendationIntendedUse::NetworkingPisoWifi,
        'category_id' => $networking->id,
        'preferred_brand' => 'tp-link',
        'tag_ids' => [$business->id],
    ]));

    expect($results)->toHaveCount(1);
    expect($results[0]->product->id)->toBe($product->id);
    expect($results[0]->reasons)->toBe([
        ['code' => 'within_budget', 'value' => '700.00'],
        ['code' => 'sagay_stock', 'value' => 'available'],
        ['code' => 'intended_use_category', 'value' => 'Networking'],
        ['code' => 'selected_category', 'value' => 'Networking'],
        ['code' => 'preferred_brand', 'value' => 'TP-LINK'],
        ['code' => 'preferred_tag', 'value' => 'Business/Enterprise'],
    ]);
});

test('direct callers receive a clear error for a nonnumeric budget', function () {
    $engine = app(RecommendationEngine::class);

    expect(fn () => $engine->recommend(recommendationTestCriteria(['budget' => 'invalid'])))
        ->toThrow(InvalidArgumentException::class, 'Recommendation budget must be numeric.');
});

test('budget comparisons preserve cents near the minimum and maximum', function (string $lowerPrice, string $higherPrice) {
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $lower = createRecommendationTestProduct($category, [$gaming->id], $lowerPrice, 3);
    $higher = createRecommendationTestProduct($category, [$gaming->id], $higherPrice, 3);
    $engine = app(RecommendationEngine::class);

    $lowerBudget = $engine->recommend(recommendationTestCriteria(['budget' => $lowerPrice]));
    $higherBudget = $engine->recommend(recommendationTestCriteria(['budget' => $higherPrice]));

    expect($lowerBudget->pluck('product.id')->all())->toBe([$lower->id]);
    expect($higherBudget->pluck('product.id')->all())->toBe([$lower->id, $higher->id]);
    expect($higherBudget->pluck('effectivePrice')->all())->toBe([$lowerPrice, $higherPrice]);
})->with([
    'minimum peso budget' => ['0.01', '0.02'],
    'maximum peso budget' => ['9999999999.98', '9999999999.99'],
]);

test('each intended-use signal matches a persisted product relationship', function (RecommendationIntendedUse $use, string $categoryName, ?string $tagName, string $reasonCode, string $reasonValue) {
    $category = Category::factory()->create(['name' => $categoryName]);
    $tagIds = $tagName === null ? [] : [Tag::factory()->create(['name' => $tagName])->id];
    $matching = createRecommendationTestProduct($category, $tagIds, '50.00', 3);
    $unrelated = Category::factory()->create(['name' => 'Unrelated test category']);
    createRecommendationTestProduct($unrelated, [], '50.00', 3);

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria(['intended_use' => $use]));

    expect($results->pluck('product.id')->all())->toBe([$matching->id]);
    expect($results[0]->reasons)->toBe([
        ['code' => 'within_budget', 'value' => '50.00'],
        ['code' => 'sagay_stock', 'value' => 'available'],
        ['code' => $reasonCode, 'value' => $reasonValue],
    ]);
})->with([
    'general use' => [RecommendationIntendedUse::GeneralUse, 'Keyboards', 'Home Use', 'intended_use_tag', 'Home Use'],
    'office use' => [RecommendationIntendedUse::OfficeWork, 'Keyboards', 'Office Use', 'intended_use_tag', 'Office Use'],
    'productivity' => [RecommendationIntendedUse::OfficeWork, 'Keyboards', 'Productivity', 'intended_use_tag', 'Productivity'],
    'gaming' => [RecommendationIntendedUse::Gaming, 'Graphics Cards', 'Gaming', 'intended_use_tag', 'Gaming'],
    'networking' => [RecommendationIntendedUse::NetworkingPisoWifi, 'Networking', null, 'intended_use_category', 'Networking'],
    'Piso WiFi parts' => [RecommendationIntendedUse::NetworkingPisoWifi, 'Vending & Coin-Op Machine Parts', null, 'intended_use_category', 'Vending & Coin-Op Machine Parts'],
    'content creation' => [RecommendationIntendedUse::ContentCreation, 'Graphics Cards', 'Content Creation', 'intended_use_tag', 'Content Creation'],
    'streaming' => [RecommendationIntendedUse::Streaming, 'Graphics Cards', 'Streaming', 'intended_use_tag', 'Streaming'],
    'security category' => [RecommendationIntendedUse::HomeSecurity, 'CCTV & Security', null, 'intended_use_category', 'CCTV & Security'],
    'security tag' => [RecommendationIntendedUse::HomeSecurity, 'Networking', 'Home Security', 'intended_use_tag', 'Home Security'],
    'business' => [RecommendationIntendedUse::BusinessEnterprise, 'Networking', 'Business/Enterprise', 'intended_use_tag', 'Business/Enterprise'],
]);

test('preferred brands match the entire normalized brand rather than a substring', function () {
    $category = Category::factory()->create(['name' => 'Networking']);
    $matching = createRecommendationTestProduct($category, [], '50.00', 3, brand: ' TP-LINK ');
    createRecommendationTestProduct($category, [], '50.00', 3, brand: 'TP-LINK Accessories');

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria([
        'intended_use' => RecommendationIntendedUse::NetworkingPisoWifi,
        'preferred_brand' => ' tp-link ',
    ]));

    expect($results->pluck('product.id')->all())->toBe([$matching->id]);
});

test('an unknown preferred brand returns no matches', function () {
    $category = Category::factory()->create(['name' => 'Networking']);
    createRecommendationTestProduct($category, [], '50.00', 3, brand: 'TP-LINK');
    createRecommendationTestProduct($category, [], '50.00', 3);

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria([
        'intended_use' => RecommendationIntendedUse::NetworkingPisoWifi,
        'preferred_brand' => 'Unknown Brand',
    ]));

    expect($results)->toBeEmpty();
});

test('preferred tags cannot replace missing intended-use signals', function () {
    $category = Category::factory()->create(['name' => 'Networking']);
    $preferred = Tag::factory()->create(['name' => 'Budget-Friendly']);
    createRecommendationTestProduct($category, [$preferred->id], '50.00', 3);

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria(['tag_ids' => [$preferred->id]]));

    expect($results)->toBeEmpty();
});

test('ranking prioritizes intended use then preferred tags then effective price then ID with stable evidence', function () {
    $category = Category::factory()->create(['name' => 'Keyboards']);
    $office = Tag::factory()->create(['name' => 'Office Use']);
    $productivity = Tag::factory()->create(['name' => 'Productivity']);
    $preferred = Tag::factory()->create(['name' => 'Budget-Friendly']);
    $cheapest = createRecommendationTestProduct($category, [$office->id], '10.00', 3);
    $preferredFirst = createRecommendationTestProduct($category, [$preferred->id, $office->id], '70.00', 3);
    $preferredSecond = createRecommendationTestProduct($category, [$office->id, $preferred->id], '70.00', 3);
    $discounted = createRecommendationTestProduct($category, [$office->id, $preferred->id], '90.00', 3, discountPrice: '60.00');
    $strongestUse = createRecommendationTestProduct($category, [$productivity->id, $office->id], '100.00', 3);
    $engine = app(RecommendationEngine::class);
    $criteria = recommendationTestCriteria([
        'intended_use' => RecommendationIntendedUse::OfficeWork,
        'tag_ids' => [$preferred->id],
    ]);

    $first = $engine->recommend($criteria);
    $second = $engine->recommend($criteria);

    $expected = [$strongestUse->id, $discounted->id, $preferredFirst->id, $preferredSecond->id, $cheapest->id];
    expect($first->pluck('product.id')->all())->toBe($expected);
    expect($second->pluck('product.id')->all())->toBe($expected);
    expect($first[0]->reasons)->toBe([
        ['code' => 'within_budget', 'value' => '100.00'],
        ['code' => 'sagay_stock', 'value' => 'available'],
        ['code' => 'intended_use_tag', 'value' => 'Office Use'],
        ['code' => 'intended_use_tag', 'value' => 'Productivity'],
    ]);
    expect($second->pluck('reasons')->all())->toBe($first->pluck('reasons')->all());
});

test('reusing the engine reflects stock depletion and restocking', function () {
    $category = Category::factory()->create(['name' => 'Networking']);
    $product = createRecommendationTestProduct($category, [], '50.00', 3);
    $engine = app(RecommendationEngine::class);
    $criteria = recommendationTestCriteria(['intended_use' => RecommendationIntendedUse::NetworkingPisoWifi]);

    expect($engine->recommend($criteria)->pluck('product.id')->all())->toBe([$product->id]);

    $product->inventory()->update(['quantity' => 0]);

    expect($engine->recommend($criteria))->toBeEmpty();

    $product->inventory()->update(['quantity' => 1]);

    $restocked = $engine->recommend($criteria);
    expect($restocked->pluck('product.id')->all())->toBe([$product->id]);
    expect($restocked[0]->product->inventory->quantity)->toBe(1);
});

test('budget applies to individual products without requiring a compatible build or combined total', function () {
    $category = Category::factory()->create(['name' => 'Networking']);
    $first = createRecommendationTestProduct($category, [], '70.00', 3);
    $second = createRecommendationTestProduct($category, [], '80.00', 3);

    $results = app(RecommendationEngine::class)->recommend(recommendationTestCriteria([
        'intended_use' => RecommendationIntendedUse::NetworkingPisoWifi,
    ]));

    expect($results->pluck('product.id')->all())->toBe([$first->id, $second->id]);
    expect($results->pluck('effectivePrice')->all())->toBe(['70.00', '80.00']);
});

arch('recommendation dependencies remain independent of chatbot and AI services')
    ->expect([
        'App\Services\Recommendation',
        RecommendationController::class,
        RecommendationInputRequest::class,
        RecommendationIntendedUse::class,
        ProductCatalogRepository::class,
        CatalogProductPresenter::class,
    ])
    ->not->toUse([
        'App\Actions\Chatbot',
        'App\Services\Chatbot',
        'App\Ai',
        'Laravel\Ai',
        Http::class,
    ]);
