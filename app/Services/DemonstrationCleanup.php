<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\ChatbotKnowledge;
use App\Models\Order;
use App\Models\PcBuild;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\Order\OrderProcessingService;
use App\Services\PcBuilder\SavedPcBuildService;
use App\Services\Wishlist\WishlistService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemonstrationCleanup
{
    public function __construct(private DemonstrationRehearsal $records, private DemonstrationProvisioning $demo) {}

    public function assertTarget(): void
    {
        if (! $this->demo->enabled() || config('demonstration.cleanup_enabled') !== true) {
            throw new RuntimeException('Cleanup requires disclosure, exact connection/host/database and temporary cleanup permission.');
        }
    }

    public function financialRefusal(Order $order): ?string
    {
        if ($order->development_fixture_key !== null || DemonstrationProvisioning::owns('order', $order->id)) {
            return 'Protected historical fixture.';
        }
        if ($order->payment_origin !== 'manual' || $order->paymongoFlows()->exists() || $order->payment_proof_path !== null) {
            return 'Gateway, payment proof or uncertain payment provenance; retained.';
        }
        if (DB::table('customer_returns')->where('order_id', $order->id)->exists()
            || DB::table('customer_return_requests')->where('order_id', $order->id)->exists()
            || DB::table('order_cancellation_requests')->where('order_id', $order->id)->exists()) {
            return 'Return, refund or cancellation-review dependency.';
        }
        $shipment = $order->shipment()->first();
        if ($shipment !== null && ($shipment->tracking_reference !== null || $shipment->booking_reference !== null
            || $shipment->carrier_cod_collected_at !== null || $shipment->carrier_cod_remitted_at !== null
            || $shipment->carrier_cost_recorded_at !== null || $shipment->actual_carrier_cost !== null
            || $shipment->carrier_cod_collected_amount !== null || $shipment->carrier_cod_remitted_amount !== null || $shipment->handed_to_carrier_at !== null)) {
            return 'Carrier or collection evidence must be retained without cleanup.';
        }
        if (! $this->records->matches('order', $order)) {
            return 'Missing or changed creation provenance.';
        }

        return null;
    }

    public function archiveRefusal(Order $order): ?string
    {
        if (($reason = $this->financialRefusal($order)) !== null) {
            return $reason;
        }
        $creation = $this->records->evidence('demo.rehearsal.created', 'order', $order->id);
        $posted = $this->records->evidence('demo.rehearsal.sale.posted', 'order', $order->id);
        $sale = $order->sale()->first();
        if ($order->status->value !== 'completed' || $order->payment_status->value !== 'verified'
            || $order->fulfillment_method->value !== 'pickup' || ! in_array($order->payment_method->value, ['cash', 'card_at_store'], true)
            || $order->shipment()->exists() || $posted === null || $sale === null
            || $posted['run'] !== $creation['run'] || $posted['fingerprint'] !== $this->records->fingerprint($order)
            || $posted['sale'] !== $sale->getAttributes() || $sale->handler_id !== $order->assigned_handler_id) {
            return 'Not a proven unchanged synthetic offline pickup sale.';
        }
        $subtotal = '0.00';
        $orderSubtotal = $order->product_subtotal;
        if ($orderSubtotal === null || ! is_numeric($sale->amount)) {
            return 'Missing or invalid saved subtotal.';
        }
        foreach ($order->items()->get() as $item) {
            $subtotal = bcadd($subtotal, bcmul((string) $item->quantity, $item->price_at_time, 2), 2);
        }
        if ($order->items()->count() === 0 || bccomp($subtotal, $sale->amount, 2) !== 0
            || bccomp($subtotal, $orderSubtotal, 2) !== 0
            || bccomp($order->delivery_fee, '0', 2) !== 0 || bccomp($subtotal, $order->total_amount, 2) !== 0) {
            return 'Inconsistent commercial snapshots.';
        }

        return null;
    }

    /** @return list<array{type: string, id: int, action: string, reason: string}> */
    public function execute(string $scope, bool $apply): array
    {
        $this->assertTarget();
        if (! in_array($scope, ['orders', 'all'], true)) {
            throw new RuntimeException('Scope must be orders or all.');
        }

        return DB::transaction(function () use ($scope, $apply): array {
            $prepared = DB::table('audit_logs')->where('action', 'demo.history.prepared')->orderByDesc('id')->first();
            if ($prepared === null || (json_decode($prepared->changes, true)['profile'] ?? null) !== DemonstrationProvisioning::PROFILE
                || (json_decode($prepared->changes, true)['fingerprint'] ?? null) !== $this->demo->historyFingerprint()
                || DB::table('products')->where('is_catalog_imported', true)->count() !== 654
                || DB::table('inventories')->whereIn('product_id', DB::table('products')->where('is_catalog_imported', true)->select('id'))->count() !== 654) {
                throw new RuntimeException('Protected catalog/inventory or prepared historical baseline is missing or damaged.');
            }
            $runs = DB::table('audit_logs')->where('action', 'demo.rehearsal.started')->where('changes->profile', DemonstrationRehearsal::PROFILE)
                ->orderBy('id')->lockForUpdate()->get();
            if ($apply) {
                foreach ($runs as $run) {
                    if ($this->records->evidence('demo.rehearsal.closed', 'run', $run->id) === null) {
                        $this->records->audit('demo.rehearsal.closed', 'run', $run->id);
                    }
                }
            }
            $result = [];
            $marked = DB::table('audit_logs')->where('action', 'demo.rehearsal.created')->where('changes->profile', DemonstrationRehearsal::PROFILE);
            $customerRefusals = [];
            if ($scope === 'all') {
                foreach (User::query()->whereIn('id', (clone $marked)->where('subject_type', 'user')->select('subject_id'))->orderBy('id')->lockForUpdate()->get() as $customer) {
                    $customerRefusals[$customer->id] = $this->customerRefusal($customer);
                }
            }
            foreach (Order::query()->whereIn('id', (clone $marked)->where('subject_type', 'order')->select('subject_id'))->orderBy('id')->lockForUpdate()->get() as $order) {
                $action = 'excluded';
                $reason = $this->financialRefusal($order);
                if ($reason === null && $order->status === OrderStatus::Completed) {
                    $reason = $this->archiveRefusal($order);
                    if ($reason === null) {
                        $action = $this->records->evidence('demo.cleanup.sale.archived', 'order', $order->id) ? 'already_archived' : 'archive_sale';
                        if ($apply && $action === 'archive_sale') {
                            $this->records->audit('demo.cleanup.sale.archived', 'order', $order->id);
                        }
                    }
                } elseif ($reason === null && $order->status === OrderStatus::Cancelled) {
                    $action = 'already_cancelled';
                } elseif ($reason === null) {
                    $order->load('shipment');
                    if ($order->sale()->exists() || ! app(OrderProcessingService::class)->canCustomerCancel($order)) {
                        $reason = 'Not an eligible unpaid Pending rehearsal order.';
                    } elseif (! $this->reservationMatches($order)) {
                        $reason = 'Stock movement evidence does not match saved quantities.';
                    } else {
                        $action = 'cancel';
                        if ($apply) {
                            app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Cancelled);
                            $this->records->audit('demo.cleanup.order.cancelled', 'order', $order->id);
                        }
                    }
                }
                $result[] = ['type' => 'order', 'id' => $order->id, 'action' => $action, 'reason' => $reason ?? 'Evidence retained; no historical deletion.'];
            }
            if ($scope === 'all') {
                $this->cleanReferences($marked, $apply, $customerRefusals, $result);
                foreach (DB::table('users')->whereNotIn('id', (clone $marked)->where('subject_type', 'user')->select('subject_id'))->pluck('id') as $id) {
                    $result[] = ['type' => 'user', 'id' => (int) $id, 'action' => 'retained', 'reason' => 'Baseline or unmarked account; access unchanged.'];
                }
            }
            foreach (DB::table('orders')->whereNull('development_fixture_key')->whereNotIn('id', (clone $marked)->where('subject_type', 'order')->select('subject_id'))->pluck('id') as $id) {
                $result[] = ['type' => 'order', 'id' => (int) $id, 'action' => 'retained', 'reason' => 'Unmarked transaction; never inferred to be rehearsal data.'];
            }

            return $result;
        }, attempts: 3);
    }

    private function reservationMatches(Order $order): bool
    {
        $expected = $order->items()->get()->groupBy('product_id')->map(fn ($items): int => -(int) $items->sum('quantity'))->sortKeys()->all();
        $movements = DB::table('inventory_movements')->where('reference_type', 'order')->where('reference_id', $order->id)->lockForUpdate()->get();
        $actual = $movements->groupBy('product_id')->map(fn ($rows): int => (int) $rows->sum('sellable_delta'))->sortKeys()->all();

        return $expected !== [] && $expected === $actual && $movements->every(fn ($row): bool => $row->kind === 'order_placement' && (int) $row->quarantined_delta === 0);
    }

    /**
     * @param  array<int, string|null>  $customerRefusals
     * @param  list<array{type: string, id: int, action: string, reason: string}>  $result
     */
    private function cleanReferences(Builder $marked, bool $apply, array $customerRefusals, array &$result): void
    {
        foreach ($this->supportedModels() as $type => $class) {
            foreach ($class::query()->whereIn('id', (clone $marked)->where('subject_type', $type)->select('subject_id'))->orderBy('id')->lockForUpdate()->get() as $model) {
                $id = $model->getKey();
                if (! is_int($id) || $id < 1) {
                    throw new RuntimeException('Unsupported rehearsal record identity.');
                }
                $reason = $this->records->matches($type, $model) ? null : 'Changed or ambiguous rehearsal record.';
                if (DemonstrationProvisioning::owns($type, $model->getKey())) {
                    $reason = 'Protected provisioned baseline.';
                }
                if ($model instanceof CartItem && $model->pc_build_cart_group_id !== null) {
                    $reason = 'Grouped cart ownership unsupported; retained.';
                }
                if ($model instanceof User) {
                    $reason ??= $customerRefusals[$id];
                }
                $action = $reason === null ? ($model instanceof User ? ($model->is_active ? 'deactivate' : 'already_inactive') : 'remove') : 'excluded';
                if ($apply && $reason === null && $action !== 'already_inactive') {
                    if ($model instanceof User) {
                        $model->forceFill(['is_active' => false, 'remember_token' => null])->save();
                        DB::table('sessions')->where('user_id', $model->getKey())->delete();
                        $model->tokens()->delete();
                    } elseif ($model instanceof WishlistItem) {
                        app(WishlistService::class)->remove($model->user, $model->product_id);
                    } elseif ($model instanceof PcBuild) {
                        app(SavedPcBuildService::class)->delete($model->user, $model->uuid);
                    } else {
                        $model->delete();
                    }
                    $this->records->audit('demo.cleanup.'.$action, $type, $model->getKey());
                }
                $result[] = ['type' => $type, 'id' => $id, 'action' => $action, 'reason' => $reason ?? 'Explicit rehearsal provenance validated.'];
            }
        }
    }

    /** @return array<string, class-string<Model>> */
    private function supportedModels(): array
    {
        return ['faq' => ChatbotKnowledge::class, 'cart_item' => CartItem::class, 'wishlist' => WishlistItem::class, 'pc_build' => PcBuild::class, 'user' => User::class];
    }

    private function customerRefusal(User $user): ?string
    {
        if ($user->role->value !== 'customer' || $user->administration_role_id !== null
            || array_key_exists(strtolower($user->email), $this->demo->accounts()) || DemonstrationProvisioning::owns('user', $user->id)) {
            return 'Protected baseline identity or administrator; access unchanged.';
        }
        foreach ($user->orders()->lockForUpdate()->get() as $order) {
            if ($this->financialRefusal($order) !== null || ($order->status !== OrderStatus::Cancelled && $this->archiveRefusal($order) !== null)
                || ($order->status === OrderStatus::Completed && $this->records->evidence('demo.cleanup.sale.archived', 'order', $order->id) === null)) {
                return 'Unrelated, unresolved or unarchived transaction dependency.';
            }
        }
        foreach (['carts', 'wishlist_items', 'pc_builds', 'customer_return_requests', 'customer_searches', 'customer_product_views', 'pc_build_events'] as $table) {
            if (DB::table($table)->where('user_id', $user->id)->exists()) {
                return 'Retained shopping or return dependency; account preserved.';
            }
        }
        if (DB::table('audit_logs')->where('actor_id', $user->id)->where('action', 'not like', 'demo.rehearsal.%')->where('action', 'not like', 'demo.cleanup.%')->exists()) {
            return 'Other audited activity requires review; account preserved.';
        }
        foreach (['inventory_movements' => 'actor_id', 'sales' => 'handler_id', 'administration_evidence' => 'uploaded_by', 'inventory_adjustment_requests' => 'requester_id', 'damage_reports' => 'requester_id', 'supplier_returns' => 'requester_id'] as $table => $column) {
            if (DB::table($table)->where($column, $user->id)->exists()) {
                return 'Operational ownership dependency; account preserved.';
            }
        }

        return null;
    }
}
