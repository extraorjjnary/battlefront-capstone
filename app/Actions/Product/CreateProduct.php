<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateProduct
{
    public function __construct(
        private readonly StoreProductImage $storeProductImage,
        private readonly DeleteManagedProductImage $deleteManagedProductImage,
    ) {}

    /**
     * Create a product, attach tags, and manage its uploaded image.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $tagIds
     */
    public function execute(array $attributes, array $tagIds, ?UploadedFile $image): Product
    {
        $imagePath = $this->storeProductImage->execute($image);
        $attributes['image_path'] = $imagePath;

        try {
            return DB::transaction(function () use ($attributes, $tagIds): Product {
                $product = Product::query()->create($attributes);
                $product->tags()->sync($tagIds);

                return $product;
            });
        } catch (Throwable $exception) {
            $this->deleteManagedProductImage->execute($imagePath);

            throw $exception;
        }
    }
}
