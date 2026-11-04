<?php

namespace App\Services\PayMongo;

use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Services\Order\OrderProcessingService;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RetryOrderPayment
{
    public function execute(Order $order, Closure $retry): mixed
    {
        $lock = Cache::lock('paymongo-retry:'.$order->id, 120);
        if (! $lock->get()) {
            throw new RuntimeException('Payment retry is already in progress.');
        }

        try {
            $currentOrder = DB::transaction(function () use ($order): Order {
                $currentOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                if ($currentOrder->payment_origin !== 'paymongo'
                    || $currentOrder->status->value !== 'pending'
                    || $currentOrder->payment_status->value !== 'pending'
                    || $currentOrder->created_at->copy()->addDay()->lessThanOrEqualTo(now())
                    || app(OrderProcessingService::class)->hasCancellationHold($currentOrder)) {
                    throw new RuntimeException('This order cannot accept another payment attempt.');
                }

                $flows = PayMongoFlow::query()->where('order_id', $currentOrder->id)->lockForUpdate()->get();
                if ($flows->contains(fn (PayMongoFlow $flow): bool => $flow->requires_attention || ($flow->active_slot === null && $flow->status !== 'closed'))) {
                    throw new RuntimeException('Previous payment history requires reconciliation.');
                }

                return $currentOrder;
            });

            return PaymentRecovery::locked($order->id, function () use ($currentOrder, $retry): mixed {
                $currentOrder->refresh();
                if ($currentOrder->payment_closure_requested_at !== null) {
                    throw new RuntimeException('Payment closure is in progress.');
                }

                return $retry($currentOrder);
            });
        } finally {
            $lock->release();
        }
    }
}
