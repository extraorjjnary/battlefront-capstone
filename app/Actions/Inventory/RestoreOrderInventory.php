<?php

namespace App\Actions\Inventory;

use App\Models\Inventory;
use App\Models\Order;
use LogicException;

class RestoreOrderInventory
{
    public function __construct(private readonly AdjustInventoryStock $adjustInventoryStock) {}

    /**
     * Restore the quantities purchased by a cancelled order.
     */
    public function execute(Order $order): void
    {
        $orderItems = $order->items()
            ->orderBy('product_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $inventories = Inventory::query()
            ->whereIn('product_id', $orderItems->pluck('product_id')->unique())
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        foreach ($orderItems as $orderItem) {
            /** @var Inventory|null $inventory */
            $inventory = $inventories->get($orderItem->product_id);

            if ($inventory === null) {
                throw new LogicException('An order item has no inventory record to restore.');
            }

            $this->adjustInventoryStock->increase($inventory, $orderItem->quantity);
        }
    }
}
