<?php

namespace App\Actions\Checkout;

use App\Actions\Cart\ReviewCheckoutCart;
use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\User;

class PrepareCheckout
{
    public function __construct(private readonly ReviewCheckoutCart $reviewCheckoutCart) {}

    /**
     * Build the complete checkout-page payload.
     *
     * @return array<string, mixed>
     */
    public function execute(User $customer): array
    {
        $cart = $this->reviewCheckoutCart->execute($customer);
        $pickupBranch = Branch::query()
            ->select(['name', 'address', 'city', 'contact_number'])
            ->operational()
            ->sole();

        return [
            'cart' => $cart,
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
