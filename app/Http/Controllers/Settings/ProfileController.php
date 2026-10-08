<?php

namespace App\Http\Controllers\Settings;

use App\Actions\User\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $canManageDefaultDeliveryAddress = $request->user()->can('use-customer-cart');

        return Inertia::render('settings/Profile', [
            'canManageDefaultDeliveryAddress' => $canManageDefaultDeliveryAddress,
            'defaultDeliveryAddress' => $canManageDefaultDeliveryAddress
                ? $request->user()->default_delivery_address
                : null,
            'searchRecommendationsEnabled' => $canManageDefaultDeliveryAddress
                ? $request->user()->search_recommendations_enabled
                : false,
            'productViewRecommendationsEnabled' => $canManageDefaultDeliveryAddress
                ? $request->user()->product_view_recommendations_enabled
                : false,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, UpdateProfile $updateProfile): RedirectResponse
    {
        $updateProfile($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
