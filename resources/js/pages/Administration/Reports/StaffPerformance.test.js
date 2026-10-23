import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { pageHeaderModule } from '../../../components/pageHeaderTestHelpers.js';
import { formatCurrency } from '../../../lib/currency.js';
import { formatCalendarDate } from '../../../lib/dateTime.js';
import { findAddressNode } from '../../../lib/deliveryAddressTestHelpers.js';
import { compileSkeletonTestComponent } from '../../../lib/skeletonTestHelpers.js';
import { toUrl } from '../../../lib/utils.js';

function reportHarness(t, overrides = {}) {
    const props = Vue.reactive({
        filters: { from: '2026-10-01', to: '2026-10-22' },
        staff: [
            {
                id: 7,
                name: 'Order Handler',
                orders_count: 1,
                gross_sales: '200.00',
                refunds_paid: '30.00',
                net_sales: '170.00',
            },
        ],
        unattributed_count: 2,
        can_view_all: true,
        selected_handler_id: null,
        selected_handler_name: null,
        transactions: null,
        ...overrides,
    });
    const visits = [];
    const index = ({ query = {} } = {}) => ({
        url:
            '/administration/reports/staff?' +
            new URLSearchParams(
                Object.entries(query).filter(
                    ([, value]) => value !== undefined,
                ),
            ),
        method: 'get',
    });
    const component = compileSkeletonTestComponent(
        readFileSync(
            new URL('./StaffPerformance.vue', import.meta.url),
            'utf8',
        ),
        {
            vue: Vue,
            '@inertiajs/vue3': {
                Head: {
                    props: ['title'],
                    render() {
                        return Vue.h('title', this.title);
                    },
                },
                Link: {
                    props: ['href'],
                    render() {
                        return Vue.h(
                            'a',
                            { ...this.$attrs, href: toUrl(this.href) },
                            this.$slots.default?.(),
                        );
                    },
                },
                router: { get: (...args) => visits.push(args) },
            },
            '@/actions/App/Http/Controllers/Administration/StaffPerformanceController':
                { default: { index } },
            '@/components/PageHeader.vue': pageHeaderModule,
            '@/components/ui/button': {
                Button: {
                    render() {
                        return Vue.h(
                            'button',
                            this.$attrs,
                            this.$slots.default?.(),
                        );
                    },
                },
            },
            '@/lib/currency': { formatCurrency },
            '@/lib/dateTime': { formatCalendarDate },
        },
    );
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const render = scope.run(() => component.setup(props, { expose() {} }));
    let tree;
    const preview = {
        render() {
            tree = render({}, []);
            return tree;
        },
    };
    return {
        props,
        visits,
        breadcrumbs: () => component.layout(props).breadcrumbs,
        tree: () => tree,
        html: () => renderToString(Vue.h(preview)),
    };
}

function selectedReport() {
    return {
        selected_handler_id: 7,
        selected_handler_name: 'Order Handler',
        transactions: {
            data: [
                {
                    id: 11,
                    order_id: 42,
                    order_reference: 'BF-0042',
                    sale_date: '2026-10-20',
                    gross_sales: '200.00',
                    order_url: '/administration/orders/42',
                },
            ],
            total: 21,
            last_page: 2,
            next_page_url:
                '/administration/reports/staff?handler_id=7&from=2026-10-01&to=2026-10-22&sale_page=2',
        },
    };
}

test('team report uses handler labels and hides individual transactions until selection', async (t) => {
    const harness = reportHarness(t);
    const html = await harness.html();

    assert.match(html, /<h1[^>]*>Sales by handler<\/h1>/);
    assert.match(html, /<th[^>]*>Order handler<\/th>/);
    assert.match(
        html,
        /Select an order handler to view individual sales transactions/,
    );
    assert.doesNotMatch(
        html,
        /Staff sales|>Staff<|Attributed sales|Back to team summary/,
    );
    assert.equal(harness.breadcrumbs()[0].title, 'Sales by handler');
    const handlerLink = findAddressNode(harness.tree(), (node) =>
        toUrl(node.props?.href ?? '').includes('handler_id=7'),
    );
    const url = new URL(
        toUrl(handlerLink.props.href),
        'https://battlefront.test',
    );
    assert.deepEqual(Object.fromEntries(url.searchParams), {
        handler_id: '7',
        from: '2026-10-01',
        to: '2026-10-22',
    });
});

test('selected handler report retains transactions, pagination, and a dated team summary link', async (t) => {
    const harness = reportHarness(t, selectedReport());
    const html = await harness.html();

    assert.match(html, /Attributed sales/);
    assert.match(html, /BF-0042/);
    assert.match(html, /Sale pages/);
    assert.doesNotMatch(html, /Select an order handler/);
    const back = findAddressNode(
        harness.tree(),
        (node) =>
            node.children?.default?.()[0]?.children === 'Back to team summary',
    );
    const url = new URL(toUrl(back.props.href), 'https://battlefront.test');
    assert.deepEqual(Object.fromEntries(url.searchParams), {
        from: '2026-10-01',
        to: '2026-10-22',
    });
    assert.match(html, /sale_page=2/);
});

test('staff keep My sales with their own transactions and no team selector', async (t) => {
    const harness = reportHarness(t, {
        ...selectedReport(),
        can_view_all: false,
        unattributed_count: 0,
    });
    const html = await harness.html();

    assert.match(html, /<h1[^>]*>My sales<\/h1>/);
    assert.match(html, /My sales records/);
    assert.match(html, /BF-0042/);
    assert.doesNotMatch(
        html,
        /Sales by handler|Back to team summary|Select an order handler/,
    );
    assert.equal(harness.breadcrumbs()[0].title, 'My sales');
});

for (const selected of [false, true]) {
    test(`date controls preserve ${selected ? 'individual selection' : 'the team summary'}`, async (t) => {
        const harness = reportHarness(t, selected ? selectedReport() : {});
        await harness.html();
        const form = findAddressNode(
            harness.tree(),
            (node) => node.type === 'form',
        );
        form.props.onSubmit({ preventDefault() {} });

        assert.deepEqual(harness.visits[0][1], {
            from: '2026-10-01',
            to: '2026-10-22',
            handler_id: selected ? 7 : undefined,
        });
        const allDates = findAddressNode(harness.tree(), (node) =>
            toUrl(node.props?.href ?? '').includes('from=&to='),
        );
        const url = new URL(
            toUrl(allDates.props.href),
            'https://battlefront.test',
        );
        assert.equal(url.searchParams.get('from'), '');
        assert.equal(url.searchParams.get('to'), '');
        assert.equal(url.searchParams.get('handler_id'), selected ? '7' : null);
        assert.equal(url.searchParams.has('sale_page'), false);
    });
}

test('returning to the team summary preserves an explicit all dates selection', async (t) => {
    const harness = reportHarness(t, {
        ...selectedReport(),
        filters: { from: null, to: null },
    });
    await harness.html();

    const back = findAddressNode(
        harness.tree(),
        (node) =>
            node.children?.default?.()[0]?.children === 'Back to team summary',
    );
    const url = new URL(toUrl(back.props.href), 'https://battlefront.test');
    assert.deepEqual(Object.fromEntries(url.searchParams), {
        from: '',
        to: '',
    });
    const form = findAddressNode(
        harness.tree(),
        (node) => node.type === 'form',
    );
    form.props.onSubmit({ preventDefault() {} });
    assert.deepEqual(harness.visits[0][1], { from: '', to: '', handler_id: 7 });
});
