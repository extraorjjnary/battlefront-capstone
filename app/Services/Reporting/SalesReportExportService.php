<?php

namespace App\Services\Reporting;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @phpstan-import-type SalesFilters from SalesReportService
 */
class SalesReportExportService
{
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(private readonly SalesReportService $salesReportService) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function printReport(array $filters): Response
    {
        return response()->view('reports.sales', [
            ...$this->salesReportService->document($filters),
            'generatedAt' => $this->generatedAt(),
            'print' => true,
        ], 200, self::HEADERS);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function pdf(array $filters): Response
    {
        $report = $this->salesReportService->document($filters);
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(base_path('vendor/dompdf/dompdf/lib'));
        $options->setTempDir(storage_path('framework/cache'));
        $options->setFontCache(storage_path('framework/cache'));

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->addInfo('Title', 'Battlefront Computer Trading Sales Report');
        $pdf->loadHtml(view('reports.sales', [
            ...$report,
            'generatedAt' => $this->generatedAt(),
            'print' => false,
        ])->render(), 'UTF-8');
        $pdf->render();

        return response($pdf->output(), 200, [
            ...self::HEADERS,
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($report['filters'], 'pdf').'"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function csv(array $filters): StreamedResponse
    {
        $report = $this->salesReportService->exportProducts($filters);

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                throw new RuntimeException('Unable to open the sales report output stream.');
            }

            try {
                fwrite($stream, "\xEF\xBB\xBF");
                $this->writeCsvRow($stream, [
                    'Product', 'Current category', 'Number of sales', 'Quantity sold', 'Item revenue (PHP, excluding third-party delivery charges)',
                    'Report from', 'Report to', 'Reporting period', 'Product filter', 'Category filter',
                ]);

                foreach ($report['products'] as $product) {
                    $this->writeCsvRow($stream, [
                        $this->csvText($product['name']),
                        $this->csvText($product['category']),
                        $product['sales_count'],
                        $product['quantity_sold'],
                        $product['item_revenue'],
                        $report['filters']['from'],
                        $report['filters']['to'],
                        $report['filters']['period'],
                        $this->csvText($report['active_filters']['product']['name'] ?? 'All products'),
                        $this->csvText($report['active_filters']['category']['name'] ?? 'All categories'),
                    ]);
                }
            } finally {
                fclose($stream);
            }
        }, $this->filename($report['filters'], 'csv'), [
            ...self::HEADERS,
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  SalesFilters  $filters
     */
    private function filename(array $filters, string $extension): string
    {
        return 'sales-report_'.$filters['from'].'_to_'.$filters['to'].'.'.$extension;
    }

    private function generatedAt(): string
    {
        return now(config('battlefront.timezone'))->format('M j, Y, g:i A').' '.config('battlefront.timezone');
    }

    /**
     * CSV quoting alone does not stop spreadsheet formulas, including padded prefixes.
     */
    private function csvText(string $value): string
    {
        return preg_match('/^(?:[\t\r\n]|[\s\p{Z}\p{C}]*[=+\-@])/u', $value) === 1 ? "'".$value : $value;
    }

    /**
     * @param  resource  $stream
     * @param  list<string|int>  $row
     */
    private function writeCsvRow($stream, array $row): void
    {
        if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Unable to write the sales report CSV row.');
        }
    }
}
