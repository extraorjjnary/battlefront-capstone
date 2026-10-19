import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { Moon, Sun } from '@lucide/vue';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';

function resolveImports(code) {
    return code
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, name) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(name)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, moduleName) =>
                `const ${name} = modules[${JSON.stringify(moduleName)}].default;`,
        );
}

const primitive = {
    render() {
        return Vue.h('div', this.$slots.default?.());
    },
};
const button = {
    render() {
        return Vue.h('button', this.$attrs, this.$slots.default?.());
    },
};

function activeAppearanceTab(html) {
    return html.match(
        /<button\b[^>]*aria-pressed="true"[^>]*>.*?<\/button>/s,
    )?.[0];
}

function appearanceHarness({
    preference = 'light',
    systemDark = false,
    server = false,
    user = { id: 1 },
    guestPreference,
    storageBlocked = false,
} = {}) {
    const requests = [];
    const sharedPage = {
        props: { appearance: preference, auth: { user } },
    };
    const storage = new Map(
        guestPreference === undefined
            ? []
            : [['battlefront_guest_appearance', guestPreference]],
    );
    const storageWrites = [];
    const localStorage = {
        getItem: (key) => {
            if (storageBlocked) throw new Error('Storage unavailable');
            return storage.get(key) ?? null;
        },
        setItem: (key, value) => {
            if (storageBlocked) throw new Error('Storage unavailable');
            storageWrites.push({ key, value });
            storage.set(key, value);
        },
    };
    const listeners = new Map();
    const classes = new Set();
    const media = {
        matches: systemDark,
        addEventListener: (_, listener) => listeners.set('system', listener),
    };
    const document = {
        documentElement: {
            dataset: { appearance: preference },
            classList: {
                toggle: (name, enabled) =>
                    enabled ? classes.add(name) : classes.delete(name),
            },
        },
    };
    const modules = {
        vue: Vue,
        '@lucide/vue': { Moon, Sun },
        '@inertiajs/vue3': {
            usePage: () => sharedPage,
            router: {
                on: (event, listener) => listeners.set(event, listener),
                patch: (url, data, options) =>
                    requests.push({ url, data, options }),
            },
        },
        '@/routes/appearance': {
            update: { url: () => '/settings/appearance' },
        },
        '@/components/ui/button': { Button: button },
        '@/components/ui/tooltip': {
            Tooltip: primitive,
            TooltipContent: primitive,
            TooltipProvider: primitive,
            TooltipTrigger: primitive,
        },
    };
    const code = resolveImports(
        readFileSync(
            new URL('../composables/useAppearance.js', import.meta.url),
            'utf8',
        ),
    ).replaceAll('export function', 'function');
    const appearanceModule = new Function(
        'modules',
        'window',
        'document',
        code + '\nreturn { initializeTheme, useAppearance };',
    )(
        modules,
        server ? undefined : { matchMedia: () => media, localStorage },
        server ? undefined : document,
    );
    appearanceModule.initializeTheme(sharedPage.props);
    modules['@/composables/useAppearance'] = appearanceModule;

    function component(path, inlineTemplate = true) {
        const { descriptor } = parse(
            readFileSync(new URL(path, import.meta.url), 'utf8'),
        );
        const script = compileScript(descriptor, {
            id: 'appearance-toggle-test',
            inlineTemplate,
        });
        return new Function(
            'modules',
            resolveImports(script.content).replace('export default', 'return'),
        )(modules);
    }

    const toggle = component('./AppearanceToggle.vue');
    const controls = component('./AppearanceToggle.vue', false).setup(
        {},
        { expose() {} },
    );
    const tabs = component('./AppearanceTabs.vue');
    return {
        ...appearanceModule.useAppearance(),
        requests,
        storage,
        storageWrites,
        page: sharedPage,
        classes,
        modules,
        component,
        controls,
        render: () => renderToString(Vue.h(toggle)),
        renderTabs: () => renderToString(Vue.h(tabs)),
        systemChange(dark) {
            media.matches = dark;
            listeners.get('system')?.();
        },
        navigate(value, user = { id: 1 }, component = 'Products/Index') {
            sharedPage.props = { appearance: value, auth: { user } };
            listeners.get('navigate')({
                detail: { page: { component, props: sharedPage.props } },
            });
        },
    };
}

for (const [initial, next, icon] of [
    ['light', 'dark', 'sun'],
    ['dark', 'light', 'moon'],
]) {
    test(`${initial} mode shows its icon and switches immediately to ${next} through the existing preference endpoint`, async () => {
        const harness = appearanceHarness({ preference: initial });
        const html = await harness.render();
        assert.match(html, new RegExp(`lucide-${icon}`));
        assert.match(html, /<svg\b[^>]*class="[^"]*\bsize-3(?:\s|")/);
        assert.match(html, /role="switch"/);
        assert.match(html, /aria-label="Dark mode"/);
        assert.match(html, new RegExp(`aria-checked="${initial === 'dark'}"`));
        assert.match(html, new RegExp(`>Switch to ${next} mode<`));
        assert.match(html, /type="button"/);
        assert.match(html, /aria-hidden="true"/);

        harness.controls.toggleAppearance();

        assert.equal(harness.appearance.value, next);
        assert.equal(harness.classes.has('dark'), next === 'dark');
        assert.equal(harness.requests[0].url, '/settings/appearance');
        assert.deepEqual(harness.requests[0].data, { appearance: next });
        assert.equal(harness.requests[0].options.preserveScroll, true);
        const updatedHtml = await harness.render();
        assert.match(
            updatedHtml,
            new RegExp(`aria-checked="${next === 'dark'}"`),
        );
        assert.match(updatedHtml, new RegExp(`>Switch to ${initial} mode<`));
        assert.match(
            activeAppearanceTab(await harness.renderTabs()),
            next === 'dark' ? />Dark<\/span>/ : />Light<\/span>/,
        );
    });
}

for (const systemDark of [false, true]) {
    for (const preference of ['system', null, '', 'unsupported']) {
        test(`legacy or missing preference ${preference} defaults to light on a ${systemDark ? 'dark' : 'light'} OS`, async () => {
            const harness = appearanceHarness({ preference, systemDark });
            assert.equal(harness.appearance.value, 'light');
            assert.equal(harness.classes.has('dark'), false);
            assert.match(await harness.render(), /lucide-sun/);
            assert.doesNotMatch(
                await harness.renderTabs(),
                />System<|lucide-monitor/,
            );
            assert.equal(harness.requests.length, 0);
            harness.systemChange(!systemDark);
            assert.equal(harness.resolvedAppearance.value, 'light');
            harness.controls.toggleAppearance();
            assert.deepEqual(harness.requests[0].data, { appearance: 'dark' });
            harness.systemChange(systemDark);
            assert.equal(harness.classes.has('dark'), true);
        });
    }
}

test('explicit light and dark preferences ignore operating-system changes', () => {
    for (const preference of ['light', 'dark']) {
        const harness = appearanceHarness({
            preference,
            systemDark: preference !== 'dark',
        });
        harness.systemChange(true);
        harness.systemChange(false);
        assert.equal(harness.appearance.value, preference);
        assert.equal(harness.classes.has('dark'), preference === 'dark');
        assert.equal(harness.requests.length, 0);
    }
});

test('settings choices synchronize the toggle while navigation and refresh restore the saved preference', async () => {
    const harness = appearanceHarness();
    const settings = harness
        .component('./AppearanceTabs.vue', false)
        .setup({}, { expose() {} });

    settings.updateAppearance('dark');

    assert.match(await harness.render(), /lucide-moon/);
    assert.equal(harness.classes.has('dark'), true);
    harness.navigate('dark');
    assert.equal(harness.appearance.value, 'dark');
    const refreshed = appearanceHarness({ preference: 'dark' });
    assert.match(await refreshed.render(), /lucide-moon/);
    settings.updateAppearance('light');
    assert.match(await harness.render(), /lucide-sun/);
    assert.match(
        activeAppearanceTab(await harness.renderTabs()),
        />Light<\/span>/,
    );
    assert.equal(harness.appearance.value, 'light');
});

test('failed saves restore both controls and the applied theme including a legacy preference', async () => {
    for (const preference of ['light', 'dark', 'system']) {
        const harness = appearanceHarness({ preference });

        harness.controls.toggleAppearance();
        harness.requests[0].options.onError();

        const expected = preference === 'dark' ? 'dark' : 'light';
        assert.equal(harness.appearance.value, expected);
        assert.equal(harness.classes.has('dark'), expected === 'dark');
        assert.match(
            await harness.render(),
            expected === 'dark' ? /lucide-moon/ : /lucide-sun/,
        );
        const label = expected === 'dark' ? 'Dark' : 'Light';
        assert.match(
            activeAppearanceTab(await harness.renderTabs()),
            new RegExp(`>${label}</span>`),
        );
    }
});

for (const [initial, selected] of [
    ['light', 'dark'],
    ['dark', 'light'],
]) {
    test(`latest ${selected} selection survives stale catalog, details and other page navigation`, async () => {
        const harness = appearanceHarness({ preference: initial });
        harness.navigate(initial);
        harness.controls.toggleAppearance();
        harness.requests[0].options.onSuccess?.();

        for (const component of [
            'Products/Index',
            'Products/Show',
            'Orders/Index',
            'Dashboard/Customer',
            'settings/Appearance',
        ]) {
            harness.navigate(initial, { id: 1 }, component);

            assert.equal(harness.appearance.value, selected, component);
            assert.equal(
                harness.classes.has('dark'),
                selected === 'dark',
                component,
            );
            assert.match(
                await harness.render(),
                new RegExp(`aria-checked="${selected === 'dark'}"`),
            );
            assert.match(
                activeAppearanceTab(await harness.renderTabs()),
                selected === 'dark' ? />Dark<\/span>/ : />Light<\/span>/,
            );
        }
        assert.equal(
            harness.requests.length,
            1,
            'Restoring a page does not save an older preference',
        );
    });
}

test('the saved preference on a fresh load survives older history without another local selection', () => {
    for (const [saved, stale] of [
        ['dark', 'light'],
        ['light', 'dark'],
    ]) {
        const harness = appearanceHarness({ preference: saved });
        harness.navigate(stale);
        assert.equal(harness.appearance.value, saved);
        assert.equal(harness.classes.has('dark'), saved === 'dark');
        assert.equal(harness.requests.length, 0);
    }
});

test('logout and account changes discard local selections and late failures from the previous account', async () => {
    const harness = appearanceHarness();
    harness.controls.toggleAppearance();
    harness.navigate('light', null, 'Welcome');
    assert.equal(harness.appearance.value, 'light');
    harness.navigate('dark', { id: 2 });
    harness.requests[0].options.onError();
    assert.equal(harness.appearance.value, 'dark');
    assert.match(await harness.render(), /lucide-moon/);
    harness.navigate('light', { id: 3 });
    assert.equal(harness.classes.has('dark'), false);
});

test('a failed save stays rolled back when a cached response carries the rejected appearance', () => {
    const harness = appearanceHarness();
    harness.controls.toggleAppearance();
    harness.requests[0].options.onError();
    harness.navigate('dark');
    assert.equal(harness.appearance.value, 'light');
    assert.equal(harness.classes.has('dark'), false);
});

test('a late failure cannot undo a newer appearance choice', () => {
    const harness = appearanceHarness({ preference: 'dark' });
    harness.controls.toggleAppearance();
    harness.controls.toggleAppearance();
    harness.controls.toggleAppearance();
    harness.requests[0].options.onError();
    assert.equal(harness.appearance.value, 'light');
    assert.equal(harness.classes.has('dark'), false);
});

test('both authenticated header variants render the toggle directly beside notifications for either account role', async () => {
    for (const administrator of [false, true]) {
        const harness = appearanceHarness();
        const page = {
            props: {
                auth: {
                    user: { id: 1, name: 'Test user' },
                    can: { accessAdministration: administrator },
                },
            },
        };
        harness.modules['@inertiajs/vue3'].usePage = () => page;
        harness.modules['@/components/AppearanceToggle.vue'] = {
            default: harness.component('./AppearanceToggle.vue'),
        };
        harness.modules['@/components/NotificationBell.vue'] = {
            default: {
                render: () =>
                    Vue.h('button', { 'aria-label': 'Notifications' }),
            },
        };
        harness.modules['@/composables/useAppNavigation'] = {
            useAppNavigation: () => ({ mainNavItems: [] }),
        };
        harness.modules['@/composables/useCurrentUrl'] = {
            useCurrentUrl: () => ({ isCurrentUrl: () => false }),
        };
        harness.modules['@/composables/useInitials'] = {
            getInitials: () => 'TU',
        };
        harness.modules['@/routes'] = {
            dashboard: () => ({ url: '/dashboard' }),
        };
        for (const path of ['./AppSidebarHeader.vue', './AppHeader.vue']) {
            const source = readFileSync(new URL(path, import.meta.url), 'utf8');
            for (const [, names, name] of source.matchAll(
                /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"]/g,
            )) {
                harness.modules[name] ??= {};
                const bindings = names
                    .split(',')
                    .map((value) => value.trim())
                    .filter(Boolean);
                for (const binding of bindings) {
                    harness.modules[name][binding] ??= primitive;
                }
            }
            for (const [, , name] of source.matchAll(
                /import\s+(\w+)\s+from\s*['"]([^'"]+)['"]/g,
            )) {
                harness.modules[name] ??= { default: primitive };
            }

            const html = await renderToString(Vue.h(harness.component(path)));

            assert.match(html, /aria-label="Dark mode"/);
            assert.match(
                html,
                /Switch to dark mode<\/div><\/div><\/div><button aria-label="Notifications"/,
            );
        }
    }
});

test('server rendering follows each account without leaking appearance between requests', async () => {
    const harness = appearanceHarness({ preference: 'dark', server: true });
    assert.equal(harness.appearance.value, 'dark');
    assert.match(await harness.render(), /lucide-moon/);
    harness.page.props.appearance = 'system';
    assert.match(await harness.render(), /lucide-sun/);
    assert.equal(
        harness.modules['@/composables/useAppearance'].useAppearance()
            .appearance.value,
        'light',
    );
    assert.equal(harness.appearance.value, 'dark');
    assert.equal(harness.requests.length, 0);
});

for (const preference of [
    undefined,
    'system',
    '',
    'invalid',
    'DARK',
    'light',
    'dark',
]) {
    test(`guest preference ${preference} resolves safely and persists without an account request`, () => {
        const harness = appearanceHarness({
            user: null,
            guestPreference: preference,
            systemDark: true,
        });
        const expected = preference === 'dark' ? 'dark' : 'light';
        assert.equal(harness.appearance.value, expected);
        harness.controls.toggleAppearance();
        const selected = expected === 'dark' ? 'light' : 'dark';
        assert.equal(harness.classes.has('dark'), selected === 'dark');
        assert.deepEqual(harness.storageWrites, [
            { key: 'battlefront_guest_appearance', value: selected },
        ]);
        for (const component of [
            'Welcome',
            'Products/Index',
            'Products/Show',
            'Branches/Index',
            'auth/Login',
            'auth/Register',
        ]) {
            harness.navigate('light', null, component);
            assert.equal(harness.appearance.value, selected);
        }
        const refreshed = appearanceHarness({
            user: null,
            guestPreference: harness.storage.get(
                'battlefront_guest_appearance',
            ),
        });
        assert.equal(refreshed.appearance.value, selected);
        assert.equal(harness.requests.length, 0);
    });
}

test('account choices override guest storage and logout restores only the guest preference', () => {
    const harness = appearanceHarness({ user: null, guestPreference: 'dark' });
    harness.navigate('light', { id: 1 });
    assert.equal(harness.appearance.value, 'light');
    harness.controls.toggleAppearance();
    harness.controls.toggleAppearance();
    assert.equal(harness.requests.length, 2);
    assert.equal(harness.storage.get('battlefront_guest_appearance'), 'dark');
    assert.deepEqual(harness.storageWrites, []);
    harness.navigate('light', null);
    assert.equal(harness.appearance.value, 'dark');
    harness.navigate('light', { id: 2 });
    assert.equal(
        harness.appearance.value,
        'light',
        'New accounts keep their own Light default',
    );
    harness.storage.delete('battlefront_guest_appearance');
    harness.navigate('dark', null);
    assert.equal(
        harness.appearance.value,
        'light',
        'No guest choice never inherits an account preference',
    );
});

test('unavailable guest storage falls back to Light while switching remains usable', () => {
    const harness = appearanceHarness({ user: null, storageBlocked: true });
    assert.equal(harness.appearance.value, 'light');
    harness.controls.toggleAppearance();
    harness.navigate('light', null);
    assert.equal(harness.appearance.value, 'dark');
    assert.equal(harness.requests.length, 0);
});

test('guest server rendering reserves the switch space until mounting to avoid hydration mismatches', async () => {
    const harness = appearanceHarness({ user: null, server: true });
    harness.modules['@/components/AppearanceToggle.vue'] = {
        default: harness.component('./AppearanceToggle.vue'),
    };
    const html = await renderToString(
        Vue.h(harness.component('./GuestAppearanceToggle.vue')),
    );
    assert.match(html, /aria-hidden="true"/);
    assert.doesNotMatch(html, /role="switch"|lucide-moon|lucide-sun/);
});

const earlyThemeScript = readFileSync(
    new URL('../../views/app.blade.php', import.meta.url),
    'utf8',
).match(/<script data-guest-appearance>([\s\S]*?)<\/script>/)[1];
for (const preference of [undefined, 'system', 'invalid', 'light', 'dark']) {
    test(`head initialization applies guest ${preference} before application code`, () => {
        const classes = new Set();
        const document = {
            documentElement: {
                dataset: { authenticated: 'false', appearance: 'light' },
                classList: {
                    toggle: (name, enabled) =>
                        enabled ? classes.add(name) : classes.delete(name),
                },
            },
        };
        runInNewContext(earlyThemeScript, {
            document,
            localStorage: { getItem: () => preference },
        });
        assert.equal(classes.has('dark'), preference === 'dark');
        assert.equal(
            document.documentElement.dataset.appearance,
            preference === 'dark' ? 'dark' : 'light',
        );
    });
}

test('head initialization preserves the server account appearance and tolerates blocked storage', () => {
    for (const authenticated of ['true', 'false']) {
        const classes = new Set(['dark']);
        let reads = 0;
        const document = {
            documentElement: {
                dataset: { authenticated, appearance: 'dark' },
                classList: {
                    toggle: (name, enabled) =>
                        enabled ? classes.add(name) : classes.delete(name),
                },
            },
        };
        runInNewContext(earlyThemeScript, {
            document,
            localStorage: {
                getItem() {
                    reads++;
                    throw new Error('Storage unavailable');
                },
            },
        });
        assert.equal(classes.has('dark'), authenticated === 'true');
        assert.equal(reads, authenticated === 'true' ? 0 : 1);
    }
});
