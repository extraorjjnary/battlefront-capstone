<?php

namespace App\Actions\Cart;

use App\Enums\CartAvailability;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReviewCheckoutCart
{
    public function __construct(private readonly ManageCart $manageCart) {}

    /**
     * Build a checkout-ready snapshot from current cart state.
     *
     * @return array{
     *     items: list<array{id: int, quantity: int, product: array{id: int, name: string, brand: string, image_url: string|null}, unit_price: string, line_total: string}>,
     *     item_count: int,
     *     total_quantity: int,
     *     total: string
     * }
     */
    public function execute(User $customer): array
    {
        return DB::transaction(function () use ($customer): array {
            $totals = $this->manageCart->totals($customer);

            if ($totals['item_count'] === 0) {
                throw CheckoutUnavailableException::emptyCart();
            }

            $availability = $this->manageCart->availability($customer);

            if (collect($availability)->contains(
                fn (array $item): bool => $item['status'] !== CartAvailability::Available->value,
            )) {
                throw CheckoutUnavailableException::unavailableItems();
            }

            $lineTotalsByItem = collect($totals['lines'])->keyBy('cart_item_id');
            $cart = $customer->cart()
                ->with([
                    'items' => fn ($query) => $query
                        ->select(['id', 'cart_id', 'product_id', 'quantity'])
                        ->orderBy('id'),
                    'items.product:id,name,brand,image_url',
                ])
                ->first();
            $items = [];

            foreach ($cart->items as $item) {
                /** @var CartItem $item */
                $lineTotal = $lineTotalsByItem->get($item->id);

                $items[] = [
                    'id' => $item->id,
                    'quantity' => $item->quantity,
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'brand' => $item->product->brand,
                        'image_url' => $item->product->image_url,
                    ],
                    'unit_price' => $lineTotal['unit_price'],
                    'line_total' => $lineTotal['line_total'],
                ];
            }

            return [
                'items' => $items,
                'item_count' => $totals['item_count'],
                'total_quantity' => $totals['total_quantity'],
                'total' => $totals['total'],
            ];
        }, attempts: 3);
    }
}
