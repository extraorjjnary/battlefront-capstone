<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class CustomerProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'default_delivery_address' => $this->default_delivery_address,
            'search_recommendations_enabled' => $this->search_recommendations_enabled,
            'product_view_recommendations_enabled' => $this->product_view_recommendations_enabled,
        ];
    }
}
