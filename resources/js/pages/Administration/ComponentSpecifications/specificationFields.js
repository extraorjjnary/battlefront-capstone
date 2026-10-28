const text = (key, label) => ({ key, label, kind: 'text' });
const number = (key, label) => ({ key, label, kind: 'number' });
const boolean = (key, label) => ({ key, label, kind: 'boolean' });
const list = (key, label, item = { kind: 'text', label: 'Value' }) => ({
    key,
    label,
    kind: 'list',
    item,
});
const counts = (key, label) => ({ key, label, kind: 'counts' });
const power = number('power_draw_w', 'Component power draw (W)');

export const componentLabels = {
    processor: 'Processor (CPU)',
    motherboard: 'Motherboard',
    memory: 'Memory (RAM)',
    storage: 'Storage',
    power_supply: 'Power supply (PSU)',
    case: 'PC case',
    graphics_card: 'Graphics card (GPU)',
    cooler: 'CPU cooler',
    fan: 'Case fan',
    network_adapter: 'Network adapter',
    monitor: 'Monitor',
    keyboard: 'Keyboard',
    mouse: 'Mouse',
    headset: 'Headset',
    speakers: 'Speakers',
    webcam: 'Webcam',
    ups_avr: 'UPS / AVR',
};

// Presentation metadata only. Compatibility decisions remain server-owned.
export const specificationFields = {
    processor: [
        text('socket', 'Socket'),
        text('generation', 'CPU generation'),
        list('supported_chipsets', 'Supported chipsets'),
        text('minimum_bios_version', 'Minimum BIOS version'),
        number('maximum_power_w', 'Maximum power (W)'),
        boolean('integrated_graphics', 'Integrated graphics'),
        boolean('included_cooler', 'Cooler included'),
        counts('required_power_connectors', 'Required power connectors'),
    ],
    motherboard: [
        text('socket', 'Socket'),
        text('chipset', 'Chipset'),
        list('supported_cpu_generations', 'Supported CPU generations'),
        text('bios_version', 'BIOS version'),
        boolean(
            'bios_update_without_supported_cpu',
            'BIOS update without a supported CPU',
        ),
        list('memory_generations', 'Supported memory generations'),
        number('dimm_slots', 'Memory slots'),
        number('maximum_memory_gb', 'Maximum memory (GB)'),
        number('maximum_memory_speed_mts', 'Maximum memory speed (MT/s)'),
        boolean('ecc_supported', 'ECC memory supported'),
        boolean('registered_memory_supported', 'Registered memory supported'),
        text('form_factor', 'Form factor'),
        number('pcie_x16_slots', 'PCIe x16 slots'),
        list('display_outputs', 'Display outputs'),
        counts('fan_headers', 'Fan headers by connector type'),
        list('storage_slots', 'Storage slots', {
            kind: 'object',
            label: 'Storage slot',
            fields: [
                text('id', 'Slot identifier'),
                list('interfaces', 'Interfaces'),
                list('form_factors', 'Form factors'),
                number('maximum_length_mm', 'Maximum length (mm)'),
                list('conflicts_with', 'Conflicting slot identifiers'),
            ],
        }),
        power,
    ],
    memory: [
        text('memory_generation', 'Memory generation'),
        number('capacity_gb', 'Capacity (GB)'),
        number('modules_per_package', 'Modules per package'),
        number('speed_mts', 'Speed (MT/s)'),
        boolean('ecc', 'ECC'),
        boolean('registered', 'Registered memory'),
        power,
    ],
    storage: [
        text('interface', 'Interface'),
        text('form_factor', 'Form factor'),
        number('length_mm', 'Length (mm)'),
        power,
    ],
    power_supply: [
        number('continuous_power_w', 'Continuous power (W)'),
        counts('connectors', 'Available power connectors'),
        text('form_factor', 'Form factor'),
        number('length_mm', 'Length (mm)'),
        power,
    ],
    case: [
        list('motherboard_form_factors', 'Motherboard form factors'),
        number('maximum_gpu_length_mm', 'Maximum GPU length (mm)'),
        number('maximum_gpu_thickness_mm', 'Maximum GPU thickness (mm)'),
        number('maximum_gpu_slots', 'Maximum GPU slots'),
        number(
            'maximum_cpu_cooler_height_mm',
            'Maximum CPU cooler height (mm)',
        ),
        list('supported_psu_form_factors', 'PSU form factors'),
        number('maximum_psu_length_mm', 'Maximum PSU length (mm)'),
        list('radiator_support_mm', 'Radiator sizes (mm)', {
            kind: 'number',
            label: 'Size (mm)',
        }),
        list('fan_mounting_positions', 'Fan mounting positions'),
        counts('fan_mounting_capacity', 'Fan capacity by position'),
        power,
    ],
    graphics_card: [
        number('length_mm', 'Length (mm)'),
        number('thickness_mm', 'Thickness (mm)'),
        number('board_power_w', 'Board power (W)'),
        number('transient_power_w', 'Transient power (W)'),
        counts('required_power_connectors', 'Required power connectors'),
        number('pcie_slots_required', 'PCIe slots required'),
        list('display_outputs', 'Display outputs'),
    ],
    cooler: [
        list('mounting_sockets', 'Mounting sockets'),
        number('height_mm', 'Air cooler height (mm)'),
        number('radiator_size_mm', 'Radiator size (mm)'),
        power,
    ],
    fan: [
        number('size_mm', 'Size (mm)'),
        text('header_type', 'Header type'),
        power,
    ],
    monitor: [
        list('input_ports', 'Input ports'),
        text('resolution', 'Resolution'),
        number('refresh_rate_hz', 'Refresh rate (Hz)'),
        power,
    ],
};

export function fieldsFor(type) {
    return specificationFields[type] ?? [power];
}

export function fieldValue(kind, value) {
    if (value === '' || value === null || value === undefined) return null;
    if (kind === 'boolean') return value === true || value === 'true';
    if (kind === 'number') return Number(value);
    return value;
}

export function parseSpecifications(value) {
    const parsed = JSON.parse(value);
    if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') {
        throw new Error('Specifications must be a JSON object.');
    }
    return parsed;
}
