<?php

namespace App\Console\Commands;

use App\Jobs\ReconcilePayMongoOrder;
use App\Models\PayMongoFlow;
use Illuminate\Console\Command;

class ReconcilePayMongoOrders extends Command
{
    protected $signature = 'paymongo:reconcile-overdue';

    protected $description = 'Reconcile and close overdue PayMongo checkout sessions before releasing order inventory';

    public function handle(): int
    {
        PayMongoFlow::query()
            ->whereIn('channel', ['hosted_checkout', 'direct_online_banking'])
            ->whereIn('status', ['open', 'closing', 'closed', 'awaiting_payment_method', 'failed', 'retrying', 'reconciliation_required'])
            ->where('merchant_cutoff_at', '<=', now())
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->each(static fn (int $flowId) => ReconcilePayMongoOrder::dispatch($flowId)->onQueue('payments'));

        return self::SUCCESS;
    }
}
