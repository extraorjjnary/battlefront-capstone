<?php

namespace App\Actions\Inventory;

use App\Enums\UserRole;
use App\Models\CustomerReturnRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\AdministrationEvidenceService;
use App\Services\CustomerReturnEligibility;
use App\Services\Notifications\OrderNotificationPublisher;
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
            $window = $this->eligibility->window($order);
            $reasonEligibility = $window['reasons'][$data['reason_code']];
            if (! $reasonEligibility['can_request']) {
                throw ValidationException::withMessages(['reason_code' => 'The discretionary return-request window has expired. Defect or other remedy concerns remain available for manual review.']);
            }
            $request = CustomerReturnRequest::query()->create([
                'user_id' => $customer->id, 'order_id' => $order->id, 'order_item_id' => $item->id,
                'quantity' => $data['quantity'], 'reason_code' => $data['reason_code'], 'reason' => $data['reason'] ?? null,
                'eligibility_path' => $reasonEligibility['manual_review'] ? 'manual_review' : 'discretionary',
                'eligibility_receipt_at' => $window['received_at'], 'eligibility_deadline_at' => $window['deadline_at'],
            ]);
            $this->audit($customer, $request, 'submitted');
            app(OrderNotificationPublisher::class)->afterCommit($order, 'customer_return.requested', context: [
                'subject_id' => $request->id,
            ]);

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

    /** @param array<array-key, mixed> $photos */
    public function receive(User $actor, int $requestId, string $condition, array $photos = []): int
    {
        abort_unless(app(AdministrationEvidenceService::class)->canReceiveCustomerReturn($actor), 403);
        $identity = CustomerReturnRequest::query()->findOrFail($requestId);

        return app(HandleCustomerReturn::class)->receive($actor, $identity->order_item_id, $identity->quantity,
            $condition, OperationalRequestReason::resolve($identity->reason_code, $identity->reason), $identity->reason_code, $identity->id, $photos);
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
