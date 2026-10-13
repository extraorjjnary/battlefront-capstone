<?php

namespace App\Services;

use App\Enums\ShippingProfile;
use App\Models\Product;
use Illuminate\Support\Facades\File;

class CatalogShippingClassification
{
    public const VERSION = 'catalog-shipping-v1';

    /** @var array<string, array{product_code: string, name: string, category: string, profile: string|null, reason: string, expected_profile: string, review: string}>|null */
    private ?array $mapping = null;

    /** @return array{profile: string|null, reason: string, review: string}|null */
    public function suggestion(Product $product): ?array
    {
        if ($this->mapping === null) {
            $data = json_decode(File::get(resource_path('data/catalog-shipping-profiles.v1.json')), true, flags: JSON_THROW_ON_ERROR);
            $this->mapping = array_column($data['products'], null, 'product_code');
        }
        $row = $this->mapping[$product->product_code] ?? null;
        if ($row === null || $row['name'] !== $product->name || $row['category'] !== $product->category->name) {
            return null;
        }

        return ['profile' => $row['profile'], 'reason' => $row['reason'], 'review' => $row['review']];
    }

    public function apply(Product $product): bool
    {
        $suggestion = $this->suggestion($product);
        if (! $product->is_catalog_imported || $product->shipping_profile_source !== null || $product->shipping_profile !== ShippingProfile::Standard || $suggestion === null || $suggestion['profile'] === null) {
            return false;
        }
        $product->shipping_profile = ShippingProfile::from($suggestion['profile']);
        $product->shipping_profile_source = self::VERSION;
        $product->save();

        return true;
    }
}
