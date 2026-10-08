<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fortify\CreateNewUser;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\CustomerProfileResource;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, CreateNewUser $createNewUser): JsonResponse
    {
        $user = $createNewUser->create($request->only([
            'name', 'email', 'password', 'password_confirmation',
        ]));

        event(new Registered($user));

        return $this->tokenResponse($request, $user, $request->string('device_name')->toString(), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $email = config('fortify.lowercase_usernames')
            ? Str::lower($validated['email'])
            : $validated['email'];

        if (! Auth::guard('web')->once(['email' => $email, 'password' => $validated['password']])) {
            abort(401, 'Invalid credentials.');
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if ($user->role !== UserRole::Customer) {
            abort(401, 'Invalid credentials.');
        }

        return $this->tokenResponse($request, $user, $validated['device_name']);
    }

    public function logout(Request $request): Response
    {
        PushDevice::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('personal_access_token_id', $request->user()->currentAccessToken()->id)
            ->update(['is_active' => false, 'expo_push_token' => null, 'token_hash' => null, 'updated_at' => now()]);
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function tokenResponse(Request $request, User $user, string $deviceName, int $status = 200): JsonResponse
    {
        $token = $user->createToken($deviceName, ['*'], now()->addDays(30));

        return response()->json([
            'data' => [
                'user' => (new CustomerProfileResource($user))->resolve($request),
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at->toIso8601String(),
            ],
        ], $status);
    }
}
