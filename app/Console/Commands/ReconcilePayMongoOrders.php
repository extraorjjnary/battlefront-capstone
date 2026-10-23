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
            ->whereIn('channel', ['hosted_checkout', 'direct_online_banking', 'direct_qrph'])
            ->whereIn('status', ['open', 'closing', 'closed', 'awaiting_payment_method', 'failed', 'retrying', 'reconciliation_required'])
            ->whereHas('order', fn ($query) => $query->where('status', 'pending')->where('payment_status', 'pending'))
            ->where(function ($query): void {
                $query->where('merchant_cutoff_at', '<=', now())
                    ->orWhere(function ($recovery): void {
                        $recovery->where('channel', 'hosted_checkout')
                            ->where('status', 'reconciliation_required')
                            ->where('merchant_cutoff_at', '>', now())
                            ->whereHas('operations', fn ($operation) => $operation
                                ->where('kind', 'create_checkout_session')
                                ->whereIn('status', ['dispatched', 'unknown'])
                                ->where(fn ($due) => $due->whereNull('next_check_at')->orWhere('next_check_at', '<=', now())));
                    });
            })
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->each(static fn (int $flowId) => ReconcilePayMongoOrder::dispatch($flowId)->onQueue('payments'));

        return self::SUCCESS;
    }
}
