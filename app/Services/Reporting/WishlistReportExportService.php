<?php

namespace App\Services\Reporting;

use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WishlistReportExportService
{
    /** @param array<string, mixed> $filters */
    public function csv(WishlistReportService $reports, array $filters): StreamedResponse
    {
        $products = $reports->exportProducts($filters);
        $activity = $reports->exportActivity($filters);
        $filename = 'wishlist-report_'.$filters['from'].'_to_'.$filters['to'].'.csv';

        return response()->streamDownload(function () use ($products, $activity, $filters): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the wishlist report output stream.');
            }
            try {
                if (fwrite($stream, "\xEF\xBB\xBF") === false) {
                    throw new RuntimeException('Unable to write the wishlist report header.');
                }
                $this->writeRow($stream, ['Product demand summary', 'From '.$filters['from'], 'To '.$filters['to'], 'Search '.$filters['q'], 'Category '.($filters['category_id'] ?? 'All'), 'Stock '.$filters['stock']]);
                $this->writeRow($stream, ['Product', 'Category', 'Price (PHP)', 'Wishlist customers', 'Stock', 'Availability', 'Additions', 'Removals', 'Net additions', 'Interest increasing']);
                foreach ($products as $product) {
                    $this->writeRow($stream, [$this->safeText($product['name']), $this->safeText((string) ($product['category'] ?? '')), $product['discount_price'] ?? $product['price'], $product['wishlist_customers'], $product['stock_quantity'] ?? 'Unavailable', $product['availability'], $product['activity']['additions'], $product['activity']['removals'], $product['activity']['net'], $product['demand']['is_increasing'] ? 'Yes' : 'No']);
                }
                $this->writeRow($stream, []);
                $this->writeRow($stream, ['Activity summary']);
                $this->writeRow($stream, ['Date', 'Additions', 'Removals']);
                foreach ($activity as $row) {
                    $this->writeRow($stream, [$row['date'], $row['additions'], $row['removals']]);
                }
            } finally {
                fclose($stream);
            }
        }, $filename, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safeText(string $value): string
    {
        return preg_match('/^(?:[\t\r\n]|[\s\p{Z}\p{C}]*[=+\-@])/u', $value) === 1 ? "'".$value : $value;
    }

    /** @param resource $stream
     * @param  list<string|int|float>  $row
     */
    private function writeRow($stream, array $row): void
    {
        if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Unable to write the wishlist report CSV row.');
        }
    }
}
