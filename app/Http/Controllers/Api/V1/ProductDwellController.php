<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Actions\User\RecordCustomerProductDwell;
use App\Enums\UserRole;
use App\Http\Requests\StoreProductDwellRequest;
use App\Models\GuestRecommendationProfile;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use Illuminate\Http\Response;

class ProductDwellController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(StoreProductDwellRequest $request, int $product, ProductCatalogRepository $catalog, RecordCustomerProductDwell $record): Response
    {
        $customer = $request->user('sanctum');
        abort_if($customer instanceof User && $customer->role !== UserRole::Customer, 403);
        $guestProfile = $request->attributes->get('guest_recommendation_profile');
        if (! $request->prefetch()) {
            $record($customer, $catalog->findEligibleOrFail($product), $guestProfile instanceof GuestRecommendationProfile ? $guestProfile : null, $request->validatedSeconds());
        }

        return response()->noContent();
    }
}
