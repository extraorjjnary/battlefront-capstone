<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Notifications\OrderNotificationPublisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomerRefundPaymentService
{
    public function __construct(private readonly AdministrationEvidenceService $evidence) {}

    public function recordPayment(int $refundId, User $actor, string $amount, string $method, string $submissionKey, bool $paymentConfirmed = false, ?UploadedFile $proof = null, ?string $externalReference = null): void
    {
        abort_unless($actor->hasAdministrationPermission('returns.record_payment') && $actor->hasAdministrationPermission('returns.approve'), 403);
        Validator::make(['amount' => $amount, 'method' => $method, 'submission_key' => $submissionKey, 'payment_confirmed' => $paymentConfirmed, 'proof' => $proof], [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'method' => ['required', Rule::in(['cash', 'gcash', 'maya', 'bank_transfer', 'other'])],
            'submission_key' => ['required', 'uuid'], 'payment_confirmed' => ['required', 'accepted'],
            'proof' => ['required_unless:method,cash', 'prohibited_if:method,cash', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
        ])->validate();
        $source = DB::table('customer_refunds')->where('id', $refundId)->firstOrFail();
        $cancellation = $source->cancellation_request_id !== null;
        $cancellationOrderId = $cancellation ? DB::table('order_cancellation_requests')->where('id', $source->cancellation_request_id)->value('order_id') : null;
        $reference = $cancellation && filled($externalReference) ? trim($externalReference) : null;
        if ($cancellation) {
            abort_unless(in_array($actor->administrationRoleCode(), ['manager', 'owner'], true) && $actor->hasAdministrationPermission('orders.view'), 403);
            Validator::make(['payment_method' => $method, 'external_reference' => $reference], [
                'payment_method' => ['required', Rule::in(['gcash', 'maya'])],
                'external_reference' => ['required', 'string', 'max:255'],
            ])->validate();
        }
        $evidence = $this->evidence;
        $prepared = $proof === null ? [] : $evidence->prepareProductPhotos([$proof]);
        try {
            $created = DB::transaction(function () use ($refundId, $actor, $amount, $method, $submissionKey, $prepared, $evidence, $source, $cancellation, $cancellationOrderId, $reference): bool {
                if ($cancellation) {
                    $order = Order::query()->whereKey($cancellationOrderId)->lockForUpdate()->firstOrFail();
                    $order->shipment()->lockForUpdate()->first();
                    $request = DB::table('order_cancellation_requests')->where('id', $source->cancellation_request_id)->lockForUpdate()->firstOrFail();
                    if ($request->status !== 'approved' || $order->status !== OrderStatus::Cancelled) {
                        throw ValidationException::withMessages(['status' => 'Approve cancellation before recording a refund.']);
                    }
                }
                $refund = DB::table('customer_refunds')->where('id', $refundId)->lockForUpdate()->firstOrFail();
                $currentActor = $actor->fresh();
                abort_unless((int) $refund->approved_by === $actor->id
                    && $currentActor->hasAdministrationPermission('returns.record_payment')
                    && $currentActor->hasAdministrationPermission('returns.approve'), 403);
                if ($cancellation) {
                    abort_unless(in_array($currentActor->administrationRoleCode(), ['manager', 'owner'], true) && $currentActor->hasAdministrationPermission('orders.view'), 403);
                }
                $existing = DB::table('customer_refund_payments')->where('submission_key', $submissionKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ((int) $existing->customer_refund_id !== $refundId || (int) $existing->recorded_by !== $actor->id || $existing->payment_method !== $method
                        || ($cancellation && $existing->external_reference !== $reference)
                        || bccomp($this->decimal($existing->amount), $this->decimal($amount), 2) !== 0) {
                        throw ValidationException::withMessages(['submission_key' => 'This payment submission has already been used. Refresh the return.']);
                    }

                    return false;
                }
                if ($cancellation && DB::table('customer_refund_payments')->where('customer_refund_id', $refundId)->where('external_reference', $reference)->lockForUpdate()->first() !== null) {
                    throw ValidationException::withMessages(['external_reference' => 'This transfer reference has already been recorded for this refund.']);
                }
                $approved = $this->decimal($refund->approved_amount);
                $paid = $this->decimal($refund->paid_amount);
                $decimalAmount = $this->decimal($amount);
                if (bccomp($decimalAmount, bcsub($approved, $paid, 2), 2) > 0) {
                    throw ValidationException::withMessages(['amount' => 'Payment exceeds the unpaid approved refund.']);
                }
                $previousConfirmed = $this->confirmedTotal($refundId);
                $paymentId = DB::table('customer_refund_payments')->insertGetId([
                    'customer_refund_id' => $refundId, 'recorded_by' => $actor->id, 'amount' => $decimalAmount,
                    'payment_method' => $method, 'external_reference' => $reference, 'submission_key' => $submissionKey, 'paid_at' => now(),
                ]);
                if ($prepared !== []) {
                    $evidence->attachCustomerPaymentProof($actor, $paymentId, $prepared);
                }
                DB::table('customer_refund_payment_reviews')->insert([
                    'customer_refund_payment_id' => $paymentId, 'reviewed_by' => $actor->id, 'decision' => 'verified',
                    'confirmation_method' => 'approver', 'reason' => $method === 'cash' ? 'Cash handed to the customer, confirmed by the refund approver.' : 'Payment sent with customer-visible proof, confirmed by the refund approver.',
                    'created_at' => now(),
                ]);
                DB::table('customer_refunds')->where('id', $refundId)->update([
                    'paid_amount' => bcadd($paid, $decimalAmount, 2), 'recorded_by' => $actor->id,
                    'payment_method' => $method, 'external_reference' => $reference, 'paid_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit($actor, 'customer_refund.paid', $paymentId, ['amount' => $decimalAmount, 'method' => $method,
                    'confirmation_method' => 'approver', 'customer_visible_proof' => $prepared !== []], null, 'refund_payment');
                $this->notifyCompletionAfterCommit($refundId, $previousConfirmed);

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

    public function confirmedTotal(int $refundId): string
    {
        return DB::table('customer_refund_payments as payments')
            ->join('customer_refund_payment_reviews as reviews', 'reviews.customer_refund_payment_id', '=', 'payments.id')
            ->where('payments.customer_refund_id', $refundId)->where('reviews.decision', 'verified')
            ->whereRaw('reviews.id = (select max(latest.id) from customer_refund_payment_reviews as latest where latest.customer_refund_payment_id = payments.id)')
            ->orderBy('payments.id')->lockForUpdate()->get(['payments.amount'])
            ->reduce(fn (string $total, object $payment): string => bcadd($total, $this->decimal((string) $payment->amount), 2), '0.00');
    }

    public function notifyCompletionAfterCommit(int $refundId, string $previousConfirmed): void
    {
        $refund = DB::table('customer_refunds')->where('id', $refundId)->firstOrFail();
        $approved = $this->decimal((string) $refund->approved_amount);
        if (bccomp($approved, '0.00', 2) <= 0 || bccomp($this->decimal($previousConfirmed), $approved, 2) >= 0
            || bccomp($this->decimal($this->confirmedTotal($refundId)), $approved, 2) < 0) {
            return;
        }
        $orderId = $refund->cancellation_request_id !== null
            ? DB::table('order_cancellation_requests')->where('id', $refund->cancellation_request_id)->value('order_id')
            : DB::table('customer_returns')->where('id', $refund->customer_return_id)->value('order_id');
        app(OrderNotificationPublisher::class)->afterCommit(Order::query()->where('id', $orderId)->firstOrFail(), 'refund.completed');
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
