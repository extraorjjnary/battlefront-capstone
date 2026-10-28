<?php

namespace App\Http\Controllers;

use App\Models\PayMongoFlow;
use App\Models\User;
use App\Services\CustomerOrderPresenter;
use App\Services\PayMongo\RetryDirectOnlineBanking;
use App\Services\PayMongo\StartDirectQrPh;
use App\Services\PayMongo\StartHostedCheckout;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class OrderPaymentRetryController extends Controller
{
    public function __invoke(
        Request $request,
        int $order,
        StartHostedCheckout $retryHostedCheckout,
        RetryDirectOnlineBanking $retryBank,
        StartDirectQrPh $retryQrPh,
        CustomerOrderPresenter $presenter,
    ): Response {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $customer->orders()->whereKey($order)->firstOrFail();

        try {
            $flow = PayMongoFlow::query()->where('order_id', $persistedOrder->id)->where('active_slot', 'active')->firstOrFail();

            if ($flow->channel === 'direct_qrph') {
                $retryQrPh->execute($persistedOrder);

                if ($request->expectsJson()) {
                    $data = $presenter->detail($persistedOrder->fresh());
                    $qrIsReady = data_get($data, 'payment.qr_code_image_url') !== null;

                    return response()->json([
                        'data' => $data,
                        'payment' => ['status' => $qrIsReady ? 'qrph_ready' : 'reconciliation_pending'],
                    ], $qrIsReady ? 200 : 202);
                }

                return to_route('orders.show', $persistedOrder);
            }

            if ($flow->channel === 'hosted_checkout') {
                $checkoutUrl = $retryHostedCheckout->execute($persistedOrder);

                if ($request->expectsJson()) {
                    return response()->json([
                        'data' => $presenter->detail($persistedOrder->fresh()),
                        'payment' => ['status' => 'checkout_ready', 'checkout_url' => $checkoutUrl],
                    ]);
                }

                Inertia::flash('toast', ['type' => 'success', 'message' => 'Your secure payment is ready. Continue in the payment tab.']);

                return to_route('orders.show', $persistedOrder);
            }

            return Inertia::location($retryBank->execute($persistedOrder));
        } catch (RuntimeException) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'The online payment is being reconciled. Check this order again shortly.'], 202);
            }

            Inertia::flash('toast', ['type' => 'info', 'message' => 'The online payment is being reconciled. Check this order again shortly.']);

            return to_route('orders.show', $persistedOrder);
        }
    }
}
