<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\PayMongoFlow;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use App\Services\PayMongo\ObserveHostedCheckout;
use App\Services\PayMongo\PaymentRecovery;
use App\Services\PayMongo\ReconcilePayMongoReturn;
use App\Services\PayMongo\StartHostedCheckout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ReconcilePayMongoOrder implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $flowId) {}

    public function uniqueId(): string
    {
        return (string) $this->flowId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(OrderProcessingService $processing, PaymentRecovery $recovery): void
    {
        $flow = PayMongoFlow::query()->find($this->flowId);
        if ($flow === null || ! in_array($flow->channel, ['hosted_checkout', 'direct_online_banking', 'direct_qrph'], true)) {
            return;
        }
        $initialFailures = $flow->recovery_failures;
        try {
            PaymentRecovery::locked($flow->order_id, function () use ($flow, $processing, $recovery, $initialFailures): void {
                $flow->refresh();
                $order = $flow->order;
                if ($order === null || ($flow->status === 'paid' && ! $flow->requires_attention)) {
                    return;
                }
                if ($order->status === OrderStatus::Pending && $order->payment_status->value === 'pending'
                    && ($order->payment_closure_requested_at !== null || $flow->merchant_cutoff_at->lessThanOrEqualTo(now()))) {
                    $actor = $order->payment_closure_requested_by === null ? null : User::query()->find($order->payment_closure_requested_by);
                    if ($order->payment_closure_requested_by !== null && $actor === null) {
                        $recovery->uncertain($flow, reason: 'initiator_unavailable');

                        return;
                    }
                    if ($order->payment_closure_initiator === 'customer' && $actor !== null) {
                        $processing->cancelForCustomer($actor, $order->id);
                    } else {
                        $processing->updateStatus($order, OrderStatus::Cancelled, $actor);
                    }
                    $recovery->observed($flow->fresh(), 'closed');

                    return;
                }
                if ($flow->status === 'failed' && $flow->checkout_session_id === null && $flow->payment_intent_id === null
                    && $flow->operations()->where('kind', 'create_checkout_session')->where('status', 'failed')->exists()) {
                    $recovery->observed($flow, 'failed');

                    return;
                }
                if ($flow->channel === 'hosted_checkout' && $flow->checkout_session_id !== null) {
                    app(ObserveHostedCheckout::class)->execute($flow);
                } elseif ($flow->channel === 'hosted_checkout' && $flow->merchant_cutoff_at->isFuture()
                    && $order->status === OrderStatus::Pending && $order->payment_closure_requested_at === null) {
                    app(StartHostedCheckout::class)->execute($order, allowReplacement: false);
                } else {
                    app(ReconcilePayMongoReturn::class)->execute($order);
                }
                $fresh = $flow->fresh();
                if ($fresh->status === 'reconciliation_required') {
                    if ($fresh->recovery_failures === $initialFailures) {
                        $recovery->uncertain($fresh);
                    }
                } elseif ($fresh->last_checked_at === null) {
                    $recovery->observed($fresh, $fresh->status === 'paid' ? 'paid' : 'awaiting');
                }
            });
        } catch (Throwable $exception) {
            $fresh = $flow->fresh();
            if ($fresh->status !== 'paid' && ($fresh->next_check_at === null || $fresh->next_check_at->lessThanOrEqualTo(now()))) {
                $recovery->uncertain($fresh, $exception);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $flow = PayMongoFlow::query()->find($this->flowId);
        if ($flow !== null) {
            app(PaymentRecovery::class)->uncertain($flow, $exception, 'worker_failure');
        }
    }
}
