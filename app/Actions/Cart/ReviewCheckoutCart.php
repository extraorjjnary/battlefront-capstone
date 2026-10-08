<?php

namespace App\Actions\Cart;

use App\Enums\CartAvailability;
use App\Models\CartItem;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Support\Facades\DB;

class ReviewCheckoutCart
{
    public function __construct(private readonly CartService $cartService) {}

    /**
     * Build a checkout-ready snapshot from current cart state.
     *
     * @param  list<int>  $cartItemIds
     * @return array{
     *     items: list<array{id: int, quantity: int, product: array{id: int, name: string, brand: string|null, image_url: string|null}, unit_price: string, line_total: string}>,
     *     item_count: int,
     *     total_quantity: int,
     *     total: string
     * }
     */
    public function execute(User $customer, array $cartItemIds): array
    {
        if (! $this->cartService->isValidCheckoutSelection($cartItemIds)) {
            throw CheckoutUnavailableException::invalidSelection();
        }

        return DB::transaction(function () use ($customer, $cartItemIds): array {
            $totals = $this->cartService->totals($customer, $cartItemIds);

            if ($totals['item_count'] !== count($cartItemIds)) {
                throw CheckoutUnavailableException::invalidSelection();
            }

            $availability = $this->cartService->availability($customer, $cartItemIds);

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
                        ->whereKey($cartItemIds)
                        ->orderBy('id'),
                    'items.product:id,name,brand,image_path',
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
