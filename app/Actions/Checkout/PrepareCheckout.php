<?php

namespace App\Actions\Checkout;

use App\Actions\Cart\ReviewCheckoutCart;
use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeliveryQuotePresenter;
use App\Services\Order\DeliveryRules;
use Carbon\CarbonImmutable;

class PrepareCheckout
{
    public function __construct(
        private readonly ReviewCheckoutCart $reviewCheckoutCart,
        private readonly DeliveryRules $deliveryRules,
        private readonly DeliveryQuotePresenter $deliveryQuotePresenter,
    ) {}

    /**
     * Build the complete checkout-page payload.
     *
     * @param  list<int>  $cartItemIds
     * @return array<string, mixed>
     */
    public function execute(User $customer, array $cartItemIds): array
    {
        $cart = $this->reviewCheckoutCart->execute($customer, $cartItemIds);
        $products = Product::query()
            ->select(['id', 'shipping_profile'])
            ->whereKey(array_column(array_column($cart['items'], 'product'), 'id'))
            ->get();
        $anchor = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $deliveryQuotes = [];

        foreach (array_keys($this->deliveryRules->destinations()) as $destination) {
            $quote = $this->deliveryRules->quote(FulfillmentMethod::Delivery, $destination, $products);
            $deliveryQuotes[] = $this->deliveryQuotePresenter->checkout($quote, $cart['total'], $anchor);
        }
        $pickupBranch = Branch::query()
            ->select(['name', 'address', 'city', 'contact_number'])
            ->operational()
            ->sole();

        return [
            'cart' => $cart,
            'deliveryQuotes' => $deliveryQuotes,
            'pickupQuote' => [
                'product_subtotal' => $cart['total'],
                'delivery_fee' => '0.00',
                'total' => $cart['total'],
            ],
            'customer' => [
                'name' => $customer->name,
                'default_delivery_address' => $customer->default_delivery_address,
            ],
            'pickupLocation' => [
                'name' => "{$pickupBranch->name} — {$pickupBranch->city}",
                'address' => $pickupBranch->address,
                'contact_number' => $pickupBranch->contact_number,
                'operating_hours' => $pickupBranch->operating_hours,
            ],
            'fulfillmentMethods' => array_map(
                fn (FulfillmentMethod $method): array => [
                    'value' => $method->value,
                    'label' => $method->label(),
                ],
                FulfillmentMethod::cases(),
            ),
            'paymentMethods' => array_map(
                fn (PaymentMethod $method): array => [
                    'value' => $method->value,
                    'label' => $method->label(),
                    'requires_proof' => $method->requiresPaymentProof(),
                    'payment_account' => $this->paymentAccount($method),
                    'available_for' => array_values(array_map(
                        fn (FulfillmentMethod $fulfillmentMethod): string => $fulfillmentMethod->value,
                        array_filter(
                            FulfillmentMethod::cases(),
                            fn (FulfillmentMethod $fulfillmentMethod): bool => $method->isAvailableFor($fulfillmentMethod),
                        ),
                    )),
                ],
                PaymentMethod::cases(),
            ),
        ];
    }

    /**
     * Get configured receiving-account details for an e-wallet method.
     *
     * @return array{account_name: string, account_number: string, is_demo: bool}|null
     */
    private function paymentAccount(PaymentMethod $method): ?array
    {
        if (! $method->requiresPaymentProof()) {
            return null;
        }

        return [
            'account_name' => (string) config("battlefront.payment_accounts.{$method->value}.account_name"),
            'account_number' => (string) config("battlefront.payment_accounts.{$method->value}.account_number"),
            'is_demo' => (bool) config("battlefront.payment_accounts.{$method->value}.is_demo"),
        ];
    }
}
