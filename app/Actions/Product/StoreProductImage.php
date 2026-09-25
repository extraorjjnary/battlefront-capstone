<?php

namespace App\Actions\Product;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StoreProductImage
{
    public function __construct(private readonly OptimizeProductImage $optimizer) {}

    /**
     * Store optimized bytes without overwriting a previous image.
     *
     * @return array{path: string, created: bool}|null
     */
    public function execute(?UploadedFile $image, int $productId): ?array
    {
        if ($image === null) {
            return null;
        }

        try {
            $optimized = $this->optimizer->execute($image->getPathname());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['image' => $exception->getMessage()]);
        }

        $bytes = $optimized['bytes'];
        $path = ProductImagePaths::admin($productId, $bytes);
        $disk = Storage::disk('public');
        if ($disk->exists($path)) {
            if ($disk->get($path) !== $bytes) {
                throw new RuntimeException('The existing product image is damaged.');
            }

            return ['path' => $path, 'created' => false];
        }

        try {
            if (! $disk->put($path, $bytes, 'public')) {
                throw new RuntimeException('The product image could not be stored.');
            }
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return ['path' => $path, 'created' => true];
    }
}
