import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as Vue from 'vue';

const settle = async () => {
    for (let i = 0; i < 8; i++) await Promise.resolve();
};

function activityHarness(t, { visible = true, enabled = true } = {}) {
    const props = Vue.reactive({
        product: { id: 1, inventory: { quantity: 5 } },
        can_record_product_dwell: enabled,
    });
    const listeners = new Map();
    const requests = [];
    const mounted = [];
    const disposed = [];
    const scope = Vue.effectScope();
    let now = 0;
    const document = {
        visibilityState: visible ? 'visible' : 'hidden',
        cookie: 'XSRF-TOKEN=test',
        addEventListener: (name, callback) => listeners.set(name, callback),
        removeEventListener: (name) => listeners.delete(name),
    };
    const globals = {
        document,
        performance: { now: () => now },
        fetch: async (url, options) => {
            requests.push({ url, ...options, data: JSON.parse(options.body) });
            return { ok: true };
        },
    };
    const originals = Object.fromEntries(
        Object.keys(globals).map((key) => [
            key,
            Object.getOwnPropertyDescriptor(globalThis, key),
        ]),
    );
    for (const [key, value] of Object.entries(globals))
        Object.defineProperty(globalThis, key, { value, configurable: true });
    const route = (kind) => ({
        store: {
            post: (id) => ({ url: `/products/${id}/${kind}`, method: 'post' }),
        },
    });
    const modules = new Proxy(
        {
            vue: {
                ...Vue,
                onMounted: (callback) => mounted.push(callback),
                onBeforeUnmount: (callback) => disposed.push(callback),
            },
            '@inertiajs/vue3': { usePage: () => ({ props: { auth: {} } }) },
            '@/lib/catalogReturn': { consumeCatalogVisit: () => false },
            '@/routes/products/view': route('view'),
            '@/routes/products/dwell': route('dwell'),
        },
        { get: (target, key) => target[key] ?? {} },
    );
    const { descriptor } = parse(
        readFileSync(new URL('./Show.vue', import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, { id: 'product-activity-test' });
    const code = script.content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, module) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(module)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, module) =>
                `const ${name} = modules[${JSON.stringify(module)}].default;`,
        )
        .replace('export default', 'return');
    scope.run(() =>
        new Function('modules', code)(modules).setup(props, { expose() {} }),
    );
    t.after(async () => {
        disposed.forEach((callback) => callback());
        await settle();
        scope.stop();
        for (const [key, original] of Object.entries(originals)) {
            if (original) Object.defineProperty(globalThis, key, original);
            else delete globalThis[key];
        }
    });
    return {
        props,
        requests,
        mounted,
        document,
        listeners,
        setTime: (value) => {
            now = value;
        },
    };
}

test('cached detail consumption records a mounted view with cookies and CSRF protection', async (t) => {
    const harness = activityHarness(t);
    assert.equal(harness.requests.length, 0);
    harness.mounted.forEach((callback) => callback());
    await settle();
    assert.equal(harness.requests.length, 1);
    assert.equal(harness.requests[0].url, '/products/1/view');
    assert.equal(harness.requests[0].credentials, 'same-origin');
    assert.equal(harness.requests[0].headers['X-XSRF-TOKEN'], 'test');
});

test('hidden time is excluded and long visible dwell is capped at one hour', async (t) => {
    const harness = activityHarness(t, { visible: false });
    harness.mounted.forEach((callback) => callback());
    assert.equal(harness.requests.length, 0);
    harness.setTime(100000);
    harness.document.visibilityState = 'visible';
    harness.listeners.get('visibilitychange')();
    await settle();
    harness.setTime(105000);
    harness.document.visibilityState = 'hidden';
    harness.listeners.get('visibilitychange')();
    await settle();
    assert.equal(harness.requests.at(-1).data.seconds, 5);
    harness.setTime(5000000);
    harness.document.visibilityState = 'visible';
    harness.listeners.get('visibilitychange')();
    harness.setTime(9000000);
    harness.document.visibilityState = 'hidden';
    harness.listeners.get('visibilitychange')();
    await settle();
    assert.equal(harness.requests.at(-1).data.seconds, 3600);
});

test('navigation records old dwell against the old product and starts the new product view', async (t) => {
    const harness = activityHarness(t);
    harness.mounted.forEach((callback) => callback());
    await settle();
    harness.setTime(8000);
    harness.props.product.id = 2;
    await Vue.nextTick();
    await settle();
    assert.ok(
        harness.requests.some(
            (request) =>
                request.url === '/products/1/dwell' &&
                request.data.seconds === 8,
        ),
    );
    assert.ok(
        harness.requests.some((request) => request.url === '/products/2/view'),
    );
});

test('paused activity never creates view or dwell requests', async (t) => {
    const harness = activityHarness(t, { enabled: false });
    harness.mounted.forEach((callback) => callback());
    harness.setTime(10000);
    harness.props.product.id = 2;
    await Vue.nextTick();
    await settle();
    assert.equal(harness.requests.length, 0);
});
