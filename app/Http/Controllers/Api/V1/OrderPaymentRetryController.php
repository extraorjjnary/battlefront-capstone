<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PayMongoFlow;
use App\Models\User;
use App\Services\CustomerOrderPresenter;
use App\Services\PayMongo\RetryDirectOnlineBanking;
use App\Services\PayMongo\RetryOrderPayment;
use App\Services\PayMongo\StartDirectQrPh;
use App\Services\PayMongo\StartHostedCheckout;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OrderPaymentRetryController extends Controller
{
    public function __invoke(
        Request $request,
        int $order,
        StartHostedCheckout $retryHostedCheckout,
        RetryDirectOnlineBanking $retryBank,
        StartDirectQrPh $retryQrPh,
        CustomerOrderPresenter $presenter,
    ): JsonResponse {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $customer->orders()->whereKey($order)->firstOrFail();

        try {
            $flow = PayMongoFlow::query()->where('order_id', $persistedOrder->id)->latest('id')->firstOrFail();

            if ($flow->channel === 'direct_qrph') {
                app(RetryOrderPayment::class)->execute($persistedOrder, fn ($currentOrder) => $retryQrPh->execute($currentOrder));
                $data = $presenter->detail($persistedOrder->fresh());
                $qrIsReady = data_get($data, 'payment.qr_code_image_url') !== null;

                return response()->json([
                    'data' => $data,
                    'payment' => ['status' => $qrIsReady ? 'qrph_ready' : 'reconciliation_pending'],
                ], $qrIsReady ? 200 : 202);
            }

            if ($flow->channel === 'hosted_checkout') {
                $checkoutUrl = app(RetryOrderPayment::class)->execute($persistedOrder, fn ($currentOrder) => $retryHostedCheckout->execute($currentOrder));

                return response()->json([
                    'data' => $presenter->detail($persistedOrder->fresh()),
                    'payment' => ['status' => 'checkout_ready', 'redirect_url' => $checkoutUrl],
                ]);
            }

            return response()->json(['payment' => ['redirect_url' => app(RetryOrderPayment::class)->execute($persistedOrder, fn ($currentOrder) => $retryBank->execute($currentOrder))]]);
        } catch (RuntimeException|RequestException|ConnectionException) {
            return response()->json(['message' => 'The online payment is being reconciled. Check this order again shortly.'], 202);
        }
    }
}
