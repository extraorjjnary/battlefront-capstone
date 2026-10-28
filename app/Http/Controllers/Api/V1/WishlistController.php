<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Wishlist\BuildWishlistViewData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWishlistItemRequest;
use App\Models\User;
use App\Services\Wishlist\WishlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class WishlistController extends Controller
{
    public function index(Request $request, BuildWishlistViewData $build): JsonResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $view = $build->execute($customer);
        /** @var LengthAwarePaginator<int, array<string, mixed>> $items */
        $items = $view['items'];
        $payload = JsonResource::collection($items)->response()->getData(true);

        return response()->json(['data' => $payload['data'], 'links' => $payload['links'], 'meta' => $payload['meta'], 'count' => $view['count']]);
    }

    public function store(StoreWishlistItemRequest $request, WishlistService $wishlist): JsonResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $wishlist->add($customer, $request->integer('product_id'));

        return response()->json(['data' => ['product_id' => $request->integer('product_id'), 'is_wishlisted' => true]], 201);
    }

    public function destroy(Request $request, int $product, WishlistService $wishlist): JsonResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $wishlist->remove($customer, $product);

        return response()->json(['data' => ['product_id' => $product, 'is_wishlisted' => false]]);
    }
}
