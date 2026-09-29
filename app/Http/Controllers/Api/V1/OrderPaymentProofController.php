<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\SubmitReplacementPaymentProof;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderPaymentProofRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\User;
use App\Repositories\Order\CustomerOrderRepository;

class OrderPaymentProofController extends Controller
{
    public function __invoke(
        StoreOrderPaymentProofRequest $request,
        int $order,
        SubmitReplacementPaymentProof $submitReplacementPaymentProof,
        CustomerOrderRepository $orders,
    ): OrderResource {
        /** @var User $customer */
        $customer = $request->user();
        $submitReplacementPaymentProof->execute($customer, $order, $request->file('payment_proof'));

        return new OrderResource($orders->find($customer, $order));
    }
}
