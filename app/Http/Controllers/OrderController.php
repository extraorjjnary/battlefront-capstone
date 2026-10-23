<?php

namespace App\Http\Controllers;

use App\Actions\Order\PlaceCustomerOrder;
use App\Enums\PaymentMethod;
use App\Http\Requests\ValidateCheckoutRequest;
use App\Models\Order;
use App\Models\User;
use App\Repositories\Order\CustomerOrderRepository;
use App\Services\CustomerOrderPresenter;
use App\Services\PayMongo\ReconcilePayMongoReturn;
use App\Services\PayMongo\StartDirectOnlineBanking;
use App\Services\PayMongo\StartHostedCheckout;
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

    public function store(
        ValidateCheckoutRequest $request,
        PlaceCustomerOrder $placeCustomerOrder,
        StartHostedCheckout $startHostedCheckout,
        StartDirectOnlineBanking $startDirectOnlineBanking,
    ): RedirectResponse|\Symfony\Component\HttpFoundation\Response {
        /** @var User $customer */
        $customer = $request->user();
        $order = $placeCustomerOrder->execute($customer, $request->checkoutData(), $request->file('payment_proof'));

        if ($order->payment_method === PaymentMethod::Online) {
            try {
                $redirectUrl = ($request->validated('payment_channel') === 'direct_online_banking')
                    ? $startDirectOnlineBanking->execute($order, [
                        'payment_channel' => 'direct_online_banking',
                        'payment_bank' => $request->validated('payment_bank'),
                    ])
                    : $startHostedCheckout->execute($order);

                return Inertia::location($redirectUrl);
            } catch (\RuntimeException) {
                Inertia::flash('toast', ['type' => 'info', 'message' => 'Your order is saved. Online payment is being reconciled; check this order again shortly.']);
            }
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Order placed successfully.',
        ]);

        return to_route('orders.show', $order)
            ->with('confirmed_order_id', $order->id);
    }

    public function show(Request $request, int $order, CustomerOrderRepository $orders, CustomerOrderPresenter $presenter, ReconcilePayMongoReturn $reconcilePayMongoReturn): Response|RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $orders->find($customer, $order);

        if ($request->boolean('paymongo_return')) {
            $reconcilePayMongoReturn->execute($persistedOrder);

            return to_route('orders.show', $persistedOrder);
        }

        return Inertia::render('Orders/Show', [
            'order' => $presenter->detail($persistedOrder),
            'isConfirmation' => $request->session()->get('confirmed_order_id') === $persistedOrder->id,
        ]);
    }
}
