<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer'],
        ]);

        return Inertia::render('Administration/Reports/AuditLog', [
            'filters' => $filters,
            'entries' => $this->query($filters)->paginate(25)->withQueryString(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer'],
        ]);

        return response()->streamDownload(function () use ($filters): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new RuntimeException('The audit log export stream could not be opened.');
            }
            fputcsv($output, ['ID', 'Date', 'Actor', 'Action', 'Subject type', 'Subject ID', 'Reason', 'Changes']);
            foreach ($this->query($filters)->cursor() as $entry) {
                fputcsv($output, [$entry->id, $entry->created_at, $entry->actor_name, $entry->action, $entry->subject_type, $entry->subject_id, $entry->reason, $entry->changes]);
            }
            fclose($output);
        }, 'audit-log.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  array{action?: string|null, actor_id?: int|null}  $filters
     */
    private function query(array $filters): Builder
    {
        return DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->select('audit_logs.*', 'users.name as actor_name')
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('audit_logs.action', $action))
            ->when($filters['actor_id'] ?? null, fn ($query, $actor) => $query->where('audit_logs.actor_id', $actor))
            ->orderByDesc('audit_logs.id');
    }
}
