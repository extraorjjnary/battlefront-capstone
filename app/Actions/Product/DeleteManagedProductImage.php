<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteManagedProductImage
{
    /**
     * Delete only product images managed by the upload workflow.
     */
    public function execute(?string $path, int $productId): void
    {
        if ($path === null || ! ProductImagePaths::isAdminOwned($path, $productId)) {
            return;
        }

        DB::transaction(function () use ($path, $productId): void {
            Product::query()->whereKey($productId)->lockForUpdate()->first();
            if (Product::query()->where('image_path', $path)->exists()) {
                return;
            }
            if (! Storage::disk('public')->delete($path)) {
                report(new \RuntimeException("Obsolete product image could not be removed: $path"));
            }
        });
    }
}
