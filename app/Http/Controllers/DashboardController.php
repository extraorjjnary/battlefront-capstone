<?php

namespace App\Http\Controllers;

use App\Actions\Recommendation\BuildRecommendationViewData;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the dashboard for the authenticated account's role.
     */
    public function __invoke(
        Request $request,
        DashboardService $dashboardService,
        BuildRecommendationViewData $buildRecommendationViewData,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        if ($user->isAdministrator()) {
            return Inertia::render('Dashboard/Administration', [
                'dashboard' => $dashboardService->administration(),
            ]);
        }

        return Inertia::render('Dashboard/Customer', [
            'is_first_visit' => function () use ($request, $user): bool {
                if ($request->prefetch() || ! $request->isMethod('GET')) {
                    return ! $user->has_visited_dashboard;
                }

                return User::query()->whereKey($user->id)
                    ->where('has_visited_dashboard', false)
                    ->update(['has_visited_dashboard' => true]) === 1;
            },
            'dashboard' => $dashboardService->customer($user),
            ...$buildRecommendationViewData($user, hideWhenPersonalizationDisabled: true),
        ]);
    }
}
