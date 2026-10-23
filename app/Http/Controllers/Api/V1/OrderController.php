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
    ): JsonResponse {
        /** @var User $customer */
        $customer = $request->user();
        $order = $placeCustomerOrder->execute($customer, $request->checkoutData(), $request->file('payment_proof'));

        if ($order->payment_method === PaymentMethod::Online) {
            try {
                $redirectUrl = $startHostedCheckout->execute($order);

                return response()->json([
                    'data' => (new OrderResource($orders->find($customer, $order->id)))->resolve(),
                    'payment' => ['redirect_url' => $redirectUrl],
                ], 201);
            } catch (RuntimeException) {
                return response()->json([
                    'data' => (new OrderResource($orders->find($customer, $order->id)))->resolve(),
                    'payment' => ['status' => 'reconciliation_pending'],
                ], 202);
            }
        }

        return (new OrderResource($orders->find($customer, $order->id)))->response()->setStatusCode(201);
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
