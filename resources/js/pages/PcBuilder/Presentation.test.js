import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import * as fields from '../Administration/ComponentSpecifications/specificationFields.js';
import * as builderActions from './builderActions.js';

async function render(file, props) {
    const { descriptor } = parse(
        readFileSync(new URL(file, import.meta.url), 'utf8'),
        { filename: file },
    );
    const { content } = compileScript(descriptor, {
        id: 'builder-presentation',
        inlineTemplate: true,
    });
    const element = (tag) =>
        Vue.defineComponent({
            inheritAttrs: false,
            setup:
                (_, { attrs, slots }) =>
                () =>
                    Vue.h(tag, attrs, slots.default?.()),
        });
    const modules = {
        vue: Vue,
        '@/components/ui/button': { Button: element('button') },
        '@/components/ui/input': { Input: element('input') },
        '@/components/ui/label': { Label: element('label') },
        './specificationFields': fields,
        './builderActions': builderActions,
        '@/components/catalog/WishlistButton.vue': {
            default: element('button'),
        },
        '@/components/ui/dialog': Object.fromEntries(
            [
                'Dialog',
                'DialogContent',
                'DialogHeader',
                'DialogTitle',
                'DialogDescription',
                'DialogFooter',
            ].map((name) => [name, element('div')]),
        ),
    };
    const codeFor = (content) =>
        content
            .replace(
                /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
                (_, symbol, path) =>
                    `const ${symbol} = modules[${JSON.stringify(path)}].default;`,
            )
            .replace(
                /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
                (_, names, path) =>
                    `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(path)}];`,
            )
            .replace('export default', 'return');
    const { descriptor: choice } = parse(
        readFileSync(
            new URL(
                '../Administration/ComponentSpecifications/SpecificationChoice.vue',
                import.meta.url,
            ),
            'utf8',
        ),
    );
    modules['./SpecificationChoice.vue'] = {
        default: new Function(
            'modules',
            codeFor(
                compileScript(choice, {
                    id: 'specification-choice',
                    inlineTemplate: true,
                }).content,
            ),
        )(modules),
    };
    const component = new Function('modules', codeFor(content))(modules);
    return renderToString(Vue.h(component, props));
}

test('component rows distinguish selecting, changing and adding another component', async () => {
    const empty = await render('ComponentRow.vue', {
        label: 'Memory',
        items: [],
    });
    const single = await render('ComponentRow.vue', {
        label: 'Processor',
        items: [{ key: 'cpu', name: 'CPU' }],
    });
    const multiple = await render('ComponentRow.vue', {
        label: 'Memory',
        items: [{ key: 'ram', name: 'RAM' }],
        multiple: true,
    });
    assert.match(empty, />Select part<\/button>/);
    assert.match(single, />Change part<\/button>/);
    assert.match(multiple, />Add another<\/button>/);
    assert.match(multiple, /aria-label="Add another Memory"/);
});

test('the summary uses compact status instead of a customer compatibility review panel', async () => {
    const html = await render('BuildSummary.vue', {
        completion: 100,
        missing: [],
        total: 'PHP 100',
        statusLabel: (status) => status,
        validation: {
            status: 'unknown',
            estimated_power_w: null,
            findings: [
                {
                    rule: 'unknown',
                    status: 'unknown',
                    component_keys: ['cpu'],
                    message: 'Verify processor evidence',
                },
                {
                    rule: 'socket',
                    status: 'compatible',
                    component_keys: ['cpu'],
                    message: 'Socket matches',
                },
            ],
        },
    });
    assert.match(html, /Selection needs attention/);
    assert.doesNotMatch(
        html,
        /Compatibility review|View passed checks|<details/,
    );
});

test('selected picker cards expose Remove and unverified candidates remain disabled', async () => {
    const html = await render('ComponentPicker.vue', {
        open: true,
        strict: true,
        label: 'Processor',
        selectedIds: [1],
        products: [
            {
                id: 1,
                name: 'Selected CPU',
                stock_quantity: 2,
                price: 100,
                component_type: 'processor',
                selectable: false,
                selection_reasons: ['Exact BIOS support is not verified'],
            },
        ],
    });
    assert.match(html, /Remove Selected CPU/);
    assert.match(html, /Exact BIOS support is not verified/);
    assert.match(html, /disabled/);
});

test('replacement confirmation remains disabled while the proposed result is not verified', async () => {
    const html = await render('ReplacementDialog.vue', {
        product: { name: 'New CPU', affected_keys: ['board'] },
        items: [{ key: 'board', name: 'Selected motherboard' }],
        selectedKeys: [],
        preview: { selectable: false, selection_reasons: ['Socket mismatch'] },
    });
    assert.match(html, /Nothing changes until you confirm/);
    assert.match(html, /type="checkbox"/);
    assert.match(html, /Socket mismatch/);
    assert.match(html, /disabled[^>]*>Confirm replacement/);
});

test('unfinished purchase copy never claims a verified complete PC', async () => {
    const html = await render('BuildSummary.vue', {
        completion: 10,
        missing: ['Motherboard'],
        total: 'PHP 100',
        statusLabel: (status) => status,
        validation: {
            complete: false,
            can_purchase: true,
            status: 'unknown',
            estimated_power_w: null,
            findings: [],
        },
    });
    assert.match(html, /You can buy these selected parts/);
    assert.match(html, /not a verified complete PC/);
    assert.match(html, /Still needed: Motherboard/);
});

test('empty component rows explain unavailable stock without hiding the slot', async () => {
    const html = await render('ComponentRow.vue', {
        label: 'CPU Cooler',
        items: [],
        available: false,
    });
    assert.match(html, /CPU Cooler/);
    assert.match(html, /No eligible parts currently in stock/);
    assert.match(html, /Select part/);
});

test('guided nested storage fields render unknowns and preserved values without requiring JSON', async () => {
    const field = fields
        .fieldsFor('motherboard')
        .find((item) => item.key === 'storage_slots');
    const html = await render(
        '../Administration/ComponentSpecifications/SpecificationField.vue',
        {
            id: 'storage',
            field,
            modelValue: [
                {
                    id: 'm2_1',
                    interfaces: ['NVMe'],
                    form_factors: ['M.2 2280'],
                    maximum_length_mm: 80,
                },
            ],
        },
    );
    assert.match(html, /Slot identifier/);
    assert.match(html, /NVMe/);
    assert.match(html, /Maximum length \(mm\)/);
    assert.match(html, /Unknown \/ not verified/);
    assert.doesNotMatch(html, /<textarea/);
});

test('uncommon saved shapes are preserved instead of being presented as verified empty lists', async () => {
    const html = await render(
        '../Administration/ComponentSpecifications/SpecificationField.vue',
        {
            id: 'slots',
            field: fields
                .fieldsFor('motherboard')
                .find((field) => field.key === 'storage_slots'),
            modelValue: { legacy: 'preserve' },
        },
    );
    assert.match(html, /saved value uses a different format/);
    assert.doesNotMatch(html, /Verified: no entries/);
});
