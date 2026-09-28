<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductCatalogIndexRequest;
use App\Http\Resources\Api\V1\CatalogFilterOptionsResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\CatalogProductPresenter;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductCatalogRepository $productCatalogRepository,
        private readonly CatalogProductPresenter $catalogProductPresenter,
    ) {}

    public function index(ProductCatalogIndexRequest $request): AnonymousResourceCollection
    {
        $products = $this->productCatalogRepository->paginate($request->filters())
            ->through(fn (Product $product): array => $this->catalogProductPresenter->present($product));

        return ProductResource::collection($products);
    }

    public function filters(): CatalogFilterOptionsResource
    {
        return new CatalogFilterOptionsResource($this->productCatalogRepository->filterOptions());
    }

    public function show(int $product): ProductResource
    {
        $catalogProduct = $this->productCatalogRepository->findEligibleOrFail($product);

        return new ProductResource($this->catalogProductPresenter->present($catalogProduct));
    }
}
