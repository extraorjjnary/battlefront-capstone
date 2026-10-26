<?php

namespace App\Actions\Inventory;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Support\OperationalRequestReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApplyInventoryAdjustment
{
    public function execute(Product $product, User $actor, int $targetQuantity, string $reasonCode, ?string $notes = null, ?int $expectedQuantity = null, ?int $initialReorderLevel = null, bool $physicalCount = false): Inventory
    {
        abort_unless($actor->can('inventory.adjust'), 403);

        $openingStock = $initialReorderLevel !== null;
        $notes = $notes === null ? null : trim($notes);
        Validator::make([
            'quantity' => $targetQuantity,
            'observed_quantity' => $expectedQuantity,
            'reorder_level' => $initialReorderLevel,
            'reason_code' => $reasonCode,
            'reason' => $notes === '' ? null : $notes,
        ], [
            'quantity' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'observed_quantity' => [Rule::requiredIf(! $openingStock), 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'reason_code' => ['required', Rule::in(['damage', 'theft_loss', 'found_stock', 'count_error', 'receiving_error', 'unit_of_measure_error', 'other'])],
            'reason' => [Rule::requiredIf($reasonCode === 'theft_loss'), 'nullable', 'string', 'min:5', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($product, $actor, $targetQuantity, $reasonCode, $notes, $expectedQuantity, $initialReorderLevel, $openingStock, $physicalCount): Inventory {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            $inventory = $lockedProduct->inventory()->lockForUpdate()->first();

            if ($openingStock && $inventory !== null) {
                throw ValidationException::withMessages(['quantity' => 'Inventory is already initialized.']);
            }
            if (! $openingStock && $inventory === null) {
                throw ValidationException::withMessages(['quantity' => 'Inventory is not initialized. Reload and set up stock.']);
            }

            $before = $inventory->quantity ?? 0;
            if (! $openingStock && $before !== $expectedQuantity) {
                throw ValidationException::withMessages(['quantity' => 'Stock changed while this adjustment was being recorded. Reload and count again.']);
            }
            OperationalRequestReason::validateStockAdjustment($reasonCode, $before, $targetQuantity);
            if ($inventory !== null && $before === $targetQuantity) {
                throw ValidationException::withMessages(['quantity' => 'The new quantity must differ from the current quantity.']);
            }
            $difference = $targetQuantity - $before;
            if ($difference < -2147483648 || $difference > 2147483647) {
                throw ValidationException::withMessages(['quantity' => 'The stock adjustment difference is too large.']);
            }

            $reason = OperationalRequestReason::resolve($reasonCode, $notes, openingStock: $openingStock, notesOptional: true);
            if ($physicalCount) {
                $reason = 'Physical sellable stock count: '.$reason;
            }
            if ($inventory === null) {
                $inventory = new Inventory(['product_id' => $lockedProduct->id, 'reorder_level' => $initialReorderLevel]);
            }
            $inventory->quantity = $targetQuantity;
            $inventory->save();

            $timestamp = now();
            $auditId = DB::table('audit_logs')->insertGetId([
                'actor_id' => $actor->id,
                'action' => 'inventory.adjusted',
                'subject_type' => 'inventory',
                'subject_id' => $inventory->id,
                'reason' => $reason,
                'changes' => json_encode([
                    'previous_quantity' => $before,
                    'new_quantity' => $targetQuantity,
                    'quantity_difference' => $difference,
                    'reason_code' => $reasonCode,
                    'operation' => $openingStock ? 'opening_stock' : ($physicalCount ? 'physical_count' : 'stock_adjustment'),
                ], JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
            ]);
            DB::table('inventory_movements')->insert([
                'product_id' => $lockedProduct->id,
                'actor_id' => $actor->id,
                'kind' => 'direct_adjustment',
                'reference_type' => 'audit_log',
                'reference_id' => $auditId,
                'sellable_before' => $before,
                'sellable_delta' => $difference,
                'sellable_after' => $targetQuantity,
                'quarantined_before' => $inventory->quarantined_quantity,
                'quarantined_delta' => 0,
                'quarantined_after' => $inventory->quarantined_quantity,
                'created_at' => $timestamp,
            ]);

            return $inventory;
        }, attempts: 3);
    }
}
