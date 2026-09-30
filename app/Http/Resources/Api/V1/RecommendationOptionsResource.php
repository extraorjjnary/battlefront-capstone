<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommendationOptionsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'intended_uses' => $this->resource['intended_uses'],
            'filter_options' => new CatalogFilterOptionsResource($this->resource['filter_options']),
        ];
    }
}
