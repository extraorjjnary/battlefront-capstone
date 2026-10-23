<?php

namespace App\Http\Requests;

use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerReturnRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Customer;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return HandleCustomerReturnRequest::submissionRules();
    }

    /** @return array{order_item_id: int, quantity: int, reason_code: string, reason: string|null} */
    public function returnRequestData(): array
    {
        $notes = $this->validated('reason');

        return [
            'order_item_id' => $this->integer('order_item_id'),
            'quantity' => $this->integer('quantity'),
            'reason_code' => $this->string('reason_code')->toString(),
            'reason' => is_string($notes) ? $notes : null,
        ];
    }
}
