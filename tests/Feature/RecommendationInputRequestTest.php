<?php

use App\Enums\RecommendationIntendedUse;
use App\Http\Requests\RecommendationInputRequest;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('/_test/recommendation-input', fn (RecommendationInputRequest $request) => response()->json($request->validatedCriteria()));
});

test('budget and intended use are required', function () {
    $this->postJson('/_test/recommendation-input', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['budget', 'intended_use'])
        ->assertJsonPath('errors.budget.0', 'Enter a budget.')
        ->assertJsonPath('errors.intended_use.0', 'Select an intended use.');
});

test('omitted optional preferences produce stable empty criteria', function () {
    $this->postJson('/_test/recommendation-input', [
        'budget' => '25000.00',
        'intended_use' => 'general_use',
        'inventory_quantity' => 999,
    ])->assertOk()->assertExactJson([
        'budget' => '25000.00',
        'intended_use' => 'general_use',
        'preferred_brand' => null,
        'category_id' => null,
        'tag_ids' => [],
    ]);
});

test('persisted category and tags and an optional brand become typed criteria', function () {
    $category = Category::factory()->create(['name' => 'Networking']);
    $firstTag = Tag::factory()->create(['name' => 'Home Use']);
    $secondTag = Tag::factory()->create(['name' => 'Business/Enterprise']);

    $this->postJson('/_test/recommendation-input', [
        'budget' => '3999.50',
        'intended_use' => 'networking_piso_wifi',
        'preferred_brand' => ' TP-LINK ',
        'category_id' => (string) $category->id,
        'tag_ids' => [(string) $firstTag->id, (string) $secondTag->id],
    ])->assertOk()->assertExactJson([
        'budget' => '3999.50',
        'intended_use' => 'networking_piso_wifi',
        'preferred_brand' => 'TP-LINK',
        'category_id' => $category->id,
        'tag_ids' => [$firstTag->id, $secondTag->id],
    ]);
});

test('an explicit null brand remains valid', function () {
    $this->postJson('/_test/recommendation-input', [
        'budget' => '1000',
        'intended_use' => 'office_work',
        'preferred_brand' => null,
        'category_id' => null,
        'tag_ids' => null,
    ])->assertOk()->assertExactJson([
        'budget' => '1000',
        'intended_use' => 'office_work',
        'preferred_brand' => null,
        'category_id' => null,
        'tag_ids' => [],
    ]);
});

test('budget rejects invalid numbers', function (mixed $budget) {
    $this->postJson('/_test/recommendation-input', [
        'budget' => $budget,
        'intended_use' => 'gaming',
    ])->assertUnprocessable()->assertJsonValidationErrors('budget');
})->with([
    'zero' => ['0'],
    'negative' => ['-1'],
    'too many decimal places' => ['100.123'],
    'non numeric' => ['not-a-budget'],
    'above price column capacity' => ['10000000000.00'],
]);

test('budget accepts its minimum and maximum boundaries', function (string $budget) {
    $this->postJson('/_test/recommendation-input', [
        'budget' => $budget,
        'intended_use' => 'gaming',
    ])->assertOk()->assertJsonPath('budget', $budget);
})->with([
    'minimum' => ['0.01'],
    'maximum' => ['9999999999.99'],
]);

test('category must exist and remain active', function () {
    $inactiveCategory = Category::factory()->inactive()->create();

    $this->postJson('/_test/recommendation-input', [
        'budget' => '500',
        'intended_use' => 'general_use',
        'category_id' => $inactiveCategory->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('category_id')
        ->assertJsonPath('errors.category_id.0', 'Select an available category.');
});

test('unknown category ID is rejected', function () {
    $category = Category::factory()->create();

    $this->postJson('/_test/recommendation-input', [
        'budget' => '500',
        'intended_use' => 'general_use',
        'category_id' => $category->id + 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
});

test('unknown tag ID is rejected', function () {
    $tag = Tag::factory()->create();

    $this->postJson('/_test/recommendation-input', [
        'budget' => '500',
        'intended_use' => 'general_use',
        'tag_ids' => [$tag->id + 1],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('tag_ids.0')
        ->assertJsonFragment(['tag_ids.0' => ['Select valid product tags.']]);
});

test('duplicate tag IDs are rejected', function () {
    $tag = Tag::factory()->create();

    $this->postJson('/_test/recommendation-input', [
        'budget' => '500',
        'intended_use' => 'general_use',
        'tag_ids' => [$tag->id, $tag->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('tag_ids.0')
        ->assertJsonFragment(['tag_ids.0' => ['Each tag may only be selected once.']]);
});

test('all intended uses map to current catalog category and tag names', function (string $value, array $categories, array $tags) {
    $this->postJson('/_test/recommendation-input', [
        'budget' => '500',
        'intended_use' => $value,
    ])->assertOk()->assertJsonPath('intended_use', $value);

    expect(RecommendationIntendedUse::from($value)->catalogSignals())->toBe([
        'categories' => $categories,
        'tags' => $tags,
    ]);
})->with([
    'general use' => ['general_use', [], ['Home Use']],
    'office work' => ['office_work', [], ['Office Use', 'Productivity']],
    'gaming' => ['gaming', [], ['Gaming']],
    'networking and Piso WiFi' => ['networking_piso_wifi', ['Networking', 'Vending & Coin-Op Machine Parts'], []],
    'content creation' => ['content_creation', [], ['Content Creation']],
    'streaming' => ['streaming', [], ['Streaming']],
    'home security' => ['home_security', ['CCTV & Security'], ['Home Security']],
    'business' => ['business_enterprise', [], ['Business/Enterprise']],
]);

test('unknown intended use is rejected', function () {
    $this->postJson('/_test/recommendation-input', [
        'budget' => '500',
        'intended_use' => 'unsupported',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('intended_use')
        ->assertJsonPath('errors.intended_use.0', 'Select a valid intended use.');
});
