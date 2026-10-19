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
        $canBrowseProductCatalog = $customer?->isAdministrator()
            && $customer->is_active
            && in_array($customer->administrationRoleCode(), ['staff', 'manager', 'owner'], true);
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
                || (! $request->user()->can('access-administration') && ! $request->user()->can('use-customer-cart'))
                ? null : app(NotificationHistory::class)->summary($request->user()),
            'appearance' => $request->user()?->appearance->value
                ?? AppearancePreference::System->value,
            'auth' => [
                'user' => $request->user(),
                'can' => [
                    'accessAdministration' => $request->user()?->can('access-administration') ?? false,
                    'approveInventory' => $request->user()?->can('inventory.approve') ?? false,
                    'approveReturns' => $request->user()?->can('returns.approve') ?? false,
                    'resolveReturns' => $request->user()?->can('returns.resolve') ?? false,
                    'reconcileReturns' => $request->user()?->can('returns.reconcile') ?? false,
                    'assignOrders' => $request->user()?->can('orders.assign') ?? false,
                    'viewReports' => $request->user()?->can('reports.view') ?? false,
                    'viewStaffReports' => $request->user()?->can('staff_reports.view') ?? false,
                    'viewAudit' => $request->user()?->can('audit.view') ?? false,
                    'exportAudit' => $request->user()?->can('audit.export') ?? false,
                    'browseProductCatalog' => $canBrowseProductCatalog,
                    'manageProducts' => $request->user()?->can('products.manage') ?? false,
                    'priceProducts' => $request->user()?->can('products.price') ?? false,
                    'deleteCatalog' => $request->user()?->can('catalog.delete') ?? false,
                    'manageSuppliers' => $request->user()?->can('suppliers.manage') ?? false,
                    'manageUsers' => $request->user()?->can('users.manage') ?? false,
                    'viewCustomers' => $request->user()?->can('customers.view') ?? false,
                    'viewInventory' => $request->user()?->can('inventory.view') ?? false,
                    'viewOrders' => $request->user()?->can('orders.view') ?? false,
                    'useCustomerCart' => $canUseCustomerCart,
                ],
            ],
            'sidebarOpen' => $sidebarCookieName === null
                || ! $request->hasCookie($sidebarCookieName)
                || $request->cookie($sidebarCookieName) === 'true',
        ];
    }
}
