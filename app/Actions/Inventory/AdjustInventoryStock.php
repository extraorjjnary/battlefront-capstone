<?php

namespace App\Actions\Inventory;

use App\Models\Inventory;
use App\Models\Product;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AdjustInventoryStock
{
    /**
     * Create the initial inventory record for a product.
     */
    public function initialize(Product $product, int $quantity, int $reorderLevel): Inventory
    {
        $this->ensureNonNegative($quantity, 'Quantity');
        $this->ensureNonNegative($reorderLevel, 'Reorder level');

        return $product->inventory()->firstOrCreate([], [
            'quantity' => $quantity,
            'reorder_level' => $reorderLevel,
        ]);
    }

    /**
     * Set the current quantity and reorder level.
     */
    public function set(Inventory $inventory, int $quantity, int $reorderLevel): Inventory
    {
        $this->ensureNonNegative($quantity, 'Quantity');
        $this->ensureNonNegative($reorderLevel, 'Reorder level');

        $inventory->update([
            'quantity' => $quantity,
            'reorder_level' => $reorderLevel,
        ]);

        return $inventory->refresh();
    }

    /**
     * Increase the current quantity atomically.
     */
    public function increase(Inventory $inventory, int $amount, ?int $orderId = null): Inventory
    {
        $this->ensurePositive($amount);

        return DB::transaction(function () use ($inventory, $amount, $orderId): Inventory {
            Inventory::query()->whereKey($inventory->getKey())->increment('quantity', $amount);
            $inventory->refresh();
            $this->recordMovement($inventory, $amount, 'order_cancellation', $orderId);

            return $inventory;
        });
    }

    /**
     * Decrease the current quantity without allowing overselling.
     */
    public function decrease(Inventory $inventory, int $amount, ?int $orderId = null): Inventory
    {
        $this->ensurePositive($amount);

        return DB::transaction(function () use ($inventory, $amount, $orderId): Inventory {
            $updatedRows = Inventory::query()
                ->whereKey($inventory->getKey())
                ->where('quantity', '>=', $amount)
                ->decrement('quantity', $amount);

            if ($updatedRows === 0) {
                throw new DomainException('Insufficient inventory stock.');
            }

            $inventory->refresh();
            $this->recordMovement($inventory, -$amount, 'order_placement', $orderId);

            return $inventory;
        });
    }

    private function recordMovement(Inventory $inventory, int $delta, string $kind, ?int $orderId): void
    {
        DB::table('inventory_movements')->insert([
            'product_id' => $inventory->product_id,
            'actor_id' => null,
            'kind' => $kind,
            'reference_type' => $orderId === null ? null : 'order',
            'reference_id' => $orderId,
            'sellable_before' => $inventory->quantity - $delta,
            'sellable_delta' => $delta,
            'sellable_after' => $inventory->quantity,
            'quarantined_before' => $inventory->quarantined_quantity,
            'quarantined_delta' => 0,
            'quarantined_after' => $inventory->quarantined_quantity,
            'created_at' => now(),
        ]);
    }

    /**
     * Ensure a stored inventory value is not negative.
     */
    private function ensureNonNegative(int $value, string $label): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("{$label} cannot be negative.");
        }
    }

    /**
     * Ensure a stock adjustment changes the quantity.
     */
    private function ensurePositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Stock adjustment amount must be greater than zero.');
        }
    }
}
