<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PcBuilder\ComponentOptionsRequest;
use App\Http\Requests\PcBuilder\ValidatePcBuildRequest;
use App\Services\PcBuilder\CompatibilityEngine;
use App\Services\PcBuilder\CompatibleComponentOptions;
use App\Services\PcBuilder\PcBuilderService;
use App\Services\PcBuilder\PcBuildTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PcBuilderController extends Controller
{
    public function index(Request $request, PcBuilderService $builder, PcBuildTemplateService $templates): JsonResponse
    {
        $catalog = $builder->catalog($request->user('sanctum'));

        return response()->json(['data' => [
            'components' => $catalog,
            'templates' => $templates->forCatalog($catalog),
            'ruleset_version' => CompatibilityEngine::RULESET_VERSION,
            'strict_selection_enabled' => (bool) config('pc-builder.strict_selection_enabled'),
        ]]);
    }

    public function validate(ValidatePcBuildRequest $request, PcBuilderService $builder): JsonResponse
    {
        return response()->json(['data' => $builder->validateSelection($request->validated('components'))]);
    }

    public function options(ComponentOptionsRequest $request, CompatibleComponentOptions $options): JsonResponse
    {
        return response()->json(['data' => $options->execute($request->validated(), $request->user('sanctum'))]);
    }
}
