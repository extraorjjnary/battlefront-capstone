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
        $noProofMethods = array_values(array_filter(PaymentMethod::cases(), fn (PaymentMethod $method): bool => ! $method->requiresPaymentProof() && $method->isAvailableFor(FulfillmentMethod::Pickup)));
        $labels = fn (array $items): string => implode(' and ', array_map(fn (PaymentMethod $method): string => $method->label(), $items));

        return ['guidance' => match ($topic) {
            'payment_methods' => 'Pickup orders support '.$methods(FulfillmentMethod::Pickup).'. Delivery orders support '.$methods(FulfillmentMethod::Delivery).'.',
            'payment_proof' => $labels($proofMethods).' require uploaded payment proof, which Battlefront staff review manually. '.$labels($noProofMethods).' are pickup payment methods and do not require an upload; cash is received or a physical card terminal transaction succeeds during pickup. Staff confirm payment and product handover together to complete pickup. Cash on Delivery (COD) is delivery-only, requires no upload, and is collected upon delivery. Battlefront manually confirms LBC collection after delivery before completing the order. Check your order history for the latest payment status.',
            'fulfillment' => 'Battlefront supports '.implode(' and ', array_map(fn (FulfillmentMethod $method): string => strtolower($method->label()), FulfillmentMethod::cases())).'. Choose an available fulfillment option during checkout; a delivery address is required for delivery.',
            'delivery_quote' => 'The delivery fee and estimated delivery time are shown at checkout after you select your items and delivery destination, when an estimate is configured. Configured quotes are Battlefront demonstration estimates, not official carrier rates or guaranteed delivery dates. For unconfigured destinations, the 0.00 placeholder is not free delivery: charges and arrangements must be confirmed separately, and no carrier or ETA is included.',
            'cancellation' => 'Check your order details for the available cancellation action. Eligible pending orders without submitted payment proof can be cancelled directly. Pending wallet orders with submitted proof require a cancellation review request; approval is not automatic. Preparation or later shipment activity can prevent cancellation. A cancellation refund is separate from returning a received product. The chatbot cannot cancel an order or submit a request.',
            'returns' => 'For an eligible completed order, open its order details to request a return for an item. Submitted requests await physical item receipt and staff review; a request does not guarantee acceptance or a refund. Available quantities and reasons are checked by the application. '.$this->returnWindowGuidance().' The chatbot cannot submit or approve a return.',
            'refunds' => 'Cancellation refunds and refunds from accepted physical returns are separate workflows. Check the relevant request in your order details. Refund completion depends on confirmed refund payments, not merely recorded payments. No processing deadline can be confirmed here, and the chatbot cannot approve or issue a refund.',
            default => '',
        }];
    }

    private function returnWindowGuidance(): string
    {
        $days = filter_var(config('battlefront.returns.window_days'), FILTER_VALIDATE_INT);
        if (config('battlefront.returns.window_approved') === true && $days !== false && $days > 0) {
            return 'The approved discretionary return window is '.$days.' days from recorded receipt; eligibility depends on the reason and current order details. Other reasons may require manual review.';
        }

        return 'The proposed discretionary return window is not a confirmed policy; staff review is required.';
    }
}
