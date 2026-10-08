<?php

namespace App\Http\Controllers;

use App\Actions\Recommendation\RecordRecommendationInteraction;
use App\Http\Requests\StoreRecommendationInteractionRequest;
use App\Repositories\Catalog\ProductCatalogRepository;
use Illuminate\Http\Response;

class RecommendationInteractionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        StoreRecommendationInteractionRequest $request,
        ProductCatalogRepository $productCatalogRepository,
        RecordRecommendationInteraction $recordRecommendationInteraction,
    ): Response {
        $interaction = $request->validatedInteraction();
        $product = $productCatalogRepository->findEligibleOrFail($interaction['product_id']);

        $recordRecommendationInteraction($product, [
            'event_id' => $interaction['event_id'],
            'event_type' => $interaction['event_type'],
            'placement' => $interaction['placement'],
            'position' => $interaction['position'],
            'reason_code' => $interaction['reason_code'],
        ]);

        return response()->noContent();
    }
}
