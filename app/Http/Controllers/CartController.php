<?php

namespace App\Http\Controllers;

use App\Actions\Cart\BuildCartViewData;
use App\Actions\Recommendation\BuildRecommendationViewData;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    /**
     * Display the authenticated customer's cart.
     */
    public function index(
        Request $request,
        BuildCartViewData $buildCartViewData,
        BuildRecommendationViewData $buildRecommendationViewData,
    ): Response {
        /** @var User $customer */
        $customer = $request->user();

        return Inertia::render('Cart/Index', [
            'cart' => $buildCartViewData->execute($customer),
            ...$buildRecommendationViewData($customer),
        ]);
    }
}
