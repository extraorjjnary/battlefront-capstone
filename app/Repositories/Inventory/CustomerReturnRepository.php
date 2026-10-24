<?php

namespace App\Repositories\Inventory;

use App\Enums\OrderStatus;
use App\Models\CustomerReturnRequest;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerReturnRepository
{
    /** @return LengthAwarePaginator<int, CustomerReturnRequest> */
    public function requests(int|string|null $requestId): LengthAwarePaginator
    {
        return CustomerReturnRequest::query()->with(['customer:id,name', 'order', 'item.product'])
            ->when($requestId !== null, fn ($query) => $query->whereKey($requestId),
                fn ($query) => $query->whereNull('customer_return_id')->whereNull('withdrawn_at'))
            ->orderBy('created_at')->orderBy('id')->paginate(10, ['*'], 'requests_page')->withQueryString();
    }

    public function paymentReturnId(int|string $paymentId): int|string|null
    {
        return DB::table('customer_refund_payments as payments')
            ->join('customer_refunds as refunds', 'refunds.id', '=', 'payments.customer_refund_id')
            ->where('payments.id', $paymentId)->value('refunds.customer_return_id');
    }

    public function completedOrder(?int $orderId): ?Order
    {
        return $orderId !== null ? Order::query()->whereKey($orderId)->where('status', OrderStatus::Completed->value)
            ->with('items.product:id,name')->first() : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function paginateReturns(array $filters, int|string|null $case, bool $overdue, CarbonInterface $overdueCutoff): LengthAwarePaginator
    {
        return DB::table('customer_returns as returns')
            ->join('orders', 'orders.id', '=', 'returns.order_id')
            ->join('users', 'users.id', '=', 'returns.requester_id')
            ->join('users as customers', 'customers.id', '=', 'orders.user_id')
            ->leftJoin('users as reviewers', 'reviewers.id', '=', 'returns.reviewer_id')
            ->join('customer_return_items as items', 'items.customer_return_id', '=', 'returns.id')
            ->join('order_items', 'order_items.id', '=', 'items.order_item_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('customer_refunds as refunds', 'refunds.customer_return_id', '=', 'returns.id')
            ->leftJoin('customer_return_resolutions as resolution', 'resolution.customer_return_id', '=', 'returns.id')
            ->leftJoin('customer_return_requests as origin_request', 'origin_request.customer_return_id', '=', 'returns.id')
            ->leftJoin('users as completers', 'completers.id', '=', 'resolution.completed_by')
            ->select('returns.*', 'users.name as requester_name', 'customers.name as customer_name', 'items.quantity', 'items.condition', 'items.disposition',
                'orders.id as purchase_order_id', 'refunds.id as refund_id', 'refunds.approved_by as refund_approver_id',
                'reviewers.name as reviewer_name',
                'resolution.type as resolution_type', 'resolution.summary as resolution_summary', 'resolution.reference as resolution_reference',
                'resolution.completed_at as resolution_completed_at', 'resolution.completion_notes', 'completers.name as resolution_completed_by_name',
                'origin_request.eligibility_path as request_eligibility_path', 'origin_request.eligibility_deadline_at as request_deadline_at')
            ->selectRaw('CAST(order_items.price_at_time AS CHAR) as purchased_unit_price, CAST(refunds.approved_amount AS CHAR) as approved_amount, CAST(refunds.paid_amount AS CHAR) as paid_amount')
            ->selectRaw('COALESCE(order_items.product_name_at_time, products.name) as product_name')
            ->when($case, fn ($query, $id) => $query->where('returns.id', $id))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(function ($query) use ($search): void {
                $query->where('products.name', 'like', '%'.$search.'%')
                    ->orWhere('order_items.product_name_at_time', 'like', '%'.$search.'%')
                    ->orWhere('users.name', 'like', '%'.$search.'%')
                    ->orWhere('customers.name', 'like', '%'.$search.'%')
                    ->orWhere('returns.reason', 'like', '%'.$search.'%');

                if (preg_match('/^(?:BF-)?(\d+)$/i', $search, $matches) === 1) {
                    $query->orWhere('orders.id', (int) $matches[1]);
                }
            }))
            ->when($overdue, fn ($query) => $query->whereIn('returns.status', ['pending', 'rejected_pending_handover'])
                ->where('returns.created_at', '<', $overdueCutoff))
            ->orderByDesc('returns.created_at')->paginate(20)->withQueryString();
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    public function heldReturnIds(Collection $ids): Collection
    {
        $heldBySupplierReturn = DB::table('supplier_returns')->whereIn('customer_return_item_id', DB::table('customer_return_items')
            ->whereIn('customer_return_id', $ids)->pluck('id'))
            ->whereIn('status', ['pending', 'approved', 'rejected_follow_up'])
            ->pluck('customer_return_item_id');

        return DB::table('customer_return_items')->whereIn('id', $heldBySupplierReturn)->pluck('customer_return_id')->flip();
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<array-key, \stdClass>
     */
    public function payments(Collection $ids): Collection
    {
        return DB::table('customer_refund_payments')->join('users as recorders', 'recorders.id', '=', 'customer_refund_payments.recorded_by')
            ->whereIn('customer_refund_id', $ids)
            ->orderBy('customer_refund_payments.id')->select(['customer_refund_payments.id', 'customer_refund_id', 'recorded_by', 'recorders.name as recorder_name', 'payment_method', 'external_reference', 'paid_at'])
            ->selectRaw('CAST(customer_refund_payments.amount AS CHAR) as amount')->get();
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<array-key, \stdClass>
     */
    public function reviews(Collection $ids): Collection
    {
        return DB::table('customer_refund_payment_reviews')->join('users as verifiers', 'verifiers.id', '=', 'customer_refund_payment_reviews.reviewed_by')
            ->whereIn('customer_refund_payment_id', $ids)
            ->orderByDesc('customer_refund_payment_reviews.id')->get(['customer_refund_payment_id', 'decision', 'reason', 'confirmation_method', 'verifiers.name as reviewer_name'])
            ->unique('customer_refund_payment_id')->keyBy('customer_refund_payment_id');
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<array-key, \stdClass>
     */
    public function productPhotos(Collection $ids): Collection
    {
        return DB::table('administration_evidence')->where('subject_type', 'customer_return')
            ->whereIn('subject_id', $ids)->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->get(['subject_id', 'path']);
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<int, Collection<int, \stdClass>>
     */
    public function evidence(string $subjectType, Collection $ids): Collection
    {
        return DB::table('administration_evidence')->where('subject_type', $subjectType)
            ->whereIn('subject_id', $ids)->get(['id', 'subject_id', 'original_name', 'mime_type'])->groupBy('subject_id');
    }
}
