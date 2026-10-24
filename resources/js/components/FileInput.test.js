import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { cn } from '../lib/utils.js';

function load(inlineTemplate, hooks = {}) {
    const { descriptor } = parse(
        readFileSync(new URL('./FileInput.vue', import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: 'file-input-test',
        inlineTemplate,
    });
    const source = script.content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, name) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(name)}];`,
        )
        .replace('export default', 'return');
    return new Function('modules', source)({
        vue: { ...Vue, ...hooks },
        '@/lib/utils': { cn },
    });
}

test('upload input preserves native form attributes and visible interaction states', async () => {
    const html = await renderToString(
        Vue.h(load(true), {
            id: 'proof',
            name: 'files[]',
            multiple: true,
            required: true,
            accept: '.png,.pdf',
            disabled: true,
            'aria-invalid': true,
        }),
    );
    const input = html.match(/<input[^>]*>/)[0];
    for (const attribute of [
        'id="proof"',
        'name="files[]"',
        'multiple',
        'required',
        'accept=".png,.pdf"',
        'disabled',
        'aria-invalid="true"',
        'type="file"',
    ]) {
        assert.ok(input.includes(attribute), attribute);
    }
    assert.match(input, /\bfile:bg-primary\s/);
    assert.match(input, /\bfile:text-primary-foreground\s/);
    assert.match(input, /enabled:hover:file:bg-primary\/90/);
    assert.match(input, /focus-visible:ring/);
    assert.match(input, /disabled:file:cursor-not-allowed/);
});

test('selection forwards the original event and clearing or resetting removes selected filenames', () => {
    let mounted;
    let unmounted;
    const component = load(false, {
        onMounted: (callback) => {
            mounted = callback;
        },
        onUnmounted: (callback) => {
            unmounted = callback;
        },
    });
    const events = [];
    const state = component.setup(
        {},
        { expose() {}, emit: (...args) => events.push(args) },
    );
    const listeners = new Map();
    const form = {
        addEventListener: (name, handler) => listeners.set(name, handler),
        removeEventListener: (name, handler) => {
            assert.equal(listeners.get(name), handler);
            listeners.delete(name);
        },
    };
    state.input.value = { value: 'selected', form };
    mounted();
    const event = {
        target: { files: [{ name: 'photo.png' }, { name: 'receipt.pdf' }] },
    };
    state.selectFiles(event);
    assert.deepEqual(state.filenames.value, ['photo.png', 'receipt.pdf']);
    assert.equal(events[0][1], event);
    state.clear();
    assert.equal(state.input.value.value, '');
    assert.deepEqual(state.filenames.value, []);
    state.selectFiles(event);
    listeners.get('reset')();
    assert.deepEqual(state.filenames.value, []);
    unmounted();
    assert.equal(listeners.size, 0);
});
