<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SavePushDeviceRequest;
use App\Models\User;
use App\Services\Notifications\PushDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PushDeviceController extends Controller
{
    public function update(SavePushDeviceRequest $request, string $device, PushDeviceService $devices): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = $user->currentAccessToken();

        return response()->json(['data' => $devices->present($devices->register($user, $session, $device, $request->deviceData()))]);
    }

    public function destroy(Request $request, string $device, PushDeviceService $devices): Response
    {
        /** @var User $user */
        $user = $request->user();
        $devices->revoke($user, $device);

        return response()->noContent();
    }
}
