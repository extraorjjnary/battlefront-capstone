<?php

namespace App\Services\Reporting;

use App\Models\User;
use App\Repositories\Inventory\InventoryRepository;
use App\Services\CustomerReturnReviewService;
use App\Services\InventoryCaseReviewService;
use BcMath\Number;
use Dompdf\Dompdf;
use Dompdf\Options;
use Generator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class OperationalExportService
{
    public const DOCUMENT_LIMIT = 500;

    public function __construct(private InventoryRepository $inventory, private InventoryCaseReviewService $cases, private CustomerReturnReviewService $customers) {}

    /** @param array<string, mixed> $filters */
    public function download(User $actor, string $feature, string $format, array $filters): Response
    {
        if ($feature === 'stock-requests') {
            $filters['view'] = in_array($actor->administrationRoleCode(), ['manager', 'owner'], true)
                ? ($filters['view'] ?? (isset($filters['case']) ? 'all' : 'to_review')) : 'mine';
        }
        $columns = match ($feature) {
            'damage-reports' => ['id', 'product_name', 'quantity', 'requester_name', 'reason', 'status', 'disposition', 'held_quantity', 'age_days', 'inspection_overdue', 'created_at'],
            'stock-requests' => ['id', 'product_name', 'requester_name', 'observed_quantity', 'target_quantity', 'requested_change', 'reason', 'status', 'decision_reason', 'created_at'],
            'supplier-returns' => ['id', 'supplier_name', 'product_name', 'quantity', 'held_quantity', 'requester_name', 'status', 'resolution_outcome', 'resolution_reference', 'age_days', 'follow_up_overdue', 'created_at'],
            'customer-requests' => ['id', 'customer_name', 'order_reference', 'product_name', 'quantity', 'reason', 'withdrawn', 'customer_return_id', 'created_at'],
            default => ['id', 'customer_name', 'purchase_order_id', 'product_name', 'quantity', 'status', 'disposition', 'resolution_type', 'resolution_completed_at', 'approved_amount', 'verified_paid_amount', 'refund_balance', 'created_at'],
        };
        $rows = [];
        $rowStream = fn (): Generator => $this->rows($actor, $feature, $filters, $columns);
        if ($format !== 'csv') {
            foreach ($rowStream() as $row) {
                if (count($rows) === self::DOCUMENT_LIMIT) {
                    throw ValidationException::withMessages(['export' => 'PDF and print support up to 500 records. Narrow your filters or export CSV.']);
                }
                $rows[] = $row;
            }
        }
        $title = ucwords(str_replace('-', ' ', $feature));
        $headers = array_map(fn (string $column): string => ucwords(str_replace('_', ' ', $column)).(in_array($column, ['approved_amount', 'verified_paid_amount', 'refund_balance'], true) ? ' (PHP)' : ''), $columns);
        $generatedAt = now(config('battlefront.timezone'))->format('Y-m-d H:i:s T');
        $filename = $feature.'-'.now()->format('Y-m-d').'.'.$format;
        $responseHeaders = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($format === 'print') {
            return response()->view('reports.operational', [
                ...compact('title', 'headers', 'rows', 'filters', 'generatedAt'),
                'print' => true,
            ], 200, $responseHeaders);
        }

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers, $rowStream): void {
                $stream = fopen('php://output', 'wb');
                if ($stream === false) {
                    throw new \RuntimeException('Unable to open the export output stream.');
                }
                try {
                    fwrite($stream, "\xEF\xBB\xBF");
                    fputcsv($stream, $headers, ',', '"', '');
                    foreach ($rowStream() as $row) {
                        fputcsv($stream, array_map(fn ($value) => preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value, $row), ',', '"', '');
                    }
                } finally {
                    fclose($stream);
                }
            }, $filename, [...$responseHeaders, 'Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(base_path('vendor/dompdf/dompdf/lib'));
        $options->setTempDir(storage_path('framework/cache'));
        $options->setFontCache(storage_path('framework/cache'));
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml(view('reports.operational', compact('title', 'headers', 'rows', 'filters', 'generatedAt'))->render());
        $pdf->render();
        $pdf->getCanvas()->page_text(750, 575, '{PAGE_NUM} / {PAGE_COUNT}', 'Helvetica', 8);

        return response($pdf->output(), 200, [...$responseHeaders, 'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $columns
     * @return Generator<int, list<string>>
     */
    private function rows(User $actor, string $feature, array $filters, array $columns): Generator
    {
        $page = 1;
        do {
            $batch = match ($feature) {
                'damage-reports' => $this->cases->damageReports($filters, $filters['case'] ?? null, (bool) ($filters['overdue'] ?? false), $page)['reports'],
                'stock-requests' => $this->inventory->paginateAdjustments($filters, $filters['case'] ?? null, $actor->id, $actor->can('inventory.approve'), $page),
                'supplier-returns' => $this->cases->supplierReturns($filters, $filters['case'] ?? null, (bool) ($filters['overdue'] ?? false), $page)['returns'],
                default => $this->customers->page($actor, $filters, $this->customers->resolveCase($filters['case'] ?? null, $filters['payment_id'] ?? null), $filters['request_id'] ?? null, null, null, (bool) ($filters['overdue'] ?? false), false, $page)[$feature === 'customer-requests' ? 'customerRequests' : 'returns'],
            };
            foreach ($batch as $entry) {
                if ($feature === 'stock-requests') {
                    $entry->requested_change = $entry->target_quantity - $entry->observed_quantity;
                }
                if ($feature === 'supplier-returns' && $entry->status === 'closed' && $entry->resolution_outcome === null) {
                    $entry->status = 'Received by supplier - outcome pending';
                }
                yield array_map(fn (string $column): string => $this->value(data_get($entry, $column), in_array($column, ['approved_amount', 'verified_paid_amount', 'refund_balance'], true)), $columns);
            }
            $page++;
        } while ($batch->hasMorePages());
    }

    private function value(mixed $value, bool $money = false): string
    {
        if ($money && $value !== null) {
            $amount = (string) $value;
            if (! is_numeric($amount)) {
                throw new \UnexpectedValueException('Stored refund amount is not numeric.');
            }

            return (new Number($amount))->add('0', 2)->value;
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return $value === null ? '' : (string) $value;
    }
}
