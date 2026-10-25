<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse;
use Symfony\Component\HttpFoundation\Response;

class PendingEmailVerificationResponse implements RegisterResponse
{
    /** @param Request $request */
    public function toResponse(mixed $request): Response
    {
        return $request->wantsJson()
            ? response()->json([
                'message' => 'Verify your email before logging in.',
                'data' => $request->attributes->get('email_verification_response'),
            ], 201)->header('Cache-Control', 'no-store')
            : to_route('verification.notice')->header('Cache-Control', 'no-store');
    }
}
