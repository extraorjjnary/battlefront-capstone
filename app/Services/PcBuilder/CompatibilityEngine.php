<?php

namespace App\Services\PcBuilder;

/**
 * Evaluates reviewed hardware facts. Missing facts produce unknown results.
 */
class CompatibilityEngine
{
    public const RULESET_VERSION = 1;

    private const REQUIRED_TYPES = ['processor', 'motherboard', 'memory', 'storage', 'power_supply', 'case'];

    private const POWER_HEADROOM_FACTOR = 1.2;

    /**
     * @param  list<array{
     *     key: string,
     *     product_id: int,
     *     component_type: string,
     *     quantity: int,
     *     specifications: array<string, mixed>
     * }>  $components
     * @return array{
     *     ruleset_version: int,
     *     status: string,
     *     complete: bool,
     *     required_components: list<string>,
     *     estimated_power_w: int|null,
     *     findings: list<array{rule: string, status: string, component_keys: list<string>, message: string}>
     * }
     */
    public function evaluate(array $components): array
    {
        $groups = [];
        $findings = [];

        foreach ($components as $component) {
            $groups[$component['component_type']][] = $component;
        }

        foreach ($components as $component) {
            if ($component['quantity'] < 1) {
                $findings[] = $this->finding(
                    'component.quantity',
                    'incompatible',
                    [$component['key']],
                    'Every selected component must have a quantity greater than zero.',
                );
            }
        }

        $requiredComponents = [];
        foreach (self::REQUIRED_TYPES as $type) {
            if (($groups[$type] ?? []) === []) {
                $requiredComponents[] = $type;
            }
        }

        $processor = $this->first($groups, 'processor');
        if ($processor !== null) {
            if (($processor['specifications']['integrated_graphics'] ?? null) === false && ($groups['graphics_card'] ?? []) === []) {
                $requiredComponents[] = 'graphics_card';
            }
            if (($processor['specifications']['included_cooler'] ?? null) === false && ($groups['cooler'] ?? []) === []) {
                $requiredComponents[] = 'cooler';
            }
        }

        $requiredComponents = array_values(array_unique($requiredComponents));

        if ($requiredComponents !== []) {
            $findings[] = $this->finding(
                'build.required_components',
                'unknown',
                [],
                'Add the required components to complete this build.',
            );
        }

        $motherboard = $this->first($groups, 'motherboard');
        $case = $this->first($groups, 'case');
        $powerSupply = $this->first($groups, 'power_supply');

        if ($processor !== null && $motherboard !== null) {
            $this->compareValues(
                $findings,
                'cpu_motherboard.socket',
                $processor,
                'socket',
                $motherboard,
                'socket',
                'Your CPU uses %s, but your motherboard supports %s.',
            );

            $supportedGenerations = $motherboard['specifications']['supported_cpu_generations'] ?? null;
            $processorGeneration = $processor['specifications']['generation'] ?? null;
            if (is_array($supportedGenerations) && is_string($processorGeneration)) {
                $findings[] = $this->finding(
                    'cpu_motherboard.generation',
                    in_array($processorGeneration, $supportedGenerations, true) ? 'compatible' : 'incompatible',
                    [$processor['key'], $motherboard['key']],
                    in_array($processorGeneration, $supportedGenerations, true)
                        ? 'The motherboard lists support for this processor generation.'
                        : 'The motherboard does not list support for this processor generation.',
                );
            } else {
                $findings[] = $this->unknown(
                    'cpu_motherboard.generation',
                    [$processor['key'], $motherboard['key']],
                    'Processor-generation support requires verified motherboard data.',
                );
            }

            $supportedChipsets = $processor['specifications']['supported_chipsets'] ?? null;
            $motherboardChipset = $motherboard['specifications']['chipset'] ?? null;
            if (is_array($supportedChipsets) && is_string($motherboardChipset)) {
                $supported = in_array($motherboardChipset, $supportedChipsets, true);
                $findings[] = $this->finding(
                    'cpu_motherboard.chipset',
                    $supported ? 'compatible' : 'incompatible',
                    [$processor['key'], $motherboard['key']],
                    $supported
                        ? 'The processor support data includes this motherboard chipset.'
                        : 'The processor support data does not include this motherboard chipset.',
                );
            } else {
                $findings[] = $this->unknown(
                    'cpu_motherboard.chipset',
                    [$processor['key'], $motherboard['key']],
                    'Processor and chipset support requires manufacturer verification.',
                );
            }

            $this->checkBios($findings, $processor, $motherboard);
        }

        $this->checkMemory($findings, $groups, $motherboard);
        $this->checkGraphicsRequirement($findings, $groups, $processor, $motherboard);
        $this->checkDisplayOutputs($findings, $groups, $processor, $motherboard);
        $this->checkCoolers($findings, $groups, $processor, $motherboard, $case);
        $this->checkMotherboardCase($findings, $motherboard, $case);
        $this->checkGraphicsCards($findings, $groups, $motherboard, $case);
        $this->checkStorage($findings, $groups, $motherboard);
        $estimatedPower = $this->checkPower($findings, $groups, $powerSupply);
        $this->checkPowerSupplyCase($findings, $powerSupply, $case);
        $this->checkFans($findings, $groups, $motherboard, $case);

        $status = $this->aggregateStatus($findings);

        return [
            'ruleset_version' => self::RULESET_VERSION,
            'status' => $status,
            'complete' => $requiredComponents === [] && $status !== 'incompatible' && $status !== 'unknown',
            'required_components' => $requiredComponents,
            'estimated_power_w' => $estimatedPower,
            'findings' => $findings,
        ];
    }

    /** @param array<string, list<array<string, mixed>>> $groups
     * @return array<string, mixed>|null
     */
    private function first(array $groups, string $type): ?array
    {
        return $groups[$type][0] ?? null;
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareValues(array &$findings, string $rule, array $left, string $leftKey, array $right, string $rightKey, string $message): void
    {
        $leftValue = $left['specifications'][$leftKey] ?? null;
        $rightValue = $right['specifications'][$rightKey] ?? null;

        if (! is_string($leftValue) || ! is_string($rightValue) || $leftValue === '' || $rightValue === '') {
            $findings[] = $this->unknown($rule, [$left['key'], $right['key']], 'This fit check requires reviewed specifications for both components.');

            return;
        }

        $compatible = mb_strtolower($leftValue) === mb_strtolower($rightValue);
        $findings[] = $this->finding(
            $rule,
            $compatible ? 'compatible' : 'incompatible',
            [$left['key'], $right['key']],
            $compatible ? 'The selected components use the same supported interface.' : sprintf($message, $leftValue, $rightValue),
        );
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, mixed>  $processor
     * @param  array<string, mixed>  $motherboard
     */
    private function checkBios(array &$findings, array $processor, array $motherboard): void
    {
        $minimumBios = $processor['specifications']['minimum_bios_version'] ?? null;
        $boardBios = $motherboard['specifications']['bios_version'] ?? null;
        $updatable = $motherboard['specifications']['bios_update_without_supported_cpu'] ?? null;

        if ($minimumBios === null && is_string($boardBios)) {
            $findings[] = $this->finding('cpu_motherboard.bios', 'compatible', [$processor['key'], $motherboard['key']], 'No minimum BIOS requirement is recorded for this processor.');

            return;
        }

        if (! is_string($minimumBios) || ! is_string($boardBios)) {
            $findings[] = $this->unknown('cpu_motherboard.bios', [$processor['key'], $motherboard['key']], 'Motherboard BIOS compatibility requires verification.');

            return;
        }

        if (version_compare($boardBios, $minimumBios, '>=')) {
            $findings[] = $this->finding('cpu_motherboard.bios', 'compatible', [$processor['key'], $motherboard['key']], 'The recorded motherboard BIOS meets the processor minimum.');

            return;
        }

        $status = $updatable === true ? 'warning' : ($updatable === false ? 'incompatible' : 'unknown');
        $findings[] = $this->finding(
            'cpu_motherboard.bios',
            $status,
            [$processor['key'], $motherboard['key']],
            $status === 'warning'
                ? 'The motherboard needs a BIOS update before this processor can be used.'
                : ($status === 'incompatible'
                    ? 'The motherboard BIOS is too old and cannot be updated with the verified available method.'
                    : 'The motherboard BIOS is too old; update support requires verification.'),
        );
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  array<string, mixed>|null  $motherboard
     */
    private function checkMemory(array &$findings, array $groups, ?array $motherboard): void
    {
        if ($motherboard === null || ($groups['memory'] ?? []) === []) {
            return;
        }

        $boardSpecs = $motherboard['specifications'];
        $modules = 0;
        $capacityGb = 0;
        $knownCapacity = true;

        foreach ($groups['memory'] as $memory) {
            $specifications = $memory['specifications'];
            $modules += (int) ($specifications['modules_per_package'] ?? 1) * $memory['quantity'];
            if (is_numeric($specifications['capacity_gb'] ?? null)) {
                $capacityGb += (int) $specifications['capacity_gb'] * $memory['quantity'];
            } else {
                $knownCapacity = false;
            }

            $memoryType = $specifications['memory_generation'] ?? null;
            $supportedTypes = $boardSpecs['memory_generations'] ?? null;
            if (is_string($memoryType) && is_array($supportedTypes)) {
                $supported = in_array($memoryType, $supportedTypes, true);
                $findings[] = $this->finding(
                    'memory.generation',
                    $supported ? 'compatible' : 'incompatible',
                    [$memory['key'], $motherboard['key']],
                    $supported ? 'This memory generation is supported by the motherboard.' : 'This motherboard does not support the selected memory generation.',
                );
            } else {
                $findings[] = $this->unknown('memory.generation', [$memory['key'], $motherboard['key']], 'Memory-generation support requires verified specifications.');
            }

            $this->checkMemoryFeature($findings, $memory, $motherboard, 'ecc', 'ecc_supported', 'memory.ecc', 'ECC support is not verified for both components.');
            $this->checkMemoryFeature($findings, $memory, $motherboard, 'registered', 'registered_memory_supported', 'memory.registered', 'Registered-memory support is not verified for both components.');
        }

        $slots = $boardSpecs['dimm_slots'] ?? null;
        if (is_int($slots)) {
            $fits = $modules <= $slots;
            $findings[] = $this->finding(
                'memory.dimm_slots',
                $fits ? 'compatible' : 'incompatible',
                [...array_column($groups['memory'], 'key'), $motherboard['key']],
                $fits ? "The selected kits use {$modules} of {$slots} DIMM slots." : "The selected kits need {$modules} DIMM slots, but this motherboard has {$slots}.",
            );
        } else {
            $findings[] = $this->unknown('memory.dimm_slots', [...array_column($groups['memory'], 'key'), $motherboard['key']], 'Motherboard DIMM-slot count is not verified.');
        }

        $maximumCapacity = $boardSpecs['maximum_memory_gb'] ?? null;
        if ($knownCapacity && is_numeric($maximumCapacity)) {
            $fits = $capacityGb <= (int) $maximumCapacity;
            $findings[] = $this->finding(
                'memory.capacity',
                $fits ? 'compatible' : 'incompatible',
                [...array_column($groups['memory'], 'key'), $motherboard['key']],
                $fits ? "The selected memory totals {$capacityGb} GB, within the motherboard limit." : "The selected memory totals {$capacityGb} GB, above the motherboard limit of {$maximumCapacity} GB.",
            );
        } else {
            $findings[] = $this->unknown('memory.capacity', [...array_column($groups['memory'], 'key'), $motherboard['key']], 'Memory capacity limits require verification.');
        }

        $maximumSpeed = $boardSpecs['maximum_memory_speed_mts'] ?? null;
        foreach ($groups['memory'] as $memory) {
            $speed = $memory['specifications']['speed_mts'] ?? null;
            if (is_numeric($speed) && is_numeric($maximumSpeed)) {
                $supported = (int) $speed <= (int) $maximumSpeed;
                $findings[] = $this->finding(
                    'memory.speed',
                    $supported ? 'compatible' : 'warning',
                    [$memory['key'], $motherboard['key']],
                    $supported ? 'The selected memory speed is within the recorded motherboard limit.' : 'The memory may operate below its advertised speed on this motherboard.',
                );
            } else {
                $findings[] = $this->unknown('memory.speed', [$memory['key'], $motherboard['key']], 'Memory speed support requires the motherboard limit and memory profile.');
            }
        }
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, mixed>  $memory
     * @param  array<string, mixed>  $motherboard
     */
    private function checkMemoryFeature(array &$findings, array $memory, array $motherboard, string $memoryKey, string $boardKey, string $rule, string $unknownMessage): void
    {
        $memoryValue = $memory['specifications'][$memoryKey] ?? null;
        $boardValue = $motherboard['specifications'][$boardKey] ?? null;
        if (! is_bool($memoryValue) || ! is_bool($boardValue)) {
            $findings[] = $this->unknown($rule, [$memory['key'], $motherboard['key']], $unknownMessage);

            return;
        }

        $supported = ! $memoryValue || $boardValue;
        $findings[] = $this->finding(
            $rule,
            $supported ? 'compatible' : 'incompatible',
            [$memory['key'], $motherboard['key']],
            $supported ? 'The selected memory type is supported.' : 'The motherboard does not support the selected memory feature.',
        );
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  array<string, mixed>|null  $processor
     * @param  array<string, mixed>|null  $motherboard
     */
    private function checkGraphicsRequirement(array &$findings, array $groups, ?array $processor, ?array $motherboard): void
    {
        if ($processor === null || ($groups['graphics_card'] ?? []) !== []) {
            return;
        }

        $integratedGraphics = $processor['specifications']['integrated_graphics'] ?? null;
        if ($integratedGraphics === false) {
            $findings[] = $this->finding('graphics.required', 'incompatible', [$processor['key']], 'This processor has no integrated graphics. Add a compatible graphics card.');

            return;
        }

        if ($motherboard === null) {
            $findings[] = $this->unknown('graphics.required', [$processor['key']], 'A motherboard with usable display outputs must be selected and verified.');

            return;
        }

        $boardOutputs = $motherboard['specifications']['display_outputs'] ?? null;
        if ($integratedGraphics === true && is_array($boardOutputs) && $boardOutputs !== []) {
            $findings[] = $this->finding('graphics.required', 'compatible', [$processor['key'], $motherboard['key']], 'The processor has integrated graphics and the motherboard lists display outputs.');
        } else {
            $findings[] = $this->unknown('graphics.required', [$processor['key'], ...($motherboard === null ? [] : [$motherboard['key']])], 'A usable graphics and display-output path requires verification.');
        }
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  array<string, mixed>|null  $processor
     * @param  array<string, mixed>|null  $motherboard
     */
    private function checkDisplayOutputs(array &$findings, array $groups, ?array $processor, ?array $motherboard): void
    {
        foreach ($groups['monitor'] ?? [] as $monitor) {
            $graphicsCard = $this->first($groups, 'graphics_card');
            if ($graphicsCard !== null) {
                $outputs = $graphicsCard['specifications']['display_outputs'] ?? null;
                $sourceKey = $graphicsCard['key'];
            } elseif (($processor['specifications']['integrated_graphics'] ?? null) === true && $motherboard !== null) {
                $outputs = $motherboard['specifications']['display_outputs'] ?? null;
                $sourceKey = $motherboard['key'];
            } elseif (($processor['specifications']['integrated_graphics'] ?? null) === false) {
                $findings[] = $this->finding('display.graphics_source', 'incompatible', [$monitor['key'], $processor['key']], 'This processor has no integrated graphics and the build has no graphics card.');

                continue;
            } else {
                $findings[] = $this->unknown('display.graphics_source', [$monitor['key']], 'A graphics source for the selected monitor requires verification.');

                continue;
            }

            $inputs = $monitor['specifications']['input_ports'] ?? null;
            if (! is_array($outputs) || ! is_array($inputs)) {
                $findings[] = $this->unknown('display.output_input', [$monitor['key'], $sourceKey], 'Graphics output and monitor input ports are not verified.');

                continue;
            }

            $compatible = array_intersect($outputs, $inputs) !== [];
            $findings[] = $this->finding('display.output_input', $compatible ? 'compatible' : 'incompatible', [$monitor['key'], $sourceKey],
                $compatible ? 'The graphics source and monitor share a display connection.' : 'The selected graphics source does not list a display output supported by this monitor.');
        }
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  array<string, mixed>|null  $processor
     * @param  array<string, mixed>|null  $motherboard
     * @param  array<string, mixed>|null  $case
     */
    private function checkCoolers(array &$findings, array $groups, ?array $processor, ?array $motherboard, ?array $case): void
    {
        if ($processor === null) {
            return;
        }

        if (($groups['cooler'] ?? []) !== []) {
            foreach ($groups['cooler'] as $cooler) {
                $socket = $processor['specifications']['socket'] ?? null;
                $sockets = $cooler['specifications']['mounting_sockets'] ?? null;
                if (is_string($socket) && is_array($sockets)) {
                    $fits = in_array($socket, $sockets, true);
                    $findings[] = $this->finding('cooling.cpu_socket', $fits ? 'compatible' : 'incompatible', [$processor['key'], $cooler['key']],
                        $fits ? 'The cooler lists mounting support for this processor socket.' : 'The cooler does not list mounting support for this processor socket.');
                } else {
                    $findings[] = $this->unknown('cooling.cpu_socket', [$processor['key'], $cooler['key']], 'Cooler mounting support requires verified socket data.');
                }

                if ($case !== null && is_numeric($cooler['specifications']['height_mm'] ?? null) && is_numeric($case['specifications']['maximum_cpu_cooler_height_mm'] ?? null)) {
                    $fits = (float) $cooler['specifications']['height_mm'] <= (float) $case['specifications']['maximum_cpu_cooler_height_mm'];
                    $findings[] = $this->finding('cooling.cpu_clearance', $fits ? 'compatible' : 'incompatible', [$cooler['key'], $case['key']],
                        $fits ? 'The air cooler height is within the case clearance.' : 'The selected air cooler exceeds the case height clearance.');
                } elseif ($case !== null && is_numeric($cooler['specifications']['radiator_size_mm'] ?? null)) {
                    $radiators = $case['specifications']['radiator_support_mm'] ?? null;
                    if (is_array($radiators)) {
                        $fits = in_array((int) $cooler['specifications']['radiator_size_mm'], $radiators, true);
                        $findings[] = $this->finding('cooling.radiator_support', $fits ? 'compatible' : 'incompatible', [$cooler['key'], $case['key']],
                            $fits ? 'The case lists support for this cooler radiator size.' : 'The case does not list support for this cooler radiator size.');
                    } else {
                        $findings[] = $this->unknown('cooling.radiator_support', [$cooler['key'], $case['key']], 'Case radiator support requires verification.');
                    }
                } elseif ($case !== null) {
                    $findings[] = $this->unknown('cooling.case_clearance', [$cooler['key'], $case['key']], 'Cooler height or radiator support is not verified.');
                }
            }

            return;
        }

        if (($processor['specifications']['included_cooler'] ?? null) === true) {
            $findings[] = $this->finding('cooling.cpu_cooler', 'compatible', [$processor['key']], 'The processor listing includes a cooler. Verify its suitability for the intended workload.');

            return;
        }

        if (($processor['specifications']['included_cooler'] ?? null) === false) {
            $findings[] = $this->finding('cooling.cpu_cooler', 'incompatible', [$processor['key']], 'Add a compatible CPU cooler; the processor listing does not include one.');

            return;
        }

        $findings[] = $this->unknown('cooling.cpu_cooler', [$processor['key']], 'Included CPU cooling is not verified.');
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, mixed>|null  $motherboard
     * @param  array<string, mixed>|null  $case
     */
    private function checkMotherboardCase(array &$findings, ?array $motherboard, ?array $case): void
    {
        if ($motherboard === null || $case === null) {
            return;
        }

        $formFactor = $motherboard['specifications']['form_factor'] ?? null;
        $supported = $case['specifications']['motherboard_form_factors'] ?? null;
        if (is_string($formFactor) && is_array($supported)) {
            $fits = in_array($formFactor, $supported, true);
            $findings[] = $this->finding(
                'case.motherboard_form_factor',
                $fits ? 'compatible' : 'incompatible',
                [$motherboard['key'], $case['key']],
                $fits ? 'The case supports this motherboard form factor.' : 'The selected motherboard form factor is not listed for this case.',
            );
        } else {
            $findings[] = $this->unknown('case.motherboard_form_factor', [$motherboard['key'], $case['key']], 'Motherboard and case mounting support requires verification.');
        }
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  array<string, mixed>|null  $motherboard
     * @param  array<string, mixed>|null  $case
     */
    private function checkGraphicsCards(array &$findings, array $groups, ?array $motherboard, ?array $case): void
    {
        foreach ($groups['graphics_card'] ?? [] as $graphicsCard) {
            if ($motherboard !== null) {
                $availableSlots = $motherboard['specifications']['pcie_x16_slots'] ?? null;
                $neededSlots = $graphicsCard['specifications']['pcie_slots_required'] ?? null;
                if (is_int($availableSlots) && is_int($neededSlots)) {
                    $fits = $neededSlots <= $availableSlots;
                    $findings[] = $this->finding(
                        'graphics.motherboard_slot',
                        $fits ? 'compatible' : 'incompatible',
                        [$graphicsCard['key'], $motherboard['key']],
                        $fits ? 'The motherboard has enough physical expansion slots for this graphics card.' : 'The graphics card occupies more expansion slots than the motherboard can provide.',
                    );
                } else {
                    $findings[] = $this->unknown('graphics.motherboard_slot', [$graphicsCard['key'], $motherboard['key']], 'Graphics-card slot and motherboard clearance data is incomplete.');
                }
            }

            if ($case !== null) {
                $length = $graphicsCard['specifications']['length_mm'] ?? null;
                $clearance = $case['specifications']['maximum_gpu_length_mm'] ?? null;
                if (is_numeric($length) && is_numeric($clearance)) {
                    $fits = (float) $length <= (float) $clearance;
                    $findings[] = $this->finding(
                        'graphics.case_length',
                        $fits ? 'compatible' : 'incompatible',
                        [$graphicsCard['key'], $case['key']],
                        $fits ? 'The graphics card length is within the case clearance.' : 'The graphics card exceeds the recorded GPU clearance for this case.',
                    );
                } else {
                    $findings[] = $this->unknown('graphics.case_length', [$graphicsCard['key'], $case['key']], 'Graphics-card length or case clearance is not verified.');
                }

                $thickness = $graphicsCard['specifications']['thickness_mm'] ?? null;
                $caseSlots = $case['specifications']['maximum_gpu_slots'] ?? null;
                $cardSlots = $graphicsCard['specifications']['pcie_slots_required'] ?? null;
                if (is_numeric($thickness) && is_numeric($case['specifications']['maximum_gpu_thickness_mm'] ?? null)) {
                    $fits = (float) $thickness <= (float) $case['specifications']['maximum_gpu_thickness_mm'];
                    $findings[] = $this->finding(
                        'graphics.case_thickness',
                        $fits ? 'compatible' : 'incompatible',
                        [$graphicsCard['key'], $case['key']],
                        $fits ? 'The graphics-card thickness is within the case clearance.' : 'The graphics card exceeds the recorded case thickness clearance.',
                    );
                } elseif (is_int($caseSlots) && is_int($cardSlots)) {
                    $fits = $cardSlots <= $caseSlots;
                    $findings[] = $this->finding(
                        'graphics.case_slots',
                        $fits ? 'compatible' : 'incompatible',
                        [$graphicsCard['key'], $case['key']],
                        $fits ? 'The case provides the required graphics-card slot width.' : 'The graphics card exceeds the case slot clearance.',
                    );
                } else {
                    $findings[] = $this->unknown('graphics.case_thickness', [$graphicsCard['key'], $case['key']], 'Graphics-card thickness and case clearance are not verified.');
                }
            }
        }
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  array<string, mixed>|null  $motherboard
     */
    private function checkStorage(array &$findings, array $groups, ?array $motherboard): void
    {
        if (($groups['storage'] ?? []) === [] || $motherboard === null) {
            return;
        }

        $drives = [];
        $unknownDriveFacts = false;
        foreach ($groups['storage'] as $drive) {
            $interface = $drive['specifications']['interface'] ?? null;
            $formFactor = $drive['specifications']['form_factor'] ?? null;
            $length = $drive['specifications']['length_mm'] ?? null;
            if (! is_string($interface) || ! is_string($formFactor)
                || (str_starts_with(mb_strtolower($formFactor), 'm.2') && ! is_numeric($length))) {
                $findings[] = $this->unknown('storage.interface', [$drive['key'], $motherboard['key']], 'Storage interface, form factor, or M.2 length is not verified.');
                $unknownDriveFacts = true;

                continue;
            }

            for ($quantity = 0; $quantity < $drive['quantity']; $quantity++) {
                $drives[] = [
                    'key' => $drive['key'],
                    'interface' => $interface,
                    'form_factor' => $formFactor,
                    'length_mm' => $length,
                ];
            }
        }

        if ($unknownDriveFacts) {
            return;
        }

        $slots = $motherboard['specifications']['storage_slots'] ?? null;
        if (! is_array($slots)) {
            $findings[] = $this->unknown('storage.slot_allocation', [...array_column($groups['storage'], 'key'), $motherboard['key']], 'Motherboard storage slots and shared lane limits are not verified.');

            return;
        }

        $assignments = $this->allocateStorageSlots($drives, $slots);
        if ($assignments !== null) {
            foreach ($assignments as $leftIndex => $leftSlotIndex) {
                foreach (array_slice($assignments, $leftIndex + 1, preserve_keys: true) as $rightSlotIndex) {
                    $leftId = $slots[$leftSlotIndex]['id'] ?? null;
                    $rightId = $slots[$rightSlotIndex]['id'] ?? null;
                    $leftConflicts = $slots[$leftSlotIndex]['conflicts_with'] ?? [];
                    $rightConflicts = $slots[$rightSlotIndex]['conflicts_with'] ?? [];
                    if (($rightId !== null && is_array($leftConflicts) && in_array($rightId, $leftConflicts, true))
                        || ($leftId !== null && is_array($rightConflicts) && in_array($leftId, $rightConflicts, true))) {
                        $assignments = null;
                        break 2;
                    }
                }
            }
        }
        $findings[] = $this->finding(
            'storage.slot_allocation',
            $assignments === null ? 'incompatible' : 'compatible',
            [...array_column($groups['storage'], 'key'), $motherboard['key']],
            $assignments === null
                ? 'The selected drives cannot all be assigned to supported motherboard storage slots.'
                : 'Every selected drive can be assigned to a supported motherboard storage slot.',
        );
    }

    /** @param list<array{key: string, interface: string, form_factor: mixed, length_mm: mixed}> $drives
     * @param  list<array<string, mixed>>  $slots
     * @return list<int>|null
     */
    private function allocateStorageSlots(array $drives, array $slots): ?array
    {
        usort($drives, function (array $left, array $right) use ($slots): int {
            return $this->candidateSlotCount($left, $slots) <=> $this->candidateSlotCount($right, $slots);
        });

        $assignments = [];
        $used = [];
        if (! $this->assignStorageDrive(0, $drives, $slots, $used, $assignments)) {
            return null;
        }

        return $assignments;
    }

    /** @param array{key: string, interface: string, form_factor: mixed, length_mm: mixed} $drive
     * @param  list<array<string, mixed>>  $slots
     */
    private function candidateSlotCount(array $drive, array $slots): int
    {
        return count(array_filter($slots, fn (array $slot): bool => $this->storageSlotSupports($drive, $slot)));
    }

    /** @param list<array{key: string, interface: string, form_factor: mixed, length_mm: mixed}> $drives
     * @param  list<array<string, mixed>>  $slots
     * @param  array<int, bool>  $used
     * @param  list<int>  $assignments
     */
    private function assignStorageDrive(int $index, array $drives, array $slots, array &$used, array &$assignments): bool
    {
        if ($index >= count($drives)) {
            return true;
        }

        foreach ($slots as $slotIndex => $slot) {
            if (($used[$slotIndex] ?? false) || ! $this->storageSlotSupports($drives[$index], $slot)) {
                continue;
            }

            $used[$slotIndex] = true;
            $assignments[$index] = $slotIndex;
            if ($this->assignStorageDrive($index + 1, $drives, $slots, $used, $assignments)) {
                return true;
            }

            unset($used[$slotIndex], $assignments[$index]);
        }

        return false;
    }

    /** @param array{key: string, interface: string, form_factor: mixed, length_mm: mixed} $drive
     * @param  array<string, mixed>  $slot
     */
    private function storageSlotSupports(array $drive, array $slot): bool
    {
        $interfaces = $slot['interfaces'] ?? null;
        if (! is_array($interfaces) || ! in_array($drive['interface'], $interfaces, true)) {
            return false;
        }

        $formFactors = $slot['form_factors'] ?? null;
        if ($drive['form_factor'] !== null
            && (! is_array($formFactors) || ! in_array($drive['form_factor'], $formFactors, true))) {
            return false;
        }

        $maximumLength = $slot['maximum_length_mm'] ?? null;

        return $drive['length_mm'] === null
            || (is_numeric($drive['length_mm']) && is_numeric($maximumLength) && (float) $drive['length_mm'] <= (float) $maximumLength);
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  array<string, mixed>|null  $powerSupply
     */
    private function checkPower(array &$findings, array $groups, ?array $powerSupply): ?int
    {
        $load = 0.0;
        $hasUnknownLoad = false;
        $powerSources = [
            'processor' => 'maximum_power_w',
            'graphics_card' => 'board_power_w',
        ];

        foreach ($groups as $type => $components) {
            foreach ($components as $component) {
                $attribute = $powerSources[$type] ?? 'power_draw_w';
                $componentLoad = $component['specifications'][$attribute] ?? null;
                if (is_numeric($componentLoad)) {
                    $load += (float) $componentLoad * $component['quantity'];
                } else {
                    $hasUnknownLoad = true;
                }
            }
        }

        if ($powerSupply === null) {
            return null;
        }

        $capacity = $powerSupply['specifications']['continuous_power_w'] ?? null;
        if ($hasUnknownLoad || ! is_numeric($capacity)) {
            $findings[] = $this->unknown('power.capacity', array_column($groups['power_supply'] ?? [], 'key'), 'The system load or continuous PSU capacity is incomplete; no power estimate can be confirmed.');

            return null;
        }

        $estimated = (int) ceil($load);
        $requiredCapacity = (int) ceil($load * self::POWER_HEADROOM_FACTOR);
        $adequate = (float) $capacity >= $requiredCapacity;
        $componentKeys = [];
        foreach ($groups as $components) {
            $componentKeys = [...$componentKeys, ...array_column($components, 'key')];
        }
        $findings[] = $this->finding(
            'power.capacity',
            $adequate ? 'compatible' : 'warning',
            [...$componentKeys, $powerSupply['key']],
            $adequate
                ? "Estimated sustained component draw is {$estimated} W; the PSU meets the current 20% headroom estimate."
                : "Estimated sustained component draw is {$estimated} W. A PSU rated at least {$requiredCapacity} W is suggested by the current headroom estimate.",
        );

        $graphicsCards = $groups['graphics_card'] ?? [];
        if ($graphicsCards !== []) {
            $transientLoad = $load;
            $hasUnknownTransient = false;
            foreach ($graphicsCards as $graphicsCard) {
                $boardPower = $graphicsCard['specifications']['board_power_w'] ?? null;
                $transientPower = $graphicsCard['specifications']['transient_power_w'] ?? null;
                if (! is_numeric($boardPower) || ! is_numeric($transientPower)) {
                    $hasUnknownTransient = true;

                    continue;
                }
                $transientLoad += ((float) $transientPower - (float) $boardPower) * $graphicsCard['quantity'];
            }

            if ($hasUnknownTransient) {
                $findings[] = $this->unknown('power.transient_capacity', array_column($graphicsCards, 'key'), 'Graphics-card transient power data is not verified; the PSU estimate covers sustained draw only.');
            } else {
                $transientRequired = (int) ceil(max($load, $transientLoad) * self::POWER_HEADROOM_FACTOR);
                $transientAdequate = (float) $capacity >= $transientRequired;
                $findings[] = $this->finding(
                    'power.transient_capacity',
                    $transientAdequate ? 'compatible' : 'warning',
                    [...array_column($graphicsCards, 'key'), $powerSupply['key']],
                    $transientAdequate
                        ? "The PSU meets the estimated {$transientRequired} W transient headroom check."
                        : "Graphics-card transient draw suggests a PSU rated at least {$transientRequired} W; treat this estimate as guidance.",
                );
            }
        }

        $requiredConnectors = [];
        foreach ([...($groups['processor'] ?? []), ...($groups['graphics_card'] ?? [])] as $component) {
            $connectors = $component['specifications']['required_power_connectors'] ?? null;
            if (! is_array($connectors)) {
                $findings[] = $this->unknown('power.connectors', [$component['key'], $powerSupply['key']], 'Power connector requirements are not verified.');

                continue;
            }

            foreach ($connectors as $connector => $count) {
                if (is_string($connector) && is_int($count) && $count > 0) {
                    $requiredConnectors[$connector] = ($requiredConnectors[$connector] ?? 0) + $count * $component['quantity'];
                }
            }
        }

        $availableConnectors = $powerSupply['specifications']['connectors'] ?? null;
        if (! is_array($availableConnectors)) {
            $findings[] = $this->unknown('power.connectors', [$powerSupply['key']], 'PSU connector counts are not verified.');
        } elseif ($requiredConnectors !== []) {
            $missing = [];
            foreach ($requiredConnectors as $connector => $count) {
                if (! is_int($availableConnectors[$connector] ?? null) || $availableConnectors[$connector] < $count) {
                    $missing[] = $connector;
                }
            }
            $findings[] = $this->finding(
                'power.connectors',
                $missing === [] ? 'compatible' : 'incompatible',
                [$powerSupply['key']],
                $missing === [] ? 'The PSU lists the required CPU and graphics power connectors.' : 'The PSU does not list enough compatible connectors: '.implode(', ', $missing).'.',
            );
        }

        return $estimated;
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, mixed>|null  $powerSupply
     * @param  array<string, mixed>|null  $case
     */
    private function checkPowerSupplyCase(array &$findings, ?array $powerSupply, ?array $case): void
    {
        if ($powerSupply === null || $case === null) {
            return;
        }

        $format = $powerSupply['specifications']['form_factor'] ?? null;
        $supported = $case['specifications']['supported_psu_form_factors'] ?? null;
        if (is_string($format) && is_array($supported)) {
            $fits = in_array($format, $supported, true);
            $findings[] = $this->finding('power.case_form_factor', $fits ? 'compatible' : 'incompatible', [$powerSupply['key'], $case['key']],
                $fits ? 'The case supports this power supply form factor.' : 'The case does not list support for this power supply form factor.');
        } else {
            $findings[] = $this->unknown('power.case_form_factor', [$powerSupply['key'], $case['key']], 'Power supply and case mounting support requires verification.');
        }

        $length = $powerSupply['specifications']['length_mm'] ?? null;
        $clearance = $case['specifications']['maximum_psu_length_mm'] ?? null;
        if (is_numeric($length) && is_numeric($clearance)) {
            $fits = (float) $length <= (float) $clearance;
            $findings[] = $this->finding('power.case_clearance', $fits ? 'compatible' : 'incompatible', [$powerSupply['key'], $case['key']],
                $fits ? 'The power supply length is within the case clearance.' : 'The power supply exceeds the case length clearance.');
        } else {
            $findings[] = $this->unknown('power.case_clearance', [$powerSupply['key'], $case['key']], 'Power supply length or case clearance is not verified.');
        }
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  array<string, mixed>|null  $motherboard
     * @param  array<string, mixed>|null  $case
     */
    private function checkFans(array &$findings, array $groups, ?array $motherboard, ?array $case): void
    {
        if (($groups['fan'] ?? []) === []) {
            return;
        }

        $positionCounts = [];
        $headerCounts = [];
        foreach ($groups['fan'] as $fan) {
            $mountingPosition = $fan['configuration']['mounting_position'] ?? null;
            $supportedPositions = $case['specifications']['fan_mounting_positions'] ?? null;
            if ($case === null || ! is_string($mountingPosition) || ! is_array($supportedPositions)) {
                $findings[] = $this->unknown('cooling.fan_mount', [$fan['key'], ...($case === null ? [] : [$case['key']])], 'The selected fan mounting position is not verified for this case.');
            } else {
                $supported = in_array($mountingPosition, $supportedPositions, true);
                $findings[] = $this->finding(
                    'cooling.fan_mount',
                    $supported ? 'compatible' : 'incompatible',
                    [$fan['key'], $case['key']],
                    $supported ? 'The case supports this fan position.' : 'The selected fan position is not supported by the case.',
                );
                $positionCounts[$mountingPosition] = ($positionCounts[$mountingPosition] ?? 0) + $fan['quantity'];
            }

            if ($motherboard !== null) {
                $headerType = $fan['specifications']['header_type'] ?? null;
                if (! is_string($headerType) || ! is_array($motherboard['specifications']['fan_headers'] ?? null)) {
                    $findings[] = $this->unknown('cooling.fan_headers', [$fan['key'], $motherboard['key']], 'Fan-header capacity and connection method require verification.');
                } else {
                    $headerCounts[$headerType] = ($headerCounts[$headerType] ?? 0) + $fan['quantity'];
                }
            }
        }

        foreach ($positionCounts as $position => $quantity) {
            $capacity = $case['specifications']['fan_mounting_capacity'][$position] ?? null;
            if (is_int($capacity)) {
                $fits = $quantity <= $capacity;
                $findings[] = $this->finding('cooling.fan_quantity', $fits ? 'compatible' : 'incompatible', array_column($groups['fan'], 'key'),
                    $fits ? "The selected fan quantity fits the {$position} mounts." : "The selected fan quantity exceeds the {$position} mounts available in the case.");
            } else {
                $findings[] = $this->unknown('cooling.fan_quantity', array_column($groups['fan'], 'key'), 'Case fan mounting capacity requires verification.');
            }
        }

        foreach ($headerCounts as $headerType => $quantity) {
            $capacity = $motherboard['specifications']['fan_headers'][$headerType] ?? null;
            if (is_int($capacity)) {
                $fits = $quantity <= $capacity;
                $findings[] = $this->finding('cooling.fan_header_quantity', $fits ? 'compatible' : 'warning', array_column($groups['fan'], 'key'),
                    $fits ? "The motherboard lists enough {$headerType} headers." : "The motherboard lists fewer {$headerType} headers than selected fans; a verified splitter or hub may be needed.");
            } else {
                $findings[] = $this->unknown('cooling.fan_header_quantity', array_column($groups['fan'], 'key'), 'Fan header counts require verification.');
            }
        }
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings
     * @return array{rule: string, status: string, component_keys: list<string>, message: string}
     */
    private function finding(string $rule, string $status, array $componentKeys, string $message): array
    {
        return [
            'rule' => $rule,
            'status' => $status,
            'component_keys' => array_values(array_unique($componentKeys)),
            'message' => $message,
        ];
    }

    /** @param list<string> $componentKeys
     * @return array{rule: string, status: string, component_keys: list<string>, message: string}
     */
    private function unknown(string $rule, array $componentKeys, string $message): array
    {
        return $this->finding($rule, 'unknown', $componentKeys, $message);
    }

    /** @param list<array{rule: string, status: string, component_keys: list<string>, message: string}> $findings */
    private function aggregateStatus(array $findings): string
    {
        foreach (['incompatible', 'unknown', 'warning'] as $status) {
            foreach ($findings as $finding) {
                if ($finding['status'] === $status) {
                    return $status;
                }
            }
        }

        return 'compatible';
    }
}
