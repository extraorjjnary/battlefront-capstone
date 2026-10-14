import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { formatCurrency } from '../../lib/currency.js';

const primitive = {
    render() {
        return Vue.h(
            'div',
            this.$attrs,
            this.$slots.default?.({ errors: {}, processing: false }),
        );
    },
};
const route = () => ({ url: '/products', method: 'get' });
const generic = new Proxy(
    { default: primitive },
    { get: (target, key) => target[key] ?? primitive },
);
const modules = new Proxy(
    {
        vue: Vue,
        '@inertiajs/vue3': {
            Form: primitive,
            Link: primitive,
            Head: primitive,
            usePage: () => ({ props: { auth: {} } }),
        },
        '@/lib/currency': { formatCurrency },
        '@/routes/products': { show: route },
        '@/actions/App/Http/Controllers/Administration/ProductController': {
            default: { index: route, edit: route, create: route, store: { form: route }, destroy: { form: route } },
        },
        '@/lib/catalogReturn': {
            rememberCatalogVisit() {},
            consumeCatalogVisit: () => false,
        },
        '@/composables/useProductPrefetch': {
            useProductPrefetch: () => ({
                prefetchProduct() {},
                cancelProductPrefetch() {},
            }),
        },
    },
    { get: (target, key) => target[key] ?? generic },
);

function loadComponent(path, inlineTemplate = true) {
    const { descriptor } = parse(
        readFileSync(new URL(path, import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: 'shipping-handling-test',
        inlineTemplate,
    });
    const code = script.content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, moduleName) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(moduleName)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, moduleName) =>
                `const ${name} = modules[${JSON.stringify(moduleName)}].default;`,
        )
        .replace('export default', 'return');
    return new Function('modules', code)(modules);
}

const profiles = [
    {
        profile: 'standard',
        label: 'Standard',
        surcharge: '0.00',
        preparation_days: 1,
    },
    {
        profile: 'fragile',
        label: 'Fragile',
        surcharge: '73.25',
        preparation_days: 4,
    },
    {
        profile: 'bulky',
        label: 'Bulky',
        surcharge: '125.50',
        preparation_days: 5,
    },
];
modules['@/components/catalog/ShippingHandling.vue'] = {
    default: loadComponent('./ShippingHandling.vue'),
};
modules['@/components/ProductForm.vue'] = {
    default: loadComponent('../ProductForm.vue'),
};
modules['@/routes/products'].index = route;
const formProps = {
    categories: [{ id: 1, name: 'Hardware' }],
    tags: [],
    shippingProfiles: profiles,
    product: null,
    form: { action: '/products', method: 'post' },
    submitLabel: 'Save',
};

test('handling details display supplied pricing, preparation, and once per order applicability for every profile', async () => {
    for (const handling of profiles) {
        const html = await renderToString(
            Vue.createSSRApp(loadComponent('./ShippingHandling.vue'), {
                handling,
            }),
        );
        assert.ok(html.includes(`Shipping handling · ${handling.label}`));
        assert.ok(html.includes(formatCurrency(handling.surcharge)));
        assert.ok(
            html.includes(
                `${handling.preparation_days} ${handling.preparation_days === 1 ? 'day' : 'days'} preparation`,
            ),
        );
        assert.ok(html.includes('once per delivery order, not per item'));
        assert.doesNotMatch(html, /highest profile|configured destination fee|available delivery estimate/);
        assert.ok(html.includes('configured delivery routes'));
    }
});

test('customer cards label fragile and bulky handling and omit standard', async () => {
    for (const handling of profiles) {
        const html = await renderToString(
            Vue.createSSRApp(loadComponent('./ProductCard.vue'), {
                product: {
                    id: 1,
                    name: 'Hardware',
                    category: { name: 'Components' },
                    inventory: {},
                    shipping_profile: handling.profile,
                },
            }),
        );
        assert.equal(
            html.includes(`${handling.profile} handling`),
            handling.profile !== 'standard',
        );
        assert.ok(!html.includes(formatCurrency(handling.surcharge)));
    }
});

test('product form renders the configured summary without a review checkbox or assignment labels', async () => {
    for (const handling of profiles) {
        const html = await renderToString(
            Vue.createSSRApp(loadComponent('../ProductForm.vue'), {
                ...formProps,
                product: {
                    shipping_profile: handling.profile,
                    shipping_profile_source: 'catalog_review_v1',
                },
            }),
        );
        assert.ok(html.includes(formatCurrency(handling.surcharge)));
        assert.ok(
            html.includes(
                `${handling.preparation_days} ${handling.preparation_days === 1 ? 'day' : 'days'} preparation`,
            ),
        );
        assert.match(
            html,
            new RegExp(`name="shipping_profile" value="${handling.profile}"`),
        );
        assert.doesNotMatch(
            html,
            /shipping_profile_reviewed|Confirm this handling|Assignment:|Unreviewed/,
        );
    }
});

test('changing the selection updates the profile summary and returning to the initial profile restores it', () => {
    const scope = Vue.effectScope();
    try {
        const formModules = new Proxy(modules, {
            get: (target, key) =>
                key === 'vue' ? { ...Vue, onBeforeUnmount() {} } : target[key],
        });
        const previousVue = modules.vue;
        modules.vue = formModules.vue;
        let form;
        try {
            form = scope.run(() =>
                loadComponent('../ProductForm.vue', false).setup(formProps, {
                    expose() {},
                }),
            );
        } finally {
            modules.vue = previousVue;
        }
        assert.equal(form.selectedHandling.value, profiles[0]);
        form.shippingProfile.value = 'bulky';
        assert.equal(form.selectedHandling.value, profiles[2]);
        form.shippingProfile.value = 'standard';
        assert.equal(form.selectedHandling.value, profiles[0]);
    } finally {
        scope.stop();
    }
});

test('add product summaries retain each surcharge and profile without preparation wording', async () => {
    for (const handling of profiles) {
        const html = await renderToString(
            Vue.createSSRApp(loadComponent('../ProductForm.vue'), {
                ...formProps,
                showPreparation: false,
                product: { shipping_profile: handling.profile },
            }),
        );
        assert.ok(html.includes(handling.label));
        assert.ok(html.includes(formatCurrency(handling.surcharge)));
        assert.ok(html.includes('per delivery order'));
        assert.doesNotMatch(html, /days? preparation|configured routes/);
    }
});

test('the add product page hides preparation wording and shows the zero surcharge', async () => {
    const html = await renderToString(
        Vue.createSSRApp(loadComponent('../../pages/Administration/Products/Create.vue'), {
            categories: formProps.categories,
            tags: [],
            shipping_profiles: profiles,
        }),
    );
    assert.ok(html.includes(formatCurrency('0.00')));
    assert.ok(html.includes('Standard'));
    assert.doesNotMatch(html, /days? preparation|configured routes/);
});

test('customer and administrator detail pages render the shared handling information', async () => {
    for (const path of [
        '../../pages/Products/Show.vue',
        '../../pages/Administration/Products/Show.vue',
    ]) {
        const html = await renderToString(
            Vue.createSSRApp(loadComponent(path), {
                product: {
                    id: 1,
                    name: 'Monitor',
                    category: { name: 'Monitors', is_active: true },
                    inventory: null,
                    tags: [],
                    shipping_profile: 'fragile',
                    shipping_handling: profiles[1],
                },
            }),
        );
        assert.ok(html.includes('Shipping handling · Fragile'));
        assert.ok(html.includes(formatCurrency('73.25')));
        assert.equal(
            html.includes('4 days preparation'),
            path.includes('Administration'),
        );
        assert.ok(html.includes('once per delivery order, not per item'));
    }
});
