<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property int $quantity
 * @property string|null $product_name_at_time
 * @property numeric-string $price_at_time
 * @property numeric-string|null $acquisition_cost_at_time
 * @property-read Order $order
 * @property-read Product $product
 */
#[Fillable(['order_id', 'product_id', 'quantity', 'price_at_time', 'product_name_at_time', 'acquisition_cost_at_time', 'acquisition_cost_source'])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (OrderItem $item): void {
            if ($item->isDirty('product_name_at_time')) {
                throw new DomainException('Purchase name snapshots cannot be changed.');
            }
        });
    }

    public function purchasedProductName(): string
    {
        return $this->product_name_at_time ?? $this->product->name;
    }

    /**
     * Get the order that contains the item.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the product purchased in the item.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price_at_time' => 'decimal:2',
            'acquisition_cost_at_time' => 'decimal:2',
        ];
    }
}
