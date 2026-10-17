<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RolePermissionController extends Controller
{
    public function update(Request $request, string $role, TeamAccessService $team): RedirectResponse
    {
        $validated = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('administration_permissions', 'code')],
        ]);
        $team->replacePermissions($request->user(), $role, $validated['permissions'] ?? []);

        return back();
    }
}
