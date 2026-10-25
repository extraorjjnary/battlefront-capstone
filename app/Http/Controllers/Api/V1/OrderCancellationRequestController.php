<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Repositories\Order\CustomerOrderRepository;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\Request;

class OrderCancellationRequestController extends Controller
{
    public function __invoke(Request $request, int $order, OrderProcessingService $processing, CustomerOrderRepository $orders): OrderResource
    {
        $validated = $request->validate(['customer_note' => ['nullable', 'string', 'max:1000']]);
        $processing->requestCancellation($request->user(), $order, $validated['customer_note'] ?? null);

        return new OrderResource($orders->find($request->user(), $order));
    }
}
