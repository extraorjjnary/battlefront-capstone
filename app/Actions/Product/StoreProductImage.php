<?php

namespace App\Actions\Product;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class StoreProductImage
{
    /**
     * Store a validated product image on the public disk.
     */
    public function execute(?UploadedFile $image): ?string
    {
        if ($image === null) {
            return null;
        }

        $path = $image->storePublicly('products', 'public');

        if ($path === false) {
            throw new RuntimeException('The product image could not be stored.');
        }

        return $path;
    }
}
