<?php

namespace App\Services\Wishlist;

use App\Enums\UserRole;
use App\Enums\WishlistActivityType;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\WishlistActivity;
use App\Models\WishlistItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WishlistService
{
    public function count(User $customer): int
    {
        $this->ensureCustomer($customer);

        return (int) WishlistItem::query()->where('user_id', $customer->id)->count();
    }

    /** @param list<int> $productIds
     * @return list<int>
     */
    public function savedProductIds(User $customer, array $productIds): array
    {
        $this->ensureCustomer($customer);

        if ($productIds === []) {
            return [];
        }

        return array_values(WishlistItem::query()
            ->where('user_id', $customer->id)
            ->whereIn('product_id', array_slice(array_unique($productIds), 0, 48))
            ->orderBy('product_id')
            ->pluck('product_id')
            ->map(static fn (int $id): int => $id)
            ->all());
    }

    public function add(User $customer, int $productId): bool
    {
        return DB::transaction(function () use ($customer, $productId): bool {
            $customer = User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $this->ensureCustomer($customer);

            if (WishlistItem::query()->where('user_id', $customer->id)->where('product_id', $productId)->exists()) {
                return false;
            }

            $product = Product::query()->with('category')->find($productId);

            $category = $product?->getRelationValue('category');
            if ($product === null || ! $product->is_active || ! $category instanceof Category || $category->trashed() || ! $category->is_active) {
                throw ValidationException::withMessages(['product_id' => __('This product cannot be saved right now.')]);
            }

            $item = WishlistItem::query()->create([
                'user_id' => $customer->id,
                'product_id' => $product->id,
                'save_cycle_id' => (string) Str::uuid(),
            ]);

            WishlistActivity::query()->create([
                'save_cycle_id' => $item->save_cycle_id,
                'product_id' => $product->id,
                'event_type' => WishlistActivityType::Added,
                'occurred_at' => now('UTC'),
            ]);

            return true;
        }, attempts: 3);
    }

    public function remove(User $customer, int $productId): bool
    {
        return DB::transaction(function () use ($customer, $productId): bool {
            $customer = User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $this->ensureCustomer($customer);
            $item = WishlistItem::query()
                ->where('user_id', $customer->id)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                return false;
            }

            WishlistActivity::query()->create([
                'save_cycle_id' => $item->save_cycle_id,
                'product_id' => $productId,
                'event_type' => WishlistActivityType::Removed,
                'occurred_at' => now('UTC'),
            ]);

            $item->delete();

            return true;
        }, attempts: 3);
    }

    /** @return Builder<WishlistItem> */
    public function customerItems(User $customer): Builder
    {
        $this->ensureCustomer($customer);

        return WishlistItem::query()->where('user_id', $customer->id);
    }

    private function ensureCustomer(User $customer): void
    {
        if (! $customer->is_active || $customer->role !== UserRole::Customer) {
            throw new AuthorizationException('Wishlist access is limited to active customers.');
        }
    }
}
