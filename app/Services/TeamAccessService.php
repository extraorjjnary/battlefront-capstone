<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\TeamAccessChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeamAccessService
{
    /**
     * @param  array{name: string, email: string, password: string, role: string}  $attributes
     */
    public function create(User $actor, array $attributes): User
    {
        $this->authorizeRoleChange($actor, null, $attributes['role']);

        return DB::transaction(function () use ($actor, $attributes): User {
            $roleId = $this->roleId($attributes['role']);
            $user = new User;
            $user->name = $attributes['name'];
            $user->email = $attributes['email'];
            $user->password = $attributes['password'];
            $user->role = UserRole::Administrator;
            $user->administration_role_id = $roleId;
            $user->is_active = true;
            $user->save();
            $this->audit($actor, 'team.created', $user->id, ['role' => $attributes['role']]);

            return $user;
        }, attempts: 3);
    }

    public function update(User $actor, User $target, string $role, bool $active): void
    {
        DB::transaction(function () use ($actor, $target, $role, $active): void {
            $ownerRoleId = $this->roleId('owner');
            $roleId = $this->roleId($role);
            DB::table('administration_roles')->where('id', $ownerRoleId)->lockForUpdate()->first();
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $this->authorizeRoleChange($actor, $locked, $role);

            if (! $locked->isAdministrator()) {
                throw ValidationException::withMessages(['user' => 'Only administrator accounts may be changed here.']);
            }
            if ($locked->id === $actor->id && (! $active || $roleId !== $ownerRoleId)) {
                throw ValidationException::withMessages(['user' => 'You cannot deactivate or demote your own owner account.']);
            }
            if ($locked->is_active && $locked->administration_role_id === $ownerRoleId
                && (! $active || $roleId !== $ownerRoleId)
                && User::query()->where('role', UserRole::Administrator->value)->where('is_active', true)
                    ->where('administration_role_id', $ownerRoleId)->count() <= 1) {
                throw ValidationException::withMessages(['user' => 'The last active owner cannot be removed.']);
            }

            $before = ['role' => $locked->administrationRoleCode(), 'is_active' => $locked->is_active];
            $locked->administration_role_id = $roleId;
            $locked->is_active = $active;
            if (! $active) {
                $locked->remember_token = null;
            }
            $locked->save();

            if (! $active || $before['role'] !== $role) {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
                $locked->tokens()->delete();
            }

            $this->audit($actor, 'team.updated', $locked->id, ['before' => $before, 'after' => ['role' => $role, 'is_active' => $active]]);
        }, attempts: 3);
    }

    /**
     * @param  list<string>  $codes
     */
    public function replacePermissions(User $actor, string $role, array $codes, int $editVersion): void
    {
        if (in_array($role, ['owner', 'it'], true)) {
            throw ValidationException::withMessages(['role' => 'Owner and IT permissions are fixed.']);
        }

        DB::transaction(function () use ($actor, $role, $codes, $editVersion): void {
            $roleId = $this->roleId($role);
            $lockedRole = DB::table('administration_roles')->where('id', $roleId)->lockForUpdate()->first();
            if ($lockedRole === null || (int) $lockedRole->edit_version !== $editVersion) {
                throw ValidationException::withMessages(['edit_version' => 'These permissions changed while you were editing. Reload and review the latest grants before saving.']);
            }
            $permissions = DB::table('administration_permissions')->whereIn('code', $codes)->pluck('id', 'code');
            if ($permissions->count() !== count(array_unique($codes))
                || array_intersect($codes, ['users.manage', 'permissions.manage', 'audit.export']) !== []) {
                throw ValidationException::withMessages(['permissions' => 'The selected permissions are invalid for this role.']);
            }

            $before = DB::table('administration_role_permissions as grants')
                ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
                ->where('grants.administration_role_id', $roleId)->pluck('permissions.code')->all();

            DB::table('administration_role_permissions')->where('administration_role_id', $roleId)->delete();
            foreach ($permissions as $permissionId) {
                DB::table('administration_role_permissions')->insert([
                    'administration_role_id' => $roleId, 'administration_permission_id' => $permissionId,
                ]);
            }

            DB::table('administration_roles')->where('id', $roleId)->update([
                'edit_version' => $editVersion + 1,
                'updated_at' => now(),
            ]);

            $this->audit($actor, 'role.permissions.updated', $roleId, ['before' => $before, 'after' => $codes]);
        }, attempts: 3);
    }

    /**
     * @return positive-int
     */
    private function roleId(string $code): int
    {
        $id = DB::table('administration_roles')->where('code', $code)->value('id');
        if ($id === null) {
            throw ValidationException::withMessages(['role' => 'Unknown administrator role.']);
        }

        $id = (int) $id;
        if ($id < 1) {
            throw ValidationException::withMessages(['role' => 'Unknown administrator role.']);
        }

        return $id;
    }

    private function authorizeRoleChange(User $actor, ?User $target, string $role): void
    {
        if (! $actor->hasAdministrationPermission('users.manage')) {
            throw new AuthorizationException;
        }

        if ($actor->administrationRoleCode() === 'it'
            && (($target !== null && ! in_array($target->administrationRoleCode(), ['staff', 'manager'], true))
                || ! in_array($role, ['staff', 'manager'], true))) {
            throw new AuthorizationException('IT administrators may manage staff and manager accounts only.');
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(User $actor, string $action, int $subjectId, array $changes): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => 'administrator_account',
            'subject_id' => $subjectId,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        DB::afterCommit(function () use ($actor, $action, $subjectId): void {
            User::query()->where('role', UserRole::Administrator->value)
                ->where('administration_role_id', $this->roleId('owner'))
                ->where('is_active', true)->get()
                ->each(fn (User $owner) => $owner->notify(new TeamAccessChanged($action, $actor->name, $subjectId)));
        });
    }
}
