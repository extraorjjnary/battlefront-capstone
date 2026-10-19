import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Reka from 'reka-ui';
import * as Vue from 'vue';
import { formatCurrency } from '../../lib/currency.js';

const primitive = {
    render() {
        return Vue.h('div', this.$slots.default?.());
    },
};
const generic = new Proxy(
    { default: primitive },
    { get: (target, key) => target[key] ?? primitive },
);
const modules = new Proxy(
    {
        vue: Vue,
        'reka-ui': Reka,
        '@/lib/currency': { formatCurrency },
    },
    { get: (target, key) => target[key] ?? generic },
);

function loadComponent(path, inlineTemplate = true) {
    const { descriptor } = parse(
        readFileSync(new URL(path, import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: path,
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

const collapsible = {
    Collapsible: loadComponent('../ui/collapsible/Collapsible.vue'),
    CollapsibleTrigger: loadComponent(
        '../ui/collapsible/CollapsibleTrigger.vue',
    ),
    CollapsibleContent: loadComponent(
        '../ui/collapsible/CollapsibleContent.vue',
    ),
};
modules['@/components/ui/collapsible'] = collapsible;
const engagementComponent = loadComponent('./RecommendationEngagement.vue');

function engagementFixture() {
    return {
        summary: {
            impressions: 1234,
            clicks: 23,
            dismissals: 4,
            wrong_reports: 5,
        },
        placements: [
            {
                placement: 'catalog',
                label: 'Product catalog',
                impressions: 1200,
                clicks: 20,
                dismissals: 4,
                wrong_reports: 0,
            },
            {
                placement: 'home',
                label: 'Home page',
                impressions: 34,
                clicks: 3,
                dismissals: 0,
                wrong_reports: 5,
            },
        ],
        reasons: [
            {
                reason_code: 'matched_recent_searches',
                label: 'Matched recent searches',
                impressions: 1200,
                clicks: 20,
                dismissals: 4,
                wrong_reports: 0,
            },
            {
                reason_code: 'featured_fallback',
                label: 'Featured fallback',
                impressions: 34,
                clicks: 3,
                dismissals: 0,
                wrong_reports: 5,
            },
        ],
        top_clicked_products: [
            {
                product_id: 7,
                product_name: 'Atlas Graphics Card <Pro>',
                impressions: 1200,
                clicks: 20,
            },
            {
                product_id: 8,
                product_name: 'Mechanical Keyboard',
                impressions: 34,
                clicks: 3,
            },
        ],
    };
}

function findNode(node, predicate) {
    if (predicate(node)) return node;
    if (!Array.isArray(node.children)) return;
    return node.children
        .map((child) => findNode(child, predicate))
        .find(Boolean);
}

function engagementHarness(t, engagement = engagementFixture()) {
    const props = Vue.reactive({
        engagement,
        rangeLabel: 'Sep 1, 2026 – Sep 30, 2026',
    });
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const render = scope.run(() =>
        engagementComponent.setup(props, { expose() {} }),
    );

    return {
        props,
        html: () => renderToString(render({}, [])),
        setOpen(open) {
            findNode(
                render({}, []),
                (node) => node.type === collapsible.Collapsible,
            ).props['onUpdate:open'](open);
        },
    };
}

function tableRows(html, heading) {
    const table = html.match(
        new RegExp(
            `<table[^>]*aria-labelledby="${heading}"[^>]*>([\\s\\S]*?)</table>`,
        ),
    );
    assert.ok(table, `Table for ${heading} should be rendered`);
    const body = table[1].match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/)[1];

    return [...body.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)].map((row) =>
        [...row[1].matchAll(/<(?:td|th)\b[^>]*>([\s\S]*?)<\/(?:td|th)>/g)].map(
            (cell) => cell[1].trim(),
        ),
    );
}

test('the real details trigger opens and closes content and links to its content ID', async (t) => {
    class HostElement extends EventTarget {
        constructor(tag) {
            super();
            this.tag = tag;
            this.nodeName = tag;
            this.children = [];
            this.props = {};
            this.style = {};
        }

        hasAttribute(name) {
            return name in this.props;
        }

        getBoundingClientRect() {
            return { width: 0, height: 0 };
        }
    }

    for (const [name, value] of Object.entries({
        Element: HostElement,
        getComputedStyle: () => ({ animationName: 'none' }),
        requestAnimationFrame: (callback) => callback(),
    })) {
        const original = Object.getOwnPropertyDescriptor(globalThis, name);
        Object.defineProperty(globalThis, name, { configurable: true, value });
        t.after(() => {
            if (original) Object.defineProperty(globalThis, name, original);
            else delete globalThis[name];
        });
    }

    const renderer = Vue.createRenderer({
        createElement: (tag) => new HostElement(tag),
        createText: (text) => Object.assign(new HostElement('#text'), { text }),
        createComment: () => new HostElement('#comment'),
        setText: (node, text) => {
            node.text = text;
        },
        setElementText: (node, text) => {
            node.children = [];
            node.text = text;
        },
        patchProp: (node, key, _previous, value) => {
            node.props[key] = value;
        },
        parentNode: (node) => node.parent,
        nextSibling: (node) =>
            node.parent?.children[node.parent.children.indexOf(node) + 1],
        insert(node, parent, anchor) {
            if (node.parent)
                node.parent.children.splice(
                    node.parent.children.indexOf(node),
                    1,
                );
            const index = anchor
                ? parent.children.indexOf(anchor)
                : parent.children.length;
            parent.children.splice(index, 0, node);
            node.parent = parent;
        },
        remove(node) {
            node.parent.children.splice(node.parent.children.indexOf(node), 1);
            node.parent = null;
        },
    });
    const root = new HostElement('root');
    const app = renderer.createApp(engagementComponent, {
        engagement: engagementFixture(),
        rangeLabel: 'Sep 1, 2026 – Sep 30, 2026',
    });
    app.mount(root);
    t.after(() => app.unmount());
    const trigger = findNode(root, (node) => node.tag === 'button');
    const content = findNode(
        root,
        (node) => node.props['data-slot'] === 'collapsible-content',
    );
    assert.equal(trigger.props.type, 'button');
    assert.equal(trigger.props['aria-expanded'], false);
    assert.equal(content.props.hidden, '');

    trigger.props.onClick();
    for (let tick = 0; tick < 4; tick++) await Vue.nextTick();
    assert.equal(trigger.props['aria-expanded'], true);
    assert.equal(trigger.props['aria-controls'], content.props.id);
    assert.equal(content.props.hidden, undefined);
    assert.ok(
        findNode(
            content,
            (node) => node.props.id === 'engagement-products-heading',
        ),
    );

    trigger.props.onClick();
    for (let tick = 0; tick < 4; tick++) await Vue.nextTick();
    assert.equal(trigger.props['aria-expanded'], false);
    assert.equal(content.props.hidden, '');
    assert.equal(
        findNode(content, (node) => node.props.tabindex === '0'),
        undefined,
    );
});

test('engagement summary renders all four backend counts and the reporting context', async (t) => {
    const html = await engagementHarness(t).html();
    const summary = html.match(/<dl\b[\s\S]*?<\/dl>/)[0];

    assert.deepEqual(
        [...summary.matchAll(/<dt[^>]*>([^<]+)<\/dt>/g)].map((match) =>
            match[1].trim(),
        ),
        ['Impressions', 'Product clicks', 'Hidden', 'Reported as a problem'],
    );
    assert.deepEqual(
        [...summary.matchAll(/<dd[^>]*>([^<]+)<\/dd>/g)].map((match) =>
            match[1].trim(),
        ),
        ['1,234', '23', '4', '5'],
    );
    assert.ok(html.includes('Sep 1, 2026 – Sep 30, 2026'));
    assert.ok(
        html.includes(
            'count separately; history is retained for up to 90 days.',
        ),
    );
    assert.doesNotMatch(html, /Metric definitions|recommendation accuracy/i);
});

test('placement table renders only supplied rows with all four event counts', async (t) => {
    const html = await engagementHarness(t).html();

    assert.deepEqual(tableRows(html, 'engagement-placements-heading'), [
        ['Product catalog', '1,200', '20', '4', '0'],
        ['Home page', '34', '3', '0', '5'],
    ]);
    assert.doesNotMatch(html, /Customer dashboard|Product page|>Cart</);
});

test('details start collapsed and opening then closing updates accessibility and visibility', async (t) => {
    const h = engagementHarness(t);
    const closed = await h.html();
    assert.match(closed, /<button[^>]*type="button"[^>]*aria-expanded="false"/);
    assert.ok(closed.includes('View engagement details'));
    assert.doesNotMatch(
        closed,
        /By recommendation reason|Most clicked products/,
    );

    h.setOpen(true);
    const opened = await h.html();
    assert.match(opened, /<button[^>]*aria-expanded="true"/);
    assert.ok(opened.includes('Hide engagement details'));
    assert.match(opened, /data-slot="collapsible-content"[^>]*id="[^"]+"/);
    assert.ok(opened.includes('By recommendation reason'));
    assert.ok(opened.includes('Most clicked products'));
    for (const definition of [
        'Client-reported cards at least half visible for one second',
        'Clicks from a recommendation card',
        'Recommendations shoppers chose to hide',
        'Anonymous product suggestion reports',
    ]) {
        assert.ok(opened.includes(definition));
    }

    h.setOpen(false);
    const collapsedAgain = await h.html();
    assert.match(collapsedAgain, /aria-expanded="false"/);
    assert.doesNotMatch(
        collapsedAgain,
        /Metric definitions|By recommendation reason|Most clicked products/,
    );
});

test('opened details preserve reason counts and product names impressions clicks and ranking', async (t) => {
    const h = engagementHarness(t);
    h.setOpen(true);
    const html = await h.html();

    assert.deepEqual(tableRows(html, 'engagement-reasons-heading'), [
        ['Matched recent searches', '1,200', '20', '4', '0'],
        ['Featured fallback', '34', '3', '0', '5'],
    ]);
    assert.deepEqual(tableRows(html, 'engagement-products-heading'), [
        ['Atlas Graphics Card &lt;Pro&gt;', '1,200', '20'],
        ['Mechanical Keyboard', '34', '3'],
    ]);
});

test('zero activity retains all KPI labels and exposes definitions and table empty states', async (t) => {
    const h = engagementHarness(t, {
        summary: { impressions: 0, clicks: 0, dismissals: 0, wrong_reports: 0 },
        placements: [],
        reasons: [],
        top_clicked_products: [],
    });
    const closed = await h.html();
    assert.match(
        closed,
        /role="status"[^>]*>\s*No recommendation activity in this range\./,
    );
    assert.equal([...closed.matchAll(/<dd[^>]*>0<\/dd>/g)].length, 4);
    assert.deepEqual(tableRows(closed, 'engagement-placements-heading'), [
        ['No placement activity in this range.'],
    ]);

    h.setOpen(true);
    const opened = await h.html();
    assert.ok(opened.includes('Metric definitions'));
    assert.deepEqual(tableRows(opened, 'engagement-reasons-heading'), [
        ['No recommendation reason activity in this range.'],
    ]);
    assert.deepEqual(tableRows(opened, 'engagement-products-heading'), [
        ['No product clicks in this range.'],
    ]);
});

test('activity without clicks or reason rows renders independent empty states', async (t) => {
    const engagement = engagementFixture();
    engagement.summary.clicks = 0;
    engagement.reasons = [];
    engagement.top_clicked_products = [];
    const h = engagementHarness(t, engagement);
    h.setOpen(true);
    const html = await h.html();

    assert.doesNotMatch(html, /No recommendation activity in this range/);
    assert.ok(
        html.includes('No recommendation reason activity in this range.'),
    );
    assert.ok(html.includes('No product clicks in this range.'));
    assert.ok(html.includes('Product catalog'));
});

test('tables have named focusable scroll regions and semantic headers with responsive widths', async (t) => {
    const h = engagementHarness(t);
    h.setOpen(true);
    const html = await h.html();

    assert.match(html, /<dl class="[^"]*grid-cols-2[^"]*md:grid-cols-4/);
    assert.equal(
        [
            ...html.matchAll(
                /role="region" aria-labelledby="engagement-[^"]+-heading" tabindex="0"/g,
            ),
        ].length,
        3,
    );
    assert.equal(
        [...html.matchAll(/<table class="[^"]*w-full[^"]*sm:min-w-0/g)].length,
        3,
    );
    assert.equal([...html.matchAll(/scope="col"/g)].length, 13);
    assert.equal([...html.matchAll(/scope="row"/g)].length, 6);
    assert.match(html, /<button[^>]*focus-visible:ring-2/);
    assert.match(html, /aria-hidden="true"/);
});

function salesProps() {
    return {
        refund_measures: {
            cutoff: '2026-09-30',
            gross_item_sales: '500.00',
            refund_payments: '0.00',
            gross_less_refunds: '500.00',
            outstanding_at_cutoff: '0.00',
            outstanding_refunds: { data: [], last_page: 1 },
            payments: { data: [], last_page: 1 },
        },
        sale_transactions: { data: [], last_page: 1 },
        filters: { from: '2026-09-01', to: '2026-09-30', period: 'day' },
        active_filters: { product: null, category: null },
        kpis: {
            total_sales: 2,
            total_revenue: '500.00',
            average_order_value: '250.00',
            total_items_sold: 6,
        },
        comparison: {
            from: '2026-08-02',
            to: '2026-08-31',
            total_sales: 2,
            total_revenue: '500.00',
        },
        timeline: { labels: ['Sep 1'], revenue: [500], sales: [2] },
        top_products: {
            labels: ['Mechanical Keyboard'],
            quantities: [6],
            revenue: [500],
            ids: [8],
        },
        categories: {
            labels: ['Peripherals'],
            revenue: [500],
            ids: [3],
            rows: [
                {
                    id: 3,
                    name: 'Peripherals',
                    sales_count: 2,
                    quantity_sold: 6,
                    item_revenue: '500.00',
                },
            ],
        },
        products: {
            data: [
                {
                    id: 8,
                    name: 'Mechanical Keyboard',
                    category: 'Peripherals',
                    sales_count: 2,
                    quantity_sold: 6,
                    item_revenue: '500.00',
                },
            ],
            total: 1,
            current_page: 1,
            last_page: 1,
        },
        recommendation_engagement: engagementFixture(),
    };
}

modules['@inertiajs/vue3'] = {
    Head: primitive,
    usePage: () => ({ props: { errors: {}, auth: { can: { exportReports: true, viewOrders: true } } } }),
    router: { visit() {} },
};
modules['chart.js'] = new Proxy(
    { Chart: { register() {} } },
    { get: (target, key) => target[key] ?? {} },
);
modules['vue-chartjs'] = Object.fromEntries(
    ['Bar', 'Doughnut', 'Line'].map((name) => [
        name,
        {
            props: ['data'],
            render() {
                return Vue.h('figure', {
                    'data-chart': name,
                    'data-values': JSON.stringify(this.data),
                });
            },
        },
    ]),
);
modules['@/components/recommendations/RecommendationEngagement.vue'] = {
    default: engagementComponent,
};
modules['@/actions/App/Http/Controllers/Administration/SalesReportController'] =
    {
        default: {
            index: (options) => ({
                url: '/administration/reports/sales',
                method: 'get',
                ...options,
            }),
        },
        printReport: { url: () => '/administration/reports/sales/print' },
        pdf: { url: () => '/administration/reports/sales/pdf' },
        csv: { url: () => '/administration/reports/sales/csv' },
    };
const salesComponent = loadComponent(
    '../../pages/Administration/Reports/Sales.vue',
);

test('Sales Reports renders engagement after sales analytics with the existing sales values', async () => {
    const html = await renderToString(Vue.h(salesComponent, salesProps()));
    const salesEnd = html.indexOf('id="category-report-heading"');
    const engagementStart = html.indexOf(
        'id="recommendation-engagement-heading"',
    );

    assert.ok(salesEnd >= 0 && engagementStart > salesEnd);
    for (const heading of [
        'Sales summary',
        'Revenue and sales over time',
        'Top-selling products',
        'Sales by current category',
    ]) {
        assert.ok(html.includes(heading));
    }
    assert.ok(html.includes(formatCurrency('500.00')));
    assert.ok(html.includes(formatCurrency('250.00')));
    assert.match(html, /data-chart="Line"[^>]*&quot;data&quot;:\[500\]/);
    assert.match(html, /data-chart="Bar"/);
    assert.match(html, /data-chart="Doughnut"/);
    assert.deepEqual(tableRows(html, 'engagement-placements-heading'), [
        ['Product catalog', '1,200', '20', '4', '0'],
        ['Home page', '34', '3', '0', '5'],
    ]);
});

test('Sales Reports passes refreshed date ranges and engagement counts to the section', async (t) => {
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const props = Vue.reactive(salesProps());
    const render = scope.run(() =>
        salesComponent.setup(props, { expose() {} }),
    );
    const initial = await renderToString(render({}, []));
    assert.ok(initial.includes('Events for Sep 1, 2026 – Sep 30, 2026.'));

    props.filters = { from: '2026-10-01', to: '2026-10-11', period: 'day' };
    props.recommendation_engagement = {
        ...engagementFixture(),
        summary: {
            impressions: 88,
            clicks: 7,
            dismissals: 6,
            wrong_reports: 5,
        },
    };
    await Vue.nextTick();
    const refreshed = await renderToString(render({}, []));

    assert.ok(refreshed.includes('Events for Oct 1, 2026 – Oct 11, 2026.'));
    const engagementHtml = refreshed.slice(
        refreshed.indexOf('id="recommendation-engagement-heading"'),
    );
    assert.match(engagementHtml, /<dd[^>]*>88<\/dd>/);
    assert.doesNotMatch(engagementHtml, /Sep 1, 2026 – Sep 30, 2026/);
});
