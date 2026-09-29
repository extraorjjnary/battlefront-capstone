<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\PlaceCustomerOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateCheckoutRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\OrderSummaryResource;
use App\Models\User;
use App\Repositories\Order\CustomerOrderRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
    ): JsonResponse {
        /** @var User $customer */
        $customer = $request->user();
        $order = $placeCustomerOrder->execute($customer, $request->checkoutData(), $request->file('payment_proof'));

        return (new OrderResource($orders->find($customer, $order->id)))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, int $order, CustomerOrderRepository $orders): OrderResource
    {
        /** @var User $customer */
        $customer = $request->user();

        return new OrderResource($orders->find($customer, $order));
    }
}
