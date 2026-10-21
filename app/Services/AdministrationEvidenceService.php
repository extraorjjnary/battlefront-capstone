<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdministrationEvidenceService
{
    /**
     * @param  list<UploadedFile>  $files
     */
    public function store(User $actor, string $subjectType, int $subjectId, array $files): void
    {
        $this->authorize($actor, $subjectType, $subjectId, true);
        $storedPaths = [];

        try {
            DB::transaction(function () use ($actor, $subjectType, $subjectId, $files, &$storedPaths): void {
                DB::table($this->table($subjectType))->where('id', $subjectId)->lockForUpdate()->firstOrFail();
                $count = DB::table('administration_evidence')->where('subject_type', $subjectType)->where('subject_id', $subjectId)->count();
                if ($count + count($files) > 5) {
                    throw ValidationException::withMessages(['files' => 'A case can have at most five evidence files.']);
                }

                foreach ($files as $file) {
                    $path = $file->store('administration/evidence', 'local');
                    if (! is_string($path)) {
                        throw new RuntimeException('Evidence storage failed.');
                    }
                    $storedPaths[] = $path;
                    DB::table('administration_evidence')->insert([
                        'subject_type' => $subjectType,
                        'subject_id' => $subjectId,
                        'uploaded_by' => $actor->id,
                        'path' => $path,
                        'original_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                        'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                        'created_at' => now(),
                    ]);
                }
                DB::table('audit_logs')->insert([
                    'actor_id' => $actor->id,
                    'action' => 'administration.evidence.added',
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'changes' => json_encode(['count' => count($files)], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }
    }

    public function authorize(User $actor, string $subjectType, int $subjectId, bool $upload): void
    {
        $permission = match ($subjectType) {
            'damage_report' => $upload ? 'returns.request' : 'inventory.view',
            'customer_return' => $upload ? 'returns.request' : 'orders.view',
            'refund_payment' => $upload ? 'returns.record_payment' : 'orders.view',
            default => throw new AuthorizationException,
        };
        if (! $actor->hasAdministrationPermission($permission)) {
            throw new AuthorizationException;
        }
        if ($upload && $subjectType === 'refund_payment'
            && ! DB::table('customer_refund_payments')->where('id', $subjectId)->where('recorded_by', $actor->id)->exists()) {
            throw new AuthorizationException;
        }
        if (! DB::table($this->table($subjectType))->where('id', $subjectId)->exists()) {
            abort(404);
        }
    }

    private function table(string $subjectType): string
    {
        return match ($subjectType) {
            'damage_report' => 'damage_reports',
            'customer_return' => 'customer_returns',
            'refund_payment' => 'customer_refund_payments',
            default => throw new AuthorizationException,
        };
    }
}
