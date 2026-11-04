<?php

namespace App\Actions\PayMongo;

use App\Jobs\ReconcilePayMongoOrder;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use App\Services\PayMongo\PaymentRecovery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RequestPaymentReconciliation
{
    public function __construct(
        private readonly OrderProcessingService $processing,
        private readonly PaymentRecovery $recovery,
    ) {}

    public function execute(User $actor, Order $order): void
    {
        DB::transaction(function () use ($actor, $order): void {
            $current = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($current->payment_origin === 'paymongo' && $this->processing->canProcess($current, $actor), 403);
            $flows = $current->paymongoFlows()->where(fn ($query) => $query->where('status', '!=', 'paid')->orWhere('requires_attention', true))->get();
            abort_if($flows->isEmpty(), 422, 'No payment requires reconciliation.');
            if (! Cache::add('paymongo-admin-reconcile:'.$order->id, true, 30)) {
                return;
            }
            foreach ($flows as $flow) {
                $flow->update(['next_check_at' => now()]);
                ReconcilePayMongoOrder::dispatch($flow->id)->onQueue('payments')->afterCommit();
            }
            $this->recovery->audit($order->id, 'payment.reconciliation_requested', [], $actor->id);
        }, attempts: 3);
    }
}
