<?php

namespace App\Models;

use App\Enums\FulfillmentMethod;
use App\Enums\ShipmentStatus;
use Database\Factories\ShipmentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property string $carrier
 * @property ShipmentStatus $status
 * @property int $preparation_days
 * @property int $transit_min_days
 * @property int $transit_max_days
 * @property int $eta_min_days
 * @property int $eta_max_days
 * @property string|null $tracking_reference
 * @property Carbon|null $handed_to_carrier_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $preparing_at
 * @property Carbon|null $ready_for_dispatch_at
 * @property Carbon|null $in_transit_at
 * @property Carbon|null $out_for_delivery_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $eta_anchor_date
 * @property string|null $eta_timezone
 * @property Carbon|null $estimated_delivery_start
 * @property Carbon|null $estimated_delivery_end
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 */
#[Fillable([
    'order_id', 'carrier', 'status', 'preparation_days', 'transit_min_days', 'transit_max_days',
    'eta_min_days', 'eta_max_days', 'tracking_reference', 'handed_to_carrier_at', 'delivered_at',
    'preparing_at', 'ready_for_dispatch_at', 'in_transit_at', 'out_for_delivery_at', 'cancelled_at',
    'eta_anchor_date', 'eta_timezone', 'estimated_delivery_start', 'estimated_delivery_end',
])]
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'awaiting_preparation',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Shipment $shipment): void {
            if ($shipment->exists && $shipment->isDirty([
                'order_id', 'carrier', 'preparation_days', 'transit_min_days', 'transit_max_days',
                'eta_min_days', 'eta_max_days',
            ])) {
                throw new DomainException('Shipment ownership and quote context cannot be changed.');
            }

            $hasQuotedDeliveryOrder = Order::query()
                ->whereKey($shipment->order_id)
                ->where('fulfillment_method', FulfillmentMethod::Delivery->value)
                ->whereNotNull('delivery_destination')
                ->whereNotNull('shipping_profile')
                ->exists();

            if (! $hasQuotedDeliveryOrder) {
                throw new DomainException('Shipments require a quoted delivery order.');
            }

            if ($shipment->exists && $shipment->getRawOriginal('eta_anchor_date') !== null && $shipment->isDirty([
                'eta_anchor_date', 'eta_timezone', 'estimated_delivery_start', 'estimated_delivery_end',
            ])) {
                throw new DomainException('The operational shipment estimate cannot be changed.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'preparation_days' => 'integer',
            'transit_min_days' => 'integer',
            'transit_max_days' => 'integer',
            'eta_min_days' => 'integer',
            'eta_max_days' => 'integer',
            'handed_to_carrier_at' => 'datetime',
            'delivered_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_for_dispatch_at' => 'datetime',
            'in_transit_at' => 'datetime',
            'out_for_delivery_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'eta_anchor_date' => 'date',
            'estimated_delivery_start' => 'date',
            'estimated_delivery_end' => 'date',
        ];
    }
}
