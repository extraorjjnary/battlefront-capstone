<?php

namespace App\Actions\Inventory;

use App\Enums\OrderStatus;
use App\Models\CustomerReturnRequest;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\AdministrationEvidenceService;
use App\Services\ApprovalMonitor;
use App\Services\CustomerReturnEligibility;
use App\Services\Notifications\OrderNotificationPublisher;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class HandleCustomerReturn
{
    /** @param array<array-key, mixed> $photos */
    public function receive(User $actor, int $orderItemId, int $quantity, string $condition, string $reason, string $reasonCode, ?int $convertingRequest = null, array $photos = []): int
    {
        $evidence = app(AdministrationEvidenceService::class);
        abort_unless($evidence->canReceiveCustomerReturn($actor), 403);
        Validator::make(['condition' => $condition], ['condition' => ['required', 'in:sealed,opened,damaged']])->validate();
        $orderId = OrderItem::query()->findOrFail($orderItemId)->order_id;
        $prepared = $evidence->prepareProductPhotos($photos);

        try {
            return DB::transaction(function () use ($actor, $orderId, $orderItemId, $quantity, $condition, $reason, $reasonCode, $convertingRequest, $evidence, $prepared): int {
                $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
                if ($order->status !== OrderStatus::Completed || $order->sale()->doesntExist()) {
                    throw ValidationException::withMessages(['order_item_id' => 'Returns require a completed system purchase.']);
                }
                $item = OrderItem::query()->where('order_id', $orderId)->whereKey($orderItemId)->lockForUpdate()->firstOrFail();
                if ($convertingRequest !== null) {
                    $source = CustomerReturnRequest::query()->whereKey($convertingRequest)->lockForUpdate()->first();
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
                $evidence->attachProductPhotos($actor, $id, $prepared);
                if ($convertingRequest !== null) {
                    $source->customer_return_id = $id;
                    $source->save();
                    $this->audit($actor, 'customer_return_request.received', $source->id,
                        ['order_id' => $order->id, 'order_item_id' => $item->id, 'quantity' => $quantity, 'customer_return_id' => $id], $reason, 'customer_return_request');
                }
                $this->movement($inventory, $actor, 'customer_return_received', $id, $beforeSellable, $beforeHeld);
                $this->audit($actor, 'customer_return.received', $id, ['order_id' => $order->id, 'order_item_id' => $item->id, 'quantity' => $quantity], $reason);

                return $id;
            }, attempts: 3);
        } catch (Throwable $exception) {
            $evidence->discardPreparedFiles($prepared);
            throw $exception;
        }
    }

    /** @param array{type?: string, summary?: string|null, reference?: string|null, inspection_notes?: string|null, public_feedback?: string|null} $resolution */
    public function decide(int $returnId, User $reviewer, bool $accept, string $disposition, string $approvedAmount, string $reason, ?int $supplierId = null, bool $inspectionConfirmed = false, array $resolution = []): void
    {
        DB::transaction(function () use ($returnId, $reviewer, $accept, $disposition, $approvedAmount, $reason, $supplierId, $inspectionConfirmed, $resolution): void {
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
            Validator::make($resolution, [
                'type' => [$accept ? 'required' : 'prohibited', Rule::in(['refund', 'replacement', 'repair', 'other'])],
                'summary' => ['required_if:type,replacement,repair,other', Rule::requiredIf($accept && ($resolution['type'] ?? null) === 'refund' && bccomp($approvedAmount, '0.00', 2) > 0 && bccomp($approvedAmount, $maximum, 2) < 0), 'nullable', 'string', 'min:5', 'max:2000'],
                'reference' => ['nullable', 'string', 'max:255'],
                'inspection_notes' => [$accept && $disposition === 'restock' ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'],
                'public_feedback' => ['nullable', 'string', 'min:5', 'max:2000'],
            ])->validate();
            $resolutionType = $resolution['type'] ?? null;
            $inspectionNotes = $resolution['inspection_notes'] ?? null;
            $monetary = $resolutionType === 'refund';
            if ($accept && ($monetary ? bccomp($approvedAmount, '0.00', 2) <= 0 : bccomp($approvedAmount, '0.00', 2) !== 0)) {
                throw ValidationException::withMessages(['approved_amount' => 'Refund resolutions require a positive amount; nonmonetary resolutions require zero.']);
            }
            if ($accept && bccomp($approvedAmount, $maximum, 2) > 0) {
                throw ValidationException::withMessages(['approved_amount' => 'Refund approval exceeds the returned item value.']);
            }

            if (! $accept && (bccomp($approvedAmount, '0.00', 2) !== 0 || $disposition !== '' || $supplierId !== null)) {
                throw ValidationException::withMessages(['decision' => 'A rejected return cannot include a disposition or refund.']);
            }

            $inventory = Inventory::query()->where('product_id', $originalItem->product_id)->lockForUpdate()->firstOrFail();
            if ($inventory->quarantined_quantity < $returnItem->quantity) {
                throw ValidationException::withMessages(['decision' => 'Received stock is no longer in quarantine.']);
            }
            if ($accept && ! $this->hasAccessibleInspectionEvidence($returnId)) {
                throw ValidationException::withMessages(['evidence' => 'A stored product photo is required before accepting this return. Ask Staff to supply missing photos.']);
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
            } elseif ($accept && $disposition === 'return_to_customer') {
                if (! in_array($resolutionType, ['repair', 'other'], true)) {
                    throw ValidationException::withMessages(['disposition' => 'Original-item handback requires a repair or other nonmonetary resolution.']);
                }
            } elseif ($accept) {
                throw ValidationException::withMessages(['disposition' => 'Unknown return disposition.']);
            }

            if ($accept && in_array($disposition, ['restock', 'write_off'], true)) {
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
                    ? 'Fit for sale: '.$inspectionNotes.'. '.$reason
                    : $reason,
                'public_feedback' => $resolution['public_feedback'] ?? null,
                'updated_at' => now(),
            ]);
            if ($accept) {
                DB::table('customer_return_resolutions')->insert([
                    'customer_return_id' => $returnId, 'type' => $resolutionType,
                    'summary' => $resolution['summary'] ?? null, 'reference' => $resolution['reference'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
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
                'resolution_type' => $resolution['type'] ?? null,
                'inspection_notes' => $resolution['inspection_notes'] ?? null,
            ], $reason);
            if ($accept) {
                app(ApprovalMonitor::class)->afterApproval($reviewer, (int) $return->requester_id);
            }
        }, attempts: 3);
    }

    public function completeResolution(int $returnId, User $actor, string $notes, bool $handoverConfirmed = false): void
    {
        abort_unless($actor->can('orders.view') && $actor->can('returns.dispatch'), 403);
        Validator::make(['completion_notes' => $notes], ['completion_notes' => ['required', 'string', 'min:5', 'max:2000']])->validate();
        DB::transaction(function () use ($returnId, $actor, $notes, $handoverConfirmed): void {
            $return = DB::table('customer_returns')->where('id', $returnId)->lockForUpdate()->firstOrFail();
            $resolution = DB::table('customer_return_resolutions')->where('customer_return_id', $returnId)->lockForUpdate()->first();
            if ($return->status !== 'accepted' || $resolution === null || $resolution->type === 'refund' || $resolution->completed_at !== null) {
                throw ValidationException::withMessages(['resolution' => 'Only a recorded pending nonmonetary resolution can be completed.']);
            }
            if (DB::table('customer_return_items')->where('customer_return_id', $returnId)->where('disposition', 'return_to_customer')->exists()) {
                if (! $handoverConfirmed) {
                    throw ValidationException::withMessages(['handover_confirmed' => 'Confirm actual handback of the original item to the customer.']);
                }
                $item = DB::table('customer_return_items')->where('customer_return_id', $returnId)->lockForUpdate()->firstOrFail();
                $productId = OrderItem::query()->whereKey($item->order_item_id)->firstOrFail()->product_id;
                $inventory = Inventory::query()->where('product_id', $productId)->lockForUpdate()->firstOrFail();
                $remainingHeld = $inventory->quarantined_quantity - (int) $item->quantity;
                if ($remainingHeld < 0) {
                    throw ValidationException::withMessages(['resolution' => 'The original item is no longer in quarantine.']);
                }
                $sellable = $inventory->quantity;
                $held = $inventory->quarantined_quantity;
                $inventory->quarantined_quantity = $remainingHeld;
                $inventory->save();
                $this->movement($inventory, $actor, 'customer_resolution_handed_back', $returnId, $sellable, $held);
            }
            DB::table('customer_return_resolutions')->where('id', $resolution->id)->update([
                'completed_by' => $actor->id, 'completed_at' => now(), 'completion_notes' => $notes, 'updated_at' => now(),
            ]);
            $this->audit($actor, 'customer_return.resolution_completed', $returnId, ['resolution_type' => $resolution->type], $notes);
        }, attempts: 3);
    }

    private function hasAccessibleInspectionEvidence(int $returnId): bool
    {
        return DB::table('administration_evidence')->where('subject_type', 'customer_return')->where('subject_id', $returnId)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->pluck('path')->contains(fn (string $path): bool => Storage::disk('local')->exists($path));
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

    public function recordPayment(int $refundId, User $actor, string $amount, string $method, string $submissionKey, bool $paymentConfirmed = false, ?UploadedFile $proof = null): void
    {
        abort_unless($actor->hasAdministrationPermission('returns.record_payment') && $actor->hasAdministrationPermission('returns.approve'), 403);
        Validator::make(['amount' => $amount, 'method' => $method, 'submission_key' => $submissionKey, 'payment_confirmed' => $paymentConfirmed, 'proof' => $proof], [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'method' => ['required', Rule::in(['cash', 'gcash', 'maya', 'bank_transfer', 'other'])],
            'submission_key' => ['required', 'uuid'], 'payment_confirmed' => ['required', 'accepted'],
            'proof' => ['required_unless:method,cash', 'prohibited_if:method,cash', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
        ])->validate();
        $evidence = app(AdministrationEvidenceService::class);
        $prepared = $proof === null ? [] : $evidence->prepareProductPhotos([$proof]);
        try {
            $created = DB::transaction(function () use ($refundId, $actor, $amount, $method, $submissionKey, $prepared, $evidence): bool {
                $refund = DB::table('customer_refunds')->where('id', $refundId)->lockForUpdate()->firstOrFail();
                abort_unless((int) $refund->approved_by === $actor->id, 403);
                $existing = DB::table('customer_refund_payments')->where('submission_key', $submissionKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ((int) $existing->customer_refund_id !== $refundId || (int) $existing->recorded_by !== $actor->id || $existing->payment_method !== $method
                        || bccomp($this->decimal($existing->amount), $this->decimal($amount), 2) !== 0) {
                        throw ValidationException::withMessages(['submission_key' => 'This payment submission has already been used. Refresh the return.']);
                    }

                    return false;
                }
                $approved = $this->decimal($refund->approved_amount);
                $paid = $this->decimal($refund->paid_amount);
                $decimalAmount = $this->decimal($amount);
                if (bccomp($decimalAmount, bcsub($approved, $paid, 2), 2) > 0) {
                    throw ValidationException::withMessages(['amount' => 'Payment exceeds the unpaid approved refund.']);
                }
                $paymentId = DB::table('customer_refund_payments')->insertGetId([
                    'customer_refund_id' => $refundId, 'recorded_by' => $actor->id, 'amount' => $decimalAmount,
                    'payment_method' => $method, 'external_reference' => null, 'submission_key' => $submissionKey, 'paid_at' => now(),
                ]);
                if ($prepared !== []) {
                    $evidence->attachCustomerPaymentProof($actor, $paymentId, $prepared);
                }
                DB::table('customer_refund_payment_reviews')->insert([
                    'customer_refund_payment_id' => $paymentId, 'reviewed_by' => $actor->id, 'decision' => 'verified',
                    'confirmation_method' => 'approver', 'reason' => $method === 'cash' ? 'Cash handed to the customer, confirmed by the return approver.' : 'Payment sent with customer-visible proof, confirmed by the return approver.',
                    'created_at' => now(),
                ]);
                DB::table('customer_refunds')->where('id', $refundId)->update([
                    'paid_amount' => bcadd($paid, $decimalAmount, 2), 'recorded_by' => $actor->id,
                    'payment_method' => $method, 'external_reference' => null, 'paid_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit($actor, 'customer_refund.paid', $paymentId, ['amount' => $decimalAmount, 'method' => $method,
                    'confirmation_method' => 'approver', 'customer_visible_proof' => $prepared !== []], null, 'refund_payment');
                $confirmed = DB::table('customer_refund_payments as payments')
                    ->join('customer_refund_payment_reviews as reviews', 'reviews.customer_refund_payment_id', '=', 'payments.id')
                    ->where('payments.customer_refund_id', $refundId)->where('reviews.decision', 'verified')
                    ->whereRaw('reviews.id = (select max(latest.id) from customer_refund_payment_reviews as latest where latest.customer_refund_payment_id = payments.id)')
                    ->sum('payments.amount');
                if (bccomp($this->decimal((string) $confirmed), $approved, 2) >= 0) {
                    $orderId = DB::table('customer_returns')->where('id', $refund->customer_return_id)->value('order_id');
                    app(OrderNotificationPublisher::class)->afterCommit(Order::query()->whereKey($orderId)->firstOrFail(), 'refund.completed');
                }

                return true;
            }, attempts: 3);
            if (! $created) {
                $evidence->discardPreparedFiles($prepared);
            }
        } catch (Throwable $exception) {
            $evidence->discardPreparedFiles($prepared);
            throw $exception;
        }
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
