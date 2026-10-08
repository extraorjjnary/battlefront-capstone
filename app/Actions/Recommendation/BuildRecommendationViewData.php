<?php

namespace App\Actions\Recommendation;

use App\Models\User;
use App\Services\CatalogProductPresenter;
use App\Services\Recommendation\BehavioralRecommendationEngine;
use App\Services\Recommendation\BehavioralRecommendedProduct;

class BuildRecommendationViewData
{
    public function __construct(
        private readonly BehavioralRecommendationEngine $recommendationEngine,
        private readonly CatalogProductPresenter $catalogProductPresenter,
    ) {}

    /**
     * Prepare the shared recommendation section data for a storefront page.
     *
     * @return array{
     *     is_personalized: bool,
     *     has_featured_fallback: bool,
     *     recommendations: array<int, array{
     *         product: array<string, mixed>,
     *         effective_price: string,
     *         reasons: list<array{code: string, value: string}>
     *     }>
     * }
     */
    public function __invoke(?User $customer, ?int $excludeProductId = null, int $limit = 4): array
    {
        if ($customer !== null && ! $customer->can('use-recommendations')) {
            return [
                'is_personalized' => false,
                'recommendations' => [],
            ];
        }

        $recommendations = $customer !== null
            ? $this->recommendationEngine->recommendFor($customer, $limit)
            : $this->recommendationEngine->popular($limit);
        $recommendations = $recommendations
            ->reject(fn (BehavioralRecommendedProduct $recommendation): bool => $recommendation->product->id === $excludeProductId)
            ->values();
        $isPersonalized = $customer !== null && $recommendations->contains(
            static fn (BehavioralRecommendedProduct $recommendation): bool => collect($recommendation->reasons)
                ->contains(static fn (array $reason): bool => ! in_array(
                    $reason['code'],
                    ['popular_with_customers', 'featured_fallback'],
                    true,
                )),
        );
        $hasFeaturedFallback = $recommendations->contains(
            static fn (BehavioralRecommendedProduct $recommendation): bool => collect($recommendation->reasons)
                ->contains(static fn (array $reason): bool => $reason['code'] === 'featured_fallback'),
        );

        return [
            'is_personalized' => $isPersonalized,
            'has_featured_fallback' => $hasFeaturedFallback,
            'recommendations' => $recommendations
                ->map(fn (BehavioralRecommendedProduct $recommendation): array => [
                    'product' => $this->catalogProductPresenter->present($recommendation->product),
                    'effective_price' => $recommendation->effectivePrice,
                    'reasons' => $recommendation->reasons,
                ])
                ->values()
                ->all(),
        ];
    }
}
