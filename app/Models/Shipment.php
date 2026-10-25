<?php

namespace App\Models;

use App\Enums\FulfillmentMethod;
use App\Enums\ShipmentArrangement;
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
 * @property ShipmentArrangement $arrangement_type
 * @property ShipmentStatus $status
 * @property int|null $preparation_days
 * @property int|null $transit_min_days
 * @property int|null $transit_max_days
 * @property int|null $eta_min_days
 * @property int|null $eta_max_days
 * @property string|null $tracking_reference
 * @property string|null $booking_source
 * @property string|null $booking_reference
 * @property numeric-string|null $carrier_cod_collected_amount
 * @property string|null $carrier_cod_collection_reference
 * @property Carbon|null $carrier_cod_collected_at
 * @property numeric-string|null $carrier_cod_remitted_amount
 * @property string|null $carrier_cod_remittance_reference
 * @property Carbon|null $carrier_cod_remitted_at
 * @property int|null $package_count
 * @property numeric-string|null $package_weight_kg
 * @property numeric-string|null $package_length_cm
 * @property numeric-string|null $package_width_cm
 * @property numeric-string|null $package_height_cm
 * @property numeric-string|null $declared_value
 * @property string|null $handoff_mode
 * @property numeric-string|null $actual_carrier_cost
 * @property string|null $carrier_cost_reference
 * @property Carbon|null $carrier_cost_recorded_at
 * @property string|null $shipment_exception_code
 * @property string|null $shipment_exception_note
 * @property Carbon|null $shipment_exception_reported_at
 * @property Carbon|null $shipment_exception_resolved_at
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
    'order_id', 'carrier', 'arrangement_type', 'status', 'preparation_days', 'transit_min_days', 'transit_max_days',
    'eta_min_days', 'eta_max_days', 'tracking_reference', 'carrier_cod_collected_amount',
    'carrier_cod_collection_reference', 'carrier_cod_collected_at', 'carrier_cod_remitted_amount',
    'carrier_cod_remittance_reference', 'carrier_cod_remitted_at', 'handed_to_carrier_at', 'delivered_at',
    'package_count', 'package_weight_kg', 'package_length_cm', 'package_width_cm', 'package_height_cm',
    'declared_value', 'handoff_mode', 'actual_carrier_cost', 'carrier_cost_reference', 'carrier_cost_recorded_at',
    'shipment_exception_code', 'shipment_exception_note', 'shipment_exception_reported_at', 'shipment_exception_resolved_at',
    'preparing_at', 'ready_for_dispatch_at', 'in_transit_at', 'out_for_delivery_at', 'cancelled_at',
    'eta_anchor_date', 'eta_timezone', 'estimated_delivery_start', 'estimated_delivery_end',
])]
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'arrangement_type' => 'configured_rate',
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
                'order_id', 'carrier', 'arrangement_type', 'preparation_days', 'transit_min_days', 'transit_max_days',
                'eta_min_days', 'eta_max_days',
            ])) {
                throw new DomainException('Shipment ownership and quote context cannot be changed.');
            }

            if ($shipment->exists && $shipment->getRawOriginal('booking_source') !== null && $shipment->isDirty(['booking_source', 'booking_reference'])) {
                throw new DomainException('Shipment booking provenance cannot be changed.');
            }

            if ($shipment->exists && $shipment->getRawOriginal('carrier_cod_collected_at') !== null && $shipment->isDirty([
                'carrier_cod_collected_amount', 'carrier_cod_collection_reference', 'carrier_cod_collected_at',
            ])) {
                throw new DomainException('Recorded carrier COD collection evidence cannot be changed.');
            }

            if ($shipment->exists && $shipment->getRawOriginal('carrier_cod_remitted_at') !== null && $shipment->isDirty([
                'carrier_cod_remitted_amount', 'carrier_cod_remittance_reference', 'carrier_cod_remitted_at',
            ])) {
                throw new DomainException('Recorded carrier COD remittance evidence cannot be changed.');
            }

            if ($shipment->isDirty(['booking_source', 'booking_reference'])
                && ($shipment->booking_source !== 'battlefront_simulation'
                    || ! is_string($shipment->booking_reference)
                    || preg_match('/\ABF-SIM-[A-Z0-9]{12}\z/', $shipment->booking_reference) !== 1
                    || ! app()->environment(['local', 'testing'])
                    || ! config('battlefront.shipping.lbc_mock_enabled', false))) {
                throw new DomainException('Only an enabled local Battlefront simulation may create synthetic shipment booking references.');
            }

            $order = Order::query()
                ->whereKey($shipment->order_id)
                ->first();

            if (
                $order === null
                || $order->fulfillment_method !== FulfillmentMethod::Delivery
                || ! $shipment->belongsToEligibleOrder($order)
            ) {
                throw new DomainException('Shipments require a quoted delivery order.');
            }

            if ($shipment->exists && $shipment->getRawOriginal('eta_anchor_date') !== null && $shipment->isDirty([
                'eta_anchor_date', 'eta_timezone', 'estimated_delivery_start', 'estimated_delivery_end',
            ])) {
                throw new DomainException('The operational shipment estimate cannot be changed.');
            }

            if ($shipment->exists && $shipment->getRawOriginal('handed_to_carrier_at') !== null && $shipment->isDirty([
                'package_count', 'package_weight_kg', 'package_length_cm', 'package_width_cm', 'package_height_cm',
                'declared_value', 'handoff_mode',
            ])) {
                throw new DomainException('Package details and handoff mode cannot change after carrier handoff.');
            }

            if ($shipment->exists && $shipment->getRawOriginal('carrier_cost_recorded_at') !== null && $shipment->isDirty([
                'actual_carrier_cost', 'carrier_cost_reference', 'carrier_cost_recorded_at',
            ])) {
                throw new DomainException('Recorded carrier cost reconciliation cannot be changed.');
            }
        });
    }

    private function belongsToEligibleOrder(Order $order): bool
    {
        if ($this->arrangement_type === ShipmentArrangement::Manual) {
            return ($order->delivery_quote_metadata['pricing_status'] ?? null) === 'not_configured'
                && $order->delivery_fee === '0.00'
                && $order->product_subtotal !== null
                && bccomp($order->total_amount, $order->product_subtotal, 2) === 0
                && $this->carrier === 'lbc'
                && $this->preparation_days === null
                && $this->transit_min_days === null
                && $this->transit_max_days === null
                && (($this->eta_min_days === null && $this->eta_max_days === null)
                    || ($this->eta_min_days !== null
                        && $this->eta_max_days !== null
                        && $this->eta_max_days >= $this->eta_min_days));
        }

        return $order->delivery_destination !== null
            && $order->shipping_profile !== null
            && ($order->delivery_quote_metadata['pricing_status'] ?? null) !== 'not_configured';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'arrangement_type' => ShipmentArrangement::class,
            'status' => ShipmentStatus::class,
            'preparation_days' => 'integer',
            'transit_min_days' => 'integer',
            'transit_max_days' => 'integer',
            'eta_min_days' => 'integer',
            'eta_max_days' => 'integer',
            'carrier_cod_collected_amount' => 'decimal:2',
            'carrier_cod_collected_at' => 'datetime',
            'carrier_cod_remitted_amount' => 'decimal:2',
            'carrier_cod_remitted_at' => 'datetime',
            'package_count' => 'integer',
            'package_weight_kg' => 'decimal:3',
            'package_length_cm' => 'decimal:2',
            'package_width_cm' => 'decimal:2',
            'package_height_cm' => 'decimal:2',
            'declared_value' => 'decimal:2',
            'actual_carrier_cost' => 'decimal:2',
            'carrier_cost_recorded_at' => 'datetime',
            'shipment_exception_reported_at' => 'datetime',
            'shipment_exception_resolved_at' => 'datetime',
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
