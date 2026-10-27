<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SavePcBuildTemplateRequest;
use App\Models\PcBuildTemplate;
use App\Models\Product;
use App\Services\PcBuilder\PcBuildTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PcBuildTemplateController extends Controller
{
    public function index(PcBuildTemplateService $service): Response
    {
        $products = Product::query()->where('is_active', true)
            ->whereHas('componentProfile', fn ($query) => $query->where('review_status', 'approved'))
            ->with('componentProfile.revisions')->orderBy('name')->get(['id', 'name', 'product_code', 'brand']);

        return Inertia::render('Administration/PcBuildTemplates/Index', [
            'templates' => $service->adminList()->map(fn (PcBuildTemplate $template): array => $this->present($template))->all(),
            'products' => $products->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'product_code' => $product->product_code,
                'component_type' => $product->componentProfile->component_type,
                'fan_mounting_positions' => $product->componentProfile->revisions
                    ->firstWhere('revision', $product->componentProfile->current_revision)
                    ?->specifications['fan_mounting_positions'] ?? [],
            ])->all(),
        ]);
    }

    public function store(SavePcBuildTemplateRequest $request, PcBuildTemplateService $service): JsonResponse
    {
        return response()->json($this->present($service->save($request->user(), $request->templateData())), 201);
    }

    public function update(SavePcBuildTemplateRequest $request, PcBuildTemplate $pcBuildTemplate, PcBuildTemplateService $service): JsonResponse
    {
        return response()->json($this->present($service->save($request->user(), $request->templateData(), $pcBuildTemplate)));
    }

    public function destroy(Request $request, PcBuildTemplate $pcBuildTemplate, PcBuildTemplateService $service): JsonResponse
    {
        abort_unless($request->user()?->can('products.manage'), 403);
        $service->delete($pcBuildTemplate);

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function present(PcBuildTemplate $template): array
    {
        return [
            'id' => $template->id,
            'uuid' => $template->uuid,
            'name' => $template->name,
            'description' => $template->description,
            'budget' => $template->budget,
            'is_active' => $template->is_active,
            'edit_version' => $template->edit_version,
            'components' => $template->items->map(fn ($item): array => [
                'key' => $item->slot_key,
                'product_id' => $item->product_id,
                'component_type' => $item->component_type,
                'quantity' => $item->quantity,
                'configuration' => $item->configuration ?? [],
                'product_name' => $item->product?->name,
                'product_code' => $item->product?->product_code,
            ])->all(),
        ];
    }
}
