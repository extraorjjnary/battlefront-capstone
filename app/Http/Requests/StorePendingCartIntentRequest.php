<?php

namespace App\Http\Requests;

class StorePendingCartIntentRequest extends StoreCartItemRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() === null;
    }
}
