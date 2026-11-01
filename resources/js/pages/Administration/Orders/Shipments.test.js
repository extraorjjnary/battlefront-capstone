import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { compileSkeletonTestComponent } from '../../../lib/skeletonTestHelpers.js';

function shipmentHarness(t, overrides = {}) {
    const primitive = {
        render() {
            return Vue.h('div', this.$attrs, this.$slots.default?.());
        },
    };
    const button = {
        render() {
            return Vue.h('button', this.$attrs, this.$slots.default?.());
        },
    };
    const input = {
        render() {
            return Vue.h('input', this.$attrs);
        },
    };
    const link = {
        render() {
            return Vue.h('a', this.$attrs, this.$slots.default?.());
        },
    };
    const visits = [];
    const orderRoutes = {
        index: (options) => ({ url: '/orders', query: options?.query ?? {} }),
        show: (id) => ({ url: '/orders/' + id }),
    };
    const props = Vue.reactive({
        shipments: {
            total: 1,
            current_page: 1,
            last_page: 1,
            data: [
                {
                    id: 7,
                    order_id: 3,
                    reference: 'BF-000003',
                    recipient: 'Customer',
                    destination: 'City of Makati',
                    status: {
                        value: 'ready_for_dispatch',
                        label: 'Ready for dispatch',
                    },
                    package_count: null,
                    weight: null,
                    length: null,
                    width: null,
                    height: null,
                    tracking: '000123',
                    receipt: null,
                    delivery_fee: '200.00',
                    carrier_cost: '0.00',
                    reconciliation: 'Confirmed',
                    handed_at: null,
                },
            ],
        },
        filters: {
            q: null,
            shipment_status: 'active',
            reconciliation: 'all',
            from: null,
            to: null,
        },
        status_options: [],
        can_export: true,
        document_limit: 500,
        ...overrides,
    });
    const source = readFileSync(
        new URL('./Shipments.vue', import.meta.url),
        'utf8',
    );
    const modules = {
        vue: Vue,
        '@inertiajs/vue3': {
            Head: { render: () => null },
            Link: link,
            router: { visit: (...args) => visits.push(args) },
            usePage: () => ({ props: { errors: {} } }),
        },
        '@/actions/App/Http/Controllers/Administration/OrderController': {
            default: orderRoutes,
        },
        '@/actions/App/Http/Controllers/Administration/OrderShipmentExportController':
            {
                default: {
                    url: (format, options) =>
                        '/shipments/' +
                        format +
                        '?' +
                        new URLSearchParams(
                            Object.entries(options.query).filter(
                                ([, value]) => value != null,
                            ),
                        ),
                },
            },
        '@/composables/useDebouncedSearch': {
            useDebouncedSearch: ({ initialSearch }) => ({
                search: Vue.ref(initialSearch ?? ''),
                isSearching: Vue.ref(false),
                cancelPendingSearch() {},
            }),
        },
        '@/lib/currency': { formatCurrency: (value) => 'PHP ' + value },
        '@/lib/dateTime': { formatDateTime: () => 'Nov 1, 2026' },
        '@/components/ui/button': { Button: button },
        '@/components/ui/input': { Input: input },
    };
    for (const [, name] of source.matchAll(/from ['"]([^'"]+)['"]/g)) {
        if (!modules[name])
            modules[name] = new Proxy({}, { get: () => primitive });
    }
    const component = compileSkeletonTestComponent(source, modules);
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const render = scope.run(() => component.setup(props, { expose() {} }));
    let tree;
    return {
        props,
        visits,
        tree: () => tree,
        html: () =>
            renderToString(Vue.h({ render: () => (tree = render({}, [])) })),
    };
}

function findNode(node, matches) {
    if (Array.isArray(node))
        return node.map((child) => findNode(child, matches)).find(Boolean);
    if (!node || typeof node !== 'object') return null;
    if (matches(node)) return node;
    return findNode(node.children?.default?.() ?? node.children, matches);
}

test('shipment records show missing measurements separately from confirmed zero carrier cost', async (t) => {
    const view = shipmentHarness(t);
    const html = await view.html();
    assert.match(html, /BF-000003/);
    assert.match(html, /Not recorded/);
    assert.match(html, /Carrier: PHP 0.00/);
    assert.match(html, /Confirmed/);
    assert.match(html, /View order/);
    assert.doesNotMatch(html, /Save package|Save carrier|Mark Handed/);
});

test('shipment document links use applied filters without pagination', async (t) => {
    const view = shipmentHarness(t, {
        filters: {
            q: '000123',
            shipment_status: 'in_transit',
            reconciliation: 'confirmed',
            from: '2026-11-01',
            to: '2026-11-02',
        },
    });
    const html = await view.html();
    assert.match(
        html,
        /shipments\/csv\?q=000123&amp;shipment_status=in_transit/,
    );
    assert.match(html, /from=2026-11-01/);
    assert.doesNotMatch(html, /shipments\/csv[^" ]*page=/);
    assert.match(html, /target="_blank"/);
});

test('shipment exports respect permissions empty results and PDF print size limits', async (t) => {
    const view = shipmentHarness(t, { can_export: false });
    assert.doesNotMatch(await view.html(), /Export CSV|Download PDF/);
    view.props.can_export = true;
    view.props.shipments.total = 501;
    let html = await view.html();
    assert.match(html, /shipments\/csv/);
    assert.doesNotMatch(html, /href="\/shipments\/(pdf|print)/);
    assert.match(html, /up to 500 shipments/);
    view.props.shipments.total = 0;
    view.props.shipments.data = [];
    html = await view.html();
    assert.match(html, /No matching shipments/);
    assert.doesNotMatch(html, /href="\/shipments\//);
});

test('applying and clearing shipment filters resets pagination and never carries order filters', async (t) => {
    const view = shipmentHarness(t);
    await view.html();
    findNode(view.tree(), (node) => node.props?.id === 'shipment-from').props[
        'onUpdate:modelValue'
    ]('2026-11-01');
    await view.html();
    findNode(view.tree(), (node) => node.type === 'form').props.onSubmit({
        preventDefault() {},
    });
    assert.equal(view.visits.length, 1);
    assert.equal(view.visits[0][0].query.view, 'shipments');
    assert.equal(view.visits[0][0].query.from, '2026-11-01');
    assert.equal(view.visits[0][0].query.page, undefined);
    assert.equal(view.visits[0][0].query.payment_status, undefined);
    const clear = findNode(
        view.tree(),
        (node) =>
            node.props?.onClick && node.props.onClick.name === 'clearFilters',
    );
    clear.props.onClick();
    assert.deepEqual(view.visits.at(-1)[0].query, { view: 'shipments' });
});

test('Orders and Shipments navigation uses independent URLs and marks the selected section', async () => {
    const source = readFileSync(
        new URL('../../../components/OrdersDirectoryTabs.vue', import.meta.url),
        'utf8',
    );
    const component = compileSkeletonTestComponent(source, {
        vue: Vue,
        '@inertiajs/vue3': {
            Link: {
                render() {
                    return Vue.h('a', this.$attrs, this.$slots.default?.());
                },
            },
        },
        '@/actions/App/Http/Controllers/Administration/OrderController': {
            default: {
                index: (options) =>
                    '/orders' +
                    (options?.query
                        ? '?' + new URLSearchParams(options.query)
                        : ''),
            },
        },
    });
    const html = await renderToString(
        Vue.h(component, { active: 'shipments' }),
    );
    assert.match(html, /href="\/orders"/);
    assert.match(html, /href="\/orders\?view=shipments" aria-current="page"/);
    assert.equal(html.match(/aria-current="page"/g)?.length, 1);
});
