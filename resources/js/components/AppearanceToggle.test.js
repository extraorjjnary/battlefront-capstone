import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { Moon, Sun, Monitor } from '@lucide/vue';
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
        /<button\b[^>]*class="[^"]*bg-white[^"]*"[^>]*>.*?<\/button>/s,
    )?.[0];
}

function appearanceHarness({ preference = 'light', systemDark = false } = {}) {
    const requests = [];
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
        '@lucide/vue': { Moon, Sun, Monitor },
        '@inertiajs/vue3': {
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
    )(modules, { matchMedia: () => media }, document);
    appearanceModule.initializeTheme();
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
        classes,
        modules,
        component,
        controls,
        render: () => renderToString(Vue.h(toggle)),
        renderTabs: () => renderToString(Vue.h(tabs)),
        systemChange(dark) {
            media.matches = dark;
            listeners.get('system')();
        },
        navigate(value) {
            listeners.get('navigate')({
                detail: { page: { props: { appearance: value } } },
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
    test(`System with a ${systemDark ? 'dark' : 'light'} OS explicitly selects the opposite mode on click`, () => {
        const harness = appearanceHarness({ preference: 'system', systemDark });

        harness.controls.toggleAppearance();

        assert.equal(harness.appearance.value, systemDark ? 'light' : 'dark');
        assert.equal(harness.classes.has('dark'), !systemDark);
        assert.deepEqual(harness.requests[0].data, {
            appearance: systemDark ? 'light' : 'dark',
        });
        harness.systemChange(!systemDark);
        harness.systemChange(systemDark);
        assert.equal(harness.classes.has('dark'), !systemDark);
    });
}

test('OS theme changes update the System icon and the next toggle action without saving a preference', async () => {
    const harness = appearanceHarness({ preference: 'system' });
    assert.equal(harness.resolvedAppearance.value, 'light');
    assert.match(await harness.render(), /lucide-sun/);

    harness.systemChange(true);

    assert.equal(harness.appearance.value, 'system');
    assert.equal(harness.resolvedAppearance.value, 'dark');
    assert.match(await harness.render(), /lucide-moon/);
    assert.equal(harness.requests.length, 0);
    harness.systemChange(false);
    assert.equal(harness.resolvedAppearance.value, 'light');
    assert.equal(harness.classes.has('dark'), false);
    harness.systemChange(true);
    harness.controls.toggleAppearance();
    assert.deepEqual(harness.requests[0].data, { appearance: 'light' });
});

test('settings choices synchronize the toggle while navigation and refresh restore the saved preference', async () => {
    const harness = appearanceHarness();
    const settings = harness.component('./AppearanceTabs.vue', false).setup(
        {},
        { expose() {} },
    );

    settings.updateAppearance('dark');

    assert.match(await harness.render(), /lucide-moon/);
    assert.equal(harness.classes.has('dark'), true);
    harness.navigate('dark');
    assert.equal(harness.appearance.value, 'dark');
    const refreshed = appearanceHarness({ preference: 'dark' });
    assert.match(await refreshed.render(), /lucide-moon/);
    settings.updateAppearance('system');
    assert.match(await harness.render(), /lucide-sun/);
    assert.match(activeAppearanceTab(await harness.renderTabs()), />System<\/span>/);
    assert.equal(harness.appearance.value, 'system');
});

test('failed saves restore both controls and the applied theme including a previous System preference', async () => {
    for (const preference of ['light', 'system']) {
        const harness = appearanceHarness({ preference });

        harness.controls.toggleAppearance();
        harness.requests[0].options.onError();

        assert.equal(harness.appearance.value, preference);
        assert.equal(harness.classes.has('dark'), false);
        assert.match(await harness.render(), /lucide-sun/);
        const label = preference === 'system' ? 'System' : 'Light';
        assert.match(
            activeAppearanceTab(await harness.renderTabs()),
            new RegExp(`>${label}</span>`),
        );
    }
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
                render: () => Vue.h('button', { 'aria-label': 'Notifications' }),
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
