import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as Vue from 'vue';

function setup(file = 'Index.vue', overrides = {}) {
    const { descriptor } = parse(
        readFileSync(new URL(file, import.meta.url), 'utf8'),
    );
    const compiled = compileScript(descriptor, { id: 'pc-builder-test' });
    const requests = [];
    const route = (name) => ({ url: () => name });
    const routes = {
        store: route('save'),
        update: route('update'),
        duplicate: route('duplicate'),
        destroy: route('delete'),
        share: route('share'),
        cart: { store: route('cart') },
    };
    const modules = new Proxy(
        {
            vue: { ...Vue, onMounted() {}, onBeforeUnmount() {} },
            '@inertiajs/vue3': {
                router: { visit() {} },
                usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
                useHttp: (initial) =>
                    Vue.reactive({
                        ...initial,
                        processing: false,
                        cancel() {},
                        post(url) {
                            return new Promise((resolve, reject) =>
                                requests.push({
                                    url,
                                    data: JSON.parse(
                                        JSON.stringify(this.components ?? []),
                                    ),
                                    resolve,
                                    reject,
                                }),
                            );
                        },
                        patch(url) {
                            return this.post(url);
                        },
                    }),
            },
            '@/routes/pc-builds': { ...routes, default: routes },
            '@/routes/cart': { index: route('cart-page') },
            '@/actions/App/Http/Controllers/PcBuilderController': {
                validate: route('validate'),
            },
        },
        {
            get: (target, key) =>
                target[key] ?? { default: {}, Button: {}, Input: {} },
        },
    );
    const code = compiled.content
        .replace(
            /import\s+(\w+),\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, symbol, names, path) =>
                `const ${symbol} = modules[${JSON.stringify(path)}].default; const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(path)}];`,
        )
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, path) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(path)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, symbol, path) =>
                `const ${symbol} = modules[${JSON.stringify(path)}].default;`,
        )
        .replace('export default', 'return');
    const component = new Function(
        'modules',
        'setTimeout',
        'clearTimeout',
        'localStorage',
        code,
    )(
        modules,
        () => 1,
        () => {},
        { setItem() {} },
    );
    const scope = Vue.effectScope();
    const state = scope.run(() =>
        component.setup(
            { components: {}, saved_builds: [], templates: [], ...overrides },
            { expose() {}, emit() {} },
        ),
    );
    return { state, requests, stop: () => scope.stop() };
}

test('new selections immediately invalidate compatibility and ignore delayed responses', async () => {
    const { state, requests, stop } = setup();
    const first = state.runValidation();
    state.selections.value = [{ key: 'cpu', product_id: 1, quantity: 1 }];
    assert.equal(state.validation.value, null);
    assert.equal(state.checking.value, true);
    const second = state.runValidation();
    requests[1].resolve({ status: 'unknown', complete: false });
    await second;
    requests[0].resolve({ status: 'compatible', complete: true });
    await first;
    assert.equal(state.validation.value.status, 'unknown');
    stop();
});

test('cart handoff saves the latest edits before transferring the saved build', async () => {
    const { state, requests, stop } = setup();
    state.editingUuid.value = 'existing';
    state.selections.value = [
        { key: 'new', product_id: 22, quantity: 2, configuration: {} },
    ];
    state.validation.value = { complete: true };
    state.checking.value = false;
    const transfer = state.addCurrentBuildToCart();
    assert.equal(requests[0].url, 'update');
    assert.equal(requests[0].data[0].product_id, 22);
    await state.addCurrentBuildToCart();
    assert.equal(requests.length, 1);
    requests[0].resolve({ uuid: 'existing', name: 'Updated', edit_version: 2 });
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(requests[1].url, 'cart');
    requests[1].resolve({});
    await transfer;
    assert.equal(state.transferring.value, false);
    stop();
});

test('failed saves and incomplete builds never reach cart transfer', async () => {
    const { state, requests, stop } = setup();
    await state.addCurrentBuildToCart();
    assert.equal(requests.length, 0);
    state.validation.value = { complete: true };
    const transfer = state.addCurrentBuildToCart();
    requests[0].reject(new Error('conflicting edit'));
    await transfer;
    assert.equal(requests.length, 1);
    assert.match(state.validationError.value, /could not be saved/);
    stop();
});

test('picker filters by stock, brand, search and price without mutating catalog order', () => {
    const products = [
        { id: 1, name: 'Alpha', brand: 'A', stock_quantity: 1, price: 50 },
        { id: 2, name: 'Beta', brand: 'B', stock_quantity: 0, price: 20 },
    ];
    const { state, stop } = setup('ComponentPicker.vue', {
        products,
        label: 'CPU',
    });
    assert.deepEqual(
        state.visible.value.map((p) => p.id),
        [1],
    );
    state.showUnavailable.value = true;
    state.sort.value = 'price';
    assert.deepEqual(
        state.visible.value.map((p) => p.id),
        [2, 1],
    );
    state.brand.value = 'B';
    assert.deepEqual(
        state.visible.value.map((p) => p.id),
        [2],
    );
    state.search.value = 'missing';
    assert.equal(state.visible.value.length, 0);
    assert.deepEqual(
        products.map((p) => p.id),
        [1, 2],
    );
    stop();
});

test('selection replaces singleton parts, caps quantities and requires GPU and cooling when specified', () => {
    const cpu = {
        id: 1,
        component_type: 'processor',
        stock_quantity: 2,
        specifications: { integrated_graphics: false, included_cooler: false },
    };
    const replacement = { ...cpu, id: 2 };
    const memory = { id: 3, component_type: 'memory', stock_quantity: 2 };
    const { state, stop } = setup('Index.vue', {
        components: { processor: [cpu, replacement], memory: [memory] },
    });
    state.addProduct(cpu);
    state.addProduct(replacement);
    assert.equal(state.selections.value.length, 1);
    assert.equal(state.selections.value[0].product_id, 2);
    assert.ok(state.requiredMissing.value.includes('graphics_card'));
    assert.ok(state.requiredMissing.value.includes('cooler'));
    state.addProduct(memory);
    state.addProduct(memory);
    state.addProduct(memory);
    assert.equal(state.selections.value[1].quantity, 2);
    state.changeQuantity(3, 1.5);
    assert.equal(state.selections.value[1].quantity, 2);
    state.addProduct({ id: 4, component_type: 'storage', stock_quantity: 0 });
    assert.equal(state.selections.value.length, 2);
    stop();
});
