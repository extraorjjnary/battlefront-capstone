<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\HandleCustomerReturn;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerReturnController extends Controller
{
    public function index(Request $request): Response
    {
        $orderId = $request->integer('order_id');
        $order = $orderId > 0 ? Order::query()->whereKey($orderId)->where('status', OrderStatus::Completed->value)
            ->with('items.product:id,name')->first() : null;
        $returns = DB::table('customer_returns as returns')
            ->join('orders', 'orders.id', '=', 'returns.order_id')
            ->join('users', 'users.id', '=', 'returns.requester_id')
            ->join('customer_return_items as items', 'items.customer_return_id', '=', 'returns.id')
            ->join('order_items', 'order_items.id', '=', 'items.order_item_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('customer_refunds as refunds', 'refunds.customer_return_id', '=', 'returns.id')
            ->select('returns.*', 'users.name as requester_name', 'items.quantity', 'items.condition', 'items.disposition',
                'products.name as product_name', 'orders.id as purchase_order_id', 'refunds.id as refund_id',
                'refunds.approved_amount', 'refunds.paid_amount')
            ->orderByDesc('returns.created_at')->paginate(20);

        return Inertia::render('Administration/CustomerReturns', [
            'returns' => $returns,
            'selected_order' => $order === null ? null : [
                'id' => $order->id,
                'reference' => $order->reference,
                'items' => $order->items->map(fn (OrderItem $item): array => [
                    'id' => $item->id,
                    'product' => $item->product->name,
                    'quantity' => $item->quantity,
                    'price_at_time' => $item->price_at_time,
                ])->all(),
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
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $returns->receive($request->user(), $validated['order_item_id'], $validated['quantity'], $validated['condition'], $validated['reason']);

        return back();
    }

    public function decide(Request $request, int $customerReturn, HandleCustomerReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'disposition' => ['required_if:decision,accept', 'nullable', Rule::in(['restock', 'write_off', 'supplier_return'])],
            'approved_amount' => ['required_if:decision,accept', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'supplier_id' => ['required_if:disposition,supplier_return', 'nullable', 'integer', Rule::exists('suppliers', 'id')],
        ]);
        $accept = $validated['decision'] === 'accept';
        $returns->decide($customerReturn, $request->user(), $accept, $validated['disposition'] ?? '', (string) ($validated['approved_amount'] ?? '0.00'), $validated['supplier_id'] ?? null);

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
}
