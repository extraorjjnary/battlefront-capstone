<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\User;
use App\Repositories\Order\CustomerOrderRepository;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\Request;

class OrderCancellationController extends Controller
{
    public function __invoke(
        Request $request,
        int $order,
        OrderProcessingService $processing,
        CustomerOrderRepository $orders,
    ): OrderResource {
        /** @var User $customer */
        $customer = $request->user();
        $processing->cancelForCustomer($customer, $order);

        return new OrderResource($orders->find($customer, $order));
    }
}
