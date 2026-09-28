<?php

namespace App\Services\Recommendation;

use App\Models\Product;

readonly class RecommendedProduct
{
    /** @var numeric-string */
    public string $effectivePrice;

    /**
     * @param  numeric-string  $effectivePrice
     * @param  list<array{code: string, value: string}>  $reasons
     */
    public function __construct(
        public Product $product,
        string $effectivePrice,
        public int $intendedUseMatchCount,
        public int $preferredTagMatchCount,
        public array $reasons,
    ) {
        $this->effectivePrice = $effectivePrice;
    }
}
