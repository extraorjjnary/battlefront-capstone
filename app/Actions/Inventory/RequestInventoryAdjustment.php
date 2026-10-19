<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestInventoryAdjustment
{
    public function execute(Product $product, User $requester, int $targetQuantity, string $reason, ?int $initialReorderLevel = null, ?int $replacesRequestId = null): int
    {
        return DB::transaction(function () use ($product, $requester, $targetQuantity, $reason, $initialReorderLevel, $replacesRequestId): int {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            $inventory = $lockedProduct->inventory()->lockForUpdate()->first();
            $observed = $inventory->quantity ?? 0;

            if ($replacesRequestId !== null) {
                $previous = DB::table('inventory_adjustment_requests')->where('id', $replacesRequestId)->lockForUpdate()->first();
                if ($previous === null || $previous->status !== 'withdrawn'
                    || (int) $previous->requester_id !== $requester->id || (int) $previous->product_id !== $lockedProduct->id
                    || DB::table('inventory_adjustment_requests')->where('replaces_request_id', $replacesRequestId)->exists()) {
                    throw ValidationException::withMessages(['replacement' => 'This request cannot be replaced.']);
                }
            }

            if ($inventory !== null && $observed === $targetQuantity) {
                throw ValidationException::withMessages(['quantity' => 'The requested quantity must differ from the current quantity.']);
            }

            $id = DB::table('inventory_adjustment_requests')->insertGetId([
                'product_id' => $lockedProduct->id,
                'requester_id' => $requester->id,
                'observed_quantity' => $observed,
                'target_quantity' => $targetQuantity,
                'initial_reorder_level' => $inventory === null ? ($initialReorderLevel ?? 0) : null,
                'status' => 'pending',
                'reason' => $reason,
                'replaces_request_id' => $replacesRequestId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $requester->id,
                'action' => 'inventory.adjustment.requested',
                'subject_type' => 'inventory_adjustment_request',
                'subject_id' => $id,
                'reason' => $reason,
                'changes' => json_encode(['observed_quantity' => $observed, 'target_quantity' => $targetQuantity, 'replaces_request_id' => $replacesRequestId], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $id;
        }, attempts: 3);
    }
}
