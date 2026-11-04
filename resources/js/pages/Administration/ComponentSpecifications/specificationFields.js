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
const counts = (key, label, options) => ({
    key,
    label,
    kind: 'counts',
    options,
});
const choice = (key, label, options, kind = 'text') => ({
    key,
    label,
    kind,
    options,
});
const power = number('power_draw_w', 'Component power draw (W)');

/**
 * Entry suggestions, never product-support mappings. Exact-model evidence is still required.
 * Terminology references:
 * https://www.amd.com/en/products/specifications/processors.html
 * https://www.asus.com/sg/motherboards-components/motherboards/prime/prime-b650-plus/techspec/
 * https://www.msi.com/Motherboard/MAG-B760-TOMAHAWK-WIFI-DDR4/Specification
 * https://www.asus.com/motherboards-components/motherboards/prime/prime-z890-p/techspec/
 * https://assets.corsair.com/image/upload/corsairmedia/sys_master/productcontent/RMx_2018_Manual.pdf
 * https://www.noctua.at/en/products/nf-a12x25-pwm
 * https://help.corsair.com/hc/en-us/articles/360052137311-Case-What-is-the-radiator-capacity-of-the-4000D-mid-tower-case
 */
const sockets = ['AM4', 'AM5', 'LGA1700', 'LGA1851'];
const generations = [
    'Ryzen 3000',
    'Ryzen 4000',
    'Ryzen 5000',
    'Ryzen 7000',
    'Ryzen 8000',
    'Ryzen 9000',
    'Intel Core 12th Gen',
    'Intel Core 13th Gen',
    'Intel Core 14th Gen',
    'Intel Core Ultra Series 2',
];
const chipsets = [
    'B450',
    'B550',
    'X570',
    'A520',
    'A620',
    'B650',
    'B650E',
    'X670',
    'X670E',
    'B850',
    'X870',
    'X870E',
    'H610',
    'B660',
    'B760',
    'Z690',
    'Z790',
    'Z890',
];
const memoryGenerations = ['DDR3', 'DDR4', 'DDR5'];
const boardFormats = ['ATX', 'Micro-ATX', 'Mini-ITX', 'E-ATX'];
const psuFormats = ['ATX', 'SFX', 'SFX-L'];
const connectors = [
    'ATX24',
    'EPS4',
    'EPS8',
    'PCIe6',
    'PCIe8',
    '12VHPWR',
    '12V-2x6',
    'SATA',
    'Molex',
];
const outputs = ['HDMI', 'DisplayPort', 'DVI', 'VGA', 'USB-C'];
const interfaces = ['NVMe', 'SATA'];
const driveFormats = [
    'M.2 2230',
    'M.2 2242',
    'M.2 2260',
    'M.2 2280',
    'M.2 22110',
    '2.5 inch',
    '3.5 inch',
];
const fanSizes = [80, 92, 120, 140, 200];
const radiatorSizes = [0, 120, 140, 240, 280, 360, 420];
const fanPositions = ['front', 'top', 'rear', 'bottom', 'side'];
const fanHeaders = ['3-pin', '4-pin PWM'];

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
        choice('socket', 'Socket', sockets),
        choice('generation', 'CPU generation / family', generations),
        list(
            'supported_chipsets',
            'Supported chipsets',
            choice(null, 'Chipset', chipsets),
        ),
        text('minimum_bios_version', 'Minimum BIOS version'),
        number('maximum_power_w', 'Maximum power (W)'),
        boolean('integrated_graphics', 'Integrated graphics'),
        boolean('included_cooler', 'Cooler included'),
        counts(
            'required_power_connectors',
            'Required power connectors',
            connectors,
        ),
    ],
    motherboard: [
        counts(
            'required_power_connectors',
            'Required motherboard power connectors',
            connectors,
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
        choice('socket', 'Socket', sockets),
        choice('chipset', 'Chipset', chipsets),
        list(
            'supported_cpu_generations',
            'Supported CPU generations',
            choice(null, 'CPU generation / family', generations),
        ),
        text('bios_version', 'BIOS version'),
        boolean(
            'bios_update_without_supported_cpu',
            'BIOS update without a supported CPU',
        ),
        list(
            'memory_generations',
            'Supported memory generations',
            choice(null, 'Memory generation', memoryGenerations),
        ),
        number('dimm_slots', 'Memory slots'),
        number('maximum_memory_gb', 'Maximum memory (GB)'),
        number('maximum_memory_speed_mts', 'Maximum memory speed (MT/s)'),
        boolean('ecc_supported', 'ECC memory supported'),
        boolean('registered_memory_supported', 'Registered memory supported'),
        choice('form_factor', 'Form factor', boardFormats),
        number('pcie_x16_slots', 'PCIe x16 slots'),
        list(
            'display_outputs',
            'Display outputs',
            choice(null, 'Display output', outputs),
        ),
        counts('fan_headers', 'Fan headers by connector type', fanHeaders),
        counts(
            'peripheral_ports',
            'Available external ports by interface (USB, 3.5mm, PS/2)',
            ['USB', '3.5mm', 'PS/2'],
        ),
        list('storage_slots', 'Storage slots', {
            kind: 'object',
            label: 'Storage slot',
            fields: [
                text('id', 'Slot identifier'),
                list(
                    'interfaces',
                    'Interfaces',
                    choice(null, 'Interface', interfaces),
                ),
                list(
                    'form_factors',
                    'Form factors',
                    choice(null, 'Form factor', driveFormats),
                ),
                number('maximum_length_mm', 'Maximum length (mm)'),
                list('conflicts_with', 'Conflicting slot identifiers'),
            ],
        }),
        power,
    ],
    memory: [
        number('height_mm', 'Module height (mm)'),
        choice('memory_generation', 'Memory generation', memoryGenerations),
        number('capacity_gb', 'Capacity (GB)'),
        number('modules_per_package', 'Modules per package'),
        number('speed_mts', 'Speed (MT/s)'),
        boolean('ecc', 'ECC'),
        boolean('registered', 'Registered memory'),
        power,
    ],
    storage: [
        choice('interface', 'Interface', interfaces),
        choice('form_factor', 'Form factor', driveFormats),
        number('length_mm', 'Length (mm)'),
        power,
    ],
    power_supply: [
        number('continuous_power_w', 'Continuous power (W)'),
        counts('connectors', 'Available power connectors', connectors),
        choice('form_factor', 'Form factor', psuFormats),
        number('length_mm', 'Length (mm)'),
        power,
    ],
    case: [
        list(
            'motherboard_form_factors',
            'Motherboard form factors',
            choice(null, 'Form factor', boardFormats),
        ),
        number('maximum_gpu_length_mm', 'Maximum GPU length (mm)'),
        number('maximum_gpu_thickness_mm', 'Maximum GPU thickness (mm)'),
        number('maximum_gpu_slots', 'Maximum GPU slots'),
        number(
            'maximum_cpu_cooler_height_mm',
            'Maximum CPU cooler height (mm)',
        ),
        list(
            'supported_psu_form_factors',
            'PSU form factors',
            choice(null, 'Form factor', psuFormats),
        ),
        number('maximum_psu_length_mm', 'Maximum PSU length (mm)'),
        list('radiator_support_mm', 'Radiator sizes (mm)', {
            kind: 'number',
            label: 'Size (mm)',
            options: radiatorSizes.filter((size) => size > 0),
        }),
        list(
            'fan_mounting_positions',
            'Fan mounting positions',
            choice(null, 'Mounting position', fanPositions),
        ),
        counts(
            'fan_mounting_capacity',
            'Fan capacity by position',
            fanPositions,
        ),
        list('fan_mounts', 'Supported fan sizes by position', {
            kind: 'object',
            label: 'Fan mount',
            fields: [
                choice('position', 'Mounting position', fanPositions),
                list('sizes_mm', 'Supported sizes (mm)', {
                    kind: 'number',
                    label: 'Size (mm)',
                    options: fanSizes,
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
        counts(
            'required_power_connectors',
            'Required power connectors',
            connectors,
        ),
        number('pcie_slots_required', 'PCIe slots required'),
        list(
            'display_outputs',
            'Display outputs',
            choice(null, 'Display output', outputs),
        ),
    ],
    cooler: [
        number('ram_clearance_mm', 'RAM clearance under the cooler (mm)'),
        list(
            'mounting_sockets',
            'Mounting sockets',
            choice(null, 'Socket', sockets),
        ),
        number('height_mm', 'Air cooler height (mm)'),
        choice(
            'radiator_size_mm',
            'Radiator size (mm; 0 confirms no radiator)',
            radiatorSizes,
            'number',
        ),
        power,
    ],
    fan: [
        choice('size_mm', 'Size (mm)', fanSizes, 'number'),
        choice('header_type', 'Header type', fanHeaders),
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
