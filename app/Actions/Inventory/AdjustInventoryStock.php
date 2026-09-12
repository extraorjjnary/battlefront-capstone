<?php

namespace App\Actions\Inventory;

use App\Models\Inventory;
use App\Models\Product;
use DomainException;
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
    public function increase(Inventory $inventory, int $amount): Inventory
    {
        $this->ensurePositive($amount);

        Inventory::query()
            ->whereKey($inventory->getKey())
            ->increment('quantity', $amount);

        return $inventory->refresh();
    }

    /**
     * Decrease the current quantity without allowing overselling.
     */
    public function decrease(Inventory $inventory, int $amount): Inventory
    {
        $this->ensurePositive($amount);

        $updatedRows = Inventory::query()
            ->whereKey($inventory->getKey())
            ->where('quantity', '>=', $amount)
            ->decrement('quantity', $amount);

        if ($updatedRows === 0) {
            throw new DomainException('Insufficient inventory stock.');
        }

        return $inventory->refresh();
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
