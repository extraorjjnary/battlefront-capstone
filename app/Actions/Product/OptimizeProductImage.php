<?php

namespace App\Actions\Product;

use GdImage;
use RuntimeException;

class OptimizeProductImage
{
    /**
     * Center-crop a product image and encode a 1024-pixel square WebP.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    public function execute(string $sourcePath): array
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException('The product image is not readable.');
        }

        $details = @getimagesize($sourcePath);
        if ($details === false || ! in_array($details['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new RuntimeException('The product image must be a PNG, JPEG, or WebP image.');
        }

        [$sourceWidth, $sourceHeight] = $details;
        if ($sourceWidth < 1 || $sourceHeight < 1 || $sourceWidth * $sourceHeight > 40000000) {
            throw new RuntimeException('The product image must contain between 1 and 40 million pixels.');
        }

        $source = match ($details['mime']) {
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
        };
        if (! $source instanceof GdImage) {
            throw new RuntimeException('The product image could not be decoded.');
        }

        $target = imagecreatetruecolor(1024, 1024);
        if (! $target instanceof GdImage) {
            throw new RuntimeException('The optimized image canvas could not be created.');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $cropSize = min($sourceWidth, $sourceHeight);
        if (! imagecopyresampled(
            $target, $source, 0, 0,
            intdiv($sourceWidth - $cropSize, 2), intdiv($sourceHeight - $cropSize, 2),
            1024, 1024, $cropSize, $cropSize,
        )) {
            throw new RuntimeException('The product image could not be resized.');
        }

        unset($source);

        $output = tmpfile();
        if ($output === false) {
            throw new RuntimeException('A temporary product image file could not be created.');
        }

        try {
            foreach ([90, 86, 82, 78, 74, 70] as $quality) {
                if (! rewind($output) || ! ftruncate($output, 0) || ! imagewebp($target, $output, $quality)) {
                    throw new RuntimeException('The product image could not be encoded as WebP.');
                }

                $size = ftell($output);
                if ($size === false || $size === 0) {
                    throw new RuntimeException('The product image could not be encoded as WebP.');
                }

                if ($size <= 819200) {
                    rewind($output);
                    $bytes = stream_get_contents($output);
                    if (! is_string($bytes) || strlen($bytes) !== $size) {
                        throw new RuntimeException('The optimized product image could not be read.');
                    }

                    return ['bytes' => $bytes, 'width' => 1024, 'height' => 1024];
                }
            }

            throw new RuntimeException('The optimized WebP exceeds 0.8 MB without acceptable compression.');
        } finally {
            fclose($output);
        }
    }
}
