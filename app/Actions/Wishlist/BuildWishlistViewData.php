<?php

namespace App\Actions\Wishlist;

use App\Models\User;
use App\Services\CatalogProductPresenter;
use App\Services\Wishlist\WishlistService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class BuildWishlistViewData
{
    public function __construct(
        private readonly WishlistService $wishlistService,
        private readonly CatalogProductPresenter $catalogProductPresenter,
        private readonly Request $request,
    ) {}

    /** @return array{items: LengthAwarePaginator<int, array<string, mixed>>, count: int} */
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
        $items = $query->paginate(12, ['*'], 'page', $page)
            ->through(function ($item): array {
                $product = $item->product;
                $isAvailable = $product !== null
                    && ! $product->trashed()
                    && $product->is_active
                    && ! $product->category?->trashed()
                    && (bool) $product->category?->is_active;

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'is_available' => $isAvailable,
                    'can_add_to_cart' => $isAvailable && (int) $product->inventory?->quantity > 0,
                    'product' => $isAvailable
                        ? $this->catalogProductPresenter->present($product)
                        : null,
                ];
            });

        return [
            'items' => $items,
            'count' => $count,
        ];
    }
}
