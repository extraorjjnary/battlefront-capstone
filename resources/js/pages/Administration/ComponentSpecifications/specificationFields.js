import { componentDefinitions } from '../../PcBuilder/builderActions.js';

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

export const componentLabels = Object.fromEntries(
    Object.entries(componentDefinitions).map(([type, definition]) => [
        type,
        definition.label,
    ]),
);

// Presentation metadata only. Compatibility decisions remain server-owned.
export const specificationFields = {
    processor: [
        text('model', 'Exact CPU model (matches motherboard support list)'),
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
        counts(
            'required_power_connectors',
            'Required motherboard power connectors',
        ),
        boolean(
            'cpu_support_complete',
            'Recorded CPU support list is exhaustive for this board revision',
        ),
        list('cpu_support', 'Reviewed CPU support list', {
            kind: 'object',
            label: 'Supported CPU',
            fields: [
                text('model', 'Exact CPU model'),
                text(
                    'minimum_bios_version',
                    'Minimum BIOS for this CPU on this motherboard',
                ),
            ],
        }),
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
        counts(
            'peripheral_ports',
            'Available external ports by interface (USB, 3.5mm, PS/2)',
        ),
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
        number('height_mm', 'Module height (mm)'),
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
        list('fan_mounts', 'Supported fan sizes by position', {
            kind: 'object',
            label: 'Fan mount',
            fields: [
                text('position', 'Mounting position'),
                list('sizes_mm', 'Supported sizes (mm)', {
                    kind: 'number',
                    label: 'Size (mm)',
                }),
            ],
        }),
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
        number('ram_clearance_mm', 'RAM clearance under the cooler (mm)'),
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
    network_adapter: [
        text('connection_interface', 'Connection interface'),
        power,
    ],
    keyboard: [text('connection_interface', 'Connection interface'), power],
    mouse: [text('connection_interface', 'Connection interface'), power],
    headset: [text('connection_interface', 'Connection interface'), power],
    speakers: [text('connection_interface', 'Connection interface'), power],
    webcam: [
        text('connection_interface', 'Connection interface'),
        text('resolution', 'Resolution'),
        power,
    ],
    ups_avr: [
        number('rated_output_w', 'Rated output (W)'),
        text('output_voltage', 'Output voltage'),
        text('outlet_type', 'Outlet type'),
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
