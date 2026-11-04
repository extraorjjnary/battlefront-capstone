<?php

namespace App\Actions\Cart;

use App\Enums\CartAvailability;
use App\Models\CartItem;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\PcBuilder\PcBuilderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BuildCartViewData
{
    public function __construct(private readonly CartService $cartService, private readonly PcBuilderService $builder) {}

    /**
     * Build the customer cart presentation from authoritative domain results.
     *
     * @return array{
     *     items: list<array{
     *         id: int,
     *         quantity: int,
     *         pc_build_group: array{uuid: string, name: string, validation: array<string, mixed>}|null,
     *         product: array{id: int, name: string, brand: string|null, image_url: string|null, category: string, price: string, discount_price: string|null},
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
                        ->select(['id', 'cart_id', 'product_id', 'quantity', 'pc_build_cart_group_id'])
                        ->orderBy('id'),
                    'items.product:id,name,brand,category_id,price,discount_price,image_path',
                    'items.product.category:id,name',
                    'items.pcBuildGroup:id,uuid,name,configuration_snapshot',
                ])
                ->first();
            $items = [];
            $conflictCount = 0;
            $buildValidations = [];
            foreach ($cart?->items->pluck('pcBuildGroup')->filter()->unique('id') ?? [] as $group) {
                try {
                    $buildValidations[$group->id] = $this->builder->validateSelection($group->configuration_snapshot['components'] ?? []);
                } catch (ValidationException) {
                    $buildValidations[$group->id] = ['complete' => false, 'can_purchase' => false, 'status' => 'unknown', 'required_components' => [], 'findings' => [], 'purchase_blockers' => ['A selected build part is no longer available. Review the build before checkout.']];
                }
            }

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
                    'pc_build_group' => $item->pcBuildGroup === null ? null : [
                        'uuid' => $item->pcBuildGroup->uuid,
                        'name' => $item->pcBuildGroup->name,
                        'validation' => $buildValidations[$item->pcBuildGroup->id],
                    ],
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
