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
        $storedImage = null;
        $productId = null;

        try {
            return DB::transaction(function () use ($attributes, $tagIds, $image, &$storedImage, &$productId): Product {
                $product = Product::query()->create($attributes);
                $productId = $product->id;
                $storedImage = $this->storeProductImage->execute($image, $productId);
                if ($storedImage !== null) {
                    $product->update(['image_path' => $storedImage['path']]);
                }
                $product->tags()->sync($tagIds);

                return $product;
            });
        } catch (Throwable $exception) {
            if ($storedImage !== null && $storedImage['created'] && $productId !== null) {
                $this->deleteManagedProductImage->execute($storedImage['path'], $productId);
            }

            throw $exception;
        }
    }
}
