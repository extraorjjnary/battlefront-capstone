<?php

namespace App\Http\Controllers;

use App\Actions\Recommendation\BuildRecommendationViewData;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecommendationController extends Controller
{
    public function index(Request $request, BuildRecommendationViewData $buildRecommendationViewData): Response
    {
        $user = $request->user();
        $customer = $user instanceof User ? $user : null;

        return Inertia::render('Recommendations/Index', $buildRecommendationViewData(
            $customer,
            limit: 12,
            guestProfile: $request->attributes->get('guest_recommendation_profile'),
        ));
    }
}
