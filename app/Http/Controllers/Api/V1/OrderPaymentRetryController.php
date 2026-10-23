<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PayMongo\RetryDirectOnlineBanking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OrderPaymentRetryController extends Controller
{
    public function __invoke(Request $request, int $order, RetryDirectOnlineBanking $retry): JsonResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $customer->orders()->whereKey($order)->firstOrFail();

        try {
            return response()->json(['payment' => ['redirect_url' => $retry->execute($persistedOrder)]]);
        } catch (RuntimeException) {
            return response()->json(['payment' => ['status' => 'reconciliation_pending']], 202);
        }
    }
}
