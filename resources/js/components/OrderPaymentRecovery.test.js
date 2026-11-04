import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';
import { compileSkeletonTestComponent } from '../lib/skeletonTestHelpers.js';

function harness(t, overrides = {}) {
    const calls = [];
    const props = Vue.reactive({
        orderId: 12,
        payment: {
            state: 'checking', label: 'Checking payment', guidance: 'Stock remains reserved.',
            stock_disposition: 'Reserved ? not released', merchant_cutoff_at: '2026-11-01',
            last_checked_at: null, next_check_at: '2026-10-31', can_reconcile: true, history: [],
            ...overrides,
        },
    });
    const form = Vue.reactive({ processing: false, errors: {}, submit: (...args) => calls.push(args) });
    const polling = [];
    const component = compileSkeletonTestComponent(
        readFileSync(new URL('./OrderPaymentRecovery.vue', import.meta.url), 'utf8'),
        {
            vue: Vue,
            '@inertiajs/vue3': {
                useForm: () => form,
                usePoll: () => ({ start: () => polling.push('start'), stop: () => polling.push('stop') }),
            },
            '@/actions/App/Http/Controllers/Administration/OrderPaymentReconciliationController': {
                default: (id) => ({ url: '/orders/' + id + '/payment-reconciliation', method: 'post' }),
            },
            '@/components/ui/button': { Button: { render() { return Vue.h('button', this.$attrs, this.$slots.default?.()); } } },
            '@/components/InputError.vue': { default: { render: () => null } },
            '@/lib/dateTime': { formatDateTime: String },
        },
    );
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const render = scope.run(() => component.setup(props, { expose() {} }));
    let tree;
    return { props, form, calls, polling, tree: () => tree, html: () => renderToString(Vue.h({ render: () => (tree = render({}, [])) })) };
}

function action(node) {
    if (Array.isArray(node)) return node.map(action).find(Boolean);
    if (!node || typeof node !== 'object') return null;
    if (node.props?.onClick) return node;
    return action(node.children?.default?.() ?? node.children);
}

test('recovery renders safe guidance and requests a check without optimistic payment changes', async (t) => {
    const view = harness(t);
    const html = await view.html();
    assert.match(html, /Checking payment/);
    assert.match(html, /Not checked yet/);
    assert.match(html, /Stock remains reserved/);
    action(view.tree()).props.onClick();
    assert.equal(view.calls.length, 1);
    assert.equal(view.calls[0][0].method, 'post');
    assert.equal(view.props.payment.state, 'checking');
});

test('stale permission and busy submissions cannot trigger recovery', async (t) => {
    const view = harness(t);
    await view.html();
    const click = action(view.tree()).props.onClick;
    view.props.payment.can_reconcile = false;
    click();
    view.props.payment.can_reconcile = true;
    view.form.processing = true;
    click();
    assert.equal(view.calls.length, 0);
});

test('terminal recovery stops polling and read-only users have no action', async (t) => {
    const view = harness(t, { can_reconcile: false });
    assert.doesNotMatch(await view.html(), /Reconcile payment/);
    view.props.payment.state = 'paid';
    await Vue.nextTick();
    assert.equal(view.polling.at(-1), 'stop');
});

test('provider references are escaped and history does not expose payment links', async (t) => {
    const view = harness(t, { history: [{ id: 1, outcome: 'Failed attempt', created_at: '2026-10-31', provider_reference: '<script>unsafe</script>' }] });
    const html = await view.html();
    assert.doesNotMatch(html, /<script>/);
    assert.match(html, /&lt;script&gt;/);
    assert.doesNotMatch(html, /checkout.paymongo.com/);
});
