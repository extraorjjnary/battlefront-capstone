<?php

namespace App\Http\Controllers;

use App\Http\Requests\PcBuilder\SavePcBuildRequest;
use App\Services\PcBuilder\PcBuildToCartService;
use App\Services\PcBuilder\SavedPcBuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PcBuildController extends Controller
{
    public function index(Request $request, SavedPcBuildService $savedPcBuildService): JsonResponse
    {
        return response()->json($savedPcBuildService->list($request->user())->map(
            fn ($build): array => $savedPcBuildService->present($build),
        ));
    }

    public function store(SavePcBuildRequest $request, SavedPcBuildService $savedPcBuildService): JsonResponse
    {
        $build = $savedPcBuildService->create($request->user(), $request->buildData());

        return response()->json($savedPcBuildService->present($build), 201);
    }

    public function update(SavePcBuildRequest $request, string $uuid, SavedPcBuildService $savedPcBuildService): JsonResponse
    {
        $data = $request->buildData();
        if (! array_key_exists('edit_version', $data)) {
            throw ValidationException::withMessages(['edit_version' => 'Reload this build before saving your changes.']);
        }
        $build = $savedPcBuildService->update($request->user(), $uuid, $data);

        return response()->json($savedPcBuildService->present($build));
    }

    public function duplicate(Request $request, string $uuid, SavedPcBuildService $savedPcBuildService): JsonResponse
    {
        $build = $savedPcBuildService->duplicate($request->user(), $uuid);

        return response()->json($savedPcBuildService->present($build), 201);
    }

    public function addToCart(Request $request, string $uuid, PcBuildToCartService $pcBuildToCartService): JsonResponse
    {
        $group = $pcBuildToCartService->add($request->user(), $uuid);

        return response()->json([
            'group_uuid' => $group->uuid,
            'name' => $group->name,
            'item_count' => $group->items->count(),
        ], 201);
    }

    public function destroy(Request $request, string $uuid, SavedPcBuildService $savedPcBuildService): JsonResponse
    {
        $savedPcBuildService->delete($request->user(), $uuid);

        return response()->json(['deleted' => true]);
    }

    public function share(Request $request, string $uuid, SavedPcBuildService $savedPcBuildService): JsonResponse
    {
        return response()->json(['url' => $savedPcBuildService->share($request->user(), $uuid)]);
    }

    public function stopSharing(Request $request, string $uuid, SavedPcBuildService $savedPcBuildService): JsonResponse
    {
        $savedPcBuildService->stopSharing($request->user(), $uuid);

        return response()->json(['shared' => false]);
    }

    public function showShared(string $token, SavedPcBuildService $savedPcBuildService): Response
    {
        $build = $savedPcBuildService->findShared($token);
        abort_if($build === null, 404);

        return Inertia::render('PcBuilder/Shared', [
            'build' => $savedPcBuildService->present($build),
        ]);
    }
}
