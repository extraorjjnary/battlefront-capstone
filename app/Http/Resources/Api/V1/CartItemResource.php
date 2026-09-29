<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'quantity' => $this->resource['quantity'],
            'product' => [
                'id' => $this->resource['product']['id'],
                'name' => $this->resource['product']['name'],
                'brand' => $this->resource['product']['brand'],
                'image_url' => $this->resource['product']['image_url'],
                'category' => $this->resource['product']['category'],
                'price' => $this->resource['product']['price'],
                'discount_price' => $this->resource['product']['discount_price'],
            ],
            'unit_price' => $this->resource['unit_price'],
            'line_total' => $this->resource['line_total'],
            'availability' => [
                'status' => $this->resource['availability']['status'],
                'available_quantity' => $this->resource['availability']['available_quantity'],
            ],
        ];
    }
}
