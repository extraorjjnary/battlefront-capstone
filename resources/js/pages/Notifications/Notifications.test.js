import { pageHeaderModule } from '../../components/pageHeaderTestHelpers.js';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { loadSkeletonComponent } from '../../lib/skeletonTestHelpers.js';
import { formatDateTime } from '../../lib/dateTime.js';

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
const link = {
    props: ['href'],
    render() {
        return Vue.h(
            'a',
            {
                href:
                    typeof this.href === 'string' ? this.href : this.href?.url,
            },
            this.$slots.default?.(),
        );
    },
};

function notificationFixture(overrides = {}) {
    return {
        id: 'notice-one',
        title: 'Payment verified',
        body: 'Open your Battlefront order.',
        occurred_at: '2026-10-08T10:00:00Z',
        is_read: false,
        order: { id: 1, reference: 'BF-000001' },
        ...overrides,
    };
}

function loadNotificationComponent(
    path,
    {
        administrator = false,
        recent = [],
        unread = 0,
        router = {},
        refreshSummary = () => {},
        loading = Vue.ref(false),
        error = Vue.ref(''),
        summary = Vue.ref({ unread_count: unread, recent }),
        inlineTemplate = true,
    } = {},
) {
    const modules = {
        '@/components/PageHeader.vue': pageHeaderModule,
        vue: Vue,
        '@/lib/dateTime': { formatDateTime },
        '@inertiajs/vue3': { Head: primitive, Link: link, router },
        '@lucide/vue': {
            Bell: primitive,
            Check: primitive,
            ArrowRight: primitive,
        },
        '@/components/ui/button': { Button: button },
        '@/components/ListSkeleton.vue': {
            default: loadSkeletonComponent('ListSkeleton.vue'),
        },
        '@/components/CatalogPagination.vue': { default: primitive },
        '@/components/ui/dropdown-menu': {
            DropdownMenu: primitive,
            DropdownMenuContent: primitive,
            DropdownMenuItem: primitive,
            DropdownMenuLabel: primitive,
            DropdownMenuSeparator: primitive,
            DropdownMenuTrigger: primitive,
        },
        '@/composables/useNotificationNavigation': {
            useNotificationNavigation: () => ({
                isAdministrator: Vue.ref(administrator),
                summary,
                index: () => ({
                    url: administrator
                        ? '/administration/notifications'
                        : '/notifications',
                }),
                read: (id) => ({
                    url: `${administrator ? '/administration' : ''}/notifications/${id}/read`,
                }),
                readAll: () => ({
                    url: `${administrator ? '/administration' : ''}/notifications/read-all`,
                }),
                order: (id) => ({
                    url: `${administrator ? '/administration' : ''}/orders/${id}`,
                }),
                destination: (notification) =>
                    notification.subject
                        ? {
                              url: `/administration/${notification.subject.type === 'stock_adjustment' ? 'inventory-adjustments' : 'customer-returns'}?case=${notification.subject.id}`,
                          }
                        : notification.order
                          ? {
                                url: `${administrator ? '/administration' : ''}/orders/${notification.order.id}`,
                            }
                          : null,
            }),
        },
        '@/composables/useNotificationPolling': {
            useNotificationPolling: () => ({
                loading,
                error,
                refresh: refreshSummary,
            }),
        },
    };
    const { descriptor } = parse(
        readFileSync(new URL(path, import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: 'notifications-test',
        inlineTemplate,
    });
    const code = script.content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, name) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(name)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, moduleName) =>
                `const ${name} = modules[${JSON.stringify(moduleName)}].default;`,
        )
        .replace('export default', 'return');

    return new Function('modules', code)(modules);
}

test('customer history renders unread controls owned order navigation and escapes notification text', async () => {
    const component = loadNotificationComponent('./Index.vue', { unread: 1 });
    const html = await renderToString(
        Vue.h(component, {
            notifications: {
                data: [
                    notificationFixture({ title: '<script>bad()</script>' }),
                    notificationFixture({
                        id: 'read',
                        is_read: true,
                        order: null,
                    }),
                ],
                current_page: 1,
                last_page: 1,
            },
        }),
    );

    assert.match(html, /1 unread/);
    assert.match(html, /Mark all as read/);
    assert.match(html, /href="\/orders\/1"/);
    assert.match(html, /&lt;script&gt;/);
    assert.doesNotMatch(html, /<script>bad/);
    assert.equal((html.match(/>Mark as read</g) ?? []).length, 1);
});

test('administrator empty history explains operational events and disables read all', async () => {
    const component = loadNotificationComponent('./Index.vue', {
        administrator: true,
    });
    const html = await renderToString(
        Vue.h(component, {
            notifications: { data: [], current_page: 1, last_page: 1 },
        }),
    );

    assert.match(html, /<h1[^>]*>Notifications<\/h1>/);
    assert.match(html, /0 unread/);
    assert.match(html, /Orders, requests, decisions, and operational updates/);
    assert.match(html, /disabled/);
});

test('owner review alerts link to recent decisions with quantities and amounts', async () => {
    const component = loadNotificationComponent('./Index.vue', {
        administrator: true,
    });
    const html = await renderToString(
        Vue.h(component, {
            notifications: {
                data: [
                    notificationFixture({
                        title: 'Repeated approvals need review',
                        order: null,
                        decisions: [
                            {
                                action: 'customer_return.accepted',
                                subject_id: 7,
                                quantity: 2,
                                approved_amount: '150.00',
                                web_url:
                                    '/administration/customer-returns?case=7',
                            },
                        ],
                    }),
                ],
                current_page: 1,
                last_page: 1,
            },
        }),
    );

    assert.match(html, /href="\/administration\/customer-returns\?case=7"/);
    assert.match(html, /2 units/);
    assert.match(html, /₱150.00 approved/);
});

test('history read actions expose loading failure and completion state', () => {
    let request;
    const component = loadNotificationComponent('./Index.vue', {
        administrator: true,
        inlineTemplate: false,
        router: {
            patch: (...args) => {
                request = args;
            },
        },
    });
    const page = component.setup({ notifications: {} }, { expose() {} });

    page.markRead('notice-one');
    assert.equal(
        request[0].url,
        '/administration/notifications/notice-one/read',
    );
    request[2].onStart();
    assert.equal(page.busy.value, true);
    request[2].onError();
    assert.match(page.error.value, /could not be saved/);
    request[2].onFinish();
    assert.equal(page.busy.value, false);
    page.markRead();
    assert.equal(request[0].url, '/administration/notifications/read-all');
});

test('bell labels unread count and exposes recent entries and history link', async () => {
    const component = loadNotificationComponent(
        '../../components/NotificationBell.vue',
        { unread: 101, recent: [notificationFixture()] },
    );
    const html = await renderToString(Vue.h(component));

    assert.match(html, /Notifications, 101 unread/);
    assert.match(html, /99\+/);
    assert.match(html, /Payment verified/);
    assert.match(html, /href="\/notifications"/);
});

test('bell refreshes the summary and marks a notification read before opening its order', () => {
    let readRequest;
    let refreshes = 0;
    const visits = [];
    const component = loadNotificationComponent(
        '../../components/NotificationBell.vue',
        {
            inlineTemplate: false,
            refreshSummary: () => {
                refreshes++;
            },
            router: {
                patch: (...args) => {
                    readRequest = args;
                },
                visit: (route) => {
                    visits.push(route.url);
                },
            },
        },
    );
    const bell = component.setup({}, { expose() {} });

    bell.refresh(false);
    assert.equal(refreshes, 0);
    bell.refresh(true);
    assert.equal(refreshes, 1);
    bell.openNotification(notificationFixture());
    assert.equal(readRequest[0].url, '/notifications/notice-one/read');
    assert.equal(visits.length, 0);
    readRequest[2].onSuccess();
    assert.deepEqual(visits, ['/orders/1']);
});

test('bell shows skeletons only while an empty summary is loading and resolves to real or empty content', async () => {
    const loading = Vue.ref(true);
    const summary = Vue.ref({ unread_count: 0, recent: [] });
    const component = loadNotificationComponent(
        '../../components/NotificationBell.vue',
        { loading, summary },
    );
    const html = () => renderToString(Vue.h(component));

    let rendered = await html();
    assert.match(rendered, /role="status"[\s\S]*Loading notifications/);
    assert.match(rendered, /data-slot="list-skeleton"/);
    assert.doesNotMatch(rendered, /No order updates yet/);

    summary.value = { unread_count: 1, recent: [notificationFixture()] };
    rendered = await html();
    assert.match(rendered, /Payment verified/);
    assert.doesNotMatch(rendered, /list-skeleton|Loading notifications/);

    loading.value = false;
    summary.value = { unread_count: 0, recent: [] };
    rendered = await html();
    assert.match(rendered, /No order updates yet/);
    assert.doesNotMatch(rendered, /list-skeleton/);
});

test('bell retains cached entries during refresh and displays errors without a stuck skeleton', async () => {
    const loading = Vue.ref(true);
    const error = Vue.ref('');
    const component = loadNotificationComponent(
        '../../components/NotificationBell.vue',
        {
            loading,
            error,
            recent: [notificationFixture()],
            unread: 1,
        },
    );

    let html = await renderToString(Vue.h(component));
    assert.match(html, /Payment verified/);
    assert.doesNotMatch(html, /list-skeleton/);

    error.value = 'Unable to refresh notifications.';
    loading.value = false;
    html = await renderToString(Vue.h(component));
    assert.match(html, /role="alert"[\s\S]*Unable to refresh notifications/);
    assert.match(html, /Payment verified/);
    assert.doesNotMatch(html, /list-skeleton/);
});

for (const administrator of [false, true]) {
    test(`${administrator ? 'administrator' : 'customer'} bell marks all read using shared server state and prevents duplicate submissions`, async () => {
        const summary = Vue.ref({
            unread_count: 2,
            recent: [notificationFixture()],
        });
        const requests = [];
        const options = {
            administrator,
            summary,
            router: { patch: (...args) => requests.push(args) },
        };
        const component = loadNotificationComponent(
            '../../components/NotificationBell.vue',
            options,
        );
        const render = () => renderToString(Vue.h(component));
        const exposed = loadNotificationComponent(
            '../../components/NotificationBell.vue',
            { ...options, inlineTemplate: false },
        );
        const bell = exposed.setup({}, { expose() {} });

        assert.match(await render(), /Mark all as read/);
        bell.markAllRead();
        bell.markAllRead();
        assert.equal(requests.length, 1);
        assert.equal(
            requests[0][0].url,
            `${administrator ? '/administration' : ''}/notifications/read-all`,
        );
        assert.equal(requests[0][2].preserveScroll, true);
        assert.equal(requests[0][2].preserveState, true);
        assert.equal(bell.markingAllRead.value, true);

        summary.value = {
            unread_count: 0,
            recent: [notificationFixture({ is_read: true })],
        };
        requests[0][2].onFinish();
        assert.equal(bell.markingAllRead.value, false);
        const html = await render();
        assert.match(html, /Notifications, 0 unread/);
        assert.match(html, /Read/);
        assert.doesNotMatch(html, /Mark all as read|>Unread</);
        assert.match(html, /View all notifications/);
        bell.markAllRead();
        assert.equal(requests.length, 1);
    });
}

test('failed dropdown read-all retains unread state and allows retry', () => {
    const requests = [];
    const component = loadNotificationComponent(
        '../../components/NotificationBell.vue',
        {
            unread: 1,
            recent: [notificationFixture()],
            inlineTemplate: false,
            router: { patch: (...args) => requests.push(args) },
        },
    );
    const bell = component.setup({}, { expose() {} });

    bell.markAllRead();
    requests[0][2].onError();
    requests[0][2].onFinish();

    assert.equal(bell.summary.value.unread_count, 1);
    assert.equal(bell.summary.value.recent[0].is_read, false);
    assert.match(bell.readError.value, /could not be saved/);
    assert.equal(bell.markingAllRead.value, false);
    bell.markAllRead();
    assert.equal(requests.length, 2);
    assert.equal(bell.readError.value, '');
});

test('operational history and dropdown entries open their case after individual read', async () => {
    const entry = notificationFixture({
        order: null,
        subject: { type: 'stock_adjustment', id: 7 },
    });
    let readRequest;
    let destination;
    const options = {
        administrator: true,
        recent: [entry],
        router: {
            patch: (...args) => {
                readRequest = args;
            },
            visit: (route) => {
                destination = route.url;
            },
        },
    };
    const component = loadNotificationComponent(
        '../../components/NotificationBell.vue',
        { ...options, inlineTemplate: false },
    );
    const bell = component.setup({}, { expose() {} });

    bell.openNotification(entry);
    assert.equal(destination, undefined);
    readRequest[2].onSuccess();
    assert.equal(destination, '/administration/inventory-adjustments?case=7');

    const history = loadNotificationComponent('./Index.vue', options);
    const html = await renderToString(
        Vue.h(history, {
            notifications: { data: [entry], current_page: 1, last_page: 1 },
        }),
    );
    assert.match(
        html,
        /href="\/administration\/inventory-adjustments\?case=7"/,
    );
    assert.match(html, /View case #7/);
});
