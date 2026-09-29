<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'items' => CartItemResource::collection($this->resource['items']),
            'item_count' => $this->resource['item_count'],
            'total_quantity' => $this->resource['total_quantity'],
            'total' => $this->resource['total'],
            'conflict_count' => $this->resource['conflict_count'],
        ];
    }
}
