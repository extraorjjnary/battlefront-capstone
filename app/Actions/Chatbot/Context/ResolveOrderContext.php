<?php

namespace App\Actions\Chatbot\Context;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;

class ResolveOrderContext
{
    /**
     * Resolve status and, for a referenced payment inquiry, payment facts owned by the supplied customer.
     *
     * @return array{orders: list<array{
     *     reference: string,
     *     created_at: string,
     *     status: array{value: string, label: string},
     *     fulfillment: array{value: string, label: string},
     *     payment?: array{
     *         method: array{value: string, label: string},
     *         status: array{value: string, label: string}
     *     }
     * }>}
     */
    public function execute(?User $customer, string $message): array
    {
        if (
            ! $customer?->exists
            || ! User::query()
                ->whereKey($customer->getKey())
                ->where('role', UserRole::Customer->value)
                ->exists()
        ) {
            return ['orders' => []];
        }

        $referenceId = $this->referenceId($message);
        $includePayment = $referenceId !== null && $this->isPaymentInquiry($message);
        $columns = ['id', 'user_id', 'fulfillment_method', 'status', 'created_at'];

        if ($includePayment) {
            array_push($columns, 'payment_method', 'payment_status');
        }

        $orderQuery = $customer->orders()->select($columns);

        if ($referenceId !== null) {
            $orderQuery->whereKey($referenceId);
        } else {
            $orderQuery
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5);
        }

        $orders = $orderQuery->get();

        return [
            'orders' => array_values($orders
                ->map(function (Order $order) use ($includePayment): array {
                    $context = [
                        'reference' => $order->reference,
                        'created_at' => $order->created_at->toIso8601String(),
                        'status' => [
                            'value' => $order->status->value,
                            'label' => $order->status->customerLabel($order->fulfillment_method),
                        ],
                        'fulfillment' => [
                            'value' => $order->fulfillment_method->value,
                            'label' => $order->fulfillment_method->label(),
                        ],
                    ];

                    if ($includePayment) {
                        $context['payment'] = [
                            'method' => [
                                'value' => $order->payment_method->value,
                                'label' => $order->payment_method->label(),
                            ],
                            'status' => [
                                'value' => $order->payment_status->value,
                                'label' => $order->payment_status->label(),
                            ],
                        ];
                    }

                    return $context;
                })
                ->all()),
        ];
    }

    private function isPaymentInquiry(string $message): bool
    {
        return preg_match('/\b(?:payment|pay|paid|proof|gcash|maya|cash|card)\b/iu', $message) === 1;
    }

    private function referenceId(string $message): ?int
    {
        $normalizedMessage = Str::of($message)
            ->trim()
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')
            ->squish()
            ->toString();

        if (preg_match('/\bbf\s*0*(\d+)\b/u', $normalizedMessage, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
