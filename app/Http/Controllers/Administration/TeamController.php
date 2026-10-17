<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $isOwner = $request->user()->administrationRoleCode() === 'owner';
        $users = User::query()->where('role', 'administrator')
            ->select(['id', 'name', 'email', 'administration_role_id', 'is_active'])
            ->orderBy('name')->paginate(25)->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->administrationRoleCode(),
                'can_edit' => $isOwner || in_array($user->administrationRoleCode(), ['staff', 'manager'], true),
                'is_active' => $user->is_active,
            ]);

        return Inertia::render('Administration/Team', [
            'users' => $users,
            'availableRoles' => $isOwner ? ['staff', 'manager', 'owner', 'it'] : ['staff', 'manager'],
            'canManagePermissions' => $request->user()->can('permissions.manage'),
            'permissions' => DB::table('administration_permissions')->orderBy('code')->get(['code', 'name']),
            'grants' => DB::table('administration_role_permissions as grants')
                ->join('administration_roles as roles', 'roles.id', '=', 'grants.administration_role_id')
                ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
                ->get(['roles.code as role', 'permissions.code as permission']),
        ]);
    }

    public function store(Request $request, TeamAccessService $team): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'role' => ['required', Rule::in(['staff', 'manager', 'owner', 'it'])],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $team->create($request->user(), $validated);

        return back();
    }

    public function update(Request $request, User $user, TeamAccessService $team): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['staff', 'manager', 'owner', 'it'])],
            'is_active' => ['required', 'boolean'],
        ]);
        $team->update($request->user(), $user, $validated['role'], (bool) $validated['is_active']);

        return back();
    }
}
