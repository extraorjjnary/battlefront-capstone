<?php

namespace App\Actions\Chatbot\Context;

use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;

class ResolveChatbotRuleGuidance
{
    /** @return array{guidance: string} */
    public function execute(string $topic): array
    {
        $methods = fn (FulfillmentMethod $fulfillment): string => implode(', ', array_map(
            fn (PaymentMethod $method): string => $method->label(),
            array_values(array_filter(PaymentMethod::cases(), fn (PaymentMethod $method): bool => $method->isAvailableFor($fulfillment))),
        ));
        $proofMethods = array_values(array_filter(PaymentMethod::cases(), fn (PaymentMethod $method): bool => $method->requiresPaymentProof()));
        $noProofMethods = array_values(array_filter(PaymentMethod::cases(), fn (PaymentMethod $method): bool => ! $method->requiresPaymentProof()));
        $labels = fn (array $items): string => implode(' and ', array_map(fn (PaymentMethod $method): string => $method->label(), $items));

        return ['guidance' => match ($topic) {
            'payment_methods' => 'Pickup orders support '.$methods(FulfillmentMethod::Pickup).'. Delivery orders support '.$methods(FulfillmentMethod::Delivery).'.',
            'payment_proof' => $labels($proofMethods).' require uploaded payment proof, which Battlefront staff review manually. '.$labels($noProofMethods).' are pickup payment methods and do not require an upload; staff confirm those payments manually. Check your order history for the latest payment status.',
            'fulfillment' => 'Battlefront supports '.implode(' and ', array_map(fn (FulfillmentMethod $method): string => strtolower($method->label()), FulfillmentMethod::cases())).'. Choose an available fulfillment option during checkout; a delivery address is required for delivery.',
            'delivery_quote' => 'The delivery fee and estimated delivery time are shown at checkout after you select your items and delivery destination.',
            default => '',
        }];
    }
}
