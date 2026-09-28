<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
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
            'address' => $this->resource['address'],
            'city' => $this->resource['city'],
            'contact_number' => $this->resource['contact_number'],
            'latitude' => $this->resource['latitude'],
            'longitude' => $this->resource['longitude'],
            'email' => $this->resource['email'],
            'operating_hours' => $this->resource['operating_hours'],
            'is_operational' => $this->resource['is_operational'],
        ];
    }
}
