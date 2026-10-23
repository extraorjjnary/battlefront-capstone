<?php

namespace App\Actions\Inventory;

use App\Enums\UserRole;
use App\Models\CustomerReturnRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CustomerReturnEligibility;
use App\Support\OperationalRequestReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HandleCustomerReturnRequest
{
    public function __construct(private readonly CustomerReturnEligibility $eligibility) {}

    /** @return array<string, array<mixed>> */
    public static function submissionRules(): array
    {
        return [
            'order_item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'reason_code' => ['required', Rule::in(array_keys(OperationalRequestReason::customerReturnOptions()))],
            'reason' => ['required_if:reason_code,other', 'nullable', 'string', 'min:5', 'max:2000'],
        ];
    }

    /** @param array{order_item_id: int, quantity: int, reason_code: string, reason?: string|null} $data */
    public function submit(User $customer, int $orderId, array $data): CustomerReturnRequest
    {
        abort_unless($customer->role === UserRole::Customer, 403);
        Validator::make($data, self::submissionRules())->validate();

        return DB::transaction(function () use ($customer, $orderId, $data): CustomerReturnRequest {
            $order = Order::query()->where('user_id', $customer->id)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $item = $order->items()->whereKey($data['order_item_id'])->lockForUpdate()->firstOrFail();
            if (! $this->eligibility->orderEligible($order)) {
                throw ValidationException::withMessages(['order_item_id' => 'Returns require a completed system purchase.']);
            }
            if ($this->eligibility->hasOpenRequest($item->id)) {
                throw ValidationException::withMessages(['order_item_id' => 'This item already has an open return request.']);
            }
            if ($data['quantity'] > $this->eligibility->remaining($item)) {
                throw ValidationException::withMessages(['quantity' => 'Returned units exceed the remaining purchased quantity.']);
            }
            $request = CustomerReturnRequest::query()->create([
                'user_id' => $customer->id, 'order_id' => $order->id, 'order_item_id' => $item->id,
                'quantity' => $data['quantity'], 'reason_code' => $data['reason_code'], 'reason' => $data['reason'] ?? null,
            ]);
            $this->audit($customer, $request, 'submitted');

            return $request;
        }, attempts: 3);
    }

    public function withdraw(User $customer, int $orderId, int $requestId): CustomerReturnRequest
    {
        abort_unless($customer->role === UserRole::Customer, 403);

        return DB::transaction(function () use ($customer, $orderId, $requestId): CustomerReturnRequest {
            $order = Order::query()->where('user_id', $customer->id)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $identity = CustomerReturnRequest::query()->where('order_id', $order->id)->where('user_id', $customer->id)->findOrFail($requestId);
            $order->items()->whereKey($identity->order_item_id)->lockForUpdate()->firstOrFail();
            $request = CustomerReturnRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            $this->assertAwaitingReceipt($request);
            $request->withdrawn_at = now();
            $request->save();
            $this->audit($customer, $request, 'withdrawn');

            return $request;
        }, attempts: 3);
    }

    public function receive(User $actor, int $requestId, string $condition): int
    {
        abort_unless($actor->can('orders.view') && $actor->can('returns.request'), 403);
        Validator::make(['condition' => $condition], ['condition' => ['required', 'in:sealed,opened,damaged']])->validate();
        $identity = CustomerReturnRequest::query()->findOrFail($requestId);

        return DB::transaction(function () use ($actor, $identity, $condition): int {
            Order::query()->whereKey($identity->order_id)->lockForUpdate()->firstOrFail();
            OrderItem::query()->where('order_id', $identity->order_id)->whereKey($identity->order_item_id)->lockForUpdate()->firstOrFail();
            $request = CustomerReturnRequest::query()->whereKey($identity->id)->lockForUpdate()->firstOrFail();
            $this->assertAwaitingReceipt($request);
            $returnId = app(HandleCustomerReturn::class)->receive($actor, $request->order_item_id, $request->quantity,
                $condition, OperationalRequestReason::resolve($request->reason_code, $request->reason), $request->reason_code, $request->id);
            $request->customer_return_id = $returnId;
            $request->save();
            $this->audit($actor, $request, 'received');

            return $returnId;
        }, attempts: 3);
    }

    private function assertAwaitingReceipt(CustomerReturnRequest $request): void
    {
        if ($request->withdrawn_at !== null || $request->customer_return_id !== null) {
            throw ValidationException::withMessages(['return_request' => 'This request is no longer awaiting item receipt.']);
        }
    }

    private function audit(User $actor, CustomerReturnRequest $request, string $event): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id, 'action' => 'customer_return_request.'.$event,
            'subject_type' => 'customer_return_request', 'subject_id' => $request->id,
            'reason' => OperationalRequestReason::resolve($request->reason_code, $request->reason),
            'changes' => json_encode(['order_id' => $request->order_id, 'order_item_id' => $request->order_item_id,
                'quantity' => $request->quantity, 'customer_return_id' => $request->customer_return_id], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
