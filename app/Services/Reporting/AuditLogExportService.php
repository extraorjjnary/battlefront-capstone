<?php

namespace App\Services\Reporting;

use App\Repositories\Reporting\AuditLogRepository;
use Carbon\CarbonImmutable;
use RuntimeException;

class AuditLogExportService
{
    public function __construct(private readonly AuditLogRepository $logs) {}

    /** @param array<string, mixed> $filters */
    public function writeCsv(array $filters): void
    {
        $output = fopen('php://output', 'w');
        if ($output === false) {
            throw new RuntimeException('The audit log export stream could not be opened.');
        }
        fputcsv($output, ['ID', 'Date', 'Actor', 'Action', 'Subject type', 'Subject ID', 'Reason', 'Changes']);
        foreach ($this->logs->query($filters)->cursor() as $entry) {
            fputcsv($output, [
                $entry->id, $this->formatTimestamp($entry->created_at), $this->csvText($entry->actor_name),
                $this->csvText($entry->action), $this->csvText($entry->subject_type),
                $entry->subject_id, $this->csvText($entry->reason), $this->csvText($entry->changes),
            ], ',', '"', '');
        }
        fclose($output);
    }

    private function csvText(?string $value): string
    {
        $value ??= '';

        return preg_match('/^(?:[\t\r\n]|[\s\p{Z}\p{C}]*[=+\-@])/u', $value) === 1 ? "'".$value : $value;
    }

    private function formatTimestamp(string $timestamp): string
    {
        return CarbonImmutable::parse($timestamp, 'UTC')
            ->setTimezone(config('battlefront.timezone'))
            ->format('Y-m-d H:i:s P');
    }
}
