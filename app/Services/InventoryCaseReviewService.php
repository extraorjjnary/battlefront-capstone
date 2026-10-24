<?php

namespace App\Services;

use App\Repositories\Inventory\InventoryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

class InventoryCaseReviewService
{
    public function __construct(private readonly InventoryRepository $inventory) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function damageReports(array $filters, int|string|null $case, bool $overdue): array
    {
        $overdueCutoff = now()->startOfDay()->subDays(3);
        $reports = $this->inventory->paginateDamageReports($filters, $case, $overdue, $overdueCutoff);
        $heldBySupplierReturn = $this->inventory->damageSupplierHolds($reports->getCollection()->pluck('id'));
        $reports->getCollection()->transform(function ($report) use ($heldBySupplierReturn, $overdueCutoff) {
            $report->held_quantity = $report->status === 'pending' || ($report->disposition === 'supplier_return' && $heldBySupplierReturn->has($report->id))
                ? $report->quantity : 0;
            $report->age_days = (int) now()->startOfDay()->diffInDays($report->created_at, true);
            $report->inspection_overdue = $report->status === 'pending'
                && Carbon::parse($report->created_at)->lt($overdueCutoff);

            return $report;
        });

        return [
            'reports' => $reports,
            'filters' => ['q' => $filters['q'] ?? null, 'overdue' => $overdue],
            'selected_case' => $case,
            'evidence' => $this->inventory->damageEvidence($reports->getCollection()->pluck('id')),
            'products' => $this->inventory->availableCaseProducts(),
            'suppliers' => $this->inventory->activeSuppliers(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function supplierReturns(array $filters, int|string|null $case, bool $overdue): array
    {
        $overdueCutoff = now()->startOfDay()->subDays(30);
        $returns = $this->inventory->paginateSupplierReturns($filters, $case, $overdue, $overdueCutoff);
        $returns->getCollection()->transform(function ($return) use ($overdueCutoff) {
            $return->held_quantity = in_array($return->status, ['pending', 'approved', 'rejected_follow_up'], true) ? $return->quantity : 0;
            $return->age_days = (int) now()->startOfDay()->diffInDays($return->created_at, true);
            $isOpen = in_array($return->status, ['pending', 'approved', 'dispatched', 'rejected_follow_up'], true)
                || ($return->status === 'closed' && $return->resolution_outcome === null);
            $return->follow_up_overdue = $isOpen && CarbonImmutable::parse($return->created_at)->lt($overdueCutoff);

            return $return;
        });

        return [
            'returns' => $returns,
            'filters' => ['q' => $filters['q'] ?? null, 'overdue' => $overdue],
            'selected_case' => $case,
            'products' => $this->inventory->availableCaseProducts(),
            'suppliers' => $this->inventory->activeSuppliers(),
        ];
    }
}
