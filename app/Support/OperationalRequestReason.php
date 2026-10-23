<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class OperationalRequestReason
{
    public static function validateStockAdjustment(string $reasonCode, int $current, int $target, string $quantityField = 'quantity'): void
    {
        if ($reasonCode === 'damage') {
            throw ValidationException::withMessages(['reason_code' => 'Use Damage Reports for damaged stock so it can be quarantined and inspected.']);
        }
        if ($reasonCode === 'found_stock' && $target <= $current) {
            throw ValidationException::withMessages([$quantityField => 'Found stock must increase the quantity.']);
        }
        if ($reasonCode === 'theft_loss') {
            if ($target >= $current) {
                throw ValidationException::withMessages([$quantityField => 'Theft or loss must decrease the quantity.']);
            }
        }
    }

    /** @return array<string, string> */
    public static function customerReturnOptions(): array
    {
        return [
            'wrong_item' => 'Wrong item', 'defective' => 'Defective', 'transit_damage' => 'Damaged in transit',
            'not_as_described' => 'Not as described', 'changed_mind' => 'Changed mind', 'other' => 'Other',
        ];
    }

    public static function resolve(string $reasonCode, ?string $notes, bool $openingStock = false, bool $notesOptional = false): string
    {
        if ($notes !== null && trim($notes) !== '') {
            return $notes;
        }

        $label = match ($reasonCode) {
            'damage' => 'Damage',
            'theft_loss' => 'Theft or loss',
            'found_stock' => 'Found stock',
            'count_error' => 'Count correction',
            'receiving_error' => $openingStock ? 'Initial receipt' : 'Receiving error',
            'unit_of_measure_error' => 'Wrong stock unit',
            'handling_damage' => 'Warehouse handling',
            'storage_damage' => 'Storage',
            'packing_damage' => 'Packing',
            'defect_discovered' => 'Defect discovered',
            'defective' => 'Defective',
            'wrong_item' => 'Wrong item',
            'supplier_damage' => 'Supplier damage',
            'transit_damage' => 'Damaged in transit',
            'not_as_described' => 'Not as described',
            'changed_mind' => 'Changed mind',
            'other' => $notesOptional ? 'Other' : null,
            default => null,
        };

        if ($label === null) {
            throw ValidationException::withMessages(['reason' => 'Enter additional notes for the selected reason.']);
        }

        return $label;
    }
}
