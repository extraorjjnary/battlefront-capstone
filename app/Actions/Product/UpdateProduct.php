<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class UpdateProduct
{
    public function __construct(
        private readonly StoreProductImage $storeProductImage,
        private readonly DeleteManagedProductImage $deleteManagedProductImage,
    ) {}

    /**
     * Update a product, synchronize tags, and replace its image safely.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $tagIds
     */
    public function execute(Product $product, array $attributes, array $tagIds, ?UploadedFile $image): Product
    {
        $oldImagePath = $product->image_path;
        $newImagePath = $this->storeProductImage->execute($image);

        if ($newImagePath !== null) {
            $attributes['image_path'] = $newImagePath;
        }

        try {
            DB::transaction(function () use ($product, $attributes, $tagIds): void {
                $product->update($attributes);
                $product->tags()->sync($tagIds);
            });
        } catch (Throwable $exception) {
            $this->deleteManagedProductImage->execute($newImagePath);

            throw $exception;
        }

        if ($newImagePath !== null) {
            $this->deleteManagedProductImage->execute($oldImagePath);
        }

        return $product->refresh();
    }
}
