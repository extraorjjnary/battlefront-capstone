<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\JsonResponse;

class CustomerPasswordController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(PasswordUpdateRequest $request): JsonResponse
    {
        $request->user()->update(['password' => $request->validated('password')]);
        if ($request->user()->must_change_password) {
            $request->user()->forceFill(['must_change_password' => false])->save();
        }

        return response()->json(['message' => __('Password updated.')]);
    }
}
