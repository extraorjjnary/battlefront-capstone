import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import * as fields from '../Administration/ComponentSpecifications/specificationFields.js';

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
    };
    const code = content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, path) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(path)}];`,
        )
        .replace('export default', 'return');
    const component = new Function('modules', code)(modules);
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

test('compatibility problems remain visible while passed checks are collapsible', async () => {
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
    const detailsStart = html.indexOf('<details');
    assert.ok(html.indexOf('Verify processor evidence') < detailsStart);
    assert.ok(html.indexOf('Socket matches') > detailsStart);
    assert.match(html, /View passed checks/);
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
