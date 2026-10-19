<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\AuditLogPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request, AuditLogPresenter $presenter): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Administration/Reports/AuditLog', [
            'filters' => $filters,
            'entries' => $this->query($filters)->paginate(25)->withQueryString()
                ->through(fn (stdClass $entry): array => $presenter->present($entry, $request->user())),
            'actors' => DB::table('users')->leftJoin('administration_roles', 'administration_roles.id', '=', 'users.administration_role_id')
                ->whereIn('users.id', DB::table('audit_logs')->whereNotNull('actor_id')->select('actor_id'))
                ->orderBy('users.name')->orderBy('users.id')
                ->get(['users.id', 'users.name', 'users.role', 'administration_roles.name as administration_role']),
            'actions' => DB::table('audit_logs')->distinct()->orderBy('action')->pluck('action'),
            'subjectTypes' => DB::table('audit_logs')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        return response()->streamDownload(function () use ($filters): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new RuntimeException('The audit log export stream could not be opened.');
            }
            fputcsv($output, ['ID', 'Date', 'Actor', 'Action', 'Subject type', 'Subject ID', 'Reason', 'Changes']);
            foreach ($this->query($filters)->cursor() as $entry) {
                fputcsv($output, [
                    $entry->id, $this->formatTimestamp($entry->created_at), $this->csvText($entry->actor_name),
                    $this->csvText($entry->action), $this->csvText($entry->subject_type),
                    $entry->subject_id, $this->csvText($entry->reason), $this->csvText($entry->changes),
                ], ',', '"', '');
            }
            fclose($output);
        }, 'audit-log.csv', ['Content-Type' => 'text/csv']);
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

    private function dateBoundary(string $date): string
    {
        return CarbonImmutable::parse($date, config('battlefront.timezone'))
            ->startOfDay()->utc()->toDateTimeString();
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'actor_type' => ['nullable', 'string', 'in:customer,administrator,system'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        return DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->select('audit_logs.*', 'users.name as actor_name')
            ->addSelect(DB::raw("CASE WHEN audit_logs.actor_id IS NULL OR users.id IS NULL THEN 'system' WHEN users.role = 'administrator' THEN 'administrator' ELSE 'customer' END as actor_type"))
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('audit_logs.action', $action))
            ->when($filters['actor_id'] ?? null, fn ($query, $actor) => $query->where('audit_logs.actor_id', $actor))
            ->when($filters['actor_type'] ?? null, fn ($query, $type) => match ($type) {
                'system' => $query->where(function (Builder $query): void {
                    $query->whereNull('audit_logs.actor_id')
                        ->orWhereNull('users.id');
                }),
                'customer' => $query->where('users.role', 'customer'),
                'administrator' => $query->where('users.role', 'administrator'),
            })
            ->when($filters['subject_type'] ?? null, fn ($query, $type) => $query->where('audit_logs.subject_type', $type))
            ->when($filters['subject_id'] ?? null, fn ($query, $id) => $query->where('audit_logs.subject_id', $id))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('audit_logs.created_at', '>=', $this->dateBoundary($from)))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('audit_logs.created_at', '<', $this->dateBoundary(CarbonImmutable::parse($to)->addDay()->toDateString())))
            ->orderByDesc('audit_logs.id');
    }
}
