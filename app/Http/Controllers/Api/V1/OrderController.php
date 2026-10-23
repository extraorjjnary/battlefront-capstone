<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\PlaceCustomerOrder;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateCheckoutRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\OrderSummaryResource;
use App\Models\User;
use App\Repositories\Order\CustomerOrderRepository;
use App\Services\PayMongo\ReconcilePayMongoReturn;
use App\Services\PayMongo\StartDirectOnlineBanking;
use App\Services\PayMongo\StartHostedCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class OrderController extends Controller
{
    public function index(Request $request, CustomerOrderRepository $orders): AnonymousResourceCollection
    {
        /** @var User $customer */
        $customer = $request->user();

        return OrderSummaryResource::collection($orders->paginate($customer));
    }

    public function store(
        ValidateCheckoutRequest $request,
        PlaceCustomerOrder $placeCustomerOrder,
        CustomerOrderRepository $orders,
        StartHostedCheckout $startHostedCheckout,
        StartDirectOnlineBanking $startDirectOnlineBanking,
    ): JsonResponse {
        /** @var User $customer */
        $customer = $request->user();
        $order = $placeCustomerOrder->execute($customer, $request->checkoutData(), $request->file('payment_proof'));

        $resource = new OrderResource($orders->find($customer, $order->id));

        if ($order->payment_method === PaymentMethod::Online) {
            try {
                $redirectUrl = ($request->validated('payment_channel') === 'direct_online_banking')
                    ? $startDirectOnlineBanking->execute($order, [
                        'payment_channel' => 'direct_online_banking',
                        'payment_bank' => $request->validated('payment_bank'),
                    ])
                    : $startHostedCheckout->execute($order);

                return response()->json(['data' => $resource->resolve(), 'payment' => ['redirect_url' => $redirectUrl]], 201);
            } catch (RuntimeException) {
                return response()->json(['data' => $resource->resolve(), 'payment' => ['status' => 'reconciliation_pending']], 202);
            }
        }

        return $resource->response()->setStatusCode(201);
    }

    public function show(Request $request, int $order, CustomerOrderRepository $orders, ReconcilePayMongoReturn $reconcilePayMongoReturn): OrderResource
    {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $orders->find($customer, $order);

        if ($request->boolean('paymongo_return')) {
            $reconcilePayMongoReturn->execute($persistedOrder);
            $persistedOrder = $orders->find($customer, $order);
        }

        return new OrderResource($persistedOrder);
    }
}
