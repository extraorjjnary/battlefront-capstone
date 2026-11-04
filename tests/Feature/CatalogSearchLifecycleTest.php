<?php

use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use App\Jobs\SyncCatalogSearch;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Algolia4Engine;
use Laravel\Scout\Engines\Engine;

test('index jobs reload current text and remove deleted or missing records', function () {
    $product = Product::factory()->create(['name' => 'Queued old name']);
    $queuedSync = new SyncCatalogSearch([$product->id]);
    $product->update(['name' => 'Current name']);
    $deleted = Product::factory()->create();
    $deleted->delete();
    config(['scout.driver' => 'algolia']);
    $engine = Mockery::mock(Engine::class);
    $engine->shouldReceive('update')->once()->withArgs(fn ($models): bool => $models->count() === 1 && $models->first()->name === 'Current name');
    $engine->shouldReceive('update')->once()->withArgs(fn ($models): bool => $models->isEmpty());
    $engine->shouldReceive('delete')->once()->withArgs(fn ($models): bool => $models->isEmpty());
    $engine->shouldReceive('delete')->once()->withArgs(fn ($models): bool => $models->modelKeys() === [$deleted->id, 99999]);
    $manager = Mockery::mock(EngineManager::class);
    $manager->shouldReceive('engine')->andReturn($engine);
    app()->instance(EngineManager::class, $manager);

    $queuedSync->handle();
    (new SyncCatalogSearch([$deleted->id, 99999]))->handle();
});

test('committed product changes enqueue current-state synchronization', function () {
    Bus::fake([SyncCatalogSearch::class]);
    config(['scout.driver' => 'algolia']);
    $product = DB::transaction(fn () => Product::factory()->create());
    Bus::assertDispatched(SyncCatalogSearch::class, fn ($job): bool => $job->productIds === [$product->id] && $job->afterCommit);
    Bus::fake([SyncCatalogSearch::class]);
    DB::transaction(fn () => $product->update(['name' => 'Changed']));
    Bus::assertDispatched(SyncCatalogSearch::class);
    Bus::fake([SyncCatalogSearch::class]);
    DB::transaction(fn () => $product->delete());
    Bus::assertDispatched(SyncCatalogSearch::class);
    Bus::fake([SyncCatalogSearch::class]);
    DB::transaction(fn () => $product->restore());
    Bus::assertDispatched(SyncCatalogSearch::class);
    Bus::fake([SyncCatalogSearch::class]);
    DB::transaction(fn () => $product->forceDelete());
    Bus::assertDispatched(SyncCatalogSearch::class);
});

test('category renames reindex attached products only when Algolia is enabled', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();
    Bus::fake([SyncCatalogSearch::class]);
    config(['scout.driver' => 'algolia']);

    $category->update(['name' => 'Renamed category']);

    Bus::assertDispatched(SyncCatalogSearch::class, fn ($job): bool => $job->productIds === [$product->id]);
});

test('rolled back changes never enqueue indexing', function () {
    Bus::fake([SyncCatalogSearch::class]);
    config(['scout.driver' => 'algolia']);
    try {
        DB::transaction(function (): void {
            Product::factory()->create();
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }
    Bus::assertNotDispatched(SyncCatalogSearch::class);
});

test('failed indexing retries log only batch size without product text or provider errors', function () {
    Log::shouldReceive('error')->once()->with('Catalog indexing job failed after retries.', ['product_count' => 2]);

    (new SyncCatalogSearch([1, 2]))->failed(new RuntimeException('private provider detail'));
});

test('disabled search refuses reconciliation', function () {
    $this->artisan('catalog:reconcile-search')->assertFailed();
});

test('index enqueue failures preserve committed product and category changes', function (bool $categoryChange, bool $loggingFails) {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();
    config(['scout.driver' => 'algolia']);
    Bus::shouldReceive('dispatch')->once()->with(Mockery::type(SyncCatalogSearch::class))
        ->andThrow(new RuntimeException('private queue transport detail'));
    $diagnostic = Log::shouldReceive('error')->once()->with(
        'Catalog indexing could not be queued. Use SQL search until catalog reconciliation succeeds.',
        ['product_ids' => [$product->id], 'product_count' => 1],
    );
    if ($loggingFails) {
        $diagnostic->andThrow(new RuntimeException('private logging transport detail'));
    }

    DB::transaction(fn () => ($categoryChange ? $category : $product)->update(['name' => 'Committed catalog text']));

    $this->assertDatabaseHas($categoryChange ? 'categories' : 'products', [
        'id' => $categoryChange ? $category->id : $product->id,
        'name' => 'Committed catalog text',
    ]);
})->with([false, true])->with([false, true]);

test('reconciliation resynchronizes current products and removes stale external ids', function () {
    $product = Product::factory()->create();
    $deleted = Product::factory()->create();
    $deleted->delete();
    Bus::fake([SyncCatalogSearch::class]);
    config(['scout.driver' => 'algolia']);
    $client = Mockery::mock(AlgoliaSearchClient::class);
    $client->shouldReceive('browseObjects')->once()->andReturn([
        ['objectID' => (string) $product->id], ['objectID' => (string) $deleted->id], ['objectID' => '99999'],
    ]);
    $client->shouldReceive('saveSynonyms')->once()->with($product->indexableAs(), config('scout.algolia.synonyms'), false, false);
    $engine = new Algolia4Engine($client);
    $manager = Mockery::mock(EngineManager::class);
    $manager->shouldReceive('engine')->andReturn($engine);
    app()->instance(EngineManager::class, $manager);

    $this->artisan('catalog:reconcile-search')->assertSuccessful();
    Bus::assertDispatchedSync(SyncCatalogSearch::class, fn ($job): bool => $job->productIds === [$product->id]);
    Bus::assertDispatchedSync(SyncCatalogSearch::class, fn ($job): bool => $job->productIds === [$deleted->id, 99999]);
});

test('a delayed deletion job reindexes a product restored before execution', function () {
    $product = Product::factory()->create();
    $product->delete();
    $product->restore();
    config(['scout.driver' => 'algolia']);
    $engine = Mockery::mock(Engine::class);
    $engine->shouldReceive('update')->once()->withArgs(fn ($models): bool => $models->modelKeys() === [$product->id]);
    $engine->shouldReceive('delete')->once()->withArgs(fn ($models): bool => $models->isEmpty());
    $manager = Mockery::mock(EngineManager::class);
    $manager->shouldReceive('engine')->andReturn($engine);
    app()->instance(EngineManager::class, $manager);

    (new SyncCatalogSearch([$product->id]))->handle();
});

test('reconciliation initializes a missing index with current products', function () {
    $product = Product::factory()->create();
    Bus::fake([SyncCatalogSearch::class]);
    config(['scout.driver' => 'algolia']);
    $client = Mockery::mock(AlgoliaSearchClient::class);
    $client->shouldReceive('browseObjects')->once()->andThrow(new NotFoundException('Index does not exist', 404));
    $client->shouldReceive('saveSynonyms')->once()->with($product->indexableAs(), config('scout.algolia.synonyms'), false, false);
    $engine = new Algolia4Engine($client);
    $manager = Mockery::mock(EngineManager::class);
    $manager->shouldReceive('engine')->andReturn($engine);
    app()->instance(EngineManager::class, $manager);

    $this->artisan('catalog:reconcile-search')->assertSuccessful();
    Bus::assertDispatchedSync(SyncCatalogSearch::class, fn ($job): bool => $job->productIds === [$product->id]);
});
