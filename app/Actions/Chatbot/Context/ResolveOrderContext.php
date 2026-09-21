<?php

namespace App\Actions\Chatbot\Context;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

class ResolveOrderContext
{
    /**
     * Resolve only order status facts owned by the supplied customer.
     *
     * @return array{orders: list<array{
     *     reference: string,
     *     created_at: string,
     *     status: array{value: string, label: string},
     *     fulfillment: array{value: string, label: string}
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

        $orderQuery = $customer->orders()
            ->select(['id', 'user_id', 'fulfillment_method', 'status', 'created_at']);
        $referenceId = $this->referenceId($message);

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
                ->map(fn (Order $order): array => [
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
                ])
                ->all()),
        ];
    }

    private function referenceId(string $message): ?int
    {
        if (preg_match('/\bBF[\s-]*0*(\d+)\b/iu', $message, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
