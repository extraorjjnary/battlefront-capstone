<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\HandleCustomerReturn;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use BcMath\Number;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerReturnController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'overdue' => ['nullable', 'boolean']]);
        $overdueCutoff = now()->startOfDay()->subDays(3);
        $case = $request->validate(['case' => ['nullable', 'integer', 'min:1']])['case'] ?? null;
        $paymentId = $request->validate(['payment_id' => ['nullable', 'integer', 'min:1']])['payment_id'] ?? null;
        if ($paymentId !== null) {
            $returnId = DB::table('customer_refund_payments as payments')
                ->join('customer_refunds as refunds', 'refunds.id', '=', 'payments.customer_refund_id')
                ->where('payments.id', $paymentId)->value('refunds.customer_return_id');
            abort_if($returnId === null || ($case !== null && (int) $case !== (int) $returnId), 404);
            $case = (int) $returnId;
        }
        $orderLookup = $request->validate(['order_id' => ['nullable', 'string', 'max:32']])['order_id'] ?? null;
        $orderId = is_string($orderLookup) && preg_match('/^(?:BF-)?(\d+)$/i', trim($orderLookup), $matches) === 1
            ? (int) $matches[1]
            : null;
        $order = $orderId !== null ? Order::query()->whereKey($orderId)->where('status', OrderStatus::Completed->value)
            ->with('items.product:id,name')->first() : null;
        $selectedOrderItems = $order?->items->map(function (OrderItem $item): array {
            $alreadyReturned = (int) DB::table('customer_return_items as items')
                ->join('customer_returns as returns', 'returns.id', '=', 'items.customer_return_id')
                ->where('items.order_item_id', $item->id)
                ->whereIn('returns.status', ['pending', 'accepted', 'rejected_pending_handover'])
                ->sum('items.quantity');

            return [
                'id' => $item->id,
                'product' => $item->purchasedProductName(),
                'quantity' => $item->quantity,
                'remaining_returnable' => max(0, $item->quantity - $alreadyReturned),
                'price_at_time' => $item->price_at_time,
            ];
        })->all();
        $returns = DB::table('customer_returns as returns')
            ->join('orders', 'orders.id', '=', 'returns.order_id')
            ->join('users', 'users.id', '=', 'returns.requester_id')
            ->join('users as customers', 'customers.id', '=', 'orders.user_id')
            ->join('customer_return_items as items', 'items.customer_return_id', '=', 'returns.id')
            ->join('order_items', 'order_items.id', '=', 'items.order_item_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('customer_refunds as refunds', 'refunds.customer_return_id', '=', 'returns.id')
            ->select('returns.*', 'users.name as requester_name', 'customers.name as customer_name', 'items.quantity', 'items.condition', 'items.disposition',
                'orders.id as purchase_order_id', 'refunds.id as refund_id',
                'refunds.approved_amount', 'refunds.paid_amount')
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
            ->when($request->boolean('overdue'), fn ($query) => $query->whereIn('returns.status', ['pending', 'rejected_pending_handover'])
                ->where('returns.created_at', '<', $overdueCutoff))
            ->orderByDesc('returns.created_at')->paginate(20)->withQueryString();
        $heldBySupplierReturn = DB::table('supplier_returns')->whereIn('customer_return_item_id', DB::table('customer_return_items')
            ->whereIn('customer_return_id', $returns->getCollection()->pluck('id'))->pluck('id'))
            ->whereIn('status', ['pending', 'approved', 'rejected_follow_up'])
            ->pluck('customer_return_item_id');
        $heldReturnIds = DB::table('customer_return_items')->whereIn('id', $heldBySupplierReturn)->pluck('customer_return_id')->flip();
        $payments = DB::table('customer_refund_payments')
            ->whereIn('customer_refund_id', $returns->getCollection()->pluck('refund_id')->filter())
            ->orderBy('id')->get(['id', 'customer_refund_id', 'recorded_by', 'amount', 'payment_method', 'external_reference', 'paid_at']);
        $reviews = DB::table('customer_refund_payment_reviews')
            ->whereIn('customer_refund_payment_id', $payments->pluck('id'))
            ->orderByDesc('id')->get(['customer_refund_payment_id', 'decision', 'reason'])
            ->unique('customer_refund_payment_id')->keyBy('customer_refund_payment_id');
        $refundBalances = $payments->groupBy('customer_refund_id')->map(function ($refundPayments) use ($reviews): array {
            $verified = '0.00';
            $awaitingReview = '0.00';
            $disputed = '0.00';

            foreach ($refundPayments as $payment) {
                $decision = $reviews->get($payment->id)?->decision;
                if ($decision === 'verified') {
                    $verified = (new Number(self::numericAmount($verified)))
                        ->add(self::numericAmount($payment->amount), 2)->value;
                } elseif ($decision === 'disputed') {
                    $disputed = (new Number(self::numericAmount($disputed)))
                        ->add(self::numericAmount($payment->amount), 2)->value;
                } elseif ($decision !== 'voided') {
                    $awaitingReview = (new Number(self::numericAmount($awaitingReview)))
                        ->add(self::numericAmount($payment->amount), 2)->value;
                }
            }

            return ['verified' => $verified, 'awaiting_review' => $awaitingReview, 'disputed' => $disputed];
        });
        $returns->getCollection()->transform(function ($return) use ($heldReturnIds, $refundBalances, $overdueCutoff) {
            $return->held_quantity = in_array($return->status, ['pending', 'rejected_pending_handover'], true)
                || ($return->status === 'accepted' && $return->disposition === 'supplier_return' && $heldReturnIds->has($return->id))
                ? $return->quantity : 0;
            $balance = $refundBalances->get($return->refund_id, ['verified' => '0.00', 'awaiting_review' => '0.00', 'disputed' => '0.00']);
            $return->verified_paid_amount = $balance['verified'];
            $return->awaiting_review_amount = $balance['awaiting_review'];
            $return->disputed_amount = $balance['disputed'];
            $approvedAmount = self::numericAmount($return->approved_amount ?? '0.00');
            $refundBalance = (new Number($approvedAmount))
                ->sub(self::numericAmount($balance['verified']), 2);
            $return->refund_balance = $refundBalance->value;
            $return->refund_fully_verified = (new Number(self::numericAmount($balance['verified'])))->compare($approvedAmount) >= 0;
            $return->refund_has_verified_payment = (new Number(self::numericAmount($balance['verified'])))->compare('0.00') > 0;
            $return->refund_has_unreviewed_payment = (new Number(self::numericAmount($balance['awaiting_review'])))->compare('0.00') > 0;
            $return->refund_has_disputed_payment = (new Number(self::numericAmount($balance['disputed'])))->compare('0.00') > 0;
            $return->refund_has_outstanding_balance = $refundBalance->compare('0.00') > 0;
            $return->age_days = (int) now()->startOfDay()->diffInDays($return->created_at, true);
            $return->inspection_overdue = in_array($return->status, ['pending', 'rejected_pending_handover'], true)
                && Carbon::parse($return->created_at)->lt($overdueCutoff);

            return $return;
        });

        return Inertia::render('Administration/CustomerReturns', [
            'returns' => $returns,
            'filters' => ['q' => $filters['q'] ?? null, 'overdue' => $request->boolean('overdue')],
            'selected_case' => $case,
            'selected_payment' => $paymentId === null ? null : (int) $paymentId,
            'evidence' => DB::table('administration_evidence')->where('subject_type', 'customer_return')
                ->whereIn('subject_id', $returns->getCollection()->pluck('id'))
                ->get(['id', 'subject_id', 'original_name', 'mime_type'])->groupBy('subject_id'),
            'paymentEvidence' => DB::table('administration_evidence')->where('subject_type', 'refund_payment')
                ->whereIn('subject_id', $payments->pluck('id'))
                ->get(['id', 'subject_id', 'original_name', 'mime_type'])->groupBy('subject_id'),
            'refundPayments' => $payments->map(function ($payment) use ($reviews): array {
                return [
                    'id' => $payment->id,
                    'refund_id' => $payment->customer_refund_id,
                    'recorded_by' => $payment->recorded_by,
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'external_reference' => $payment->external_reference,
                    'paid_at' => $payment->paid_at,
                    'review' => $reviews->get($payment->id),
                ];
            })->groupBy('refund_id'),
            'selected_order' => $order === null ? null : [
                'id' => $order->id,
                'reference' => $order->reference,
                'items' => $selectedOrderItems,
            ],
            'suppliers' => DB::table('suppliers')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, HandleCustomerReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'order_item_id' => ['required', 'integer', Rule::exists(OrderItem::class, 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'condition' => ['required', Rule::in(['sealed', 'opened', 'damaged'])],
            'reason_code' => ['required', Rule::in(['wrong_item', 'defective', 'transit_damage', 'not_as_described', 'changed_mind', 'other'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $returns->receive($request->user(), $validated['order_item_id'], $validated['quantity'], $validated['condition'], $validated['reason'], $validated['reason_code']);

        return back();
    }

    public function decide(Request $request, int $customerReturn, HandleCustomerReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'disposition' => ['required_if:decision,accept', 'prohibited_if:decision,reject', 'nullable', Rule::in(['restock', 'write_off', 'supplier_return'])],
            'approved_amount' => ['required_if:decision,accept', 'prohibited_if:decision,reject', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'supplier_id' => ['required_if:disposition,supplier_return', 'prohibited_if:decision,reject', 'nullable', 'integer', Rule::exists('suppliers', 'id')],
            'inspection_confirmed' => ['sometimes', 'required_if:disposition,restock', 'accepted'],
        ]);
        $accept = $validated['decision'] === 'accept';
        $returns->decide($customerReturn, $request->user(), $accept, $validated['disposition'] ?? '', (string) ($validated['approved_amount'] ?? '0.00'), $validated['reason'], $validated['supplier_id'] ?? null, isset($validated['inspection_confirmed']));

        return back();
    }

    public function handBack(Request $request, int $customerReturn, HandleCustomerReturn $returns): RedirectResponse
    {
        $returns->handBack($customerReturn, $request->user());

        return back();
    }

    public function recordPayment(Request $request, int $refund, HandleCustomerReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'payment_method' => ['required', Rule::in(['cash', 'gcash', 'maya', 'bank_transfer', 'other'])],
            'external_reference' => ['required', 'string', 'max:255'],
        ]);
        $returns->recordPayment($refund, $request->user(), (string) $validated['amount'], $validated['payment_method'], $validated['external_reference']);

        return back();
    }

    public function reviewPayment(Request $request, int $payment, HandleCustomerReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'disputed', 'voided'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $returns->reviewPayment($payment, $request->user(), $validated['decision'], $validated['reason']);

        return back();
    }

    /**
     * @return numeric-string
     */
    private static function numericAmount(mixed $amount): string
    {
        if (! is_string($amount) && ! is_int($amount)) {
            throw new \UnexpectedValueException('A stored monetary amount is not a decimal string.');
        }

        $decimal = (string) $amount;

        if (! self::isDecimalAmount($decimal)) {
            throw new \UnexpectedValueException('A stored monetary amount is not a valid decimal.');
        }

        return $decimal;
    }

    /**
     * @phpstan-assert-if-true numeric-string $amount
     */
    private static function isDecimalAmount(string $amount): bool
    {
        return preg_match('/^-?\d+(?:\.\d+)?$/D', $amount) === 1;
    }
}
