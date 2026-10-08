<?php

namespace App\Http\Controllers;

use App\Actions\Recommendation\BuildRecommendationViewData;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(Request $request, BuildRecommendationViewData $buildRecommendationViewData): Response
    {
        $user = $request->user();
        $customer = $user instanceof User ? $user : null;

        return Inertia::render('Welcome', $buildRecommendationViewData(
            $customer,
            guestProfile: $request->attributes->get('guest_recommendation_profile'),
        ));
    }
}
