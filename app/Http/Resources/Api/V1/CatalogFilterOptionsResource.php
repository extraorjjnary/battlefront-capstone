<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Category;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogFilterOptionsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'categories' => $this->resource['categories']->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])->all(),
            'brands' => $this->resource['brands']->all(),
            'tags' => $this->resource['tags']->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->all(),
        ];
    }
}
