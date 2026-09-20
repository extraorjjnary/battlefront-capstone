<?php

namespace App\Services\Order;

use App\Actions\Cart\CartOperationException;
use App\Actions\Inventory\AdjustInventoryStock;
use App\Actions\Order\OrderPlacementException;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderPlacementService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly AdjustInventoryStock $adjustInventoryStock,
    ) {}

    /**
     * Create an order and consume its cart and stock atomically.
     *
     * @param  array{
     *     recipient_name: string,
     *     contact_number: string,
     *     fulfillment_method: FulfillmentMethod,
     *     delivery_address: string|null,
     *     payment_method: PaymentMethod,
     *     payment_proof_path: string|null
     * }  $checkout
     */
    public function execute(User $customer, array $checkout): Order
    {
        return DB::transaction(function () use ($customer, $checkout): Order {
            $lockedCustomer = User::query()
                ->whereKey($customer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCustomer->role !== UserRole::Customer) {
                throw new AuthorizationException('Orders may only be placed by customers.');
            }

            $cart = Cart::query()
                ->where('user_id', $lockedCustomer->id)
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                throw OrderPlacementException::emptyCart();
            }

            /** @var Collection<int, CartItem> $cartItems */
            $cartItems = CartItem::query()
                ->where('cart_id', $cart->id)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            if ($cartItems->isEmpty()) {
                throw OrderPlacementException::emptyCart();
            }

            $productIds = $cartItems->pluck('product_id')->all();
            $products = Product::query()
                ->whereKey($productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $categories = Category::query()
                ->whereKey($products->pluck('category_id')->unique()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $inventories = Inventory::query()
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');
            $lines = [];
            $total = '0.00';

            foreach ($cartItems as $cartItem) {
                /** @var Product $product */
                $product = $products->get($cartItem->product_id);
                /** @var Category $category */
                $category = $categories->get($product->category_id);
                /** @var Inventory|null $inventory */
                $inventory = $inventories->get($product->id);

                try {
                    $this->cartService->ensureAvailableForCheckout(
                        $product,
                        $category,
                        $inventory,
                        $cartItem->quantity,
                    );
                } catch (CartOperationException) {
                    throw OrderPlacementException::unavailableItems();
                }

                $unitPrice = $product->discount_price ?? $product->price;

                if (! is_numeric($unitPrice)) {
                    throw new InvalidArgumentException('Product prices must be numeric.');
                }

                $lineTotal = bcmul($unitPrice, (string) $cartItem->quantity, 2);
                $total = bcadd($total, $lineTotal, 2);
                $lines[] = [
                    'product' => $product,
                    'inventory' => $inventory,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $unitPrice,
                ];
            }

            $order = Order::query()->create([
                'user_id' => $lockedCustomer->id,
                'recipient_name' => $checkout['recipient_name'],
                'contact_number' => $checkout['contact_number'],
                'fulfillment_method' => $checkout['fulfillment_method'],
                'delivery_address' => $checkout['delivery_address'],
                'total_amount' => $total,
                'status' => OrderStatus::Pending,
                'payment_status' => PaymentStatus::Pending,
                'payment_method' => $checkout['payment_method'],
                'payment_proof_path' => $checkout['payment_proof_path'],
            ]);

            foreach ($lines as $line) {
                $order->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'price_at_time' => $line['unit_price'],
                ]);

                $this->adjustInventoryStock->decrease(
                    $line['inventory'],
                    $line['quantity'],
                );
            }

            $cart->delete();

            return $order->load('items');
        }, attempts: 3);
    }
}
