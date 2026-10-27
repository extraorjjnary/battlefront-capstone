<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TeamEmailVerification;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class TeamEmailVerificationController extends Controller
{
    public function __construct(private TeamEmailVerification $verification) {}

    public function store(Request $request): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->verification->send($request->user(), $request->session(), $this->email($request), (string) $request->ip()));
    }

    public function verify(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            $email = $this->email($request);
            $validated = $request->validate(['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);

            return $this->verification->verify($request->user(), $request->session(), $email, $validated['code'], (string) $request->ip());
        });
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'max:255']]);
        $this->verification->clear($request->user(), $request->session(), Str::lower(trim($validated['email'])));

        return response()->json(['data' => null])->header('Cache-Control', 'no-store');
    }

    private function email(Request $request): string
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
        ]);

        return $validated['email'];
    }

    /** @param Closure(): array<string, mixed> $operation */
    private function respond(Request $request, Closure $operation): JsonResponse
    {
        try {
            $data = $operation();
            $failed = $data['delivery_status'] === 'failed';

            return response()->json([
                'message' => $failed ? 'We could not send the code. Wait for the resend timer and try again.'
                    : ($data['status'] === 'verified' ? 'Email verified. You can now create the account.' : 'Check the recipient inbox for the verification code.'),
                'data' => $data,
            ], $failed ? 503 : 200)->header('Cache-Control', 'no-store');
        } catch (TooManyRequestsHttpException $exception) {
            $seconds = max(1, (int) ($exception->getHeaders()['Retry-After'] ?? 60));
            $data = $this->verification->details($request->user(), $request->session());
            if ($data !== null) {
                $data['resend_available_at'] = now()->addSeconds($seconds)->toIso8601String();
                $data['delivery_status'] = 'rate_limited';
            }

            return response()->json(['message' => $exception->getMessage(), 'data' => $data], 429)
                ->header('Retry-After', (string) $seconds)->header('Cache-Control', 'no-store');
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Verification was unsuccessful. Check the code and try again.',
                'errors' => $exception->errors(),
                'data' => $this->verification->details($request->user(), $request->session()),
            ], 422)->header('Cache-Control', 'no-store');
        }
    }
}
