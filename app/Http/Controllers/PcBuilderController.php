<?php

namespace App\Http\Controllers;

use App\Http\Requests\PcBuilder\ComponentOptionsRequest;
use App\Http\Requests\PcBuilder\ValidatePcBuildRequest;
use App\Services\PcBuilder\CompatibilityEngine;
use App\Services\PcBuilder\CompatibleComponentOptions;
use App\Services\PcBuilder\PcBuilderService;
use App\Services\PcBuilder\PcBuildTemplateService;
use App\Services\PcBuilder\SavedPcBuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PcBuilderController extends Controller
{
    public function index(Request $request, PcBuilderService $pcBuilderService, PcBuildTemplateService $templateService, SavedPcBuildService $savedBuildService): Response
    {
        $customer = $request->user();
        $wishlistCustomer = $customer !== null && $customer->can('use-customer-cart') ? $customer : null;
        $catalog = $pcBuilderService->catalog($wishlistCustomer);

        return Inertia::render('PcBuilder/Index', [
            'components' => $catalog,
            'templates' => $templateService->forCatalog($catalog),
            'ruleset_version' => CompatibilityEngine::RULESET_VERSION,
            'strict_selection_enabled' => (bool) config('pc-builder.strict_selection_enabled'),
            'saved_builds' => $customer !== null && $customer->can('use-customer-cart')
                ? $savedBuildService->list($customer)->map(fn ($build): array => $savedBuildService->present($build))->all()
                : [],
        ]);
    }

    public function validate(ValidatePcBuildRequest $request, PcBuilderService $pcBuilderService): JsonResponse
    {
        return response()->json($pcBuilderService->validateSelection($request->validated()['components']));
    }

    public function options(ComponentOptionsRequest $request, CompatibleComponentOptions $options): JsonResponse
    {
        $customer = $request->user();

        return response()->json($options->execute($request->validated(), $customer?->can('use-customer-cart') ? $customer : null));
    }
}
