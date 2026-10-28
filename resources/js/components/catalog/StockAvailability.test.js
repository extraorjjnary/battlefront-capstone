import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';

const primitive = {
    render() {
        return Vue.h(
            'div',
            this.$attrs,
            this.$slots.default?.({ errors: {}, processing: false }),
        );
    },
};
const route = () => ({ url: '/products/7', method: 'get' });
route.form = () => ({ action: '/cart/items/11', method: 'post' });
const generic = new Proxy(
    {},
    {
        get: (_, name) =>
            ['show', 'update', 'destroy'].includes(name) ? route : primitive,
    },
);
const modules = new Proxy(
    {
        vue: Vue,
        '@inertiajs/vue3': {
            Form: primitive,
            Link: primitive,
            usePage: () => ({ props: { auth: { can: {} } } }),
        },
        '@/components/ui/input': {
            Input: {
                props: ['defaultValue'],
                render() {
                    return Vue.h('input', {
                        ...this.$attrs,
                        value: this.defaultValue,
                    });
                },
            },
        },
        '@/lib/currency': { formatCurrency: (value) => value },
        '@/lib/catalogReturn': { rememberCatalogVisit() {} },
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
        id: 'inventory-display-test',
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

const stock = loadComponent('./StockAvailability.vue');
modules['@/components/catalog/StockAvailability.vue'] = { default: stock };

test('stock badges show server status and exact positive quantities with safe unavailable states', async () => {
    for (const [inventory, label] of [
        [{ quantity: 15, status: 'in_stock' }, 'In stock · 15 available'],
        [{ quantity: 3, status: 'low_stock' }, 'Low stock · Only 3 left'],
        [{ quantity: 1, status: 'in_stock' }, 'In stock · 1 available'],
        [{ quantity: 0, status: 'out_of_stock' }, 'Out of stock'],
        [{ quantity: 0, status: 'low_stock' }, 'Out of stock'],
        [{ quantity: null, status: 'unavailable' }, 'Stock unavailable'],
        [{ quantity: null, status: 'in_stock' }, 'Stock unavailable'],
        [{ quantity: -1, status: 'in_stock' }, 'Stock unavailable'],
        [{ quantity: 3, status: 'unknown' }, 'Stock unavailable'],
        [null, 'Stock unavailable'],
    ]) {
        const html = (
            await renderToString(Vue.h(stock, { inventory }))
        ).replace(/<!--[\s\S]*?-->/g, '');
        assert.ok(html.replace(/<[^>]+>/g, '').includes(label), html);
        if (
            !inventory ||
            !(inventory.quantity > 0) ||
            inventory.status === 'unknown'
        ) {
            assert.doesNotMatch(html, /\d+ available|Only \d+ left/);
        }
    }
});

test('catalog product cards render the stock quantity badge', async () => {
    const html = await renderToString(
        Vue.h(loadComponent('./ProductCard.vue'), {
            product: {
                id: 7,
                name: 'Graphics card',
                category: { name: 'Graphics cards' },
                inventory: { quantity: 3, status: 'low_stock' },
            },
        }),
    );
    assert.ok(html.replace(/<[^>]+>/g, '').includes('Low stock · Only 3 left'));
});

function cartItem(status = 'available', quantity = 5) {
    return {
        id: 11,
        quantity: 3,
        line_total: '300.00',
        product: { id: 7, name: 'Graphics card', category: 'Graphics cards' },
        availability: { status, available_quantity: quantity },
    };
}

test('cart rows show current stock counts and warnings for unavailable or excessive quantities', async () => {
    const row = loadComponent('../cart/CartItemRow.vue');
    for (const [status, quantity, expected] of [
        ['available', 5, 'In stock · 5 available'],
        [
            'insufficient_stock',
            2,
            'Your cart has 3, but only 2 are currently available.',
        ],
        ['out_of_stock', 0, '0 available'],
        [
            'inventory_unavailable',
            null,
            'Current stock information is unavailable.',
        ],
        ['product_ineligible', 5, 'Product unavailable'],
        ['available', null, 'Current stock information is unavailable.'],
    ]) {
        const html = (
            await renderToString(
                Vue.h(row, { item: cartItem(status, quantity) }),
            )
        ).replace(/<!--[\s\S]*?-->/g, '');
        assert.ok(html.includes(expected), html);
        if (
            ['available', 'insufficient_stock'].includes(status) &&
            quantity > 0
        ) {
            assert.match(
                html,
                new RegExp(
                    `name="quantity" type="number" min="1" max="${quantity}"`,
                ),
            );
        } else {
            assert.match(html, /<input[^>]*name="quantity"[^>]*disabled/);
        }
        if (status === 'product_ineligible' || quantity === null) {
            assert.doesNotMatch(html, /\d+ available|null available/);
        }
    }
});

test('cart quantity guidance updates when inventory falls and recovers', () => {
    const scope = Vue.effectScope();
    try {
        const props = Vue.reactive({ item: cartItem() });
        const row = scope.run(() =>
            loadComponent('../cart/CartItemRow.vue', false).setup(props, {
                expose() {},
                emit() {},
            }),
        );
        assert.equal(row.exceedsStock.value, false);
        props.item.availability = {
            status: 'insufficient_stock',
            available_quantity: 2,
        };
        assert.equal(row.exceedsStock.value, true);
        assert.ok(row.availabilityCopy.value.description.includes('only 2'));
        row.trackEnteredQuantity({ currentTarget: { valueAsNumber: 2 } });
        assert.equal(row.exceedsStock.value, false);
        assert.equal(row.isAtMaximum.value, true);
        props.item.availability = {
            status: 'inventory_unavailable',
            available_quantity: null,
        };
        assert.equal(row.canUpdate.value, false);
        props.item.availability = {
            status: 'out_of_stock',
            available_quantity: 0,
        };
        assert.equal(row.canUpdate.value, false);
        props.item.availability = {
            status: 'available',
            available_quantity: 8,
        };
        assert.equal(row.canUpdate.value, true);
        assert.equal(row.isAtMaximum.value, false);
    } finally {
        scope.stop();
    }
});
