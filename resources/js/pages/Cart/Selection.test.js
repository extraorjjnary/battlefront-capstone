import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { formatCurrency } from '../../lib/currency.js';

const primitive = {
    render() {
        return Vue.h('div', this.$slots.default?.({ errors: {}, processing: false }));
    },
};
const button = {
    render() {
        return Vue.h('button', this.$attrs, this.$slots.default?.());
    },
};

function loadPage(path, inlineTemplate = false) {
    const { descriptor } = parse(readFileSync(new URL(path, import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'selection-test', inlineTemplate });
    const code = script.content.replace(/import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g, (_, names, moduleName) =>
        `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(moduleName)}];`)
        .replace(/import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g, (_, name, moduleName) =>
            `const ${name} = modules[${JSON.stringify(moduleName)}].default;`)
        .replace('export default', 'return');
    const route = (options) => ({ url: `/checkout?${new URLSearchParams(options?.query ?? {})}`, method: 'get' });
    route.form = () => ({ action: '/orders', method: 'post' });
    const generic = new Proxy({}, { get: (_, key) => key === 'index' || key === 'show' || key === 'store' ? route : primitive });
    const modules = new Proxy({
        vue: Vue,
        '@inertiajs/vue3': { Head: primitive, Link: primitive, Form: primitive, usePage: () => ({ props: { errors: {} } }) },
        '@/components/ui/button': { Button: button },
        '@/lib/currency': { formatCurrency },
    }, { get: (target, key) => target[key] ?? generic });

    return new Function('modules', code)(modules);
}

function cartProps() {
    return {
        cart: {
            items: [
                { id: 11, quantity: 2, product: { name: 'Mouse' }, line_total: '18.66', availability: { status: 'available' } },
                { id: 12, quantity: 1, product: { name: 'Bulky case' }, line_total: '30.00', availability: { status: 'out_of_stock' } },
            ],
            item_count: 2, total_quantity: 3, total: '48.66', conflict_count: 1,
        },
    };
}

test('cart initially selects all items and supports deselect all and individual selection', () => {
    const scope = Vue.effectScope();
    try {
        const page = scope.run(() => loadPage('./Index.vue').setup(Vue.reactive(cartProps()), { expose() {} }));

        assert.deepEqual(page.selectedIds.value, [11, 12]);
        assert.deepEqual(page.selectedSummary.value, { products: 2, units: 3, subtotal: '48.66' });
        assert.equal(page.selectAllState.value, true);
        assert.equal(page.canCheckout.value, false);
        page.selectItem(12, false);
        assert.deepEqual(page.selectedIds.value, [11]);
        assert.deepEqual(page.selectedSummary.value, { products: 1, units: 2, subtotal: '18.66' });
        assert.equal(page.selectAllState.value, 'indeterminate');
        assert.equal(page.canCheckout.value, true);
        page.selectAll(false);
        assert.deepEqual(page.selectedIds.value, []);
        assert.deepEqual(page.selectedSummary.value, { products: 0, units: 0, subtotal: '0.00' });
        assert.equal(page.canCheckout.value, false);
        page.selectAll(true);
        assert.deepEqual(page.selectedIds.value, [11, 12]);
        assert.deepEqual(page.selectedSummary.value, { products: 2, units: 3, subtotal: '48.66' });
    } finally {
        scope.stop();
    }
});

test('cart mutations retain selection and discard removed IDs without selecting new rows', async () => {
    const scope = Vue.effectScope();
    try {
        const props = Vue.reactive(cartProps());
        const page = scope.run(() => loadPage('./Index.vue').setup(props, { expose() {} }));
        page.selectItem(12, false);

        props.cart.items = [{ ...props.cart.items[0], quantity: 1, line_total: '9.33' }, props.cart.items[1]];
        await Vue.nextTick();
        assert.deepEqual(page.selectedIds.value, [11]);
        assert.deepEqual(page.selectedSummary.value, { products: 1, units: 1, subtotal: '9.33' });
        props.cart.items = [props.cart.items[1]];
        await Vue.nextTick();
        assert.deepEqual(page.selectedIds.value, []);
        assert.deepEqual(page.selectedSummary.value, { products: 0, units: 0, subtotal: '0.00' });
        assert.equal(page.canCheckout.value, false);
        props.cart.items = [...props.cart.items, { id: 13, availability: { status: 'available' } }];
        await Vue.nextTick();
        assert.deepEqual(page.selectedIds.value, []);
        assert.equal(page.canCheckout.value, false);
    } finally {
        scope.stop();
    }
});

test('checkout renders only server-reviewed IDs as hidden placement fields', async () => {
    const props = cartProps();
    props.cart.items = [props.cart.items[0]];
    const html = await renderToString(Vue.h(loadPage('../Checkout/Index.vue', true), {
        ...props,
        customer: { name: 'Customer' },
        pickupLocation: { name: 'Sagay' },
        fulfillmentMethods: [{ value: 'pickup', label: 'Pickup' }],
        paymentMethods: [{ value: 'cash', label: 'Cash', available_for: ['pickup'], requires_proof: false }],
        pickupQuote: { product_subtotal: '18.66', delivery_fee: '0.00', total: '18.66' },
        deliveryQuotes: [],
    }));

    assert.match(html, /name="cart_item_ids\[\]" value="11"/);
    assert.doesNotMatch(html, /name="cart_item_ids\[\]" value="12"/);
    assert.ok(html.includes(formatCurrency('18.66')));
});

function findNode(node, predicate) {
    if (predicate(node)) return node;
    if (!Array.isArray(node.children)) return;
    return node.children.map((child) => findNode(child, predicate)).find(Boolean);
}

test('rendered cart summary reacts to selection and disables checkout at zero', async () => {
    const scope = Vue.effectScope();
    try {
        const props = Vue.reactive(cartProps());
        props.cart.items[1].availability.status = 'available';
        const render = scope.run(() => loadPage('./Index.vue', true).setup(props, { expose() {} }));

        async function assertSummary(products, units, subtotal) {
            const html = await renderToString(render({}, []));
            const summary = html.match(/<aside\b[\s\S]*?<\/aside>/)[0];
            assert.match(summary, new RegExp(`Products[\\s\\S]*?<dd[^>]*>\\s*${products}\\s*<`));
            assert.match(summary, new RegExp(`Units[\\s\\S]*?<dd[^>]*>\\s*${units}\\s*<`));
            assert.ok(summary.includes('Selected subtotal'));
            assert.ok(summary.includes(formatCurrency(subtotal)));
            assert.doesNotMatch(summary, /Full cart total/);
            return summary;
        }

        await assertSummary(2, 3, '48.66');
        findNode(render({}, []), (node) => node.props?.item?.id === 12).props['onUpdate:selected'](false);
        await assertSummary(1, 2, '18.66');
        findNode(render({}, []), (node) => node.props?.id === 'select-all-cart-items').props['onUpdate:modelValue'](false);
        const emptySummary = await assertSummary(0, 0, '0.00');
        assert.match(emptySummary, /<button[^>]*disabled[\s\S]*?Select items to checkout/);
        assert.doesNotMatch(emptySummary, /Proceed to checkout/);
        findNode(render({}, []), (node) => node.props?.id === 'select-all-cart-items').props['onUpdate:modelValue'](true);
        await assertSummary(2, 3, '48.66');
    } finally {
        scope.stop();
    }
});

test('selected subtotal aggregates server line totals without calculating product prices', () => {
    const scope = Vue.effectScope();
    try {
        const props = Vue.reactive(cartProps());
        props.cart.items = [
            { id: 11, quantity: 3, line_total: '0.29', product: { price: '999.99' }, availability: { status: 'available' } },
            { id: 12, quantity: 7, line_total: '0.01', product: { discount_price: '500.00' }, availability: { status: 'available' } },
        ];
        const page = scope.run(() => loadPage('./Index.vue').setup(props, { expose() {} }));

        assert.deepEqual(page.selectedSummary.value, { products: 2, units: 10, subtotal: '0.30' });
        page.selectItem(11, false);
        assert.deepEqual(page.selectedSummary.value, { products: 1, units: 7, subtotal: '0.01' });
    } finally {
        scope.stop();
    }
});
