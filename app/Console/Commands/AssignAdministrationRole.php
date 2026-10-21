<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('administration:assign-role {email} {role : staff, manager, owner, or it}')]
#[Description('Assign an existing administrator to an administration role during rollout.')]
class AssignAdministrationRole extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $roleCode = (string) $this->argument('role');
        if (! in_array($roleCode, ['staff', 'manager', 'owner', 'it'], true)) {
            $this->error('Role must be staff, manager, owner, or it.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', (string) $this->argument('email'))
            ->where('role', UserRole::Administrator->value)->first();
        if ($user === null) {
            $this->error('No administrator has that email address.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $roleCode): void {
            $roleId = DB::table('administration_roles')->where('code', $roleCode)->value('id');
            $previous = $user->administration_role_id;
            $user->administration_role_id = $roleId;
            $user->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            DB::table('audit_logs')->insert([
                'actor_id' => null,
                'action' => 'team.role.assigned_by_cli',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'reason' => 'Explicit existing administrator rollout',
                'changes' => json_encode(['before' => $previous, 'after' => $roleCode], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        $this->info("Assigned {$user->email} to {$roleCode}.");

        return self::SUCCESS;
    }
}
