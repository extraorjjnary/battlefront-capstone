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
    const activityTarget = new EventTarget();
    const activityListeners = new Set();
    const timeouts = new Map();
    let now = 0;
    let nextTimeout = 0;
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
            useEventListener: (events, callback, options) => {
                for (const event of events) {
                    activityTarget.addEventListener(event, callback, options);
                    activityListeners.add(event);
                    Vue.onScopeDispose(() => {
                        activityTarget.removeEventListener(
                            event,
                            callback,
                            options,
                        );
                        activityListeners.delete(event);
                    });
                }
            },
            useIntervalFn: (callback, interval, options) => {
                const isActive = Vue.ref(false);
                timer = {
                    callback,
                    interval,
                    options,
                    get active() {
                        return isActive.value;
                    },
                };
                const pause = () => {
                    isActive.value = false;
                };
                Vue.onScopeDispose(pause);
                return {
                    pause,
                    isActive,
                    resume: () => {
                        isActive.value = true;
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
        'Date',
        'setTimeout',
        'clearTimeout',
        code + '\nreturn useNotificationPolling;',
    )(
        modules,
        { now: () => now },
        (callback, delay) => {
            const id = ++nextTimeout;
            timeouts.set(id, { callback, at: now + delay });
            return id;
        },
        (id) => timeouts.delete(id),
    );
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
        activityListeners,
        timeouts,
        activity: (event = 'pointerdown') =>
            activityTarget.dispatchEvent(new Event(event)),
        advanceTime: (milliseconds) => {
            const target = now + milliseconds;
            while (timeouts.size) {
                const [id, timeout] = [...timeouts].sort(
                    (a, b) => a[1].at - b[1].at,
                )[0];
                if (timeout.at > target) break;
                now = timeout.at;
                timeouts.delete(id);
                timeout.callback();
            }
            now = target;
        },
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
        harness.advanceTime(600000);
        harness.visibility.value = 'visible';
        assert.equal(harness.timer.active, true);
        assert.equal(harness.requests.length, 1);
        harness.activity();
        await harness.timer.callback();
        assert.equal(harness.requests.length, 1);
        harness.requests[0].resolve({ data: { unread_count: 2, recent: [] } });
        await Vue.nextTick();
        assert.equal(harness.page.props.notificationSummary.unread_count, 2);
        harness.visibility.value = 'hidden';
        assert.equal(harness.timer.active, false);
        assert.equal(harness.timeouts.size, 0);
        harness.advanceTime(120000);
        harness.activity();
        await harness.timer.callback();
        assert.equal(harness.requests.length, 1);
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

test('visible tabs stop polling at two minutes without user activity', async () => {
    const harness = pollingHarness();
    try {
        harness.advanceTime(119999);
        assert.equal(harness.timer.active, true);
        const request = harness.timer.callback();
        harness.requests[0].resolve({ data: { unread_count: 1, recent: [] } });
        await request;

        harness.advanceTime(1);
        assert.equal(harness.timer.active, false);
        assert.equal(harness.timeouts.size, 0);
        await harness.timer.callback();
        harness.advanceTime(600000);
        assert.equal(harness.requests.length, 1);
    } finally {
        harness.scope.stop();
    }
});

test('activity extends the idle deadline without requesting notifications or duplicating timers', async () => {
    const harness = pollingHarness();
    try {
        harness.advanceTime(90000);
        for (let i = 0; i < 100; i++) harness.activity('pointermove');
        harness.advanceTime(119999);
        assert.equal(harness.timer.active, true);
        assert.equal(harness.timeouts.size, 1);
        assert.equal(harness.requests.length, 0);
        assert.equal(harness.timer.interval, 30000);

        harness.advanceTime(1);
        assert.equal(harness.timer.active, false);
    } finally {
        harness.scope.stop();
    }
});

test('pointer, keyboard, touch, scrolling and focus activity resume idle polling with one immediate refresh', async () => {
    for (const event of [
        'pointerdown',
        'pointermove',
        'keydown',
        'touchstart',
        'wheel',
        'scroll',
        'focus',
    ]) {
        const harness = pollingHarness();
        try {
            harness.advanceTime(120000);
            harness.activity(event);
            assert.equal(harness.timer.active, true, event);
            assert.equal(harness.requests.length, 1, event);
            assert.equal(harness.timeouts.size, 1, event);
            harness.activity(event);
            await harness.timer.callback();
            assert.equal(harness.requests.length, 1, event);
            harness.requests[0].resolve({
                data: { unread_count: 3, recent: [] },
            });
            await Vue.nextTick();
            assert.equal(
                harness.page.props.notificationSummary.unread_count,
                3,
                event,
            );
        } finally {
            harness.scope.stop();
        }
    }
});

test('opening the bell refreshes immediately and restarts polling after idle', async () => {
    const harness = pollingHarness();
    try {
        harness.advanceTime(120000);
        const request = harness.polling.refresh();
        assert.equal(harness.timer.active, true);
        assert.equal(harness.requests.length, 1);
        harness.requests[0].resolve({ data: { unread_count: 4, recent: [] } });
        await request;
        assert.equal(harness.page.props.notificationSummary.unread_count, 4);
        assert.equal(harness.timeouts.size, 1);
    } finally {
        harness.scope.stop();
    }
});

test('returning to a hidden tab during navigation defers refresh until navigation finishes', async () => {
    const harness = pollingHarness({ visible: false });
    try {
        harness.listeners.get('start')();
        harness.visibility.value = 'visible';
        harness.activity();
        await harness.polling.refresh();
        assert.equal(harness.timer.active, false);
        assert.equal(harness.requests.length, 0);

        harness.listeners.get('finish')();
        assert.equal(harness.timer.active, true);
        assert.equal(harness.requests.length, 1);
        harness.requests[0].resolve({ data: { unread_count: 1, recent: [] } });
        await Vue.nextTick();
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
            harness.advanceTime(120000);
            harness.activity();
            harness.visibility.value = 'hidden';
            harness.visibility.value = 'visible';
            await harness.polling.refresh();
            assert.equal(harness.requests.length, 1);
            assert.equal(harness.timeouts.size, 0);
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
    assert.equal(harness.activityListeners.size, 0);
    assert.equal(harness.timeouts.size, 0);
    harness.advanceTime(120000);
    harness.activity();
    harness.visibility.value = 'hidden';
    harness.visibility.value = 'visible';
    await harness.polling.refresh();
    assert.equal(harness.requests.length, 1);
    assert.equal(harness.timer.active, false);
});

test('navigation and read mutations invalidate pending summaries instead of overwriting fresh read state', async () => {
    const harness = pollingHarness();
    try {
        const first = harness.polling.refresh();
        harness.listeners.get('start')();
        assert.equal(harness.timer.active, false);
        assert.equal(harness.timeouts.size, 0);
        harness.activity();
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
        assert.equal(harness.timer.active, true);
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
        harness.activity();
        harness.visibility.value = 'hidden';
        harness.visibility.value = 'visible';
        await harness.polling.refresh();
        assert.equal(harness.requests.length, 0);
        assert.equal(harness.timeouts.size, 0);
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
