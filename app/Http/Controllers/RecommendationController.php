<?php

namespace App\Http\Controllers;

use App\Enums\RecommendationIntendedUse;
use App\Http\Requests\RecommendationInputRequest;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\CatalogProductPresenter;
use App\Services\Recommendation\RecommendationEngine;
use App\Services\Recommendation\RecommendedProduct;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class RecommendationController extends Controller
{
    public function __construct(
        private readonly RecommendationEngine $recommendationEngine,
        private readonly ProductCatalogRepository $productCatalogRepository,
        private readonly CatalogProductPresenter $catalogProductPresenter,
    ) {}

    public function index(): Response
    {
        return $this->page(null, null);
    }

    public function results(RecommendationInputRequest $request): Response
    {
        $criteria = $request->validatedCriteria();

        return $this->page($criteria, $this->recommendationEngine->recommend($criteria));
    }

    /**
     * @param  array{budget: string, intended_use: RecommendationIntendedUse, preferred_brand: string|null, category_id: int|null, tag_ids: list<int>}|null  $criteria
     * @param  Collection<int, RecommendedProduct>|null  $recommendations
     */
    private function page(?array $criteria, ?Collection $recommendations): Response
    {
        return Inertia::render('Recommendations/Index', [
            'criteria' => $criteria === null ? null : [
                ...$criteria,
                'intended_use' => $criteria['intended_use']->value,
            ],
            'intended_uses' => array_map(
                static fn (RecommendationIntendedUse $use): array => [
                    'value' => $use->value,
                    'label' => $use->label(),
                ],
                RecommendationIntendedUse::cases(),
            ),
            'filter_options' => fn (): array => $this->productCatalogRepository->filterOptions(),
            'recommendations' => $recommendations?->map(fn (RecommendedProduct $recommendation): array => [
                'product' => $this->catalogProductPresenter->present($recommendation->product),
                'effective_price' => $recommendation->effectivePrice,
                'reasons' => $recommendation->reasons,
            ])->values()->all(),
        ]);
    }
}
