<?php

namespace App\Actions\Product;

use Illuminate\Support\Facades\Storage;

class DeleteManagedProductImage
{
    /**
     * Delete only product images managed by the upload workflow.
     */
    public function execute(?string $path): void
    {
        if ($path !== null && str_starts_with($path, 'products/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
