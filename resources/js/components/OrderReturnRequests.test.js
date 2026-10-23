import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { formatCurrency } from '../lib/currency.js';
import { formatDateTime } from '../lib/dateTime.js';

const primitive = {
    render() {
        return Vue.h('div', this.$attrs, this.$slots.default?.());
    },
};
const dialog = {
    props: ['open'],
    render() {
        return this.open ? Vue.h('div', this.$slots.default?.()) : null;
    },
};

function loadComponent(inlineTemplate = false) {
    const { descriptor } = parse(
        readFileSync(
            new URL('./OrderReturnRequests.vue', import.meta.url),
            'utf8',
        ),
    );
    const script = compileScript(descriptor, {
        id: 'return-request-test',
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
            (_, symbol, name) =>
                `const ${symbol} = modules[${JSON.stringify(name)}].default;`,
        )
        .replace('export default', 'return');
    const submissions = [];
    const forms = [];
    const modules = {
        vue: Vue,
        '@inertiajs/vue3': {
            useForm(initial) {
                const form = Vue.reactive({
                    ...initial,
                    errors: {},
                    processing: false,
                    reset() {
                        Object.assign(form, initial);
                    },
                    clearErrors() {
                        form.errors = {};
                    },
                    submit(target, options) {
                        form.processing = true;
                        submissions.push({ target, options });
                    },
                });
                forms.push(form);
                return form;
            },
        },
        '@/actions/App/Http/Controllers/CustomerReturnRequestController': {
            store: (order) => ({
                url: `/orders/${order}/return-requests`,
                method: 'post',
            }),
            withdraw: ({ order, returnRequest }) => ({
                url: `/orders/${order}/return-requests/${returnRequest}/withdraw`,
                method: 'post',
            }),
        },
        '@/components/ui/dialog': new Proxy(
            { Dialog: dialog },
            { get: (target, key) => target[key] ?? primitive },
        ),
        '@/components/ui/button': { Button: primitive },
        '@/components/ui/input': { Input: primitive },
        '@/components/ui/label': { Label: primitive },
        '@/components/ui/spinner': { Spinner: primitive },
        '@/components/InputError.vue': { default: primitive },
        '@/lib/currency': { formatCurrency },
        '@/lib/dateTime': { formatDateTime },
    };
    return {
        component: new Function('modules', code)(modules),
        forms,
        submissions,
    };
}

function order(overrides = {}) {
    return {
        id: 7,
        reference: 'BF-000007',
        can_request_return: true,
        items: [
            {
                id: 8,
                product: { name: 'Purchased monitor' },
                can_request: true,
                remaining_returnable: 2,
            },
        ],
        return_reason_options: { defective: 'Defective', other: 'Other' },
        return_requests: [],
        ...overrides,
    };
}

test('request requires confirmation and submits once with the selected item', () => {
    const { component, forms, submissions } = loadComponent();
    const state = component.setup({ order: order() }, { expose() {} });
    state.requestReturn();
    assert.equal(state.open.value, true);
    Object.assign(forms[0], {
        order_item_id: 8,
        quantity: 2,
        reason_code: 'defective',
    });
    state.submit();
    assert.equal(submissions.length, 0);
    state.confirming.value = true;
    state.submit();
    state.submit();
    assert.equal(submissions.length, 1);
    assert.deepEqual(submissions[0].target, {
        url: '/orders/7/return-requests',
        method: 'post',
    });
    assert.equal(forms[0].quantity, 2);
    submissions[0].options.onError();
    assert.equal(state.confirming.value, false);
    submissions[0].options.onSuccess();
    assert.equal(state.open.value, false);
});

test('withdrawal is limited to eligible requests and guards repeated clicks', () => {
    const { component, forms, submissions } = loadComponent();
    const state = component.setup({ order: order() }, { expose() {} });
    state.withdrawing.value = { id: 3, can_withdraw: false };
    state.submitWithdrawal();
    assert.equal(submissions.length, 0);
    state.withdrawing.value = { id: 3, can_withdraw: true };
    state.submitWithdrawal();
    state.submitWithdrawal();
    assert.equal(forms[1].processing, true);
    assert.equal(submissions.length, 1);
    assert.equal(
        submissions[0].target.url,
        '/orders/7/return-requests/3/withdraw',
    );
    submissions[0].options.onSuccess();
    assert.equal(state.withdrawing.value, null);
});

test('server-ineligible orders cannot open or submit a return request', async () => {
    const plain = loadComponent();
    const state = plain.component.setup(
        { order: order({ can_request_return: false }) },
        { expose() {} },
    );
    state.requestReturn();
    assert.equal(state.open.value, false);
    state.confirming.value = true;
    state.submit();
    assert.equal(plain.submissions.length, 0);
    const { component } = loadComponent(true);
    const html = await renderToString(
        Vue.createSSRApp(component, {
            order: order({ can_request_return: false }),
        }),
    );
    assert.doesNotMatch(html, /Request Return\/Refund/);
});

test('customer progress renders verified refund amounts and escapes customer notes', async () => {
    const { component } = loadComponent(true);
    const html = await renderToString(
        Vue.createSSRApp(component, {
            order: order({
                can_request_return: false,
                return_requests: [
                    {
                        id: 1,
                        product: { name: 'Purchased monitor' },
                        quantity: 1,
                        status: {
                            value: 'refund_processing',
                            label: 'Refund processing',
                        },
                        reason_label: 'Other',
                        reason: '<script>alert("notes")</script>',
                        submitted_at: '2026-10-23T00:00:00Z',
                        can_withdraw: false,
                        feedback: 'Your return has been approved.',
                        refund: {
                            approved_amount: '100.00',
                            verified_paid_amount: '40.00',
                            remaining_amount: '60.00',
                        },
                    },
                ],
            }),
        }),
    );
    assert.match(html, /Refund processing/);
    assert.match(html, /Verified refund payments/);
    assert.match(html, /40\.00/);
    assert.match(html, /60\.00/);
    assert.doesNotMatch(html, /Refund completed|Withdraw request|<script>/);
    assert.match(html, /&lt;script&gt;/);
});
