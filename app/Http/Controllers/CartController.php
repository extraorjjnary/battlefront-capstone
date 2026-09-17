<?php

namespace App\Http\Controllers;

use App\Actions\Cart\ManageCart;
use App\Enums\CartAvailability;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    /**
     * Display the authenticated customer's cart.
     */
    public function index(Request $request, ManageCart $manageCart): Response
    {
        /** @var User $customer */
        $customer = $request->user();

        return Inertia::render('Cart/Index', [
            'cart' => DB::transaction(
                fn (): array => $this->cartData($customer, $manageCart),
                attempts: 3,
            ),
        ]);
    }

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
    private function cartData(User $customer, ManageCart $manageCart): array
    {
        $totals = $manageCart->totals($customer);
        $availability = $manageCart->availability($customer);
        $lineTotalsByItem = [];
        $availabilityByItem = [];

        foreach ($totals['lines'] as $lineTotal) {
            $lineTotalsByItem[$lineTotal['cart_item_id']] = $lineTotal;
        }

        foreach ($availability as $itemAvailability) {
            $availabilityByItem[$itemAvailability['cart_item_id']] = $itemAvailability;
        }

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
            $lineTotal = $lineTotalsByItem[$item->id];
            $itemAvailability = $availabilityByItem[$item->id];

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
    }
}
