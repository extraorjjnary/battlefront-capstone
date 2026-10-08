<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\User;
use App\Services\Notifications\NotificationHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationHistory $history): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return NotificationResource::collection($history->paginate($user))
            ->additional(['meta' => ['unread_count' => $history->unreadCount($user)]]);
    }

    public function unreadCount(Request $request, NotificationHistory $history): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => ['unread_count' => $history->unreadCount($user)]]);
    }

    public function update(Request $request, string $notification, NotificationHistory $history): NotificationResource
    {
        /** @var User $user */
        $user = $request->user();

        return (new NotificationResource($history->read($user, $notification)))
            ->additional(['meta' => ['unread_count' => $history->unreadCount($user)]]);
    }

    public function readAll(Request $request, NotificationHistory $history): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $history->readAll($user);

        return response()->json(['data' => ['unread_count' => $history->unreadCount($user)]]);
    }
}
