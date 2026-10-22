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
use App\Services\Notifications\OrderNotificationPublisher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * @phpstan-type CheckoutData array{
 *     cart_item_ids: list<int>,
 *     recipient_name: string, contact_number: string, fulfillment_method: FulfillmentMethod,
 *     delivery_address: string|null, payment_method: PaymentMethod, payment_proof_path: string|null,
 *     delivery_address_components?: array<string, mixed>|null
 * }
 */
class OrderPlacementService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly AdjustInventoryStock $adjustInventoryStock,
        private readonly DeliveryRules $deliveryRules,
        private readonly OrderNotificationPublisher $notifications,
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

            if ($checkout['payment_method'] === PaymentMethod::Cod
                && ($checkout['fulfillment_method'] !== FulfillmentMethod::Delivery
                    || $deliveryDestination === null
                    || ! filled($checkout['delivery_address'])
                    || $checkout['payment_proof_path'] !== null
                    || Config::string('battlefront.delivery.carrier') !== 'lbc')) {
                throw ValidationException::withMessages([
                    'payment_method' => 'COD requires a quoted LBC delivery with a delivery address and no payment proof.',
                ]);
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
            $zone = $deliveryDestination !== null ? $this->deliveryRules->zone($deliveryDestination) : null;
            if ($zone !== null && ! in_array($checkout['payment_method']->value, $zone['payment_methods'], true)) {
                throw ValidationException::withMessages(['payment_method' => 'This payment method is unavailable for the selected delivery route.']);
            }
            $deliveryFee = $quote['delivery_fee'] ?? '0.00';
            $finalTotal = bcadd($total, $deliveryFee, 2);

            if (bccomp($finalTotal, '9999999999.99', 2) > 0) {
                throw new InvalidArgumentException('Order total exceeds the supported monetary limit.');
            }

            $order = Order::query()->create([
                'handler_required' => true,
                'user_id' => $lockedCustomer->id,
                'recipient_name' => $checkout['recipient_name'],
                'contact_number' => $checkout['contact_number'],
                'fulfillment_method' => $checkout['fulfillment_method'],
                'delivery_address' => $checkout['delivery_address'],
                'product_subtotal' => $total,
                'delivery_fee' => $deliveryFee,
                'total_amount' => $finalTotal,
                'delivery_destination' => $zone['destination'] ?? null,
                'delivery_quote_metadata' => $zone !== null ? [
                    'city_code' => $zone['city_code'], 'province_code' => $zone['province_code'], 'region_code' => $zone['region_code'],
                    'dataset_version' => PsgcDeliveryLocations::VERSION, 'rule_id' => $zone['rule_id'], 'rules_version' => $zone['rules_version'],
                    'pricing_status' => $zone['configured'] ? 'configured_estimate' : 'not_configured',
                    'payment_methods' => $zone['payment_methods'],
                    'address_components' => $checkout['delivery_address_components'] ?? null,
                    'payment_assumption' => Config::string(($zone['configured']
                        ? 'battlefront.delivery.payment_assumptions.'
                        : 'battlefront.delivery.unconfigured_route.payment_assumptions.').$checkout['payment_method']->value),
                ] : null,
                'delivery_base_fee' => $quote['base_fee'] ?? null,
                'shipping_profile' => $quote['shipping_profile'] ?? null,
                'handling_surcharge' => $quote['handling_surcharge'] ?? null,
                'delivery_origin_city' => $quote['origin_city'] ?? null,
                'delivery_is_demo' => $zone === null
                    ? null
                    : ($zone['configured'] ? ($quote['is_demo'] ?? null) : false),
                'delivery_assumption_label' => $zone !== null && ! $zone['configured']
                    ? Config::string('battlefront.delivery.unconfigured_route.assumption_label')
                    : ($quote['assumption_label'] ?? null),
                'status' => OrderStatus::Pending,
                'payment_status' => PaymentStatus::Pending,
                'payment_method' => $checkout['payment_method'],
                'payment_proof_path' => $checkout['payment_proof_path'],
            ]);

            foreach ($lines as $line) {
                $order->items()->create([
                    'product_id' => $line['product']->id,
                    'product_name_at_time' => $line['product']->name,
                    'quantity' => $line['quantity'],
                    'price_at_time' => $line['unit_price'],
                    'acquisition_cost_at_time' => $line['product']->acquisition_cost,
                    'acquisition_cost_source' => $line['product']->acquisition_cost_source,
                ]);

                $this->adjustInventoryStock->decrease(
                    $line['inventory'],
                    $line['quantity'],
                    $order->id,
                );
            }

            if ($quote !== null && $zone['configured'] === true) {
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

            $this->notifications->afterCommit($order, 'order.placed');
            if ($order->payment_method->requiresPaymentProof() && $order->payment_proof_path !== null) {
                $this->notifications->afterCommit($order, 'payment.proof_submitted');
            }

            return $order->load('items');
        }, attempts: 3);
    }
}
