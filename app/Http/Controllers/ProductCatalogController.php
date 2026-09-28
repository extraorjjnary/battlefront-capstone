<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductCatalogIndexRequest;
use App\Models\Product;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\CatalogProductPresenter;
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
    public function index(ProductCatalogIndexRequest $request): Response
    {
        $filters = $request->filters();

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
    public function show(int $product): Response
    {
        $catalogProduct = $this->productCatalogRepository->findEligibleOrFail($product);

        return Inertia::render('Products/Show', [
            'product' => $this->catalogProductPresenter->present($catalogProduct),
        ]);
    }
}
