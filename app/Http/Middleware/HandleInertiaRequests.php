<?php

namespace App\Http\Middleware;

use App\Enums\AppearancePreference;
use App\Services\Cart\CartService;
use App\Services\Notifications\NotificationHistory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $customer = $request->user();
        $canUseCustomerCart = $customer?->can('use-customer-cart') ?? false;
        $sidebarCookieName = $request->user() === null
            ? null
            : 'sidebar_state_'.$request->user()->getKey();

        return [
            ...parent::share($request),
            ...($customer !== null && $canUseCustomerCart ? [
                'cartQuantity' => Inertia::always(fn (): int => app(CartService::class)->totalQuantity($customer)),
            ] : []),
            'name' => config('app.name'),
            'notificationSummary' => fn (): ?array => $request->user() === null
                ? null : app(NotificationHistory::class)->summary($request->user()),
            'appearance' => $request->user()?->appearance->resolved()->value
                ?? AppearancePreference::Light->value,
            'auth' => [
                'user' => $request->user(),
                'can' => [
                    'accessAdministration' => $request->user()?->can('access-administration') ?? false,
                    'useCustomerCart' => $canUseCustomerCart,
                ],
            ],
            'sidebarOpen' => $sidebarCookieName === null
                || ! $request->hasCookie($sidebarCookieName)
                || $request->cookie($sidebarCookieName) === 'true',
        ];
    }
}
