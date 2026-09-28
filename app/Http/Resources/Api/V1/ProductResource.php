<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'name' => $this->resource['name'],
            'description' => $this->resource['description'],
            'brand' => $this->resource['brand'],
            'price' => $this->resource['price'],
            'discount_price' => $this->resource['discount_price'],
            'image_url' => $this->resource['image_url'],
            'is_featured' => $this->resource['is_featured'],
            'category' => $this->resource['category'],
            'tags' => $this->resource['tags'],
            'inventory' => ['status' => $this->resource['inventory']['status']],
        ];
    }
}
