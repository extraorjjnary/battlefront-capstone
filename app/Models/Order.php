<?php

namespace App\Models;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use Database\Factories\OrderFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $recipient_name
 * @property string $contact_number
 * @property FulfillmentMethod $fulfillment_method
 * @property string|null $delivery_address
 * @property string $total_amount
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property PaymentMethod $payment_method
 * @property string|null $payment_proof_path
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read Collection<int, OrderItem> $items
 */
#[Fillable([
    'user_id',
    'recipient_name',
    'contact_number',
    'fulfillment_method',
    'delivery_address',
    'total_amount',
    'status',
    'payment_status',
    'payment_method',
    'payment_proof_path',
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
     * Register the order persistence rules.
     */
    protected static function booted(): void
    {
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
            'total_amount' => 'decimal:2',
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
        ];
    }
}
