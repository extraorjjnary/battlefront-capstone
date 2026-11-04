<?php

namespace App\Services\Reporting;

use App\Models\Shipment;
use App\Repositories\Order\AdministratorOrderRepository;
use App\Services\AdministratorOrderPresenter;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ShipmentExportService
{
    public const DOCUMENT_LIMIT = 500;

    private const HEADERS = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(private readonly AdministratorOrderRepository $orders, private readonly AdministratorOrderPresenter $presenter) {}

    /** @param array<string, mixed> $filters */
    public function download(string $format, array $filters): Response
    {
        $query = $this->orders->shipmentQuery($filters);
        $count = (clone $query)->count();
        $generatedAt = now(config('battlefront.timezone'))->format('Y-m-d H:i:s T');
        $labels = [
            'Search' => $filters['q'] ?? 'Any',
            'Shipment status' => ucwords(str_replace('_', ' ', $filters['shipment_status'])),
            'Reconciliation' => ucfirst($filters['reconciliation']),
            'Handoff from' => $filters['from'] ?? 'Any', 'Handoff to' => $filters['to'] ?? 'Any',
            'Timezone' => config('battlefront.timezone'),
        ];
        $filename = 'battlefront-shipments-'.now(config('battlefront.timezone'))->format('Y-m-d-His').'.'.$format;
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($query, $count, $generatedAt, $labels): void {
                $stream = fopen('php://output', 'wb');
                if ($stream === false) {
                    throw new RuntimeException('Unable to open shipment export stream.');
                }
                try {
                    fwrite($stream, "\xEF\xBB\xBF");
                    $this->writeCsv($stream, ['Order reference', 'Recipient', 'Destination', 'Shipment status', 'Parcel count', 'Packed weight (kg)',
                        'Length (cm)', 'Width (cm)', 'Height (cm)', 'Declared value (PHP)', 'Tracking number', 'Customer delivery fee (PHP)',
                        'Actual carrier cost (PHP)', 'Reconciliation', 'Carrier receipt', 'Handoff date (Asia/Manila)',
                        'Report', 'Applied filters', 'Matching records', 'Generated at']);
                    $filterText = collect($labels)->map(fn ($value, $key): string => $key.': '.$value)->implode('; ');
                    foreach ($query->lazy(200) as $shipment) {
                        $row = $this->documentRow($shipment);
                        $values = [
                            "'".$row['reference'], $row['recipient'], $row['destination'], $row['status']['label'], $row['package_count'], $row['weight'],
                            $row['length'], $row['width'], $row['height'], $row['declared_value'],
                            $row['tracking'] === null ? null : "'".$row['tracking'], $row['delivery_fee'], $row['carrier_cost'], $row['reconciliation'],
                            $row['receipt'] === null ? null : "'".$row['receipt'], $row['handed_at'],
                            'Battlefront Computer Trading - Shipment records', $filterText, (string) $count, $generatedAt,
                        ];
                        $this->writeCsv($stream, array_map(fn ($value): string => $this->csvText($value === null ? 'Not recorded' : (string) $value), $values));
                    }
                } finally {
                    fclose($stream);
                }
            }, $filename, [...self::HEADERS, 'Content-Type' => 'text/csv; charset=UTF-8']);
        }
        if ($count > self::DOCUMENT_LIMIT) {
            throw ValidationException::withMessages(['export' => 'PDF and print support up to 500 shipments. Narrow your filters or export CSV.']);
        }
        $rows = $query->limit(self::DOCUMENT_LIMIT + 1)->get()->map(fn (Shipment $shipment): array => $this->documentRow($shipment))->all();
        if (count($rows) > self::DOCUMENT_LIMIT) {
            throw ValidationException::withMessages(['export' => 'The selection changed. Narrow your filters or export CSV.']);
        }
        $document = ['rows' => $rows, 'filters' => $labels, 'generatedAt' => $generatedAt, 'print' => $format === 'print'];
        if ($format === 'print') {
            return response()->view('reports.shipments', $document, 200, self::HEADERS);
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
        $pdf->addInfo('Title', 'Battlefront Computer Trading - Shipment records');
        $pdf->loadHtml(view('reports.shipments', $document)->render(), 'UTF-8');
        $pdf->render();
        $pdf->getCanvas()->page_text(750, 575, '{PAGE_NUM} / {PAGE_COUNT}', 'Helvetica', 8);

        return response($pdf->output(), 200, [...self::HEADERS, 'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }

    /** @return array<string, mixed> */
    private function documentRow(Shipment $shipment): array
    {
        $row = $this->presenter->shipmentRow($shipment);
        $row['handed_at'] = $row['handed_at'] === null ? null : CarbonImmutable::parse($row['handed_at'])->setTimezone(config('battlefront.timezone'))->format('Y-m-d H:i:s T');

        return $row;
    }

    private function csvText(string $value): string
    {
        return preg_match('/^(?:[\t\r\n]|[\s\p{Z}\p{C}]*[=+\-@])/u', $value) === 1 ? "'".$value : $value;
    }

    /** @param resource $stream
     * @param  list<string>  $values
     */
    private function writeCsv($stream, array $values): void
    {
        if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Unable to write shipment export row.');
        }
    }
}
