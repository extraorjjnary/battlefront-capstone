<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Services\Order\OrderProcessingService;
use App\Services\PayMongo\CloseHostedCheckout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ReconcilePayMongoOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $flowId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('paymongo-flow-'.$this->flowId))->expireAfter(90)->releaseAfter(10)];
    }

    public function handle(CloseHostedCheckout $closeHostedCheckout, OrderProcessingService $orderProcessing): void
    {
        $flow = PayMongoFlow::query()->find($this->flowId);

        if ($flow === null || $flow->merchant_cutoff_at->isFuture()
            || ! in_array($flow->channel, ['hosted_checkout', 'direct_online_banking'], strict: true)) {
            return;
        }

        if (! $closeHostedCheckout->execute($flow->order_id)) {
            return;
        }

        $order = Order::query()->find($flow->order_id);
        if ($order !== null && $order->status === OrderStatus::Pending) {
            $orderProcessing->updateStatus($order, OrderStatus::Cancelled);
        }
    }
}
