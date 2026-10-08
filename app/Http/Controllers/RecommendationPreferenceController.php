<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RecommendationPreferenceController extends Controller
{
    public function enable(Request $request): RedirectResponse
    {
        $customer = $request->user();

        abort_unless($customer instanceof User && $customer->role === UserRole::Customer, 403);

        $customer->personalized_recommendations_enabled = true;
        $customer->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Personalized recommendations enabled.']);

        return to_route('recommendations.index');
    }
}
