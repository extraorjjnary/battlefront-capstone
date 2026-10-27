import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { ref } from 'vue';

function harness(file, extra = {}) {
    const mounted = [];
    const unmounted = [];
    const source = readFileSync(new URL(file, import.meta.url), 'utf8')
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, path) =>
                `const { ${names} } = modules[${JSON.stringify(path)}];`,
        )
        .replace('export function', 'function');
    const name = file.replace('.js', '');
    const modules = {
        vue: {
            ref,
            onMounted: (fn) => mounted.push(fn),
            onBeforeUnmount: (fn) => unmounted.push(fn),
        },
        ...extra.modules,
    };
    const use = new Function('modules', 'window', source + `\nreturn ${name};`)(
        modules,
        extra.window,
    );
    return {
        use,
        mount: () => mounted.forEach((fn) => fn()),
        unmount: () => unmounted.forEach((fn) => fn()),
    };
}

test('confirmation resolves cancellation, approval and cleanup without leaving pending actions', async () => {
    const { use, unmount } = harness('useActionConfirmation.js');
    const { confirm, answer, confirmation } = use();
    const first = confirm({ title: 'Delete template?' });
    assert.equal(confirmation.value.title, 'Delete template?');
    answer(false);
    assert.equal(await first, false);
    const second = confirm({ title: 'Replace build?' });
    answer(true);
    assert.equal(await second, true);
    assert.equal(confirmation.value, null);
    const pending = confirm({ title: 'Discard changes?' });
    unmount();
    assert.equal(await pending, false);
});

test('dirty editor navigation waits for approval and retains the requested destination', async () => {
    let before;
    const visits = [];
    let resolveConfirmation;
    const window = {
        location: { href: 'https://local.test/editor' },
        addEventListener() {},
        removeEventListener() {},
    };
    const router = {
        on: (_, fn) => {
            before = fn;
            return () => {};
        },
        visit: (...args) => visits.push(args),
    };
    const { use, mount, unmount } = harness('useUnsavedEditor.js', {
        window,
        modules: { '@inertiajs/vue3': { router } },
    });
    use(
        ref(true),
        () =>
            new Promise((resolve) => {
                resolveConfirmation = resolve;
            }),
    );
    mount();
    let prevented = 0;
    const event = {
        detail: {
            visit: {
                method: 'get',
                url: new URL('https://local.test/templates'),
                data: {},
                preserveScroll: true,
            },
        },
        preventDefault() {
            prevented += 1;
        },
    };
    before(event);
    resolveConfirmation(false);
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(visits.length, 0);
    before(event);
    resolveConfirmation(true);
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(visits[0][0].href, 'https://local.test/templates');
    assert.equal(visits[0][1].preserveScroll, true);
    before(event);
    assert.equal(prevented, 2);
    unmount();
});

test('dirty editors guard tab closure and remove listeners when unmounted', () => {
    let leave;
    let removed = false;
    const dirty = ref(false);
    const window = {
        location: { href: 'https://local.test/editor' },
        addEventListener: (_, fn) => {
            leave = fn;
        },
        removeEventListener: (_, fn) => {
            removed = fn === leave;
        },
    };
    const { use, mount, unmount } = harness('useUnsavedEditor.js', {
        window,
        modules: { '@inertiajs/vue3': { router: { on: () => () => {} } } },
    });
    use(dirty, async () => true);
    mount();
    let prevented = false;
    const event = {
        preventDefault() {
            prevented = true;
        },
    };
    leave(event);
    assert.equal(prevented, false);
    dirty.value = true;
    leave(event);
    assert.equal(prevented, true);
    unmount();
    assert.equal(removed, true);
});
