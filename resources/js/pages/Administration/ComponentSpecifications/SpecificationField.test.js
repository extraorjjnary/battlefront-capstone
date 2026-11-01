import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as Vue from 'vue';
import * as fields from './specificationFields.js';

function component(file, modules) {
    const { descriptor } = parse(
        readFileSync(new URL(file, import.meta.url), 'utf8'),
        { filename: file },
    );
    const compiled = compileScript(descriptor, {
        id: file,
        inlineTemplate: true,
    });
    const code = compiled.content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, name) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(name)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, moduleName) =>
                `const ${name} = modules[${JSON.stringify(moduleName)}].default;`,
        )
        .replace('export default', 'return');
    return new Function('modules', code)(modules);
}

function mountField(type, key, initialValue) {
    const node = (tag, text = '') => ({
        tag,
        text,
        children: [],
        props: {},
        parent: null,
    });
    const renderer = Vue.createRenderer({
        createElement: node,
        createText: (text) => node('#text', text),
        createComment: (text) => node('#comment', text),
        setText: (element, text) => {
            element.text = text;
        },
        setElementText: (element, text) => {
            element.text = text;
            element.children = [];
        },
        parentNode: (element) => element.parent,
        nextSibling: (element) =>
            element.parent?.children[
                element.parent.children.indexOf(element) + 1
            ],
        patchProp: (element, key, _, value) => {
            element.props[key] = value;
        },
        insert(element, parent, anchor = null) {
            if (element.parent)
                element.parent.children.splice(
                    element.parent.children.indexOf(element),
                    1,
                );
            const index = anchor ? parent.children.indexOf(anchor) : -1;
            parent.children.splice(
                index < 0 ? parent.children.length : index,
                0,
                element,
            );
            element.parent = parent;
        },
        remove(element) {
            element.parent.children.splice(
                element.parent.children.indexOf(element),
                1,
            );
            element.parent = null;
        },
    });
    const modules = {
        vue: Vue,
        './specificationFields': fields,
        '@/components/ui/button': {
            Button: {
                render() {
                    return Vue.h(
                        'button',
                        this.$attrs,
                        this.$slots.default?.(),
                    );
                },
            },
        },
        '@/components/ui/label': {
            Label: {
                render() {
                    return Vue.h('label', this.$attrs, this.$slots.default?.());
                },
            },
        },
        '@/components/ui/input': {
            Input: {
                props: ['modelValue'],
                emits: ['update:modelValue'],
                render() {
                    return Vue.h('input', {
                        ...this.$attrs,
                        value: this.modelValue,
                        onInput: (event) =>
                            this.$emit('update:modelValue', event.target.value),
                    });
                },
            },
        },
    };
    modules['./SpecificationChoice.vue'] = {
        default: component('SpecificationChoice.vue', modules),
    };
    const Field = component('SpecificationField.vue', modules);
    const value = Vue.ref(initialValue);
    const field = fields.fieldsFor(type).find((field) => field.key === key);
    const root = node('root');
    const app = renderer.createApp({
        render: () =>
            Vue.h(Field, {
                id: 'spec',
                field,
                modelValue: value.value,
                'onUpdate:modelValue': (next) => {
                    value.value = next;
                },
            }),
    });
    app.mount(root);
    function find(predicate, element = root) {
        if (predicate(element)) return element;
        for (const child of element.children) {
            const found = find(predicate, child);
            if (found) return found;
        }
    }
    return {
        value,
        field,
        find,
        unmount: () => app.unmount(),
        async choose(id, option) {
            const select = find(
                (element) =>
                    element.tag === 'select' && element.props.id === id,
            );
            assert.ok(select, `Expected dropdown ${id}`);
            const entry = select.children.find(
                (element) =>
                    element.tag === 'option' &&
                    element.text.trim() === String(option),
            );
            assert.ok(entry, `Expected choice ${option}`);
            select.props.onChange({ target: { value: entry.props.value } });
            await Vue.nextTick();
        },
        async input(id, text) {
            const input = find(
                (element) => element.tag === 'input' && element.props.id === id,
            );
            assert.ok(input, `Expected input ${id}`);
            input.props.onInput({ target: { value: text } });
            await Vue.nextTick();
        },
        async click(text) {
            const button = find(
                (element) =>
                    element.tag === 'button' &&
                    element.children.some(
                        (child) => child.text.trim() === text,
                    ),
            );
            assert.ok(button, `Expected button ${text}`);
            button.props.onClick();
            await Vue.nextTick();
        },
    };
}

test('CPU presets and custom values preserve exact spelling and can return to unknown', async () => {
    const editor = mountField('processor', 'socket', 'Legacy socket');
    assert.equal(
        editor.find((node) => node.props.id === 'spec-custom').props.value,
        'Legacy socket',
    );
    await editor.choose('spec', 'AM5');
    assert.equal(editor.value.value, 'AM5');
    await editor.choose('spec', 'Custom value');
    await editor.input('spec-custom', 'Uncommon socket');
    assert.equal(editor.value.value, 'Uncommon socket');
    await editor.choose('spec', 'Unknown / not verified');
    assert.equal(editor.value.value, null);
    editor.unmount();
});

test('numeric choices preserve legacy strings until edited and emit numbers including zero', async () => {
    const editor = mountField('cooler', 'radiator_size_mm', '240');
    assert.equal(editor.value.value, '240');
    await editor.choose('spec', '280');
    assert.equal(editor.value.value, 280);
    await editor.choose('spec', 'Custom value');
    await editor.input('spec-custom', '480');
    assert.equal(editor.value.value, 480);
    await editor.choose('spec', '0');
    assert.equal(editor.value.value, 0);
    await editor.choose('spec', 'Unknown / not verified');
    assert.equal(editor.value.value, null);
    editor.unmount();
});

test('supported socket lists keep unknown separate from verified none and emit strings', async () => {
    const editor = mountField('cooler', 'mounting_sockets', null);
    await editor.click('Confirm none');
    assert.deepEqual(editor.value.value, []);
    await editor.click('Add entry');
    await editor.choose('spec-0', 'AM4');
    assert.deepEqual(editor.value.value, ['AM4']);
    await editor.click('Mark unknown');
    assert.equal(editor.value.value, null);
    editor.unmount();
});

test('nested case fan sizes remain numeric and retain attributes outside guided fields', async () => {
    const editor = mountField('case', 'fan_mounts', [
        { position: 'front', sizes_mm: [120], custom_clearance: 33 },
    ]);
    await editor.choose('spec-0-position', 'top');
    await editor.choose('spec-0-sizes_mm-0', '140');
    assert.deepEqual(editor.value.value, [
        { position: 'top', sizes_mm: [140], custom_clearance: 33 },
    ]);
    assert.deepEqual(
        fields.parseSpecifications(
            JSON.stringify({ fan_mounts: editor.value.value, uncommon: false }),
        ),
        {
            fan_mounts: [
                { position: 'top', sizes_mm: [140], custom_clearance: 33 },
            ],
            uncommon: false,
        },
    );
    editor.unmount();
});

test('connector choices retain custom keys and prevent duplicate entries', async () => {
    const editor = mountField('power_supply', 'connectors', { Proprietary: 2 });
    await editor.choose('spec', 'EPS8');
    await editor.click('Add entry');
    await editor.input('spec-EPS8', '1');
    await editor.choose('spec', 'EPS8');
    await editor.click('Add entry');
    assert.deepEqual(editor.value.value, { Proprietary: 2, EPS8: 1 });
    assert.ok(
        editor.find(
            (node) =>
                node.props.role === 'alert' && node.text.includes('unique'),
        ),
    );
    await editor.choose('spec', 'Custom value');
    await editor.input('spec-custom', 'Special connector');
    await editor.click('Add entry');
    assert.deepEqual(editor.value.value, {
        Proprietary: 2,
        EPS8: 1,
        'Special connector': null,
    });
    editor.unmount();
});

test('boolean controls preserve false and unexpected saved formats stay untouched', async () => {
    const boolean = mountField('memory', 'ecc', false);
    assert.equal(boolean.value.value, false);
    await boolean.choose('spec', 'Unknown / not verified');
    assert.equal(boolean.value.value, null);
    boolean.unmount();
    const legacy = mountField('processor', 'socket', { legacy: 'AM4' });
    assert.deepEqual(legacy.value.value, { legacy: 'AM4' });
    assert.ok(legacy.find((node) => node.text.includes('different format')));
    legacy.unmount();
});

test('matching component fields offer the same values without adding choices to peripherals', async () => {
    for (const [type, key, option] of [
        ['motherboard', 'socket', 'AM5'],
        ['memory', 'memory_generation', 'DDR5'],
        ['storage', 'interface', 'NVMe'],
        ['case', 'motherboard_form_factors', 'ATX'],
        ['graphics_card', 'display_outputs', 'HDMI'],
        ['fan', 'size_mm', '120'],
        ['processor', 'generation', 'Ryzen 5000'],
    ]) {
        const field = fields.fieldsFor(type).find((field) => field.key === key);
        const editor = mountField(
            type,
            key,
            field.kind === 'list' ? [null] : null,
        );
        await editor.choose(field.kind === 'list' ? 'spec-0' : 'spec', option);
        assert.equal(
            String(
                field.kind === 'list'
                    ? editor.value.value[0]
                    : editor.value.value,
            ),
            option,
        );
        editor.unmount();
    }
    assert.deepEqual(
        fields.fieldsFor('processor').find((field) => field.key === 'socket')
            .options,
        fields
            .fieldsFor('cooler')
            .find((field) => field.key === 'mounting_sockets').item.options,
    );
    assert.equal(
        fields.fieldsFor('monitor').find((field) => field.key === 'resolution')
            .options,
        undefined,
    );
});
