<?php

use App\Models\Category;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearch;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;

test('disabled provider requests database fallback', function () {
    config(['scout.driver' => null]);

    expect(app(CatalogSearch::class)->matchingIds('processor'))->toBeNull();
});

test('complete provider matches return product ids', function (array $hits, array $expected) {
    config(['scout.driver' => 'algolia']);
    $engine = Mockery::mock(Engine::class);
    $engine->shouldReceive('search')->once()->withArgs(fn ($builder): bool => $builder->query === 'procssor'
        && $builder->options['hitsPerPage'] === 1000
        && $builder->options['attributesToRetrieve'] === ['objectID']
        && $builder->options['advancedSyntax'] === true
        && $builder->options['advancedSyntaxFeatures'] === ['exactPhrase'])
        ->andReturn(['nbHits' => count($hits), 'exhaustiveNbHits' => true, 'hits' => $hits]);
    $manager = Mockery::mock(EngineManager::class);
    $manager->shouldReceive('engine')->andReturn($engine);
    app()->instance(EngineManager::class, $manager);

    expect(app(CatalogSearch::class)->matchingIds('procssor'))->toBe($expected);
})->with([
    'matches' => [[['objectID' => '42'], ['objectID' => 7]], [42, 7]],
    'no matches' => [[], []],
]);

test('unreliable provider results request database fallback', function (array $result) {
    config(['scout.driver' => 'algolia']);
    $engine = Mockery::mock(Engine::class);
    $engine->shouldReceive('search')->once()->andReturn($result);
    $manager = Mockery::mock(EngineManager::class);
    $manager->shouldReceive('engine')->andReturn($engine);
    app()->instance(EngineManager::class, $manager);

    expect(app(CatalogSearch::class)->matchingIds('processor'))->toBeNull();
})->with([
    'too many' => [['nbHits' => 1001, 'exhaustiveNbHits' => true, 'hits' => []]],
    'partial' => [['nbHits' => 2, 'exhaustiveNbHits' => true, 'hits' => [['objectID' => 1]]]],
    'estimated total' => [['nbHits' => 0, 'exhaustiveNbHits' => false, 'hits' => []]],
    'invalid id' => [['nbHits' => 1, 'exhaustiveNbHits' => true, 'hits' => [['objectID' => 'invalid']]]],
    'duplicate ids' => [['nbHits' => 2, 'exhaustiveNbHits' => true, 'hits' => [['objectID' => 1], ['objectID' => 1]]]],
]);

test('provider exceptions request database fallback without logging secrets', function () {
    config(['scout.driver' => 'algolia']);
    $engine = Mockery::mock(Engine::class);
    $engine->shouldReceive('search')->once()->andThrow(new RuntimeException('private provider diagnostic'));
    $manager = Mockery::mock(EngineManager::class);
    $manager->shouldReceive('engine')->andReturn($engine);
    app()->instance(EngineManager::class, $manager);
    Log::shouldReceive('warning')->once()->with('Catalog search used database fallback.', Mockery::on(fn (array $context): bool => $context['reason'] === 'provider_failure'
        && is_float($context['duration_ms'])));

    expect(app(CatalogSearch::class)->matchingIds('processor'))->toBeNull();
});

test('search documents expose only public catalog text', function () {
    $category = Category::factory()->create(['name' => 'Processor']);
    $product = new Product;
    $product->forceFill(['id' => 42, 'product_code' => 'CPU42', 'name' => 'Processor', 'category_id' => $category->id, 'brand' => 'Brand', 'description' => '<p>Fast</p>', 'acquisition_cost' => '100.00']);

    expect($product->toSearchableArray())->toBe([
        'id' => 42, 'product_code' => 'CPU42', 'name' => 'Processor', 'brand' => 'Brand', 'category' => 'Processor', 'description' => 'Fast',
    ]);
});

test('search documents include the current public category label', function () {
    $product = Product::factory()->for(Category::factory()->create(['name' => 'Graphics Card']))->create();

    expect($product->toSearchableArray()['category'])->toBe('Graphics Card');
});
