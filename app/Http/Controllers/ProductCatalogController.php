<?php

namespace App\Http\Controllers;

use App\Actions\Recommendation\BuildRecommendationViewData;
use App\Actions\User\RecordCustomerProductView;
use App\Http\Requests\ProductCatalogIndexRequest;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\CatalogProductPresenter;
use App\Services\Recommendation\RecordCustomerSearch;
use Illuminate\Http\Request;
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
    public function index(ProductCatalogIndexRequest $request, RecordCustomerSearch $recordCustomerSearch): Response
    {
        $filters = $request->filters();

        if ($request->integer('page', 1) === 1) {
            $recordCustomerSearch->record(
                $request->user(),
                $filters['q'],
                $request->attributes->get('guest_recommendation_profile'),
            );
        }

        $products = $this->productCatalogRepository->paginate($filters)
            ->through(fn (Product $product): array => $this->catalogProductPresenter->present($product));

        return Inertia::render('Products/Index', [
            'products' => Inertia::scroll($products),
            'filters' => $filters,
            'filter_options' => fn (): array => $this->productCatalogRepository->filterOptions(),
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
        $catalogProduct = $this->productCatalogRepository->findEligibleOrFail($product);
        $recordCustomerProductView(
            $request->user(),
            $catalogProduct,
            $request->attributes->get('guest_recommendation_profile'),
        );
        $user = $request->user();
        $customer = $user instanceof User ? $user : null;

        return Inertia::render('Products/Show', [
            'product' => $this->catalogProductPresenter->present($catalogProduct),
            ...$buildRecommendationViewData(
                $customer,
                $catalogProduct->id,
                guestProfile: $request->attributes->get('guest_recommendation_profile'),
            ),
        ]);
    }
}
