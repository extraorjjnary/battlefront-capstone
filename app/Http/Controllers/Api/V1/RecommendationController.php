<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RecommendationIntendedUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecommendationInputRequest;
use App\Http\Resources\Api\V1\RecommendationOptionsResource;
use App\Http\Resources\Api\V1\RecommendationResource;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\Recommendation\RecommendationEngine;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecommendationController extends Controller
{
    public function results(RecommendationInputRequest $request, RecommendationEngine $engine): AnonymousResourceCollection
    {
        return RecommendationResource::collection($engine->recommend($request->validatedCriteria()));
    }

    public function options(ProductCatalogRepository $catalog): RecommendationOptionsResource
    {
        return new RecommendationOptionsResource([
            'intended_uses' => array_map(
                static fn (RecommendationIntendedUse $use): array => [
                    'value' => $use->value,
                    'label' => $use->label(),
                ],
                RecommendationIntendedUse::cases(),
            ),
            'filter_options' => $catalog->filterOptions(),
        ]);
    }
}
