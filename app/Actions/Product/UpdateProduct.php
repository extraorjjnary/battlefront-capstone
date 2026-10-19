<?php

namespace App\Actions\Product;

use App\Actions\Catalog\CatalogName;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        if (isset($attributes['name'])) {
            $attributes['name'] = CatalogName::normalize($attributes['name']);
        }
        $storedImage = null;

        try {
            DB::transaction(function () use ($product, $attributes, $tagIds, $image, &$storedImage): void {
                $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
                $expectedVersion = (int) $attributes['edit_version'];
                unset($attributes['edit_version']);
                if ($expectedVersion !== $lockedProduct->edit_version) {
                    throw ValidationException::withMessages(['edit_version' => 'This product changed while you were editing. Reload and review the latest values before saving.']);
                }
                $priceChanged = bccomp($this->numericAmount($attributes['price']), $this->numericAmount($lockedProduct->price), 2) !== 0
                    || (($attributes['discount_price'] ?? null) === null) !== ($lockedProduct->discount_price === null)
                    || (($attributes['discount_price'] ?? null) !== null && $lockedProduct->discount_price !== null
                        && bccomp($this->numericAmount($attributes['discount_price']), $this->numericAmount($lockedProduct->discount_price), 2) !== 0);
                $priceChangeReason = trim((string) ($attributes['price_change_reason'] ?? ''));
                unset($attributes['price_change_reason']);
                if ($priceChanged && mb_strlen($priceChangeReason) < 5) {
                    throw ValidationException::withMessages(['price_change_reason' => 'Explain the price change in at least five characters.']);
                }
                if ($lockedProduct->is_catalog_imported && isset($attributes['product_code'])
                    && $attributes['product_code'] !== $lockedProduct->product_code) {
                    throw ValidationException::withMessages(['product_code' => 'Imported product codes cannot be changed.']);
                }
                $oldImagePath = $lockedProduct->image_path;
                $storedImage = $this->storeProductImage->execute($image, $lockedProduct->id);
                if ($storedImage !== null) {
                    $attributes['image_path'] = $storedImage['path'];
                }
                $profileChanged = isset($attributes['shipping_profile']) && $lockedProduct->shipping_profile->value !== $attributes['shipping_profile'];
                unset($attributes['shipping_profile_reviewed']);
                $lockedProduct->fill($attributes);
                if ($profileChanged) {
                    $lockedProduct->shipping_profile_source = 'manual';
                }
                $changedAttributes = $lockedProduct->getDirty();
                $before = array_intersect_key($lockedProduct->getOriginal(), $changedAttributes);
                $lockedProduct->edit_version++;
                $lockedProduct->save();
                $lockedProduct->tags()->sync($tagIds);

                DB::table('audit_logs')->insert([
                    'actor_id' => auth()->id(),
                    'action' => 'product.updated',
                    'subject_type' => 'product',
                    'subject_id' => $lockedProduct->id,
                    'reason' => $priceChanged ? $priceChangeReason : null,
                    'changes' => json_encode(['before' => $before, 'after' => $changedAttributes, 'tag_ids' => $tagIds], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                if ($storedImage !== null && $storedImage['path'] !== $oldImagePath) {
                    DB::afterCommit(fn () => $this->deleteManagedProductImage->execute($oldImagePath, $lockedProduct->id));
                }
            });
        } catch (Throwable $exception) {
            if ($storedImage !== null && $storedImage['created']) {
                $this->deleteManagedProductImage->execute($storedImage['path'], $product->id);
            }

            if ($exception instanceof UniqueConstraintViolationException) {
                CatalogName::handleUniqueFailure($exception, 'products', CatalogName::PRODUCT_MESSAGE);
            }

            throw $exception;
        }

        return $product->refresh();
    }

    /** @return numeric-string */
    private function numericAmount(mixed $amount): string
    {
        if (! is_numeric($amount)) {
            throw ValidationException::withMessages(['price' => 'Enter a valid product price.']);
        }

        return (string) $amount;
    }
}
