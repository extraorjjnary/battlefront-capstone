<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatbotResponseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => $this->resource['message'],
            'source' => $this->resource['source'],
            'context_token' => $this->resource['context_token'],
            'product_links' => $this->when(isset($this->resource['product_links']), fn (): array => $this->resource['product_links']),
        ];
    }
}
