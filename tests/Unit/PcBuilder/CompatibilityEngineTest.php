<?php

use App\Services\PcBuilder\CompatibilityEngine;

test('it reports a socket mismatch as incompatible', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('cpu', 'processor', ['socket' => 'AM5', 'generation' => 'Zen 4', 'supported_chipsets' => ['B650'], 'minimum_bios_version' => '1.0', 'maximum_power_w' => 120, 'integrated_graphics' => false, 'included_cooler' => false]),
        component('board', 'motherboard', ['socket' => 'AM4', 'chipset' => 'B650', 'supported_cpu_generations' => ['Zen 4'], 'bios_version' => '1.0']),
    ]);

    expect($result['status'])->toBe('incompatible')
        ->and(collect($result['findings'])->firstWhere('rule', 'cpu_motherboard.socket')['message'])
        ->toContain('AM5', 'AM4');
});

test('it rejects an unsupported memory generation', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('memory', 'memory', ['memory_generation' => 'DDR4', 'capacity_gb' => 16, 'modules_per_package' => 2, 'speed_mts' => 3200, 'ecc' => false, 'registered' => false]),
        component('board', 'motherboard', ['memory_generations' => ['DDR5'], 'dimm_slots' => 4, 'maximum_memory_gb' => 128, 'maximum_memory_speed_mts' => 6400, 'ecc_supported' => false, 'registered_memory_supported' => false]),
    ]);

    expect(collect($result['findings'])->firstWhere('rule', 'memory.generation')['status'])->toBe('incompatible');
});

test('it checks monitor input against the selected graphics output', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('cpu', 'processor', ['integrated_graphics' => true]),
        component('board', 'motherboard', ['display_outputs' => ['HDMI']]),
        component('monitor', 'monitor', ['input_ports' => ['DisplayPort']]),
    ]);

    expect(collect($result['findings'])->firstWhere('rule', 'display.output_input')['status'])->toBe('incompatible');
});

test('it warns when a motherboard needs an available BIOS update', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('cpu', 'processor', ['socket' => 'AM5', 'generation' => 'Zen 5', 'supported_chipsets' => ['B850'], 'minimum_bios_version' => '2.0', 'maximum_power_w' => 100]),
        component('board', 'motherboard', ['socket' => 'AM5', 'chipset' => 'B850', 'supported_cpu_generations' => ['Zen 5'], 'bios_version' => '1.0', 'bios_update_without_supported_cpu' => true]),
    ]);

    expect(collect($result['findings'])->firstWhere('rule', 'cpu_motherboard.bios')['status'])->toBe('warning');
});

test('it requires a dedicated graphics card when the processor has no integrated graphics', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('cpu', 'processor', ['integrated_graphics' => false]),
    ]);

    expect($result['status'])->toBe('incompatible')
        ->and($result['required_components'])->toContain('graphics_card')
        ->and(collect($result['findings'])->firstWhere('rule', 'graphics.required')['message'])
        ->toContain('no integrated graphics');
});

test('it keeps missing specifications unknown', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('cpu', 'processor', []),
        component('board', 'motherboard', []),
    ]);

    expect($result['status'])->toBe('unknown')
        ->and(collect($result['findings'])->where('status', 'unknown'))->not->toBeEmpty();
});

test('it can allocate multiple storage drives to distinct supported slots', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('drive-nvme', 'storage', ['interface' => 'NVMe', 'form_factor' => 'M.2 2280', 'length_mm' => 80]),
        component('drive-sata', 'storage', ['interface' => 'SATA', 'form_factor' => '2.5 inch'], 2),
        component('board', 'motherboard', [
            'storage_slots' => [
                ['interfaces' => ['NVMe'], 'form_factors' => ['M.2 2280'], 'maximum_length_mm' => 80],
                ['interfaces' => ['SATA'], 'form_factors' => ['2.5 inch']],
                ['interfaces' => ['SATA'], 'form_factors' => ['2.5 inch']],
            ],
        ]),
    ]);

    expect(collect($result['findings'])->firstWhere('rule', 'storage.slot_allocation')['status'])->toBe('compatible');
});

test('it rejects storage assignments that conflict through shared PCIe lanes', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('drive-a', 'storage', ['interface' => 'NVMe', 'form_factor' => 'M.2 2280', 'length_mm' => 80]),
        component('drive-b', 'storage', ['interface' => 'NVMe', 'form_factor' => 'M.2 2280', 'length_mm' => 80]),
        component('board', 'motherboard', ['storage_slots' => [
            ['id' => 'm2_1', 'interfaces' => ['NVMe'], 'form_factors' => ['M.2 2280'], 'maximum_length_mm' => 80, 'conflicts_with' => ['m2_2']],
            ['id' => 'm2_2', 'interfaces' => ['NVMe'], 'form_factors' => ['M.2 2280'], 'maximum_length_mm' => 80],
        ]]),
    ]);

    expect(collect($result['findings'])->firstWhere('rule', 'storage.slot_allocation')['status'])->toBe('incompatible');
});

test('it validates CPU cooler sockets and case height clearance', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('cpu', 'processor', ['socket' => 'AM5', 'included_cooler' => false]),
        component('cooler', 'cooler', ['mounting_sockets' => ['LGA1700'], 'height_mm' => 160]),
        component('case', 'case', ['maximum_cpu_cooler_height_mm' => 155]),
    ]);

    expect(collect($result['findings'])->firstWhere('rule', 'cooling.cpu_socket')['status'])->toBe('incompatible')
        ->and(collect($result['findings'])->firstWhere('rule', 'cooling.cpu_clearance')['status'])->toBe('incompatible');
});

test('it requires a separate CPU cooler when the processor does not include one', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('cpu', 'processor', ['included_cooler' => false]),
    ]);

    expect($result['required_components'])->toContain('cooler')
        ->and(collect($result['findings'])->firstWhere('rule', 'cooling.cpu_cooler')['status'])->toBe('incompatible');
});

test('it treats graphics card transient power as a separate guidance check', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('cpu', 'processor', ['maximum_power_w' => 100, 'required_power_connectors' => []]),
        component('gpu', 'graphics_card', ['board_power_w' => 250, 'transient_power_w' => 500, 'required_power_connectors' => []]),
        component('psu', 'power_supply', ['continuous_power_w' => 500, 'connectors' => [], 'power_draw_w' => 0]),
    ]);

    expect(collect($result['findings'])->firstWhere('rule', 'power.capacity')['status'])->toBe('compatible')
        ->and(collect($result['findings'])->firstWhere('rule', 'power.transient_capacity')['status'])->toBe('warning');
});

test('it checks graphics card length, thickness, and motherboard slot clearance', function () {
    $result = app(CompatibilityEngine::class)->evaluate([
        component('gpu', 'graphics_card', ['length_mm' => 320, 'thickness_mm' => 60, 'board_power_w' => 200, 'transient_power_w' => 300, 'pcie_slots_required' => 3, 'required_power_connectors' => []]),
        component('board', 'motherboard', ['pcie_x16_slots' => 2]),
        component('case', 'case', ['maximum_gpu_length_mm' => 300, 'maximum_gpu_thickness_mm' => 55]),
    ]);

    expect(collect($result['findings'])->firstWhere('rule', 'graphics.motherboard_slot')['status'])->toBe('incompatible')
        ->and(collect($result['findings'])->firstWhere('rule', 'graphics.case_length')['status'])->toBe('incompatible')
        ->and(collect($result['findings'])->firstWhere('rule', 'graphics.case_thickness')['status'])->toBe('incompatible');
});

test('it rejects missing PSU connectors and reports excess fan quantities', function () {
    $connectorResult = app(CompatibilityEngine::class)->evaluate([
        component('cpu', 'processor', ['maximum_power_w' => 65, 'required_power_connectors' => []]),
        component('gpu', 'graphics_card', ['board_power_w' => 200, 'transient_power_w' => 250, 'required_power_connectors' => ['8pin' => 2]]),
        component('psu', 'power_supply', ['continuous_power_w' => 600, 'connectors' => ['8pin' => 1], 'power_draw_w' => 0]),
    ]);
    $fanResult = app(CompatibilityEngine::class)->evaluate([
        component('fan', 'fan', ['size_mm' => 120, 'power_draw_w' => 3, 'header_type' => '4pin'], 3) + ['configuration' => ['mounting_position' => 'front']],
        component('board', 'motherboard', ['fan_headers' => ['4pin' => 1]]),
        component('case', 'case', ['fan_mounting_positions' => ['front'], 'fan_mounting_capacity' => ['front' => 2]]),
    ]);

    expect(collect($connectorResult['findings'])->firstWhere('rule', 'power.connectors')['status'])->toBe('incompatible')
        ->and(collect($fanResult['findings'])->firstWhere('rule', 'cooling.fan_quantity')['status'])->toBe('incompatible')
        ->and(collect($fanResult['findings'])->firstWhere('rule', 'cooling.fan_header_quantity')['status'])->toBe('warning');
});

/** @param array<string, mixed> $specifications
 * @return array{key: string, product_id: int, component_type: string, quantity: int, specifications: array<string, mixed>}
 */
function component(string $key, string $type, array $specifications, int $quantity = 1): array
{
    return [
        'key' => $key,
        'product_id' => 1,
        'component_type' => $type,
        'quantity' => $quantity,
        'specifications' => $specifications,
    ];
}
