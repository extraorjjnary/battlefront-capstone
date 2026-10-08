import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';

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
        return Vue.h('a', { href: this.href?.url }, this.$slots.default?.());
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
        inlineTemplate = true,
    } = {},
) {
    const modules = {
        vue: Vue,
        '@inertiajs/vue3': { Head: primitive, Link: link, router },
        '@lucide/vue': {
            Bell: primitive,
            Check: primitive,
            ArrowRight: primitive,
        },
        '@/components/ui/button': { Button: button },
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
                summary: Vue.ref({ unread_count: unread, recent }),
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
            }),
        },
        '@/composables/useNotificationPolling': {
            useNotificationPolling: () => ({
                loading: Vue.ref(false),
                error: Vue.ref(''),
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

    assert.match(html, /Order operations/);
    assert.match(html, /New orders and submitted wallet proofs/);
    assert.match(html, /disabled/);
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
