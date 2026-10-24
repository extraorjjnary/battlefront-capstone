<?php

namespace App\Actions\Supplier;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSupplier
{
    /** @param array<string, mixed> $validated */
    public function execute(User $actor, int $supplier, array $validated): void
    {
        DB::transaction(function () use ($actor, $supplier, $validated): void {
            $old = DB::table('suppliers')->where('id', $supplier)->lockForUpdate()->first();
            if ($old === null) {
                abort(404);
            }
            if ((int) $validated['edit_version'] !== (int) $old->edit_version) {
                throw ValidationException::withMessages(['edit_version' => 'This supplier changed while you were editing. Reload and review the latest values before saving.']);
            }
            unset($validated['edit_version']);
            DB::table('suppliers')->where('id', $supplier)->update([...$validated, 'edit_version' => $old->edit_version + 1, 'updated_at' => now()]);
            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'action' => 'supplier.updated',
                'subject_type' => 'supplier',
                'subject_id' => $supplier,
                'changes' => json_encode(['before' => $old, 'after' => $validated], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });
    }
}
