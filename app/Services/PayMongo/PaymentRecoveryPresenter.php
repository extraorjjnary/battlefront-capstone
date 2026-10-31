<?php

namespace App\Services\PayMongo;

use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Models\User;
use App\Services\Order\OrderProcessingService;

class PaymentRecoveryPresenter
{
    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'awaiting' => 'Awaiting payment', 'failed' => 'Failed attempt',
            'expired' => 'Expired payment', 'checking' => 'Checking payment',
            'closing' => 'Closing payment', 'needs_attention' => 'Needs attention',
            'paid' => 'Payment verified', 'closed' => 'Payment closed',
        ];
    }

    /** @return array<string, mixed>|null */
    public function detail(Order $order, ?User $actor = null, bool $history = true): ?array
    {
        if ($order->payment_origin !== 'paymongo') {
            return null;
        }
        $order->loadMissing($history ? 'paymongoFlows.operations' : 'paymongoFlows');
        $flows = $order->paymongoFlows->sortByDesc('id');
        $flow = $flows->firstWhere('active_slot', 'active') ?? $flows->first();
        $exception = $flows->firstWhere('requires_attention', true);
        $state = $exception !== null || $flow === null ? 'needs_attention' : (
            $order->payment_status->value === 'verified' ? 'paid' : (
                $order->status->value === 'cancelled' ? 'closed' : (
                    $order->payment_closure_requested_at !== null ? 'closing' : $this->state($flow)
                )
            )
        );
        $reason = ($exception ?? $flow)?->recovery_reason;
        $guidance = match ($state) {
            'failed' => 'The payment attempt failed. The customer can retry before the payment deadline when provider state permits.',
            'expired' => 'The payment attempt or session expired. The customer can retry before the deadline after safe provider confirmation.',
            'closing' => 'Cancellation is requested. Stock remains reserved until every payment flow is confirmed closed and unpaid.',
            'checking' => 'Payment confirmation is pending. Do not request another payment or release stock.',
            'needs_attention' => $reason === 'financial_anomaly'
                ? 'Payment evidence conflicts with this order. Review the provider references with PayMongo support. Do not fulfill, release stock, or refund automatically.'
                : 'Automatic recovery needs investigation. Review provider availability and worker health; stock remains reserved on unpaid pending orders.',
            'paid' => 'Payment is confirmed. Continue the normal order fulfillment workflow.',
            'closed' => 'The order is cancelled. Reserved stock was restored through cancellation; no refund was issued automatically.',
            default => 'Awaiting customer payment. The original payment deadline remains unchanged.',
        };

        return [
            'channel' => $flow->channel ?? 'hosted_checkout', 'bank' => $flow?->bank_code,
            'status' => $flow->status ?? 'reconciliation_required',
            'state' => $state, 'label' => self::labels()[$state], 'guidance' => $guidance,
            'merchant_cutoff_at' => $flow?->merchant_cutoff_at?->toIso8601String() ?? $order->created_at->copy()->addDay()->toIso8601String(),
            'last_checked_at' => $flow?->last_checked_at?->toIso8601String(),
            'next_check_at' => $flow?->next_check_at?->toIso8601String(),
            'exception_since' => $exception?->exception_since?->toIso8601String(),
            'reason' => $reason,
            'stock_disposition' => $order->status->value === 'cancelled' ? 'Restored on cancellation'
                : ($order->payment_status->value === 'verified' ? 'Allocated to the paid order' : 'Reserved; not released'),
            'can_reconcile' => $actor !== null && $flow !== null && app(OrderProcessingService::class)->canProcess($order, $actor)
                && ($state !== 'paid' && $state !== 'closed'),
            'history' => ! $history ? [] : $flows->map(fn (PayMongoFlow $item): array => [
                'id' => $item->id, 'status' => $item->status,
                'outcome' => self::labels()[$this->state($item)],
                'provider_reference' => $item->checkout_session_id ?? $item->payment_intent_id,
                'payment_id' => $item->payment_id,
                'events' => $this->events($item),
                'last_checked_at' => $item->last_checked_at?->toIso8601String(),
                'created_at' => $item->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return list<array{id: int, label: string, reference: string|null, at: string|null}> */
    private function events(PayMongoFlow $flow): array
    {
        $events = [];
        foreach ($flow->operations->sortByDesc('id') as $operation) {
            if (! in_array($operation->kind, ['observe_hosted_failure', 'resume_hosted_failure', 'expire_checkout_session', 'payment_anomaly'], true)) {
                continue;
            }
            $events[] = [
                'id' => $operation->id,
                'label' => match ($operation->kind) {
                    'observe_hosted_failure' => 'Failure or expiry confirmed',
                    'resume_hosted_failure' => 'Customer resumed payment',
                    'expire_checkout_session' => $operation->status === 'completed' ? 'Session closure confirmed' : 'Session closure awaiting confirmation',
                    default => 'Payment requires investigation',
                },
                'reference' => $operation->kind === 'payment_anomaly' ? $operation->provider_reference : null,
                'at' => $operation->created_at?->toIso8601String(),
            ];
        }

        return $events;
    }

    private function state(?PayMongoFlow $flow): string
    {
        if ($flow === null) {
            return 'checking';
        }
        if ($flow->requires_attention) {
            return 'needs_attention';
        }

        return match ($flow->status) {
            'paid' => 'paid', 'closing' => 'closing',
            'reconciliation_required', 'creating', 'retrying' => 'checking',
            'closed' => 'expired', 'failed' => 'failed',
            default => in_array($flow->recovery_outcome, ['failed', 'expired'], true) ? $flow->recovery_outcome : 'awaiting',
        };
    }
}
