<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

class AdministrationWorkQueue
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $actor, array $filters = []): LengthAwarePaginator
    {
        $query = $this->query($actor);

        if ($query === null) {
            return new LengthAwarePaginator([], 0, 25);
        }

        return $query
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind): Builder => $query->where('kind', $kind))
            ->when($filters['responsible'] ?? null, fn (Builder $query, string $responsible): Builder => $query->where('responsible_name', 'like', '%'.$responsible.'%'))
            ->when($filters['older_than'] ?? null, fn (Builder $query, int $days): Builder => $query->where('created_at', '<', now()->subDays($days)))
            ->orderBy('created_at')->orderBy('kind')->orderBy('id')
            ->paginate(25)->withQueryString()
            ->through(fn (stdClass $entry): array => $this->present($entry));
    }

    /** @return array<string, int> */
    public function counts(User $actor): array
    {
        return $this->query($actor)?->select('kind')->selectRaw('count(*) as total')
            ->groupBy('kind')->pluck('total', 'kind')
            ->map(fn (mixed $total): int => (int) $total)->all() ?? [];
    }

    private function query(User $actor): ?Builder
    {
        $queries = [];
        if ($actor->can('orders.view') && $actor->can('returns.dispatch')) {
            $queries[] = DB::table('customer_return_resolutions as work')
                ->select('work.id', 'work.customer_return_id as case_id', 'work.created_at')
                ->selectRaw("null as responsible_name, null as responsible_active, 'pending' as status, 'customer_resolution' as kind")
                ->where('work.type', '<>', 'refund')->whereNull('work.completed_at');
        }
        if (app(AdministrationEvidenceService::class)->canReceiveCustomerReturn($actor)) {
            $queries[] = DB::table('customer_return_requests as work')
                ->select('work.id', 'work.id as case_id', 'work.created_at')
                ->selectRaw("null as responsible_name, null as responsible_active, 'submitted' as status, 'customer_request' as kind")
                ->whereNull('work.customer_return_id')->whereNull('work.withdrawn_at');
        }

        if ($actor->can('inventory.view') && $actor->can('inventory.approve')) {
            $queries[] = $this->entries('inventory_adjustment_requests as work', 'stock_adjustment', 'pending')
                ->where('work.requester_id', '<>', $actor->id);
        }
        if ($actor->can('inventory.view') && $actor->can('returns.approve')) {
            $queries[] = $this->entries('damage_reports as work', 'damage', 'pending')
                ->where('work.requester_id', '<>', $actor->id);
            $queries[] = $this->entries('supplier_returns as work', 'supplier_return', 'pending')
                ->where('work.requester_id', '<>', $actor->id);
            $queries[] = $this->entries('supplier_returns as work', 'supplier_return', 'dispatched');
            $queries[] = $this->entries('supplier_returns as work', 'supplier_return', 'rejected_follow_up')
                ->where('work.requester_id', '<>', $actor->id)
                ->where(fn (Builder $query): Builder => $query->whereNull('work.reviewer_id')
                    ->orWhere('work.reviewer_id', '<>', $actor->id));
        }
        if ($actor->can('inventory.view') && $actor->can('returns.dispatch')) {
            $queries[] = $this->entries('supplier_returns as work', 'supplier_return', 'approved');
        }
        if ($actor->can('inventory.view') && $actor->can('returns.resolve')) {
            $queries[] = $this->entries('supplier_returns as work', 'supplier_return', 'closed')
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('supplier_return_resolutions')
                    ->whereColumn('supplier_return_resolutions.supplier_return_id', 'work.id'));
        }
        if ($actor->can('orders.view') && $actor->can('returns.dispatch')) {
            $queries[] = $this->entries('customer_returns as work', 'customer_handover', 'rejected_pending_handover');
        }
        if ($actor->can('orders.view') && $actor->can('returns.record_payment') && $actor->can('returns.approve')) {
            $queries[] = DB::table('customer_refunds as work')
                ->join('customer_returns as returns', 'returns.id', '=', 'work.customer_return_id')
                ->leftJoin('users', 'users.id', '=', 'work.approved_by')
                ->select('work.id', 'work.customer_return_id as case_id', 'work.created_at', 'users.name as responsible_name')
                ->selectRaw('null as responsible_active')
                ->selectRaw("'unpaid' as status, 'refund' as kind")
                ->where('work.approved_by', $actor->id)->whereColumn('work.paid_amount', '<', 'work.approved_amount');
        }
        if ($actor->can('orders.view') && $actor->can('returns.reconcile')) {
            $queries[] = DB::table('customer_refund_payments as work')
                ->join('customer_refunds as refunds', 'refunds.id', '=', 'work.customer_refund_id')
                ->join('customer_returns as returns', 'returns.id', '=', 'refunds.customer_return_id')
                ->leftJoin('users', 'users.id', '=', 'work.recorded_by')
                ->select('work.id', 'returns.id as case_id')
                ->selectRaw('work.paid_at as created_at')
                ->addSelect('users.name as responsible_name')
                ->selectRaw('null as responsible_active')
                ->selectRaw("'unreviewed' as status, 'refund_reconciliation' as kind")
                ->where('work.recorded_by', '<>', $actor->id)
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('customer_refund_payment_reviews as reviews')
                    ->whereColumn('reviews.customer_refund_payment_id', 'work.id')
                    ->whereRaw('reviews.id = (select max(latest.id) from customer_refund_payment_reviews as latest where latest.customer_refund_payment_id = work.id)')
                    ->whereIn('reviews.decision', ['verified', 'voided']));
        }
        if ($actor->can('orders.view') && $actor->can('returns.approve')) {
            $queries[] = $this->entries('customer_returns as work', 'customer_return', 'pending')
                ->where('work.requester_id', '<>', $actor->id);
        }
        if ($actor->can('orders.view') && ($actor->can('orders.process') || $actor->can('orders.assign'))) {
            $queries[] = DB::table('orders as work')
                ->leftJoin('users', 'users.id', '=', 'work.assigned_handler_id')
                ->select('work.id', 'work.id as case_id', 'work.created_at', 'users.name as responsible_name', 'users.is_active as responsible_active', 'work.status')
                ->selectRaw("'order' as kind")
                ->whereIn('work.status', ['pending', 'processing'])
                ->when(! $actor->can('orders.assign'), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->whereNull('work.assigned_handler_id')->orWhere('users.is_active', true)));
        }

        if ($queries === []) {
            return null;
        }

        $combined = array_shift($queries);
        foreach ($queries as $query) {
            $combined->unionAll($query);
        }

        return DB::query()->fromSub($combined, 'work_queue');
    }

    /** @param string|list<string> $statuses */
    private function entries(string $table, string $kind, string|array $statuses): Builder
    {
        return DB::table($table)
            ->leftJoin('users', 'users.id', '=', 'work.requester_id')
            ->select('work.id', 'work.id as case_id', 'work.created_at', 'users.name as responsible_name')
            ->selectRaw('null as responsible_active')
            ->addSelect('work.status')
            ->selectRaw('? as kind', [$kind])
            ->whereIn('work.status', (array) $statuses);
    }

    /** @return array<string, mixed> */
    private function present(stdClass $entry): array
    {
        [$action, $route] = match ($entry->kind) {
            'stock_adjustment' => ['Review stock request', 'administration.inventory-adjustments.index'],
            'damage' => ['Decide damage disposition', 'administration.damage-reports.index'],
            'supplier_return' => match ($entry->status) {
                'approved' => ['Dispatch supplier return', 'administration.supplier-returns.index'],
                'dispatched' => ['Record supplier acknowledgment', 'administration.supplier-returns.index'],
                'rejected_follow_up' => ['Decide supplier follow-up', 'administration.supplier-returns.index'],
                'closed' => ['Record supplier resolution', 'administration.supplier-returns.index'],
                default => ['Review supplier return', 'administration.supplier-returns.index'],
            },
            'customer_handover' => ['Hand goods back to customer', 'administration.customer-returns.index'],
            'customer_request' => ['Confirm physical receipt of customer request', 'administration.customer-returns.index'],
            'customer_resolution' => ['Confirm actual customer resolution fulfillment', 'administration.customer-returns.index'],
            'refund' => ['Record outstanding refund payment', 'administration.customer-returns.index'],
            'refund_reconciliation' => ['Reconcile refund payment', 'administration.customer-returns.index'],
            'customer_return' => ['Review customer return', 'administration.customer-returns.index'],
            'order' => [$entry->responsible_active !== null && ! (bool) $entry->responsible_active ? 'Reassign inactive handler' : ($entry->status === 'pending' ? 'Review payment and assign or process order' : 'Review active fulfillment'), 'administration.orders.show'],
            default => throw new LogicException('Unknown administration work queue item.'),
        };

        $id = (int) $entry->id;

        return [
            'id' => $id,
            'kind' => $entry->kind,
            'created_at' => $entry->created_at,
            'age_days' => max(0, (int) CarbonImmutable::parse($entry->created_at)->diffInDays(now())),
            'responsible_name' => $entry->responsible_name === null ? 'Unassigned' : ($entry->responsible_active !== null && ! (bool) $entry->responsible_active ? $entry->responsible_name.' (inactive)' : $entry->responsible_name),
            'reference' => $entry->kind === 'order' ? sprintf('BF-%06d', $id) : strtoupper(str_replace('_', ' ', $entry->kind)).' #'.$id,
            'required_action' => $action,
            'href' => $route === 'administration.orders.show' ? route($route, $id) : route($route, [
                ...($entry->kind === 'customer_request' ? ['request_id' => $id] : ['case' => $entry->case_id ?? $id]),
                ...($entry->kind === 'refund_reconciliation' ? ['payment_id' => $id] : []),
            ]).($entry->kind === 'refund_reconciliation' ? '#refund-payment-'.$id : ''),
        ];
    }
}
