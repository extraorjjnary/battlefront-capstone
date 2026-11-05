<?php

namespace App\Http\Controllers;

use App\Services\DemonstrationProvisioning;
use App\Services\DemonstrationRehearsal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DemonstrationRehearsalController extends Controller
{
    private function guard(): void
    {
        abort_unless(app(DemonstrationProvisioning::class)->enabled() && config('demonstration.rehearsal_enabled') === true
            && is_string(config('demonstration.rehearsal_run_id')) && Str::isUuid(config('demonstration.rehearsal_run_id')) && is_string(config('demonstration.rehearsal_key_hash'))
            && preg_match('/^[a-f0-9]{64}$/', config('demonstration.rehearsal_key_hash')), 404);
    }

    public function show(Request $request): Response
    {
        $this->guard();

        return Inertia::render('auth/DemonstrationRehearsal', ['active' => $request->session()->has('demonstration_rehearsal')]);
    }

    public function store(Request $request, DemonstrationRehearsal $records): RedirectResponse
    {
        $this->guard();
        $data = $request->validate(['password' => ['required', 'string', 'min:32', 'max:512'], 'fictional' => ['accepted']]);
        if (! hash_equals(config('demonstration.rehearsal_key_hash'), hash('sha256', $data['password']))) {
            throw ValidationException::withMessages(['password' => 'Invalid rehearsal credential.']);
        }
        $request->session()->put('demonstration_rehearsal', $records->begin(config('demonstration.rehearsal_run_id'), $request->user()?->id));

        return to_route('demonstration.rehearsal.show');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('demonstration_rehearsal');

        return to_route('home');
    }
}
