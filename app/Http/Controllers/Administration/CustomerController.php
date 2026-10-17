<?php

namespace App\Http\Controllers\Administration;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    /**
     * Display the customer account directory.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        $customers = User::query()
            ->select(['id', 'name', 'email', 'created_at'])
            ->where('role', UserRole::Customer)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $customer): array => $this->customerData($customer));

        return Inertia::render('Administration/Customers/Index', [
            'customers' => $customers,
        ]);
    }

    /**
     * Display a customer account record.
     */
    public function show(User $customer, Request $request): Response
    {
        $this->authorize('view', $customer);

        $status = $request->query('status') === 'all' ? 'all' : 'completed';
        $orders = $customer->orders()
            ->with(['items.product:id,name', 'sale'])
            ->when($status === 'completed', fn ($query) => $query->where('status', OrderStatus::Completed->value))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn ($order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'created_at' => $order->created_at->toIso8601String(),
                'status' => $order->status->value,
                'payment_status' => $order->payment_status->value,
                'product_subtotal' => $order->product_subtotal,
                'delivery_fee' => $order->delivery_fee,
                'total_amount' => $order->total_amount,
                'items' => $order->items->map(fn ($item): array => [
                    'name' => $item->product->name,
                    'quantity' => $item->quantity,
                    'price_at_time' => $item->price_at_time,
                ])->all(),
            ]);

        return Inertia::render('Administration/Customers/Show', [
            'customer' => $this->customerData($customer),
            'orders' => $orders,
            'order_status_filter' => $status,
        ]);
    }

    /**
     * Return only the customer account fields approved for administration.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     created_at: string|null
     * }
     */
    private function customerData(User $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }
}
