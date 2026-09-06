<?php

use App\Models\Product;
use App\Models\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('the tag schemas follow the approved ERD decisions', function () {
    expect(Schema::getColumnListing('tags'))->toEqualCanonicalizing([
        'id',
        'name',
    ])->and(Schema::getColumnListing('product_tag'))->toEqualCanonicalizing([
        'id',
        'product_id',
        'tag_id',
    ]);
});

test('a tag persists without timestamps', function () {
    $tag = Tag::factory()->create(['name' => 'Gaming']);

    $this->assertModelExists($tag);
    expect($tag->name)->toBe('Gaming')
        ->and($tag->timestamps)->toBeFalse();
});

test('tag names are required and unique', function () {
    Tag::factory()->create(['name' => 'Wireless']);

    expect(fn () => Tag::query()->create([]))->toThrow(QueryException::class)
        ->and(fn () => Tag::factory()->create(['name' => 'Wireless']))->toThrow(QueryException::class);
});

test('products and tags expose reciprocal relationships', function () {
    $product = Product::factory()->create();
    $tag = Tag::factory()->create();

    $product->tags()->attach($tag);

    expect($product->tags->sole()->is($tag))->toBeTrue()
        ->and($tag->products->sole()->is($product))->toBeTrue();
});

test('attaching a validated tag repeatedly does not duplicate the pivot', function () {
    $product = Product::factory()->create();
    $tag = Tag::factory()->create();

    $product->tags()->syncWithoutDetaching([$tag->id]);
    $product->tags()->syncWithoutDetaching([$tag->id]);

    $this->assertDatabaseCount('product_tag', 1);
    $this->assertDatabaseHas('product_tag', [
        'product_id' => $product->id,
        'tag_id' => $tag->id,
    ]);
});

test('duplicate pivot entries are rejected by the database', function () {
    $product = Product::factory()->create();
    $tag = Tag::factory()->create();
    $product->tags()->attach($tag);

    expect(fn () => $product->tags()->attach($tag))->toThrow(QueryException::class);
});

test('a product cannot attach a tag that does not exist', function () {
    $product = Product::factory()->create();

    expect(fn () => $product->tags()->attach(PHP_INT_MAX))->toThrow(QueryException::class);
});

test('detaching a tag preserves the product and tag', function () {
    $product = Product::factory()->create();
    $tag = Tag::factory()->create();
    $product->tags()->attach($tag);

    $product->tags()->detach($tag);

    $this->assertDatabaseMissing('product_tag', [
        'product_id' => $product->id,
        'tag_id' => $tag->id,
    ]);
    $this->assertModelExists($product);
    $this->assertModelExists($tag);
});

test('deleting a tag removes its owned pivot rows', function () {
    $product = Product::factory()->create();
    $tag = Tag::factory()->create();
    $product->tags()->attach($tag);

    $tag->delete();

    $this->assertDatabaseMissing('product_tag', [
        'product_id' => $product->id,
        'tag_id' => $tag->id,
    ]);
    $this->assertModelExists($product);
});

test('deleting a product removes its owned pivot rows', function () {
    $product = Product::factory()->create();
    $tag = Tag::factory()->create();
    $product->tags()->attach($tag);

    $product->delete();

    $this->assertDatabaseMissing('product_tag', [
        'product_id' => $product->id,
        'tag_id' => $tag->id,
    ]);
    $this->assertModelExists($tag);
});
