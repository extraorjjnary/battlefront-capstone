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
        $filters = $this->catalogFilters($request);

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

    /**
     * Convert validated catalog input into stable Inertia filter values.
     *
     * @return array{q: string|null, category_id: int|null, brand: string|null, tag_id: int|null}
     */
    private function catalogFilters(ProductCatalogIndexRequest $request): array
    {
        return [
            'q' => $request->filled('q') ? $request->string('q')->toString() : null,
            'category_id' => $request->filled('category_id') ? $request->integer('category_id') : null,
            'brand' => $request->filled('brand') ? $request->string('brand')->toString() : null,
            'tag_id' => $request->filled('tag_id') ? $request->integer('tag_id') : null,
        ];
    }
}
