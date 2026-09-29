<?php

namespace App\Http\Controllers;

use App\Actions\Order\PlaceCustomerOrder;
use App\Http\Requests\ValidateCheckoutRequest;
use App\Models\Order;
use App\Models\User;
use App\Repositories\Order\CustomerOrderRepository;
use App\Services\CustomerOrderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request, CustomerOrderRepository $orders, CustomerOrderPresenter $presenter): Response
    {
        /** @var User $customer */
        $customer = $request->user();

        return Inertia::render('Orders/Index', [
            'orders' => $orders->paginate($customer)->through(fn (Order $order): array => $presenter->summary($order)),
        ]);
    }

    public function store(ValidateCheckoutRequest $request, PlaceCustomerOrder $placeCustomerOrder): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $order = $placeCustomerOrder->execute($customer, $request->checkoutData(), $request->file('payment_proof'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Order placed successfully.',
        ]);

        return to_route('orders.show', $order)
            ->with('confirmed_order_id', $order->id);
    }

    public function show(Request $request, int $order, CustomerOrderRepository $orders, CustomerOrderPresenter $presenter): Response
    {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $orders->find($customer, $order);

        return Inertia::render('Orders/Show', [
            'order' => $presenter->detail($persistedOrder),
            'isConfirmation' => $request->session()->get('confirmed_order_id') === $persistedOrder->id,
        ]);
    }
}
