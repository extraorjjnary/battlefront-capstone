<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Notifications\NotificationHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function summary(Request $request, NotificationHistory $history): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $history->summary($user),
            'meta' => ['user_id' => $user->id, 'audience' => $user->role->value],
        ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, NotificationHistory $history): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Notifications/Index', ['notifications' => $history->paginate($user)]);
    }

    public function update(Request $request, string $notification, NotificationHistory $history): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $history->read($user, $notification);

        return back();
    }

    public function readAll(Request $request, NotificationHistory $history): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $history->readAll($user);

        return back();
    }
}
