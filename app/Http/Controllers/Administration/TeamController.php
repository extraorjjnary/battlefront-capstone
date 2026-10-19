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
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $isOwner = $request->user()->administrationRoleCode() === 'owner';
        $users = User::query()->where('role', 'administrator')
            ->select(['id', 'name', 'email', 'administration_role_id', 'is_active'])
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')->orderBy('id')->paginate(25)->withQueryString();
        $userIds = $users->getCollection()->modelKeys();
        $assignedOrderCounts = DB::table('orders')->whereIn('status', ['pending', 'processing'])
            ->whereIn('assigned_handler_id', $userIds)->groupBy('assigned_handler_id')
            ->selectRaw('assigned_handler_id, count(*) as total')->pluck('total', 'assigned_handler_id');
        $assignedOrders = $request->user()->can('orders.view') ? DB::table('orders')->whereIn('status', ['pending', 'processing'])
            ->whereIn('assigned_handler_id', $userIds)->orderBy('created_at')->orderBy('id')
            ->get(['id', 'assigned_handler_id'])->groupBy('assigned_handler_id')
            ->map(fn ($orders) => $orders->take(5)->pluck('id')->all()) : collect();
        $users->through(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->administrationRoleCode(),
            'can_edit' => $isOwner || in_array($user->administrationRoleCode(), ['staff', 'manager'], true),
            'is_active' => $user->is_active,
            'open_order_count' => (int) ($assignedOrderCounts[$user->id] ?? 0),
            'open_order_ids' => $assignedOrders[$user->id] ?? [],
        ]);

        return Inertia::render('Administration/Team', [
            'users' => $users,
            'filters' => $filters,
            'canViewOrders' => $request->user()->can('orders.view'),
            'canAssignOrders' => $request->user()->can('orders.assign'),
            'availableRoles' => $isOwner ? ['staff', 'manager', 'owner', 'it'] : ['staff', 'manager'],
            'canManagePermissions' => $request->user()->can('permissions.manage'),
            'permissions' => $request->user()->can('permissions.manage') ? DB::table('administration_permissions')->orderBy('code')->get(['code', 'name']) : [],
            'grants' => $request->user()->can('permissions.manage') ? DB::table('administration_role_permissions as grants')
                ->join('administration_roles as roles', 'roles.id', '=', 'grants.administration_role_id')
                ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
                ->get(['roles.code as role', 'permissions.code as permission']) : [],
            'roleVersions' => $request->user()->can('permissions.manage') ? DB::table('administration_roles')->whereIn('code', ['staff', 'manager'])->pluck('edit_version', 'code') : [],
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

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Administrator account created.']);

        return back();
    }

    public function update(Request $request, User $user, TeamAccessService $team): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['staff', 'manager', 'owner', 'it'])],
            'is_active' => ['required', 'boolean'],
        ]);
        $team->update($request->user(), $user, $validated['role'], (bool) $validated['is_active']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Team access updated.']);

        return back();
    }
}
