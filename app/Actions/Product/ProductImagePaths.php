<?php

namespace App\Actions\Product;

class ProductImagePaths
{
    public static function isAdminOwned(?string $path, ?int $productId = null): bool
    {
        if ($path === null) {
            return false;
        }

        if (preg_match('~^products/admin/([1-9][0-9]*)/[a-f0-9]{64}\.webp$~D', $path, $matches)) {
            return $productId === null || (int) $matches[1] === $productId;
        }

        return preg_match('~^products/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)$~D', $path) === 1;
    }

    public static function admin(int $productId, string $bytes): string
    {
        return 'products/admin/'.$productId.'/'.hash('sha256', $bytes).'.webp';
    }
}
