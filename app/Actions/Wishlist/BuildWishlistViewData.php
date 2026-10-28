<?php

namespace App\Actions\Wishlist;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\CatalogProductPresenter;
use App\Services\Wishlist\WishlistService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @phpstan-import-type CatalogProductData from CatalogProductPresenter
 *
 * @phpstan-type WishlistViewItem array{id:int, product_id:int, is_available:bool, can_add_to_cart:bool, is_in_cart:bool, product:CatalogProductData|null}
 */
class BuildWishlistViewData
{
    public function __construct(
        private readonly WishlistService $wishlistService,
        private readonly CatalogProductPresenter $catalogProductPresenter,
        private readonly Request $request,
    ) {}

    /** @return array{items: LengthAwarePaginator<int, covariant WishlistViewItem>, count: int} */
    public function execute(User $customer): array
    {
        $query = $this->wishlistService->customerItems($customer)
            ->with([
                'product.category',
                'product.tags',
                'product.inventory',
                'product.lowStockInventory',
            ])
            ->orderByDesc('id');
        $count = $this->wishlistService->count($customer);
        $lastPage = max(1, (int) ceil($count / 12));
        $page = min(max(1, $this->request->integer('page', 1)), $lastPage);
        $items = $query->paginate(12, ['*'], 'page', $page);
        $cartProductIds = CartItem::query()
            ->whereHas('cart', fn ($query) => $query->where('user_id', $customer->id))
            ->whereIn('product_id', $items->getCollection()->pluck('product_id'))
            ->distinct()
            ->pluck('product_id')
            ->all();
        $items->through(
            /** @return WishlistViewItem */
            fn (WishlistItem $item): array => $this->presentItem($item, in_array($item->product_id, $cartProductIds, true)),
        );

        return [
            'items' => $items,
            'count' => $count,
        ];
    }

    /** @return WishlistViewItem */
    private function presentItem(WishlistItem $item, bool $isInCart): array
    {
        $product = $item->product;
        $category = $product?->getRelationValue('category');
        $isAvailable = $product instanceof Product
            && ! $product->trashed()
            && $product->is_active
            && $category instanceof Category
            && ! $category->trashed()
            && $category->is_active;

        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'is_in_cart' => $isInCart,
            'is_available' => $isAvailable,
            'can_add_to_cart' => $isAvailable && (int) $product->inventory?->quantity > 0,
            'product' => $isAvailable
                ? $this->catalogProductPresenter->present($product)
                : null,
        ];
    }
}
