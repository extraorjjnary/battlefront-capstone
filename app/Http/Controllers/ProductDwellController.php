<?php

namespace App\Http\Controllers;

use App\Actions\User\RecordCustomerProductDwell;
use App\Http\Requests\StoreProductDwellRequest;
use App\Models\GuestRecommendationProfile;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use Illuminate\Http\Response;

class ProductDwellController extends Controller
{
    public function store(
        StoreProductDwellRequest $request,
        int $product,
        ProductCatalogRepository $productCatalogRepository,
        RecordCustomerProductDwell $recordCustomerProductDwell,
    ): Response {
        $customer = $request->user();
        $guestProfile = $request->attributes->get('guest_recommendation_profile');

        $recordCustomerProductDwell(
            $customer instanceof User ? $customer : null,
            $productCatalogRepository->findEligibleOrFail($product),
            $guestProfile instanceof GuestRecommendationProfile ? $guestProfile : null,
            $request->validatedSeconds(),
        );

        return response()->noContent();
    }
}
