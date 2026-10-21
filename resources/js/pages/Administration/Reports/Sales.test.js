import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { formatCurrency } from '../../../lib/currency.js';

const primitive = {
    inheritAttrs: false,
    render() {
        return Vue.h('div', this.$slots.default?.());
    },
};
const button = {
    inheritAttrs: false,
    render() {
        return this.$slots.default?.();
    },
};
const generic = new Proxy(
    { default: primitive },
    { get: (target, key) => target[key] ?? primitive },
);

function salesFixture(empty = false) {
    return {
        refund_measures: {
            cutoff: '2026-09-30',
            gross_item_sales: '400.00',
            refund_payments: '0.00',
            gross_less_refunds: '400.00',
            outstanding_at_cutoff: '0.00',
            outstanding_refunds: { data: [], last_page: 1 },
            payments: { data: [], last_page: 1 },
            reversals: { data: [], last_page: 1 },
        },
        sale_transactions: { data: [], last_page: 1 },
        filters: {
            from: '2026-09-01',
            to: '2026-09-30',
            period: 'month',
            product_id: 7,
            category_id: 3,
            page: 2,
        },
        active_filters: {
            product: { id: 7, name: 'Ryzen 7' },
            category: { id: 3, name: 'Components' },
        },
        kpis: {
            total_sales: empty ? 0 : 2,
            total_revenue: '400.00',
            average_order_value: '200.00',
            total_items_sold: 5,
        },
        comparison: {
            from: '2026-08-02',
            to: '2026-08-31',
            total_sales: 1,
            total_revenue: '200.00',
        },
        timeline: { labels: ['Sep 2026'], sales: [2], revenue: [400] },
        top_products: { ids: [7], labels: ['Ryzen 7'], quantities: [5] },
        categories: {
            ids: [3],
            labels: ['Components'],
            revenue: [400],
            rows: [],
        },
        products: {
            data: empty
                ? []
                : [
                      {
                          id: 7,
                          name: 'Ryzen 7',
                          category: 'Components',
                          sales_count: 2,
                          quantity_sold: 5,
                          item_revenue: '400.00',
                      },
                  ],
            total: empty ? 0 : 1,
            current_page: 2,
            last_page: 2,
        },
        recommendation_engagement: { summary: {}, placements: [] },
    };
}

function salesHarness(t, empty = false) {
    const props = Vue.reactive(salesFixture(empty));
    const page = Vue.reactive({
        props: { errors: {}, auth: { can: { exportReports: true } } },
    });
    const visits = [];
    const route = (format) => ({
        url({ query }) {
            const params = new URLSearchParams(
                Object.entries(query).filter(
                    ([, value]) => value !== undefined,
                ),
            );
            return `/administration/reports/sales/${format}?${params}`;
        },
    });
    const modules = new Proxy(
        {
            vue: Vue,
            '@inertiajs/vue3': {
                Head: primitive,
                usePage: () => page,
                router: { visit: (...args) => visits.push(args) },
            },
            'chart.js': new Proxy(
                { Chart: { register() {} } },
                { get: (target, key) => target[key] ?? primitive },
            ),
            '@/actions/App/Http/Controllers/Administration/SalesReportController':
                {
                    default: { index: (options) => options },
                    printReport: route('print'),
                    pdf: route('pdf'),
                    csv: route('csv'),
                    reset: () => ({
                        url: '/administration/reports/sales/reset',
                        method: 'post',
                    }),
                },
            '@/components/ui/button': { Button: button },
            '@/lib/currency': { formatCurrency },
        },
        { get: (target, key) => target[key] ?? generic },
    );
    const { descriptor } = parse(
        readFileSync(new URL('./Sales.vue', import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: 'sales-export-test',
        inlineTemplate: true,
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
    const component = new Function('modules', code)(modules);
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const render = scope.run(() => component.setup(props, { expose() {} }));

    return {
        props,
        page,
        visits,
        tree: () => render({}, []),
        html: () => renderToString(render({}, [])),
    };
}

function findNodes(node, predicate) {
    if (!node || typeof node !== 'object') return [];
    const children = Array.isArray(node.children)
        ? node.children
        : (node.children?.default?.() ?? []);
    return [
        ...(predicate(node) ? [node] : []),
        ...children.flatMap((child) => findNodes(child, predicate)),
    ];
}

function exportLinks(harness) {
    return findNodes(harness.tree(), (node) => node.type === 'a');
}

test('three accessible native export links use all applied filters and omit pagination', async (t) => {
    const harness = salesHarness(t);
    const html = await harness.html();

    assert.match(html, /aria-label="Sales report print and export"/);
    for (const label of ['Print report', 'Export PDF', 'Export CSV']) {
        assert.ok(html.includes(label));
    }
    const links = exportLinks(harness);
    assert.equal(links.length, 3);
    for (const [index, format] of ['print', 'pdf', 'csv'].entries()) {
        const url = new URL(
            links[index].props.href,
            'https://battlefront.test',
        );
        assert.equal(url.pathname, `/administration/reports/sales/${format}`);
        assert.deepEqual(Object.fromEntries(url.searchParams), {
            from: '2026-09-01',
            to: '2026-09-30',
            period: 'month',
            product_id: '7',
            category_id: '3',
        });
        links[index].props.onClick({ preventDefault: t.mock.fn() });
    }
    assert.equal(links[0].props.target, '_blank');
    assert.equal(links[0].props.rel, 'noopener');
    assert.equal(links[1].props.target, undefined);
    assert.equal(harness.visits.length, 0);
    assert.ok(html.includes('opens in a new tab'));
});

test('editing an unapplied date leaves export links bound to the displayed report', (t) => {
    const harness = salesHarness(t);
    const before = exportLinks(harness).map((link) => link.props.href);
    const input = findNodes(
        harness.tree(),
        (node) => node.props?.id === 'sales-report-from',
    )[0];

    input.props['onUpdate:modelValue']('2026-10-01');

    assert.deepEqual(
        exportLinks(harness).map((link) => link.props.href),
        before,
    );
});

test('export links update after applied filters change and omit cleared cross filters', async (t) => {
    const harness = salesHarness(t);
    harness.props.filters = {
        from: '2026-10-01',
        to: '2026-10-12',
        period: 'day',
        product_id: null,
        category_id: null,
    };
    await Vue.nextTick();

    for (const link of exportLinks(harness)) {
        const query = Object.fromEntries(
            new URL(link.props.href, 'https://battlefront.test').searchParams,
        );
        assert.deepEqual(query, {
            from: '2026-10-01',
            to: '2026-10-12',
            period: 'day',
        });
    }
});

test('cross-filter reload refreshes transaction and refund sections with the charts', (t) => {
    const harness = salesHarness(t);
    const product = findNodes(
        harness.tree(),
        (node) => node.type === 'button' && 'aria-pressed' in node.props,
    )[0];

    product.props.onClick();

    assert.deepEqual(harness.visits[0][1].only, [
        'filters',
        'active_filters',
        'kpis',
        'comparison',
        'timeline',
        'top_products',
        'categories',
        'products',
        'sale_transactions',
        'refund_measures',
    ]);
});

function resetControl(harness) {
    return findNodes(
        harness.tree(),
        (node) =>
            node.props?.onClick &&
            findNodes(
                node,
                (child) =>
                    typeof child.children === 'string' &&
                    child.children.trim() === 'Reset to default',
            ).length > 0,
    )[0];
}

for (const interaction of ['date range', 'product filter', 'reset']) {
    test(`export actions block navigation while a ${interaction} update is pending`, (t) => {
        const harness = salesHarness(t);
        if (interaction === 'date range') {
            const form = findNodes(
                harness.tree(),
                (node) => node.type === 'form',
            )[0];
            form.props.onSubmit({ preventDefault() {} });
        } else if (interaction === 'product filter') {
            const product = findNodes(
                harness.tree(),
                (node) =>
                    node.type === 'button' && 'aria-pressed' in node.props,
            )[0];
            product.props.onClick();
        } else {
            resetControl(harness).props.onClick();
        }
        const options = harness.visits[0][1];
        options.onStart();

        assert.equal(resetControl(harness).props.disabled, true);

        for (const link of exportLinks(harness)) {
            assert.equal(link.props['aria-disabled'], true);
            assert.equal(link.props.tabindex, -1);
            const preventDefault = t.mock.fn();
            link.props.onClick({ preventDefault });
            assert.equal(preventDefault.mock.callCount(), 1);
        }
        options.onFinish();
        assert.equal(resetControl(harness).props.disabled, false);
        for (const link of exportLinks(harness)) {
            assert.equal(link.props['aria-disabled'], false);
            assert.equal(link.props.tabindex, undefined);
        }
    });
}

test('empty reports retain usable export controls', (t) => {
    const harness = salesHarness(t, true);

    assert.equal(exportLinks(harness).length, 3);
    assert.ok(
        exportLinks(harness).every(
            (link) => link.props['aria-disabled'] === false,
        ),
    );
});

test('report filters and sales overview come before refund source records', async (t) => {
    const harness = salesHarness(t);
    const html = await harness.html();
    const filterPosition = html.indexOf('Reporting period');
    const summaryPosition = html.indexOf('Sales summary');
    const productsPosition = html.indexOf('Product performance report');
    const refundsPosition = html.indexOf('Sales and refunds by event date');

    assert.ok(filterPosition < summaryPosition);
    assert.ok(summaryPosition < productsPosition);
    assert.ok(productsPosition < refundsPosition);
    assert.match(
        html,
        /<details class="border-border mt-6 border"><summary/,
    );
    assert.ok(!html.includes('<details open'));
});

test('profitability values appear only for administrators who can view profit reports', async (t) => {
    const harness = salesHarness(t);
    harness.page.props.auth.can.viewProfitReports = true;
    harness.props.kpis = {
        ...harness.props.kpis,
        sales_returns: '10.00',
        net_sales: '390.00',
        cost_of_goods_sold: '240.00',
        cost_recovered: '20.00',
        gross_profit: '170.00',
        gross_margin: '43.59',
        cost_data_complete: true,
    };
    harness.props.products.data[0] = {
        ...harness.props.products.data[0],
        cost_of_goods_sold: '240.00',
        gross_profit_before_returns: '160.00',
        gross_margin_before_returns: '40.00',
    };
    const html = await harness.html();

    assert.match(html, /Net product sales/);
    assert.match(html, /Cost of goods sold/);
    assert.match(html, /Gross profit/);
    assert.match(html, /₱170\.00/);
    assert.match(html, /Gross profit before returns/);
    assert.match(html, /₱160\.00/);
});

test('reset posts without current filters and synchronizes the controls and exports from the server response', async (t) => {
    const harness = salesHarness(t);

    resetControl(harness).props.onClick();

    assert.deepEqual(harness.visits[0][0], {
        url: '/administration/reports/sales/reset',
        method: 'post',
    });
    assert.equal(harness.visits[0][1].replace, true);
    harness.props.filters = {
        from: '2026-09-14',
        to: '2026-10-13',
        period: 'day',
        product_id: null,
        category_id: null,
    };
    harness.props.active_filters = { product: null, category: null };
    await Vue.nextTick();

    const input = findNodes(
        harness.tree(),
        (node) => node.props?.id === 'sales-report-from',
    )[0];
    assert.equal(input.props.modelValue, '2026-09-14');
    const html = await harness.html();
    assert.ok(!html.includes('Product: Ryzen 7'));
    assert.ok(
        html.includes('Product revenue excludes third-party delivery charges.'),
    );
    for (const link of exportLinks(harness)) {
        const query = Object.fromEntries(
            new URL(link.props.href, 'https://battlefront.test').searchParams,
        );
        assert.deepEqual(query, {
            from: '2026-09-14',
            to: '2026-10-13',
            period: 'day',
        });
    }
});

test('invalid product and category filters display accessible feedback', async (t) => {
    const harness = salesHarness(t);
    harness.page.props.errors = {
        product_id: 'The selected product is unavailable.',
        category_id: 'The selected category is unavailable.',
    };

    const html = await harness.html();
    assert.match(
        html,
        /role="alert"[^>]*>\s*The selected product is unavailable\./,
    );
    assert.match(
        html,
        /role="alert"[^>]*>\s*The selected category is unavailable\./,
    );
});
