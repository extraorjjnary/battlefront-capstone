<?php

namespace App\Actions\Supplier;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateSupplier
{
    /** @param array<string, mixed> $validated */
    public function execute(User $actor, array $validated): void
    {
        DB::transaction(function () use ($actor, $validated): void {
            $id = DB::table('suppliers')->insertGetId([...$validated, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'action' => 'supplier.created',
                'subject_type' => 'supplier',
                'subject_id' => $id,
                'changes' => json_encode($validated, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });
    }
}
