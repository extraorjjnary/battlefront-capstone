<?php

namespace App\Services\Order;

use App\Actions\Cart\CartOperationException;
use App\Actions\Inventory\AdjustInventoryStock;
use App\Actions\Order\OrderPlacementException;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @phpstan-type CheckoutData array{
 *     cart_item_ids: list<int>,
 *     recipient_name: string, contact_number: string, fulfillment_method: FulfillmentMethod,
 *     delivery_address: string|null, payment_method: PaymentMethod, payment_proof_path: string|null
 * }
 */
class OrderPlacementService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly AdjustInventoryStock $adjustInventoryStock,
        private readonly DeliveryRules $deliveryRules,
    ) {}

    /**
     * Create an order and consume its cart and stock atomically.
     *
     * @param  array{
     *     cart_item_ids: list<int>,
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
        return $this->place($customer, $checkout, null);
    }

    /**
     * Place a quoted delivery order using validated checkout data and a canonical zone.
     *
     * @param  CheckoutData  $checkout
     */
    public function executeWithDeliveryQuote(User $customer, array $checkout, string $deliveryDestination): Order
    {
        if ($checkout['fulfillment_method'] !== FulfillmentMethod::Delivery) {
            throw new InvalidArgumentException('Quoted delivery placement requires delivery fulfillment.');
        }

        return $this->place($customer, $checkout, $deliveryDestination);
    }

    /** @param CheckoutData $checkout */
    private function place(User $customer, array $checkout, ?string $deliveryDestination): Order
    {
        return DB::transaction(function () use ($customer, $checkout, $deliveryDestination): Order {
            $lockedCustomer = User::query()
                ->whereKey($customer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCustomer->role !== UserRole::Customer) {
                throw new AuthorizationException('Orders may only be placed by customers.');
            }

            $cartItemIds = $checkout['cart_item_ids'];

            if (! $this->cartService->isValidCheckoutSelection($cartItemIds)) {
                throw OrderPlacementException::invalidSelection();
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
                ->whereKey($cartItemIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            if ($cartItems->count() !== count($cartItemIds)) {
                throw OrderPlacementException::invalidSelection();
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

            $quote = $deliveryDestination !== null
                ? $this->deliveryRules->quote(FulfillmentMethod::Delivery, $deliveryDestination, $products)
                : null;
            $deliveryFee = $quote['delivery_fee'] ?? '0.00';
            $finalTotal = bcadd($total, $deliveryFee, 2);

            if (bccomp($finalTotal, '9999999999.99', 2) > 0) {
                throw new InvalidArgumentException('Order total exceeds the supported monetary limit.');
            }

            $order = Order::query()->create([
                'user_id' => $lockedCustomer->id,
                'recipient_name' => $checkout['recipient_name'],
                'contact_number' => $checkout['contact_number'],
                'fulfillment_method' => $checkout['fulfillment_method'],
                'delivery_address' => $checkout['delivery_address'],
                'product_subtotal' => $total,
                'delivery_fee' => $deliveryFee,
                'total_amount' => $finalTotal,
                'delivery_destination' => $quote['destination'] ?? null,
                'delivery_base_fee' => $quote['base_fee'] ?? null,
                'shipping_profile' => $quote['shipping_profile'] ?? null,
                'handling_surcharge' => $quote['handling_surcharge'] ?? null,
                'delivery_origin_city' => $quote['origin_city'] ?? null,
                'delivery_is_demo' => $quote['is_demo'] ?? null,
                'delivery_assumption_label' => $quote['assumption_label'] ?? null,
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

            if ($quote !== null) {
                $order->shipment()->create([
                    'carrier' => Config::string('battlefront.delivery.carrier'),
                    'status' => ShipmentStatus::AwaitingPreparation,
                    'preparation_days' => $quote['preparation_days'],
                    'transit_min_days' => $quote['transit_min_days'],
                    'transit_max_days' => $quote['transit_max_days'],
                    'eta_min_days' => $quote['eta_min_days'],
                    'eta_max_days' => $quote['eta_max_days'],
                ]);
            }

            $cart->items()->whereKey($cartItemIds)->delete();

            if (! $cart->items()->exists()) {
                $cart->delete();
            }

            return $order->load('items');
        }, attempts: 3);
    }
}
