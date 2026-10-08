<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BehavioralRecommendationResource;
use App\Models\User;
use App\Services\Recommendation\BehavioralRecommendationEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecommendationController extends Controller
{
    public function feed(Request $request, BehavioralRecommendationEngine $engine): AnonymousResourceCollection
    {
        $customer = $request->user('sanctum');
        $recommendations = $customer instanceof User
            ? $engine->recommendFor($customer)
            : $engine->popular();

        return BehavioralRecommendationResource::collection($recommendations);
    }

    public function personalized(Request $request, BehavioralRecommendationEngine $engine): AnonymousResourceCollection
    {
        /** @var User $customer */
        $customer = $request->user();

        return BehavioralRecommendationResource::collection($engine->recommendFor($customer));
    }
}
