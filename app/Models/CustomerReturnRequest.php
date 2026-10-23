<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CustomerReturnRequestFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $order_id
 * @property int $order_item_id
 * @property int $quantity
 * @property string $reason_code
 * @property string|null $reason
 * @property int|null $customer_return_id
 * @property Carbon|CarbonImmutable|null $withdrawn_at
 * @property Carbon|CarbonImmutable $created_at
 * @property-read User $customer
 * @property-read Order $order
 * @property-read OrderItem $item
 */
#[Fillable(['user_id', 'order_id', 'order_item_id', 'quantity', 'reason_code', 'reason'])]
class CustomerReturnRequest extends Model
{
    /** @use HasFactory<CustomerReturnRequestFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'withdrawn_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (CustomerReturnRequest $request): void {
            if ($request->isDirty(['user_id', 'order_id', 'order_item_id', 'quantity', 'reason_code', 'reason'])
                || $request->getOriginal('customer_return_id') !== null
                || $request->getOriginal('withdrawn_at') !== null) {
                throw new DomainException('Submitted return request history cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
