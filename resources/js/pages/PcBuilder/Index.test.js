import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as Vue from 'vue';
import * as builderActions from './builderActions.js';
import * as specificationFields from '../Administration/ComponentSpecifications/specificationFields.js';
import * as templateEditor from '../Administration/PcBuildTemplates/templateEditor.js';

function setup(file = 'Index.vue', overrides = {}) {
    const { descriptor } = parse(
        readFileSync(new URL(file, import.meta.url), 'utf8'),
    );
    const compiled = compileScript(descriptor, { id: 'pc-builder-test' });
    const requests = [];
    const visits = [];
    const confirmations = [];
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
            './builderActions': builderActions,
            './specificationFields': specificationFields,
            '../ComponentSpecifications/specificationFields':
                specificationFields,
            './templateEditor': templateEditor,
            '@/routes': { login: route('login') },
            '@/composables/useActionConfirmation': {
                useActionConfirmation: () => ({
                    confirmation: Vue.ref(null),
                    answer() {},
                    confirm: (options) =>
                        new Promise((resolve) =>
                            confirmations.push({ ...options, resolve }),
                        ),
                }),
            },
            '@/composables/useUnsavedEditor': {
                useUnsavedEditor: (dirty, confirm) => ({
                    mayDiscard: () =>
                        !dirty.value ||
                        confirm({ title: 'Discard unsaved changes?' }),
                }),
            },
            '@/actions/App/Http/Controllers/Administration/ComponentSpecificationController':
                {
                    default: {
                        store: route('spec-save'),
                        approve: route('spec-approve'),
                    },
                },
            '@/actions/App/Http/Controllers/Administration/PcBuildTemplateController':
                {
                    default: {
                        store: route('template-save'),
                        update: route('template-update'),
                        destroy: route('template-delete'),
                    },
                },
            '@inertiajs/vue3': {
                router: {
                    visit(url) {
                        visits.push(url);
                    },
                    reload() {},
                },
                usePage: () => ({
                    props: {
                        auth: { user: overrides.guest ? null : { id: 1 } },
                    },
                }),
                useHttp: (initial) =>
                    Vue.reactive({
                        ...initial,
                        processing: false,
                        errors: {},
                        clearErrors() {
                            this.errors = {};
                        },
                        cancel() {},
                        post(url) {
                            return new Promise((resolve, reject) =>
                                requests.push({
                                    url,
                                    data: JSON.parse(
                                        JSON.stringify(this.components ?? []),
                                    ),
                                    payload: JSON.parse(
                                        JSON.stringify(
                                            initial &&
                                                Object.fromEntries(
                                                    Object.keys(initial).map(
                                                        (key) => [
                                                            key,
                                                            this[key],
                                                        ],
                                                    ),
                                                ),
                                        ),
                                    ),
                                    resolve,
                                    reject,
                                }),
                            );
                        },
                        patch(url) {
                            return this.post(url);
                        },
                        put(url) {
                            return this.post(url);
                        },
                        delete(url) {
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
        'document',
        code,
    )(
        modules,
        () => 1,
        () => {},
        { setItem() {} },
        {
            getElementById() {
                return { focus() {} };
            },
        },
    );
    const scope = Vue.effectScope();
    const state = scope.run(() =>
        component.setup(
            {
                components: {},
                saved_builds: [],
                templates: [],
                products: [],
                profiles: [],
                candidates: [],
                ...overrides,
            },
            { expose() {}, emit() {} },
        ),
    );
    return { state, requests, confirmations, visits, stop: () => scope.stop() };
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

test('replacing a customer build waits for confirmation and cancellation retains selections', async () => {
    const { state, confirmations, stop } = setup();
    state.selections.value = [{ key: 'cpu', product_id: 1, quantity: 1 }];
    const canceled = state.startNewBuild();
    assert.equal(state.selections.value.length, 1);
    confirmations[0].resolve(false);
    await canceled;
    assert.equal(state.selections.value.length, 1);
    const accepted = state.loadTemplate({
        name: 'Starter',
        components: [
            {
                key: 'fan',
                product_id: 2,
                configuration: { mounting_position: 'front' },
            },
        ],
    });
    confirmations[1].resolve(true);
    await accepted;
    assert.equal(state.selections.value[0].product_id, 2);
    assert.equal(state.buildName.value, 'Starter');
    stop();
});

test('guest save and cart actions lead to sign in without sending a build request', async () => {
    const { state, requests, visits, stop } = setup('Index.vue', {
        guest: true,
    });
    state.selections.value = [{ key: 'cpu', product_id: 1, quantity: 1 }];
    await state.saveCurrentBuild();
    await state.addCurrentBuildToCart();
    assert.deepEqual(visits, ['login', 'login']);
    assert.equal(requests.length, 0);
    assert.equal(state.selections.value[0].product_id, 1);
    stop();
});

test('guided specification editing preserves false, zero, nested facts and uncommon attributes', async () => {
    const profile = {
        id: 3,
        product_id: 1,
        component_type: 'motherboard',
        edit_version: 2,
        revision: 1,
        review_status: 'draft',
        specifications: {
            ecc_supported: false,
            power_draw_w: 0,
            socket: null,
            storage_slots: [{ interfaces: ['NVMe'], custom_lane: 'shared' }],
            vendor_note: 'keep',
        },
        evidence: [{ title: 'Evidence', variant: 'MODEL' }],
    };
    const { state, requests, stop } = setup(
        '../Administration/ComponentSpecifications/Index.vue',
        { profiles: [profile] },
    );
    await state.openEditor({ profile_id: 3 });
    state.specifications.value.socket = 'AM5';
    const save = state.saveDraft();
    assert.deepEqual(requests[0].payload.specifications, {
        ...profile.specifications,
        socket: 'AM5',
    });
    requests[0].resolve({
        id: 3,
        product_id: 1,
        edit_version: 3,
        revision: 2,
        review_status: 'draft',
    });
    await save;
    assert.equal(state.dirty.value, false);
    assert.equal(state.selected.value.edit_version, 3);
    stop();
});

test('invalid advanced JSON and unsaved changes cannot be approved or silently discarded', async () => {
    const profile = {
        id: 3,
        product_id: 1,
        component_type: 'processor',
        edit_version: 2,
        specifications: { socket: 'AM5' },
        evidence: [],
    };
    const { state, requests, confirmations, stop } = setup(
        '../Administration/ComponentSpecifications/Index.vue',
        { profiles: [profile] },
    );
    await state.openEditor({ profile_id: 3 });
    state.updateAdvanced('{');
    await state.saveDraft();
    await state.approve();
    assert.equal(requests.length, 0);
    assert.equal(state.dirty.value, true);
    const change = state.openEditor();
    confirmations[0].resolve(false);
    await change;
    assert.equal(state.selected.value.id, 3);
    state.updateAdvanced('{"socket":"AM5"}');
    const approval = state.approve();
    assert.equal(requests[0].url, 'spec-approve');
    requests[0].reject(new Error('Stale revision'));
    await approval;
    assert.match(state.message.value, /did not complete/);
    stop();
});

test('review queue keeps existing profiles outside the customer catalog accessible', () => {
    const { state, stop } = setup(
        '../Administration/ComponentSpecifications/Index.vue',
        {
            profiles: [
                {
                    id: 4,
                    product_id: 8,
                    product_name: 'Inactive CPU',
                    product_code: 'CPU-8',
                    review_status: 'approved',
                },
            ],
        },
    );
    assert.equal(state.queue.value[0].profile_id, 4);
    state.search.value = 'CPU-8';
    assert.equal(state.queue.value.length, 1);
    state.status.value = 'needs_review';
    assert.equal(state.queue.value.length, 0);
    stop();
});

test('template saves retain configurations and publication intent while failed saves retain edits', async () => {
    const template = {
        id: 1,
        name: 'Starter',
        is_active: false,
        edit_version: 5,
        components: [
            {
                key: 'fan-1',
                product_id: 1,
                component_type: 'fan',
                quantity: 2,
                configuration: {
                    mounting_position: 'front',
                    future_setting: 'keep',
                },
            },
        ],
    };
    const { state, requests, stop } = setup(
        '../Administration/PcBuildTemplates/Index.vue',
    );
    await state.edit(template);
    state.name.value = 'Updated starter';
    const failed = state.save(true);
    assert.equal(requests[0].payload.is_active, true);
    assert.equal(requests[0].payload.edit_version, 5);
    assert.deepEqual(
        requests[0].data[0].configuration,
        template.components[0].configuration,
    );
    requests[0].reject(new Error('Compatibility failure'));
    await failed;
    assert.equal(state.dirty.value, true);
    assert.equal(state.active.value, false);
    const saved = state.save(false);
    requests[1].resolve({ id: 1, edit_version: 6, is_active: false });
    await saved;
    assert.equal(state.dirty.value, false);
    stop();
});

test('template component additions avoid duplicate products and slot keys after removal', () => {
    const products = [1, 2, 3].map((id) => ({
        id,
        name: `Drive ${id}`,
        component_type: 'storage',
    }));
    const { state, stop } = setup(
        '../Administration/PcBuildTemplates/Index.vue',
        { products },
    );
    for (const id of [1, 2]) {
        state.productId.value = String(id);
        state.addComponent();
    }
    state.components.value.splice(0, 1);
    state.productId.value = '3';
    state.addComponent();
    assert.equal(
        new Set(state.components.value.map((item) => item.key)).size,
        2,
    );
    state.productId.value = '3';
    state.addComponent();
    assert.equal(state.components.value.length, 2);
    stop();
});

test('template deletion cancellation sends no destructive request', async () => {
    const { state, requests, confirmations, stop } = setup(
        '../Administration/PcBuildTemplates/Index.vue',
    );
    const deletion = state.remove({ id: 1, name: 'Student PC' });
    assert.match(confirmations[0].title, /Student PC/);
    confirmations[0].resolve(false);
    await deletion;
    assert.equal(requests.length, 0);
    stop();
});

test('empty PHP configuration arrays can receive a mounting position when edited', async () => {
    const { state, requests, stop } = setup(
        '../Administration/PcBuildTemplates/Index.vue',
    );
    await state.edit({
        id: 1,
        name: 'Fans',
        edit_version: 1,
        is_active: false,
        components: [
            {
                key: 'fan-1',
                product_id: 1,
                component_type: 'fan',
                quantity: 1,
                configuration: [],
            },
        ],
    });
    state.components.value[0].configuration.mounting_position = 'rear';
    const save = state.save(false);
    assert.deepEqual(requests[0].data[0].configuration, {
        mounting_position: 'rear',
    });
    requests[0].resolve({ id: 1, edit_version: 2, is_active: false });
    await save;
    stop();
});

test('guided values distinguish unknown from false and zero and reject non-object advanced data', () => {
    assert.equal(specificationFields.fieldValue('boolean', ''), null);
    assert.equal(specificationFields.fieldValue('boolean', 'false'), false);
    assert.equal(specificationFields.fieldValue('number', '0'), 0);
    assert.equal(specificationFields.fieldValue('number', ''), null);
    assert.throws(() => specificationFields.parseSpecifications('[]'));
    assert.throws(() => specificationFields.parseSpecifications('null'));
});

test('cart blockers describe missing parts, pending checks and unresolved compatibility', () => {
    assert.match(
        builderActions.cartBlocker({ checking: true, missing: [] }),
        /Checking compatibility/,
    );
    assert.match(
        builderActions.cartBlocker({ error: 'Failed', missing: [] }),
        /Resolve the error/,
    );
    assert.match(
        builderActions.cartBlocker({ missing: ['Processor'] }),
        /Processor/,
    );
    assert.match(
        builderActions.cartBlocker({
            missing: [],
            validation: { complete: false },
        }),
        /compatibility findings/,
    );
    assert.equal(
        builderActions.cartBlocker({
            missing: [],
            validation: { complete: true },
        }),
        '',
    );
});
