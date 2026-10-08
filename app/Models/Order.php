<?php

namespace App\Models;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Enums\ShippingProfile;
use App\Enums\UserRole;
use Database\Factories\OrderFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $recipient_name
 * @property string $contact_number
 * @property FulfillmentMethod $fulfillment_method
 * @property string|null $delivery_address
 * @property string|null $delivery_destination
 * @property string|null $delivery_base_fee
 * @property ShippingProfile|null $shipping_profile
 * @property string|null $handling_surcharge
 * @property string $delivery_fee
 * @property string|null $product_subtotal
 * @property string|null $delivery_origin_city
 * @property bool|null $delivery_is_demo
 * @property string|null $delivery_assumption_label
 * @property string $total_amount
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property PaymentMethod $payment_method
 * @property string|null $payment_proof_path
 * @property PaymentRejectionReason|null $payment_rejection_reason
 * @property string|null $payment_rejection_note
 * @property Carbon $created_at
 * @property-read string $reference
 * @property-read User $user
 * @property-read Collection<int, OrderItem> $items
 * @property-read Sale|null $sale
 * @property-read Shipment|null $shipment
 */
#[Fillable([
    'user_id',
    'recipient_name',
    'contact_number',
    'fulfillment_method',
    'delivery_address',
    'delivery_destination',
    'delivery_base_fee',
    'shipping_profile',
    'handling_surcharge',
    'delivery_fee',
    'product_subtotal',
    'delivery_origin_city',
    'delivery_is_demo',
    'delivery_assumption_label',
    'total_amount',
    'status',
    'payment_status',
    'payment_method',
    'payment_proof_path',
    'payment_rejection_reason',
    'payment_rejection_note',
])]
#[Hidden(['payment_proof_path'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * The name of the "updated at" column.
     *
     * @var null
     */
    public const UPDATED_AT = null;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'delivery_fee' => '0.00',
        'status' => 'pending',
        'payment_status' => 'pending',
    ];

    /**
     * Get the customer who owns the order.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the purchase-time items in the order.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the sale recorded for the completed order.
     *
     * @return HasOne<Sale, $this>
     */
    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    /** @return HasOne<Shipment, $this> */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    /**
     * Get the customer-facing global order reference.
     *
     * @return Attribute<string, never>
     */
    protected function reference(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): string => sprintf('BF-%06d', $attributes['id']),
        );
    }

    /**
     * Register the order persistence rules.
     */
    protected static function booted(): void
    {
        static::updating(function (Order $order): void {
            if ($order->getRawOriginal('product_subtotal') !== null && $order->isDirty([
                'user_id', 'fulfillment_method', 'delivery_address',
                'product_subtotal', 'total_amount', 'delivery_destination', 'delivery_base_fee',
                'shipping_profile', 'handling_surcharge', 'delivery_fee',
                'delivery_origin_city', 'delivery_is_demo', 'delivery_assumption_label',
            ])) {
                throw new DomainException('Placed order commercial snapshots cannot be changed.');
            }
        });

        static::saving(function (Order $order): void {
            $belongsToCustomer = User::query()
                ->whereKey($order->user_id)
                ->where('role', UserRole::Customer->value)
                ->exists();

            if (! $belongsToCustomer) {
                throw new DomainException('Orders may only belong to customers.');
            }

            if ($order->fulfillment_method === FulfillmentMethod::Pickup) {
                $order->delivery_address = null;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fulfillment_method' => FulfillmentMethod::class,
            'delivery_base_fee' => 'decimal:2',
            'shipping_profile' => ShippingProfile::class,
            'handling_surcharge' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'product_subtotal' => 'decimal:2',
            'delivery_is_demo' => 'boolean',
            'total_amount' => 'decimal:2',
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_rejection_reason' => PaymentRejectionReason::class,
        ];
    }
}
