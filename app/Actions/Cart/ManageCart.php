<?php

namespace App\Actions\Cart;

use App\Enums\CartAvailability;
use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ManageCart
{
    /**
     * Add a quantity of a product to the customer's cart.
     */
    public function add(User $customer, int $productId, int $quantity): CartItem
    {
        $this->ensurePositiveQuantity($quantity);

        return DB::transaction(function () use ($customer, $productId, $quantity): CartItem {
            $lockedCustomer = $this->lockCustomer($customer);
            $cart = Cart::query()->firstOrCreate(['user_id' => $lockedCustomer->id]);
            $cartItem = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();
            [$product, $category, $inventory] = $this->lockProductContext($productId);
            $currentQuantity = $cartItem->quantity ?? 0;

            $this->ensureAvailable($product, $category, $inventory, $quantity, $currentQuantity);

            if ($cartItem === null) {
                return CartItem::query()->create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ]);
            }

            $cartItem->update(['quantity' => $currentQuantity + $quantity]);

            return $cartItem->refresh();
        }, attempts: 3);
    }

    /**
     * Replace the quantity of a customer-owned cart item.
     */
    public function updateQuantity(User $customer, int $cartItemId, int $quantity): CartItem
    {
        $this->ensurePositiveQuantity($quantity);

        return DB::transaction(function () use ($customer, $cartItemId, $quantity): CartItem {
            $lockedCustomer = $this->lockCustomer($customer);
            $cartItem = $this->ownedCartItemQuery($lockedCustomer, $cartItemId)
                ->lockForUpdate()
                ->firstOrFail();
            [$product, $category, $inventory] = $this->lockProductContext($cartItem->product_id);

            $this->ensureAvailable($product, $category, $inventory, $quantity);

            $cartItem->update(['quantity' => $quantity]);

            return $cartItem->refresh();
        }, attempts: 3);
    }

    /**
     * Remove a customer-owned item, including an item that has become stale.
     */
    public function remove(User $customer, int $cartItemId): void
    {
        DB::transaction(function () use ($customer, $cartItemId): void {
            $lockedCustomer = $this->lockCustomer($customer);
            $cartItem = $this->ownedCartItemQuery($lockedCustomer, $cartItemId)
                ->lockForUpdate()
                ->firstOrFail();

            $cartItem->delete();
        }, attempts: 3);
    }

    /**
     * Calculate current cart line totals and aggregate totals.
     *
     * @return array{
     *     lines: list<array{cart_item_id: int, quantity: int, unit_price: string, line_total: string}>,
     *     item_count: int,
     *     total_quantity: int,
     *     total: string
     * }
     */
    public function totals(User $customer): array
    {
        $customer = $this->currentCustomer($customer);
        $items = $this->customerItemsQuery($customer)
            ->with('product:id,price,discount_price')
            ->orderBy('id')
            ->get();
        $total = '0.00';
        $totalQuantity = 0;
        $lines = [];

        foreach ($items as $item) {
            $unitPrice = $item->product->discount_price ?? $item->product->price;

            if (! is_numeric($unitPrice)) {
                throw new InvalidArgumentException('Product prices must be numeric.');
            }

            $lineTotal = bcmul($unitPrice, (string) $item->quantity, 2);
            $total = bcadd($total, $lineTotal, 2);
            $totalQuantity += $item->quantity;
            $lines[] = [
                'cart_item_id' => $item->id,
                'quantity' => $item->quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        return [
            'lines' => $lines,
            'item_count' => count($lines),
            'total_quantity' => $totalQuantity,
            'total' => $total,
        ];
    }

    /**
     * Report current availability for every persisted cart item.
     *
     * @return list<array{
     *     cart_item_id: int,
     *     requested_quantity: int,
     *     available_quantity: int|null,
     *     status: string
     * }>
     */
    public function availability(User $customer): array
    {
        $customer = $this->currentCustomer($customer);
        $items = $this->customerItemsQuery($customer)
            ->with([
                'product:id,category_id,is_active',
                'product.category:id,is_active',
                'product.inventory:id,product_id,quantity',
            ])
            ->orderBy('id')
            ->get();

        $availability = [];

        foreach ($items as $item) {
            $inventory = $item->product->inventory;
            $status = $this->evaluateAvailability(
                $item->product,
                $item->product->category,
                $inventory,
                $item->quantity,
            );

            $availability[] = [
                'cart_item_id' => $item->id,
                'requested_quantity' => $item->quantity,
                'available_quantity' => $inventory?->quantity,
                'status' => $status->value,
            ];
        }

        return $availability;
    }

    /**
     * Reject a checkout line that is not eligible at its requested quantity.
     */
    public function ensureAvailableForCheckout(
        Product $product,
        Category $category,
        ?Inventory $inventory,
        int $requestedQuantity,
    ): void {
        $this->ensurePositiveQuantity($requestedQuantity);

        $outcome = $this->evaluateAvailability(
            $product,
            $category,
            $inventory,
            $requestedQuantity,
        );

        if ($outcome !== CartAvailability::Available) {
            throw new CartOperationException($outcome, $inventory?->quantity);
        }
    }

    /**
     * Lock and return the current customer record.
     */
    private function lockCustomer(User $customer): User
    {
        $lockedCustomer = User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

        if ($lockedCustomer->role !== UserRole::Customer) {
            throw new AuthorizationException('Customer cart operations are not available for this account.');
        }

        return $lockedCustomer;
    }

    /**
     * Return the current customer record for read-only calculations.
     */
    private function currentCustomer(User $customer): User
    {
        $currentCustomer = User::query()->findOrFail($customer->id);

        if ($currentCustomer->role !== UserRole::Customer) {
            throw new AuthorizationException('Customer cart operations are not available for this account.');
        }

        return $currentCustomer;
    }

    /**
     * Build a query limited to a customer's cart items.
     *
     * @return Builder<CartItem>
     */
    private function customerItemsQuery(User $customer): Builder
    {
        return CartItem::query()->whereHas(
            'cart',
            fn (Builder $query): Builder => $query->where('user_id', $customer->id),
        );
    }

    /**
     * Build a query for a customer-owned cart item.
     *
     * @return Builder<CartItem>
     */
    private function ownedCartItemQuery(User $customer, int $cartItemId): Builder
    {
        return $this->customerItemsQuery($customer)->whereKey($cartItemId);
    }

    /**
     * Lock the records that determine a product's current cart availability.
     *
     * @return array{Product, Category, Inventory|null}
     */
    private function lockProductContext(int $productId): array
    {
        $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
        $category = Category::query()->whereKey($product->category_id)->lockForUpdate()->firstOrFail();
        $inventory = Inventory::query()
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        return [$product, $category, $inventory];
    }

    /**
     * Reject a product or quantity that is not currently available.
     */
    private function ensureAvailable(
        Product $product,
        Category $category,
        ?Inventory $inventory,
        int $addedQuantity,
        int $currentQuantity = 0,
    ): void {
        $outcome = $this->evaluateAvailability(
            $product,
            $category,
            $inventory,
            1,
        );

        if ($outcome !== CartAvailability::Available) {
            throw new CartOperationException($outcome, $inventory?->quantity);
        }

        if ($inventory === null
            || $currentQuantity > $inventory->quantity
            || $addedQuantity > $inventory->quantity - $currentQuantity) {
            throw new CartOperationException(
                CartAvailability::InsufficientStock,
                $inventory?->quantity,
            );
        }
    }

    /**
     * Determine the current availability outcome for a requested quantity.
     */
    private function evaluateAvailability(
        Product $product,
        Category $category,
        ?Inventory $inventory,
        int $requestedQuantity,
    ): CartAvailability {
        if (! $product->is_active || ! $category->is_active) {
            return CartAvailability::ProductIneligible;
        }

        if ($inventory === null) {
            return CartAvailability::InventoryUnavailable;
        }

        if ($inventory->quantity === 0) {
            return CartAvailability::OutOfStock;
        }

        if ($requestedQuantity > $inventory->quantity) {
            return CartAvailability::InsufficientStock;
        }

        return CartAvailability::Available;
    }

    /**
     * Ensure direct callers cannot bypass positive quantity validation.
     */
    private function ensurePositiveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Cart item quantity must be greater than zero.');
        }
    }
}
