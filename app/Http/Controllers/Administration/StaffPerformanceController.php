<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StaffPerformanceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? today()->toDateString();
        $canViewAll = $request->user()->can('staff_reports.view');

        $sales = DB::table('users')
            ->leftJoin('sales', function ($join) use ($from, $to): void {
                $join->on('users.id', '=', 'sales.handler_id')
                    ->whereDate('sales.sale_date', '>=', $from)
                    ->whereDate('sales.sale_date', '<=', $to);
            })
            ->where('users.role', 'administrator')
            ->whereNotNull('users.administration_role_id')
            ->when(! $canViewAll, fn ($query) => $query->where('users.id', $request->user()->id))
            ->select('users.id', 'users.name')
            ->selectRaw('COUNT(sales.id) as orders_count, COALESCE(SUM(sales.amount), 0) as gross_sales')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('gross_sales')
            ->get();

        $refunds = DB::table('customer_refund_payments as payments')
            ->join('customer_refunds as refunds', 'refunds.id', '=', 'payments.customer_refund_id')
            ->join('customer_returns as returns', 'returns.id', '=', 'refunds.customer_return_id')
            ->join('sales', 'sales.order_id', '=', 'returns.order_id')
            ->whereDate('payments.paid_at', '>=', $from)
            ->whereDate('payments.paid_at', '<=', $to)
            ->select('sales.handler_id')
            ->selectRaw('SUM(payments.amount) as amount')
            ->groupBy('sales.handler_id')
            ->pluck('amount', 'handler_id');

        return Inertia::render('Administration/Reports/StaffPerformance', [
            'filters' => ['from' => $from, 'to' => $to],
            'can_view_all' => $canViewAll,
            'staff' => $sales->map(fn (object $sale): array => [
                'id' => $sale->id,
                'name' => $sale->name,
                'orders_count' => (int) $sale->orders_count,
                'gross_sales' => (string) $sale->gross_sales,
                'refunds_paid' => (string) ($refunds[$sale->id] ?? '0.00'),
            ]),
            'unattributed_count' => $canViewAll ? DB::table('sales')->whereNull('handler_id')
                ->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)->count() : 0,
        ]);
    }
}
