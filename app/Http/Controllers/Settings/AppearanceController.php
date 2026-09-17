<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AppearancePreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AppearanceUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    /**
     * Show the user's appearance settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Appearance');
    }

    /**
     * Update the user's appearance preference.
     */
    public function update(AppearanceUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'appearance' => AppearancePreference::from($request->string('appearance')->toString()),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appearance updated.')]);

        return back();
    }
}
