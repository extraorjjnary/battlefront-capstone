<?php

namespace App\Actions\Product;

use GdImage;
use RuntimeException;

class OptimizeGeneratedProductImage
{
    /**
     * Encode one validated square source as a catalog-ready WebP.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    public function execute(string $sourcePath): array
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException('The generated source image is not readable.');
        }

        $details = @getimagesize($sourcePath);
        if ($details === false || ! in_array($details['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new RuntimeException('The generated source must be a PNG, JPEG, or WebP image.');
        }

        [$sourceWidth, $sourceHeight] = $details;
        if ($sourceWidth !== $sourceHeight || $sourceWidth < 1024) {
            throw new RuntimeException('The generated source must be square and at least 1024 pixels wide.');
        }

        $sourceBytes = file_get_contents($sourcePath);
        $source = $sourceBytes === false ? false : @imagecreatefromstring($sourceBytes);
        if (! $source instanceof GdImage) {
            throw new RuntimeException('The generated source image could not be decoded.');
        }

        $target = imagecreatetruecolor(1024, 1024);
        if (! $target instanceof GdImage) {
            throw new RuntimeException('The optimized image canvas could not be created.');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, 1024, 1024, $sourceWidth, $sourceHeight);

        foreach ([90, 86, 82, 78, 74, 70] as $quality) {
            ob_start();
            $encoded = imagewebp($target, null, $quality);
            $bytes = ob_get_clean();

            if (! $encoded || ! is_string($bytes) || $bytes === '') {
                throw new RuntimeException('The generated source could not be encoded as WebP.');
            }

            if (strlen($bytes) <= 819200) {
                return ['bytes' => $bytes, 'width' => 1024, 'height' => 1024];
            }
        }

        throw new RuntimeException('The optimized WebP exceeds 0.8 MB without acceptable compression.');
    }
}
