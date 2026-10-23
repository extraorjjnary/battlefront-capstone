<?php

namespace App\Actions\Inventory;

use App\Enums\OrderStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\ApprovalMonitor;
use App\Services\CustomerReturnEligibility;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HandleCustomerReturn
{
    public function receive(User $actor, int $orderItemId, int $quantity, string $condition, string $reason, string $reasonCode, ?int $convertingRequest = null): int
    {
        $orderId = OrderItem::query()->findOrFail($orderItemId)->order_id;

        return DB::transaction(function () use ($actor, $orderId, $orderItemId, $quantity, $condition, $reason, $reasonCode, $convertingRequest): int {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->status !== OrderStatus::Completed || $order->sale()->doesntExist()) {
                throw ValidationException::withMessages(['order_item_id' => 'Returns require a completed system purchase.']);
            }
            $item = OrderItem::query()->where('order_id', $orderId)->whereKey($orderItemId)->lockForUpdate()->firstOrFail();
            if ($convertingRequest !== null) {
                $source = DB::table('customer_return_requests')->where('id', $convertingRequest)->lockForUpdate()->first();
                if ($source === null || (int) $source->order_id !== $order->id || (int) $source->user_id !== $order->user_id
                    || (int) $source->order_item_id !== $item->id || (int) $source->quantity !== $quantity
                    || $source->withdrawn_at !== null || $source->customer_return_id !== null) {
                    throw ValidationException::withMessages(['return_request' => 'This request is no longer eligible for receipt.']);
                }
            }
            if ($quantity < 1 || $quantity > app(CustomerReturnEligibility::class)->remaining($item, $convertingRequest)) {
                throw ValidationException::withMessages(['quantity' => 'Returned units exceed the remaining purchased quantity.']);
            }

            $inventory = Inventory::query()->where('product_id', $item->product_id)->lockForUpdate()->firstOrFail();
            $beforeSellable = $inventory->quantity;
            $beforeHeld = $inventory->quarantined_quantity;
            $inventory->quarantined_quantity = max(0, (int) ($inventory->quarantined_quantity + $quantity));
            $inventory->save();

            $id = DB::table('customer_returns')->insertGetId([
                'order_id' => $order->id,
                'requester_id' => $actor->id,
                'status' => 'pending',
                'reason' => $reason,
                'reason_code' => $reasonCode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('customer_return_items')->insert([
                'customer_return_id' => $id,
                'order_item_id' => $item->id,
                'quantity' => $quantity,
                'condition' => $condition,
            ]);
            $this->movement($inventory, $actor, 'customer_return_received', $id, $beforeSellable, $beforeHeld);
            $this->audit($actor, 'customer_return.received', $id, ['order_id' => $order->id, 'order_item_id' => $item->id, 'quantity' => $quantity], $reason);

            return $id;
        }, attempts: 3);
    }

    public function decide(int $returnId, User $reviewer, bool $accept, string $disposition, string $approvedAmount, string $reason, ?int $supplierId = null, bool $inspectionConfirmed = false): void
    {
        DB::transaction(function () use ($returnId, $reviewer, $accept, $disposition, $approvedAmount, $reason, $supplierId, $inspectionConfirmed): void {
            $return = DB::table('customer_returns')->where('id', $returnId)->lockForUpdate()->first();
            if ($return === null) {
                abort(404);
            }
            if ($return->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This return has already been decided.']);
            }
            if ((int) $return->requester_id === $reviewer->id) {
                throw ValidationException::withMessages(['decision' => 'You cannot decide your own return.']);
            }
            if (! $reviewer->hasAdministrationPermission('returns.approve')) {
                abort(403);
            }

            $returnItem = DB::table('customer_return_items')->where('customer_return_id', $returnId)->lockForUpdate()->first();
            $originalItem = OrderItem::query()->whereKey($returnItem->order_item_id)->firstOrFail();
            $maximum = bcmul($this->decimal((string) $returnItem->quantity), $originalItem->price_at_time, 2);
            $approvedAmount = $this->decimal($approvedAmount);
            if ($accept && (bccomp($approvedAmount, '0.00', 2) < 0 || bccomp($approvedAmount, $maximum, 2) > 0)) {
                throw ValidationException::withMessages(['approved_amount' => 'Refund approval exceeds the returned item value.']);
            }

            if (! $accept && (bccomp($approvedAmount, '0.00', 2) !== 0 || $disposition !== '' || $supplierId !== null)) {
                throw ValidationException::withMessages(['decision' => 'A rejected return cannot include a disposition or refund.']);
            }

            $inventory = Inventory::query()->where('product_id', $originalItem->product_id)->lockForUpdate()->firstOrFail();
            if ($inventory->quarantined_quantity < $returnItem->quantity) {
                throw ValidationException::withMessages(['decision' => 'Received stock is no longer in quarantine.']);
            }
            if ($accept && $disposition === 'write_off'
                && ! DB::table('administration_evidence')->where('subject_type', 'customer_return')->where('subject_id', $returnId)->exists()) {
                throw ValidationException::withMessages(['evidence' => 'Attach evidence before writing off returned stock.']);
            }
            $beforeSellable = $inventory->quantity;
            $beforeHeld = $inventory->quarantined_quantity;
            if ($accept && $disposition === 'write_off') {
                $inventory->quarantined_quantity = max(0, (int) ($inventory->quarantined_quantity - $returnItem->quantity));
            } elseif ($accept && $disposition === 'restock') {
                if (! $inspectionConfirmed) {
                    throw ValidationException::withMessages(['inspection_confirmed' => 'Confirm that inspection found the item suitable for sale before restocking it.']);
                }
                $inventory->quarantined_quantity = max(0, (int) ($inventory->quarantined_quantity - $returnItem->quantity));
                $inventory->quantity += $returnItem->quantity;
            } elseif ($accept && $disposition === 'supplier_return') {
                if ($supplierId === null || ! DB::table('suppliers')->where('id', $supplierId)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['supplier_id' => 'Select an active supplier.']);
                }
                DB::table('supplier_returns')->insert([
                    'supplier_id' => $supplierId,
                    'product_id' => $originalItem->product_id,
                    'requester_id' => $reviewer->id,
                    'customer_return_item_id' => $returnItem->id,
                    'quantity' => $returnItem->quantity,
                    'status' => 'pending',
                    'reason' => $return->reason,
                    'reason_code' => $return->reason_code,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($accept) {
                throw ValidationException::withMessages(['disposition' => 'Unknown return disposition.']);
            }

            if ($accept && $disposition !== 'supplier_return') {
                $inventory->save();
                $this->movement($inventory, $reviewer, 'customer_return_'.$disposition, $returnId, $beforeSellable, $beforeHeld);
            }

            DB::table('customer_return_items')->where('id', $returnItem->id)->update([
                'disposition' => $accept ? $disposition : 'return_to_customer',
                'sales_return_amount' => $accept ? $approvedAmount : '0.00',
                'cost_recovered_amount' => $accept && $disposition === 'restock' && $originalItem->acquisition_cost_at_time !== null
                    ? bcmul($this->decimal((string) $returnItem->quantity), $originalItem->acquisition_cost_at_time, 2)
                    : ($accept ? '0.00' : null),
                'financial_recognized_at' => $accept ? now() : null,
            ]);
            DB::table('customer_returns')->where('id', $returnId)->update([
                'status' => $accept ? 'accepted' : 'rejected_pending_handover',
                'reviewer_id' => $reviewer->id,
                'decision_reason' => $accept && $disposition === 'restock'
                    ? 'Sellability inspected and confirmed. '.$reason
                    : $reason,
                'updated_at' => now(),
            ]);
            if ($accept && bccomp($approvedAmount, '0.00', 2) > 0) {
                DB::table('customer_refunds')->insert([
                    'customer_return_id' => $returnId,
                    'approved_by' => $reviewer->id,
                    'approved_amount' => $approvedAmount,
                    'paid_amount' => '0.00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->audit($reviewer, $accept ? 'customer_return.accepted' : 'customer_return.rejected', $returnId, [
                'requester_id' => $return->requester_id, 'disposition' => $accept ? $disposition : 'return_to_customer',
                'approved_amount' => $accept ? $approvedAmount : '0.00',
            ], $reason);
            if ($accept) {
                app(ApprovalMonitor::class)->afterApproval($reviewer, (int) $return->requester_id);
            }
        }, attempts: 3);
    }

    public function handBack(int $returnId, User $actor): void
    {
        DB::transaction(function () use ($returnId, $actor): void {
            $return = DB::table('customer_returns')->where('id', $returnId)->lockForUpdate()->first();
            if ($return === null) {
                abort(404);
            }
            if ($return->status !== 'rejected_pending_handover') {
                throw ValidationException::withMessages(['handover' => 'Only rejected returns awaiting handover may be returned to the customer.']);
            }
            $item = DB::table('customer_return_items')->where('customer_return_id', $returnId)->first();
            $productId = OrderItem::query()->whereKey($item->order_item_id)->firstOrFail()->product_id;
            $inventory = Inventory::query()->where('product_id', $productId)->lockForUpdate()->firstOrFail();
            if ($inventory->quarantined_quantity < $item->quantity) {
                throw ValidationException::withMessages(['handover' => 'Received stock is no longer in quarantine.']);
            }
            $beforeSellable = $inventory->quantity;
            $beforeHeld = $inventory->quarantined_quantity;
            $inventory->quarantined_quantity = max(0, (int) ($inventory->quarantined_quantity - $item->quantity));
            $inventory->save();
            $this->movement($inventory, $actor, 'customer_return_handed_back', $returnId, $beforeSellable, $beforeHeld);
            DB::table('customer_returns')->where('id', $returnId)->update(['status' => 'rejected', 'updated_at' => now()]);
            $this->audit($actor, 'customer_return.handed_back', $returnId, ['quantity' => $item->quantity], null);
        }, attempts: 3);
    }

    public function recordPayment(int $refundId, User $actor, string $amount, string $method, string $externalReference): void
    {
        DB::transaction(function () use ($refundId, $actor, $amount, $method, $externalReference): void {
            $refund = DB::table('customer_refunds')->where('id', $refundId)->lockForUpdate()->first();
            if ($refund === null) {
                abort(404);
            }
            $approvedAmount = $this->decimal($refund->approved_amount);
            $paidAmount = $this->decimal($refund->paid_amount);
            $amount = $this->decimal($amount);
            $remaining = bcsub($approvedAmount, $paidAmount, 2);
            if (bccomp($amount, '0.00', 2) <= 0 || bccomp($amount, $remaining, 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the unpaid approved refund.']);
            }
            if (DB::table('customer_refund_payments')->where('customer_refund_id', $refundId)->where('external_reference', $externalReference)->exists()) {
                throw ValidationException::withMessages(['external_reference' => 'This refund reference is already recorded.']);
            }
            $paymentId = DB::table('customer_refund_payments')->insertGetId([
                'customer_refund_id' => $refundId,
                'recorded_by' => $actor->id,
                'amount' => $amount,
                'payment_method' => $method,
                'external_reference' => $externalReference,
                'paid_at' => now(),
            ]);
            DB::table('customer_refunds')->where('id', $refundId)->update([
                'paid_amount' => bcadd($paidAmount, $amount, 2),
                'recorded_by' => $actor->id,
                'payment_method' => $method,
                'external_reference' => $externalReference,
                'paid_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($actor, 'customer_refund.paid', $paymentId, ['amount' => $amount, 'method' => $method, 'external_reference' => $externalReference], null, 'refund_payment');
        }, attempts: 3);
    }

    public function reviewPayment(int $paymentId, User $reviewer, string $decision, string $reason): void
    {
        DB::transaction(function () use ($paymentId, $reviewer, $decision, $reason): void {
            $payment = DB::table('customer_refund_payments')->where('id', $paymentId)->lockForUpdate()->first();
            if ($payment === null) {
                abort(404);
            }
            if ((int) $payment->recorded_by === $reviewer->id) {
                throw ValidationException::withMessages(['decision' => 'You cannot reconcile a payment you recorded.']);
            }
            if (! $reviewer->hasAdministrationPermission('returns.reconcile')) {
                abort(403);
            }
            $latest = DB::table('customer_refund_payment_reviews')->where('customer_refund_payment_id', $paymentId)->orderByDesc('id')->first();
            if (in_array($latest?->decision, ['verified', 'voided'], true) || $latest?->decision === $decision) {
                throw ValidationException::withMessages(['decision' => 'This payment already has that reconciliation status.']);
            }
            if ($decision === 'voided' && $latest?->decision !== 'disputed') {
                throw ValidationException::withMessages(['decision' => 'Only a disputed payment record can be voided.']);
            }

            if ($decision === 'voided') {
                $refund = DB::table('customer_refunds')->where('id', $payment->customer_refund_id)->lockForUpdate()->firstOrFail();
                $paidAmount = $this->decimal($refund->paid_amount);
                $paymentAmount = $this->decimal($payment->amount);
                if (bccomp($paidAmount, $paymentAmount, 2) < 0) {
                    throw ValidationException::withMessages(['decision' => 'The refund total no longer includes this payment amount.']);
                }
                $remainingPayment = DB::table('customer_refund_payments as payments')
                    ->where('payments.customer_refund_id', $refund->id)
                    ->where('payments.id', '!=', $paymentId)
                    ->whereNotExists(fn (Builder $query): Builder => $query->selectRaw('1')
                        ->from('customer_refund_payment_reviews as reviews')
                        ->whereColumn('reviews.customer_refund_payment_id', 'payments.id')
                        ->where('reviews.decision', 'voided'))
                    ->orderByDesc('payments.paid_at')->orderByDesc('payments.id')->first();

                DB::table('customer_refunds')->where('id', $refund->id)->update([
                    'paid_amount' => bcsub($paidAmount, $paymentAmount, 2),
                    'recorded_by' => $remainingPayment?->recorded_by,
                    'payment_method' => $remainingPayment?->payment_method,
                    'external_reference' => $remainingPayment?->external_reference,
                    'paid_at' => $remainingPayment?->paid_at,
                    'updated_at' => now(),
                ]);
            }

            DB::table('customer_refund_payment_reviews')->insert([
                'customer_refund_payment_id' => $paymentId,
                'reviewed_by' => $reviewer->id,
                'decision' => $decision,
                'reason' => $reason,
                'created_at' => now(),
            ]);
            $this->audit($reviewer, 'customer_refund.payment_reviewed', $paymentId, [
                'decision' => $decision,
                'previous_decision' => $latest?->decision,
                'amount_removed_from_refund_total' => $decision === 'voided' ? $payment->amount : '0.00',
            ], $reason, 'refund_payment');
        }, attempts: 3);
    }

    private function movement(Inventory $inventory, User $actor, string $kind, int $returnId, int $beforeSellable, int $beforeHeld): void
    {
        DB::table('inventory_movements')->insert([
            'product_id' => $inventory->product_id,
            'actor_id' => $actor->id,
            'kind' => $kind,
            'reference_type' => 'customer_return',
            'reference_id' => $returnId,
            'sellable_before' => $beforeSellable,
            'sellable_delta' => $inventory->quantity - $beforeSellable,
            'sellable_after' => $inventory->quantity,
            'quarantined_before' => $beforeHeld,
            'quarantined_delta' => $inventory->quarantined_quantity - $beforeHeld,
            'quarantined_after' => $inventory->quarantined_quantity,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(User $actor, string $action, int $id, array $changes, ?string $reason, string $subjectType = 'customer_return'): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $id,
            'reason' => $reason,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /**
     * @return numeric-string
     */
    private function decimal(string $value): string
    {
        if (! is_numeric($value) || ! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            throw new \InvalidArgumentException('A valid decimal amount is required.');
        }

        return $value;
    }
}
