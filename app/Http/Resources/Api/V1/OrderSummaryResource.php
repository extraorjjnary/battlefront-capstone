<?php

namespace App\Http\Resources\Api\V1;

use App\Services\CustomerOrderPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return app(CustomerOrderPresenter::class)->summary($this->resource);
    }
}
