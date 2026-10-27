<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SaveComponentSpecificationRequest;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Services\PcBuilder\ComponentReadinessService;
use App\Services\PcBuilder\ComponentSpecificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComponentSpecificationController extends Controller
{
    public function index(ComponentReadinessService $readiness): Response
    {
        $products = Product::query()->orderBy('name')->get(['id', 'product_code', 'name', 'brand']);
        $profiles = ProductComponentProfile::query()->with(['product', 'revisions'])->orderBy('component_type')->get()
            ->map(function (ProductComponentProfile $profile): array {
                $revision = $profile->revisions->firstWhere('revision', $profile->current_revision);

                return [
                    'id' => $profile->id,
                    'product_id' => $profile->product_id,
                    'product_name' => $profile->product->name,
                    'product_code' => $profile->product->product_code,
                    'component_type' => $profile->component_type,
                    'manufacturer_part_number' => $profile->manufacturer_part_number,
                    'hardware_revision' => $profile->hardware_revision,
                    'review_status' => $profile->review_status,
                    'edit_version' => $profile->edit_version,
                    'specifications' => $revision?->specifications ?? [],
                    'evidence' => $revision?->evidence ?? [],
                    'revision' => $revision?->revision,
                    'incomplete_attributes' => $this->incompleteAttributes($profile->component_type, $revision?->specifications ?? []),
                ];
            });

        return Inertia::render('Administration/ComponentSpecifications/Index', [
            'products' => $products,
            'profiles' => $profiles,
            'candidates' => $readiness->candidates(),
        ]);
    }

    public function store(SaveComponentSpecificationRequest $request, ComponentSpecificationService $service): JsonResponse
    {
        $profile = $service->saveDraft($request->validated());

        return response()->json([
            'id' => $profile->id,
            'product_id' => $profile->product_id,
            'component_type' => $profile->component_type,
            'manufacturer_part_number' => $profile->manufacturer_part_number,
            'hardware_revision' => $profile->hardware_revision,
            'review_status' => $profile->review_status,
            'edit_version' => $profile->edit_version,
            'revision' => $profile->current_revision,
        ], 201);
    }

    public function approve(Request $request, ProductComponentProfile $componentSpecification, ComponentSpecificationService $service): JsonResponse
    {
        $validated = $request->validate(['edit_version' => ['required', 'integer', 'min:1']]);
        $profile = $service->approve($componentSpecification, $request->user(), (int) $validated['edit_version']);

        return response()->json([
            'id' => $profile->id,
            'product_id' => $profile->product_id,
            'review_status' => $profile->review_status,
            'edit_version' => $profile->edit_version,
            'revision' => $profile->current_revision,
        ]);
    }

    /** @param array<string, mixed> $specifications
     * @return list<string>
     */
    private function incompleteAttributes(string $componentType, array $specifications): array
    {
        $requiredByType = [
            'processor' => ['socket', 'generation', 'supported_chipsets', 'maximum_power_w', 'integrated_graphics', 'included_cooler', 'required_power_connectors'],
            'motherboard' => ['socket', 'chipset', 'supported_cpu_generations', 'bios_version', 'memory_generations', 'dimm_slots', 'maximum_memory_gb', 'maximum_memory_speed_mts', 'ecc_supported', 'registered_memory_supported', 'form_factor', 'pcie_x16_slots', 'display_outputs', 'storage_slots', 'fan_headers'],
            'memory' => ['memory_generation', 'capacity_gb', 'modules_per_package', 'speed_mts', 'ecc', 'registered'],
            'storage' => ['interface', 'form_factor'],
            'power_supply' => ['continuous_power_w', 'connectors', 'form_factor', 'length_mm'],
            'case' => ['motherboard_form_factors', 'maximum_gpu_length_mm', 'maximum_cpu_cooler_height_mm', 'supported_psu_form_factors', 'maximum_psu_length_mm'],
            'graphics_card' => ['length_mm', 'thickness_mm', 'board_power_w', 'transient_power_w', 'required_power_connectors', 'pcie_slots_required'],
            'cooler' => ['mounting_sockets', 'height_mm', 'radiator_size_mm'],
            'fan' => ['size_mm', 'power_draw_w', 'header_type'],
            'monitor' => ['input_ports', 'resolution', 'refresh_rate_hz'],
        ];

        return array_values(array_filter(
            $requiredByType[$componentType] ?? [],
            fn (string $attribute): bool => ! array_key_exists($attribute, $specifications) || $specifications[$attribute] === null || $specifications[$attribute] === '',
        ));
    }
}
