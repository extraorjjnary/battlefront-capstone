import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import * as Vue from 'vue';

function pollingHarness({
    administrator = false,
    visible = true,
    userId = 1,
    queuedUpdates = false,
} = {}) {
    const page = Vue.reactive({
        props: {
            auth: {
                user: userId ? { id: userId } : null,
                can: { accessAdministration: administrator },
            },
            notificationSummary: { unread_count: 0, recent: [] },
            order: { id: 42, status: 'pending' },
        },
    });
    const visibility = Vue.ref(visible ? 'visible' : 'hidden');
    const scope = Vue.effectScope();
    const requests = [];
    const replacements = [];
    const updates = [];
    const listeners = new Map();
    let timer;
    let cancellations = 0;
    const modules = {
        vue: Vue,
        '@inertiajs/vue3': {
            usePage: () => page,
            useHttp: () => ({
                get: (url, options) => {
                    const meta = {
                        user_id: page.props.auth.user.id,
                        audience: page.props.auth.can.accessAdministration
                            ? 'administrator'
                            : 'customer',
                    };
                    return new Promise((resolve, reject) => {
                        requests.push({
                            url,
                            options,
                            resolve: (response) =>
                                resolve({ meta, ...response }),
                            reject,
                        });
                    });
                },
                cancel: () => {
                    cancellations++;
                },
            }),
            router: {
                on: (name, callback) => {
                    listeners.set(name, callback);
                    return () => listeners.delete(name);
                },
                replaceProp: (name, update) => {
                    replacements.push(name);
                    const apply = () => {
                        page.props = {
                            ...page.props,
                            [name]: update(page.props[name], page.props),
                        };
                    };
                    if (queuedUpdates) updates.push(apply);
                    else apply();
                },
            },
        },
        '@vueuse/core': {
            useDocumentVisibility: () => visibility,
            useIntervalFn: (callback, interval, options) => {
                timer = { callback, interval, options, active: false };
                const pause = () => {
                    timer.active = false;
                };
                Vue.onScopeDispose(pause);
                return {
                    pause,
                    resume: () => {
                        timer.active = true;
                    },
                };
            },
        },
    };
    const code = readFileSync(
        new URL('./useNotificationPolling.js', import.meta.url),
        'utf8',
    )
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, moduleName) =>
                `const { ${names} } = modules[${JSON.stringify(moduleName)}];`,
        )
        .replace('export function', 'function');
    const useNotificationPolling = new Function(
        'modules',
        code + '\nreturn useNotificationPolling;',
    )(modules);
    const polling = scope.run(() =>
        useNotificationPolling({
            isAdministrator: Vue.computed(
                () => page.props.auth.can.accessAdministration,
            ),
            summaryUrl: () =>
                page.props.auth.can.accessAdministration
                    ? '/administration/notifications/summary'
                    : '/notifications/summary',
        }),
    );
    return {
        page,
        scope,
        visibility,
        requests,
        replacements,
        updates,
        listeners,
        timer,
        polling,
        cancellations: () => cancellations,
    };
}

test('polling updates only notificationSummary every 30 seconds without changing page data', async () => {
    const harness = pollingHarness();
    try {
        assert.equal(harness.timer.interval, 30000);
        assert.equal(harness.timer.active, true);
        const order = harness.page.props.order;
        const request = harness.timer.callback();
        assert.equal(harness.requests[0].url, '/notifications/summary');
        harness.requests[0].resolve({ data: { unread_count: 2, recent: [] } });
        await request;
        assert.deepEqual(harness.replacements, ['notificationSummary']);
        assert.equal(harness.page.props.notificationSummary.unread_count, 2);
        assert.equal(harness.page.props.order, order);
        assert.equal(harness.polling.loading.value, false);
    } finally {
        harness.scope.stop();
    }
});

test('slow requests suppress overlapping poll ticks and bell refreshes', async () => {
    const harness = pollingHarness({ administrator: true });
    try {
        const first = harness.polling.refresh();
        await harness.timer.callback();
        await harness.polling.refresh();
        assert.equal(harness.requests.length, 1);
        assert.equal(
            harness.requests[0].url,
            '/administration/notifications/summary',
        );
        harness.requests[0].resolve({ data: { unread_count: 1, recent: [] } });
        await first;
        const next = harness.timer.callback();
        assert.equal(harness.requests.length, 2);
        harness.requests[1].resolve({ data: { unread_count: 1, recent: [] } });
        await next;
    } finally {
        harness.scope.stop();
    }
});

test('hidden tabs pause requests and resume after becoming visible', async () => {
    const harness = pollingHarness({ visible: false });
    try {
        assert.equal(harness.timer.active, false);
        await harness.polling.refresh();
        assert.equal(harness.requests.length, 0);
        harness.visibility.value = 'visible';
        await Vue.nextTick();
        assert.equal(harness.timer.active, true);
        harness.visibility.value = 'hidden';
        await Vue.nextTick();
        assert.equal(harness.timer.active, false);
    } finally {
        harness.scope.stop();
    }
});

test('failed polling preserves the previous summary and remains eligible for the next interval', async () => {
    const harness = pollingHarness();
    try {
        const first = harness.polling.refresh();
        harness.requests[0].reject(new Error('Network unavailable'));
        await first;
        assert.equal(harness.page.props.notificationSummary.unread_count, 0);
        assert.match(harness.polling.error.value, /could not be refreshed/);
        assert.equal(harness.polling.loading.value, false);
        assert.equal(harness.timer.active, true);
    } finally {
        harness.scope.stop();
    }
});

test('401 and 403 stop further polling until the authenticated identity changes', async () => {
    for (const status of [401, 403]) {
        const harness = pollingHarness();
        try {
            const first = harness.polling.refresh();
            harness.requests[0].options.onHttpException({ status });
            harness.requests[0].reject(new Error('Session unavailable'));
            await first;
            assert.equal(harness.timer.active, false);
            await harness.polling.refresh();
            assert.equal(harness.requests.length, 1);
            harness.page.props.auth.user = { id: 2 };
            await Vue.nextTick();
            assert.equal(harness.timer.active, true);
        } finally {
            harness.scope.stop();
        }
    }
});

test('account changes cancel requests and discard a previous accounts late response', async () => {
    const harness = pollingHarness();
    try {
        const first = harness.polling.refresh();
        harness.page.props.auth.user = { id: 2 };
        await Vue.nextTick();
        assert.equal(harness.cancellations(), 1);
        harness.requests[0].resolve({ data: { unread_count: 99, recent: [] } });
        await first;
        assert.equal(harness.replacements.length, 0);
        assert.equal(harness.page.props.notificationSummary.unread_count, 0);
    } finally {
        harness.scope.stop();
    }
});

test('unmount cancels pending work and prevents late notification updates', async () => {
    const harness = pollingHarness();
    const first = harness.polling.refresh();
    harness.scope.stop();
    harness.requests[0].resolve({ data: { unread_count: 99, recent: [] } });
    await first;
    assert.equal(harness.cancellations(), 1);
    assert.equal(harness.timer.active, false);
    assert.equal(harness.replacements.length, 0);
    assert.equal(harness.listeners.size, 0);
});

test('navigation and read mutations invalidate pending summaries instead of overwriting fresh read state', async () => {
    const harness = pollingHarness();
    try {
        const first = harness.polling.refresh();
        harness.listeners.get('start')();
        await harness.polling.refresh();
        assert.equal(harness.requests.length, 1);
        harness.page.props.notificationSummary = {
            unread_count: 0,
            recent: [],
        };
        harness.requests[0].resolve({ data: { unread_count: 99, recent: [] } });
        await first;
        assert.equal(harness.replacements.length, 0);
        harness.listeners.get('finish')();
        const second = harness.polling.refresh();
        assert.equal(harness.requests.length, 2);
        harness.requests[1].resolve({ data: { unread_count: 0, recent: [] } });
        await second;
    } finally {
        harness.scope.stop();
    }
});

test('unauthenticated pages cannot poll notification history', async () => {
    const harness = pollingHarness({ userId: null });
    try {
        assert.equal(harness.timer.active, false);
        await harness.polling.refresh();
        assert.equal(harness.requests.length, 0);
    } finally {
        harness.scope.stop();
    }
});

test('a different server session cannot replace the current tabs notification summary', async () => {
    const harness = pollingHarness();
    try {
        const first = harness.polling.refresh();
        harness.requests[0].resolve({
            data: { unread_count: 99, recent: [{ title: 'Another account' }] },
            meta: { user_id: 2, audience: 'customer' },
        });
        await first;
        assert.equal(harness.page.props.notificationSummary.unread_count, 0);
        assert.deepEqual(harness.page.props.notificationSummary.recent, []);
        assert.equal(harness.timer.active, false);
        assert.match(harness.polling.error.value, /session changed/);
    } finally {
        harness.scope.stop();
    }
});

test('queued prop updates cannot overwrite fresh summary after leaving and returning to an account', async () => {
    for (const serverUserId of [1, 2]) {
        const harness = pollingHarness({ queuedUpdates: true });
        try {
            const first = harness.polling.refresh();
            harness.requests[0].resolve({
                data: { unread_count: 99, recent: [] },
                meta: { user_id: serverUserId, audience: 'customer' },
            });
            await first;
            harness.page.props.auth.user = { id: 2 };
            await Vue.nextTick();
            harness.page.props.auth.user = { id: 1 };
            await Vue.nextTick();
            harness.page.props.notificationSummary = {
                unread_count: 3,
                recent: [],
            };
            harness.updates[0]();
            assert.equal(
                harness.page.props.notificationSummary.unread_count,
                3,
            );
        } finally {
            harness.scope.stop();
        }
    }
});
