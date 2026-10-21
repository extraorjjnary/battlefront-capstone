<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Repositories\Reporting\SalesReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StaffPerformanceController extends Controller
{
    public function index(Request $request, SalesReportRepository $reports): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'handler_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $canViewAll = $request->user()->can('staff_reports.view');
        $handlerId = isset($filters['handler_id']) ? (int) $filters['handler_id'] : $request->user()->id;
        abort_if(! $canViewAll && $handlerId !== $request->user()->id, 403);
        abort_unless(DB::table('users')->where('id', $handlerId)->where('role', 'administrator')->exists(), 404);

        $useCurrentMonth = $canViewAll && ! isset($filters['handler_id'])
            && ! $request->has('from') && ! $request->has('to');
        $timezone = config('battlefront.timezone');
        $from = $filters['from'] ?? ($useCurrentMonth ? CarbonImmutable::today($timezone)->startOfMonth()->toDateString() : null);
        $to = $filters['to'] ?? ($useCurrentMonth ? CarbonImmutable::today($timezone)->toDateString() : null);

        $recordedSales = $reports->attributedSales($from, $to);

        $sales = DB::table('users')
            ->leftJoinSub(clone $recordedSales, 'recorded_sales', 'users.id', '=', 'recorded_sales.handler_id')
            ->where('users.role', 'administrator')
            ->whereNotNull('users.administration_role_id')
            ->when(! $canViewAll, fn ($query) => $query->where('users.id', $request->user()->id))
            ->select('users.id', 'users.name')
            ->selectRaw('COUNT(recorded_sales.id) as orders_count, COALESCE(SUM(recorded_sales.product_revenue), 0) as gross_sales')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('gross_sales')
            ->get();

        $refundStart = $from === null ? null : CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()->toDateTimeString();
        $refundEnd = $to === null ? null : CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc()->toDateTimeString();
        $refunds = DB::table('customer_refund_payments as payments')
            ->join('customer_refunds as refunds', 'refunds.id', '=', 'payments.customer_refund_id')
            ->join('customer_returns as returns', 'returns.id', '=', 'refunds.customer_return_id')
            ->join('sales', 'sales.order_id', '=', 'returns.order_id')
            ->when($refundStart, fn ($query) => $query->where('payments.paid_at', '>=', $refundStart))
            ->when($refundEnd, fn ($query) => $query->where('payments.paid_at', '<', $refundEnd))
            ->whereExists(fn ($reviews) => $reviews->selectRaw('1')
                ->from('customer_refund_payment_reviews as verified_reviews')
                ->whereColumn('verified_reviews.customer_refund_payment_id', 'payments.id')
                ->where('verified_reviews.decision', 'verified')
                ->when($refundEnd, fn ($query) => $query->where('verified_reviews.created_at', '<', $refundEnd)))
            ->select('sales.handler_id')
            ->selectRaw('SUM(payments.amount) as amount')
            ->groupBy('sales.handler_id')
            ->pluck('amount', 'handler_id');

        $transactions = (clone $recordedSales)->where('sales.handler_id', $handlerId)
            ->orderByDesc('sales.sale_date')->orderByDesc('sales.id')
            ->paginate(20, ['*'], 'sale_page')->withQueryString();
        $orders = Order::query()->whereIn('id', $transactions->getCollection()->pluck('order_id'))
            ->get(['id'])->keyBy('id');
        $transactions->through(fn (\stdClass $sale): array => [
            'id' => (int) $sale->id,
            'sale_date' => $sale->sale_date,
            'order_id' => (int) $sale->order_id,
            'order_reference' => $orders->get($sale->order_id)->reference,
            'gross_sales' => (string) $sale->product_revenue,
            'order_url' => route('administration.orders.show', $sale->order_id),
        ]);

        return Inertia::render('Administration/Reports/StaffPerformance', [
            'filters' => ['from' => $from, 'to' => $to],
            'can_view_all' => $canViewAll,
            'selected_handler_id' => $handlerId,
            'selected_handler_name' => DB::table('users')->where('id', $handlerId)->value('name'),
            'transactions' => $transactions,
            'staff' => $sales->map(fn (object $sale): array => [
                'id' => $sale->id,
                'name' => $sale->name,
                'orders_count' => (int) $sale->orders_count,
                'gross_sales' => (string) $sale->gross_sales,
                'refunds_paid' => bcadd((string) ($refunds[$sale->id] ?? '0.00'), '0.00', 2),
                'net_sales' => bcsub((string) $sale->gross_sales, (string) ($refunds[$sale->id] ?? '0.00'), 2),
            ]),
            'unattributed_count' => $canViewAll ? DB::table('sales')->whereNull('handler_id')
                ->when($from, fn ($query) => $query->whereDate('sale_date', '>=', $from))
                ->when($to, fn ($query) => $query->whereDate('sale_date', '<=', $to))->count() : 0,
        ]);
    }
}
