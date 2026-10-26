<?php

namespace App\Http\Controllers;

use App\Actions\Wishlist\BuildWishlistViewData;
use App\Http\Requests\StoreWishlistItemRequest;
use App\Models\User;
use App\Services\Wishlist\WishlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WishlistController extends Controller
{
    public function index(Request $request, BuildWishlistViewData $buildWishlistViewData): Response
    {
        /** @var User $customer */
        $customer = $request->user();

        return Inertia::render('Wishlist/Index', [
            'wishlist' => $buildWishlistViewData->execute($customer),
        ]);
    }

    public function store(StoreWishlistItemRequest $request, WishlistService $wishlistService): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $productId = $request->integer('product_id');
        $added = $wishlistService->add($customer, $productId);

        Inertia::flash('wishlistMutation', [
            'product_id' => $productId,
            'is_wishlisted' => true,
        ]);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $added ? __('Product saved to your wishlist.') : __('Product is already in your wishlist.'),
        ]);

        return back();
    }

    public function destroy(Request $request, int $product, WishlistService $wishlistService): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $removed = $wishlistService->remove($customer, $product);

        Inertia::flash('wishlistMutation', [
            'product_id' => $product,
            'is_wishlisted' => false,
        ]);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $removed ? __('Product removed from your wishlist.') : __('Product is not in your wishlist.'),
        ]);

        return back();
    }
}
