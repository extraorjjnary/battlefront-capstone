<?php

namespace App\Actions\Cart;

use App\Enums\CartAvailability;
use App\Models\CartItem;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Support\Facades\DB;

class BuildCartViewData
{
    public function __construct(private readonly CartService $cartService) {}

    /**
     * Build the customer cart presentation from authoritative domain results.
     *
     * @return array{
     *     items: list<array{
     *         id: int,
     *         quantity: int,
     *         product: array{id: int, name: string, brand: string, image_url: string|null, category: string, price: string, discount_price: string|null},
     *         unit_price: string,
     *         line_total: string,
     *         availability: array{status: string, available_quantity: int|null}
     *     }>,
     *     item_count: int,
     *     total_quantity: int,
     *     total: string,
     *     conflict_count: int
     * }
     */
    public function execute(User $customer): array
    {
        return DB::transaction(function () use ($customer): array {
            $totals = $this->cartService->totals($customer);
            $availability = $this->cartService->availability($customer);
            $lineTotalsByItem = collect($totals['lines'])->keyBy('cart_item_id');
            $availabilityByItem = collect($availability)->keyBy('cart_item_id');
            $cart = $customer->cart()
                ->with([
                    'items' => fn ($query) => $query
                        ->select(['id', 'cart_id', 'product_id', 'quantity'])
                        ->orderBy('id'),
                    'items.product:id,name,brand,category_id,price,discount_price,image_path',
                    'items.product.category:id,name',
                ])
                ->first();
            $items = [];
            $conflictCount = 0;

            foreach ($cart->items ?? [] as $item) {
                /** @var CartItem $item */
                $lineTotal = $lineTotalsByItem->get($item->id);
                $itemAvailability = $availabilityByItem->get($item->id);

                if ($itemAvailability['status'] !== CartAvailability::Available->value) {
                    $conflictCount++;
                }

                $items[] = [
                    'id' => $item->id,
                    'quantity' => $item->quantity,
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'brand' => $item->product->brand,
                        'image_url' => $item->product->image_url,
                        'category' => $item->product->category->name,
                        'price' => $item->product->price,
                        'discount_price' => $item->product->discount_price,
                    ],
                    'unit_price' => $lineTotal['unit_price'],
                    'line_total' => $lineTotal['line_total'],
                    'availability' => [
                        'status' => $itemAvailability['status'],
                        'available_quantity' => $itemAvailability['available_quantity'],
                    ],
                ];
            }

            return [
                'items' => $items,
                'item_count' => $totals['item_count'],
                'total_quantity' => $totals['total_quantity'],
                'total' => $totals['total'],
                'conflict_count' => $conflictCount,
            ];
        }, attempts: 3);
    }
}
