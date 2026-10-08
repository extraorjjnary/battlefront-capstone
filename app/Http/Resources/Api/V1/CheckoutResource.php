<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'cart' => $this->resource['cart'],
            'delivery_quotes' => $this->resource['deliveryQuotes'],
            'pickup_quote' => $this->resource['pickupQuote'],
            'customer' => $this->resource['customer'],
            'pickup_location' => $this->resource['pickupLocation'],
            'fulfillment_methods' => $this->resource['fulfillmentMethods'],
            'payment_methods' => $this->resource['paymentMethods'],
        ];
    }
}
