<?php

namespace App\Actions\PayMongo;

use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Services\PayMongo\RetryDirectOnlineBanking;
use App\Services\PayMongo\RetryOrderPayment;
use App\Services\PayMongo\StartDirectQrPh;
use App\Services\PayMongo\StartHostedCheckout;

class RetryPayMongoPayment
{
    public function __construct(
        private readonly StartHostedCheckout $hostedCheckout,
        private readonly RetryDirectOnlineBanking $bank,
        private readonly StartDirectQrPh $qrPh,
        private readonly RetryOrderPayment $retryOrderPayment,
    ) {}

    /** @return array{channel: string, redirect_url: string|null} */
    public function execute(Order $order): array
    {
        return $this->retryOrderPayment->execute($order, function (Order $currentOrder): array {
            $flow = PayMongoFlow::query()->where('order_id', $currentOrder->id)->latest('id')->firstOrFail();

            if ($flow->channel === 'direct_qrph') {
                $this->qrPh->execute($currentOrder);

                return ['channel' => $flow->channel, 'redirect_url' => null];
            }

            if ($flow->channel === 'hosted_checkout') {
                return ['channel' => $flow->channel, 'redirect_url' => $this->hostedCheckout->execute($currentOrder)];
            }

            return ['channel' => $flow->channel, 'redirect_url' => $this->bank->execute($currentOrder)];
        });
    }
}
