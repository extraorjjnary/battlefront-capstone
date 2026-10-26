<?php

namespace App\Http\Controllers;

use App\Actions\Recommendation\BuildRecommendationViewData;
use App\Actions\User\RecordCustomerProductView;
use App\Enums\UserRole;
use App\Http\Requests\ProductCatalogIndexRequest;
use App\Models\GuestRecommendationProfile;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\CatalogProductPresenter;
use App\Services\Recommendation\RecordCustomerSearch;
use App\Services\Wishlist\WishlistService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class ProductCatalogController extends Controller
{
    public function __construct(
        private readonly ProductCatalogRepository $productCatalogRepository,
        private readonly CatalogProductPresenter $catalogProductPresenter,
    ) {}

    /**
     * Display the customer product catalog.
     */
    public function index(
        ProductCatalogIndexRequest $request,
        RecordCustomerSearch $recordCustomerSearch,
        BuildRecommendationViewData $buildRecommendationViewData,
    ): Response {
        $filters = $request->filters();
        $hasDiscoveryFilters = collect($filters)
            ->only(['q', 'category_id', 'brand', 'tag_id'])
            ->contains(static fn (mixed $value): bool => $value !== null);

        if (! $request->prefetch() && $request->integer('page', 1) === 1) {
            $recordCustomerSearch->record(
                $request->user(),
                $filters['q'],
                $request->attributes->get('guest_recommendation_profile'),
            );
        }

        $products = fn (): LengthAwarePaginator => $this->paginateProducts($filters, $request->user() instanceof User && $request->user()->can('use-customer-cart') ? $request->user() : null);

        $recommendationViewData = null;
        $resolveRecommendations = function () use (&$recommendationViewData, $hasDiscoveryFilters, $request, $buildRecommendationViewData): array {
            if ($recommendationViewData !== null) {
                return $recommendationViewData;
            }

            if ($hasDiscoveryFilters) {
                return $recommendationViewData = [
                    'recommendations' => [],
                    'is_personalized' => false,
                    'has_featured_fallback' => false,
                    'guest_recommendation_scope' => null,
                ];
            }

            $user = $request->user();

            return $recommendationViewData = $buildRecommendationViewData(
                $user instanceof User ? $user : null,
                guestProfile: $request->attributes->get('guest_recommendation_profile'),
                hideWhenPersonalizationDisabled: true,
            );
        };

        $user = $request->user();
        $recommendationsEnabled = ! $hasDiscoveryFilters && (! $user instanceof User
            || ($user->can('use-recommendations') && $user->personalized_recommendations_enabled));
        $recommendationProps = $recommendationsEnabled ? [] : $resolveRecommendations();

        if ($recommendationsEnabled) {
            foreach (['recommendations', 'is_personalized', 'has_featured_fallback', 'guest_recommendation_scope'] as $key) {
                $recommendationProps[$key] = Inertia::defer(fn (): mixed => $resolveRecommendations()[$key], 'recommendations', rescue: true);
            }
        }

        return Inertia::render('Products/Index', [
            'products' => Inertia::scroll($products),
            'filters' => $filters,
            'filter_options' => fn (): array => $this->productCatalogRepository->filterOptions(),
            'recommendations_enabled' => $recommendationsEnabled,
            ...$recommendationProps,
        ]);
    }

    /**
     * Display an eligible product using authoritative catalog data.
     */
    public function show(
        Request $request,
        int $product,
        RecordCustomerProductView $recordCustomerProductView,
        BuildRecommendationViewData $buildRecommendationViewData,
    ): Response {
        $catalogProduct = $this->productCatalogRepository->findAvailableOrFail($product);
        if (! $request->prefetch()) {
            $recordCustomerProductView(
                $request->user(),
                $catalogProduct,
                $request->attributes->get('guest_recommendation_profile'),
            );
        }
        $user = $request->user();
        $customer = $user instanceof User ? $user : null;
        $guestProfile = $request->attributes->get('guest_recommendation_profile');
        $isWishlisted = $customer instanceof User && $customer->can('use-customer-cart')
            ? app(WishlistService::class)->savedProductIds($customer, [$catalogProduct->id]) !== []
            : false;

        return Inertia::render('Products/Show', [
            'product' => [
                ...$this->catalogProductPresenter->present($catalogProduct),
                'shipping_handling' => $this->catalogProductPresenter->handling($catalogProduct->shipping_profile),
                'is_wishlisted' => $isWishlisted,
            ],
            'can_record_product_dwell' => $customer instanceof User
                ? $customer->role === UserRole::Customer
                    && $customer->personalized_recommendations_enabled
                    && $customer->product_view_recommendations_enabled
                : $guestProfile instanceof GuestRecommendationProfile,
            ...$buildRecommendationViewData(
                $customer,
                $catalogProduct->id,
                guestProfile: $guestProfile instanceof GuestRecommendationProfile ? $guestProfile : null,
            ),
        ]);
    }

    /**
     * @param  array{q: string|null, category_id: int|null, brand: string|null, tag_id: int|null, category_ids?: list<int>, min_price?: string, max_price?: string, sort?: string}  $filters
     * @return LengthAwarePaginator<int, covariant array{
     *     id: int, name: string, description: string|null, brand: string|null,
     *     price: string, discount_price: string|null, image_url: string|null,
     *     is_featured: bool, shipping_profile: string,
     *     category: array{id: int, name: string}, tags: list<array{id: int, name: string}>,
     *     inventory: array{quantity: int|null, status: string}
     * }>
     */
    private function paginateProducts(array $filters, ?User $customer): LengthAwarePaginator
    {
        $products = $this->productCatalogRepository->paginate($filters);
        $savedIds = $customer instanceof User
            ? app(WishlistService::class)->savedProductIds($customer, $products->getCollection()->modelKeys())
            : [];

        return $products->through(fn (Product $product): array => [
            ...$this->catalogProductPresenter->present($product),
            'is_wishlisted' => in_array($product->id, $savedIds, true),
        ]);
    }
}
