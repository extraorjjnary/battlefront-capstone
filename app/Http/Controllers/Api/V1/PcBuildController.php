<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PcBuilder\SavePcBuildRequest;
use App\Services\PcBuilder\PcBuildToCartService;
use App\Services\PcBuilder\SavedPcBuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PcBuildController extends Controller
{
    public function index(Request $request, SavedPcBuildService $builds): JsonResponse
    {
        return response()->json(['data' => $builds->list($request->user())->map(fn ($build): array => $builds->present($build))->values()]);
    }

    public function store(SavePcBuildRequest $request, SavedPcBuildService $builds): JsonResponse
    {
        return response()->json(['data' => $builds->present($builds->create($request->user(), $request->validated()))], 201);
    }

    public function update(SavePcBuildRequest $request, string $uuid, SavedPcBuildService $builds): JsonResponse
    {
        $data = $request->validated();
        if (! array_key_exists('edit_version', $data)) {
            throw ValidationException::withMessages(['edit_version' => 'Reload this build before saving your changes.']);
        }

        return response()->json(['data' => $builds->present($builds->update($request->user(), $uuid, $data))]);
    }

    public function duplicate(Request $request, string $uuid, SavedPcBuildService $builds): JsonResponse
    {
        return response()->json(['data' => $builds->present($builds->duplicate($request->user(), $uuid))], 201);
    }

    public function destroy(Request $request, string $uuid, SavedPcBuildService $builds): JsonResponse
    {
        $builds->delete($request->user(), $uuid);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function share(Request $request, string $uuid, SavedPcBuildService $builds): JsonResponse
    {
        return response()->json(['data' => ['url' => $builds->share($request->user(), $uuid)]]);
    }

    public function stopSharing(Request $request, string $uuid, SavedPcBuildService $builds): JsonResponse
    {
        $builds->stopSharing($request->user(), $uuid);

        return response()->json(['data' => ['shared' => false]]);
    }

    public function showShared(string $token, SavedPcBuildService $builds): JsonResponse
    {
        $build = $builds->findShared($token);
        abort_if($build === null, 404);

        return response()->json(['data' => $builds->present($build)]);
    }

    public function addToCart(Request $request, string $uuid, PcBuildToCartService $cart): JsonResponse
    {
        $group = $cart->add($request->user(), $uuid);

        return response()->json(['data' => ['group_uuid' => $group->uuid, 'name' => $group->name, 'item_count' => $group->items->count()]], 201);
    }
}
