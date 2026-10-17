<?php

namespace App\Http\Controllers;

use App\Actions\Recommendation\BuildRecommendationViewData;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\RedirectResponse;
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
    ): Response|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        if ($user->isAdministrator()) {
            if (! $user->is_active || $user->administration_role_id === null) {
                abort(403);
            }
            if ($user->administrationRoleCode() === 'it') {
                return to_route('administration.team.index');
            }
            if (! $user->hasAdministrationPermission('reports.view')) {
                return to_route('administration.orders.index');
            }

            return Inertia::render('Dashboard/Administration', [
                'dashboard' => Inertia::defer(fn (): array => $dashboardService->administration(), 'dashboard', rescue: true),
            ]);
        }

        $recommendationsEnabled = $user->can('use-recommendations') && $user->personalized_recommendations_enabled;
        $recommendationViewData = null;
        $resolveRecommendations = function () use (&$recommendationViewData, $user, $buildRecommendationViewData): array {
            return $recommendationViewData ??= $buildRecommendationViewData($user, hideWhenPersonalizationDisabled: true);
        };
        $recommendationProps = $recommendationsEnabled ? [] : $resolveRecommendations();

        if ($recommendationsEnabled) {
            foreach (['recommendations', 'is_personalized', 'has_featured_fallback', 'guest_recommendation_scope'] as $key) {
                $recommendationProps[$key] = Inertia::defer(fn (): mixed => $resolveRecommendations()[$key], 'recommendations', rescue: true);
            }
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
            'dashboard' => Inertia::defer(fn (): array => $dashboardService->customer($user), 'dashboard', rescue: true),
            'recommendations_enabled' => $recommendationsEnabled,
            ...$recommendationProps,
        ]);
    }
}
