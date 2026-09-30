<?php

namespace App\Http\Resources\Api\V1;

use App\Services\CatalogProductPresenter;
use App\Services\Recommendation\RecommendedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecommendedProduct */
class RecommendationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product' => new ProductResource(app(CatalogProductPresenter::class)->present($this->product)),
            'effective_price' => $this->effectivePrice,
            'reasons' => $this->reasons,
        ];
    }
}
