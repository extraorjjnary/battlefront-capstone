<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\User\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Resources\Api\V1\CustomerProfileResource;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): CustomerProfileResource
    {
        return new CustomerProfileResource($request->user());
    }

    public function update(ProfileUpdateRequest $request, UpdateProfile $updateProfile): CustomerProfileResource
    {
        return new CustomerProfileResource($updateProfile($request->user(), $request->validated()));
    }
}
