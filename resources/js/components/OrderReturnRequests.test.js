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

function loadComponent(
    inlineTemplate = false,
    file = './OrderReturnRequests.vue',
) {
    const { descriptor } = parse(
        readFileSync(new URL(file, import.meta.url), 'utf8'),
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
        '@/routes/orders/refund-payments/proof': {
            show: ({ order, payment, evidence }) => ({
                url: `/orders/${order}/refund-payments/${payment}/proof/${evidence}`,
            }),
        },
        '@/components/ReturnInstructions.vue': {
            default:
                file === './OrderReturnRequests.vue'
                    ? loadComponent(true, './ReturnInstructions.vue').component
                    : primitive,
        },
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

test('customer progress renders verified refund amounts and public refund explanations while escaping notes', async () => {
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
                        resolution: {
                            type: 'refund',
                            status: 'pending',
                            summary:
                                'Partial refund agreed for the returned component.',
                        },
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
    assert.match(html, /Confirmed refund payments/);
    assert.match(
        html,
        /Refund explanation: Partial refund agreed for the returned component/,
    );
    assert.match(html, /40\.00/);
    assert.match(html, /60\.00/);
    assert.doesNotMatch(html, /Refund completed|Withdraw request|<script>/);
    assert.match(html, /&lt;script&gt;/);
});

test('receipt eligibility labels the proposed policy and keeps manual review available after expiry', async () => {
    const { component } = loadComponent(true);
    const html = await renderToString(
        Vue.createSSRApp(component, {
            order: order({
                return_eligibility: {
                    received_at: '2026-10-23T06:30:00Z',
                    receipt_source: 'delivery',
                    deadline_at: '2026-10-30T06:30:00Z',
                    window_days: 7,
                    window_approved: true,
                    deadline_passed: true,
                    remaining_seconds: 0,
                },
            }),
        }),
    );
    assert.match(html, /Purchase received/);
    assert.match(html, /Changed mind window has expired/);
    assert.match(html, /can still be submitted for manual review/);
    assert.match(html, /2 of/);
});

test('nonmonetary acceptance shows pending repair without claiming fulfillment or payment', async () => {
    const { component } = loadComponent(true);
    const html = await renderToString(
        Vue.createSSRApp(component, {
            order: order({
                return_requests: [
                    {
                        id: 1,
                        product: { name: 'Original hardware' },
                        quantity: 1,
                        status: { value: 'approved', label: 'Return accepted' },
                        return_decision: 'accepted',
                        reason_label: 'Defective',
                        submitted_at: '2026-10-23T06:30:00Z',
                        can_withdraw: false,
                        resolution: {
                            type: 'repair',
                            status: 'pending',
                            summary: 'Repair the original hardware',
                            completed_at: null,
                        },
                        refund: null,
                    },
                ],
            }),
        }),
    );
    assert.match(html, /repair resolution: pending/);
    assert.match(html, /Repair the original hardware/);
    assert.doesNotMatch(html, /Refund completed|Refund approved|Fulfilled/);
});

test('fully verified refunds display completion without asking customers to await verification', async () => {
    const { component } = loadComponent(true);
    const html = await renderToString(
        Vue.createSSRApp(component, {
            order: order({
                return_requests: [
                    {
                        id: 1,
                        product: { name: 'Returned hardware' },
                        quantity: 1,
                        status: {
                            value: 'refunded',
                            label: 'Refund completed',
                        },
                        return_decision: 'accepted',
                        reason_label: 'Defective',
                        submitted_at: '2026-10-23T06:30:00Z',
                        can_withdraw: false,
                        resolution: {
                            type: 'refund',
                            status: 'completed',
                            completed_at: null,
                        },
                        refund: {
                            approved_amount: '100.00',
                            verified_paid_amount: '100.00',
                            remaining_amount: '0.00',
                            payment_recorded: true,
                        },
                    },
                ],
            }),
        }),
    );
    assert.match(html, /Refund completed through confirmed payments/);
    assert.doesNotMatch(
        html,
        /completion requires independent verification|No active refund payment recorded/,
    );
});

test('awaiting receipt shows one compact notice using the server return destination', async () => {
    const { component } = loadComponent(true);
    const instructions = {
        label: 'Return instructions',
        branch: {
            name: 'Battlefront Computer Trading',
            city: 'Sagay City',
            address: 'Verified Sagay return address',
            contact_number: '0938 647 6046',
        },
        message:
            'Pack securely. Contact us before courier shipping (e.g. LBC).',
    };
    const html = await renderToString(
        Vue.createSSRApp(component, {
            order: order({
                return_instructions: instructions,
                return_requests: [
                    {
                        id: 1,
                        product: { name: 'Returned monitor' },
                        quantity: 1,
                        status: {
                            value: 'submitted',
                            label: 'Awaiting receipt',
                        },
                        reason_label: 'Defective',
                        submitted_at: '2026-10-23T06:30:00Z',
                        can_withdraw: true,
                    },
                ],
            }),
        }),
    );
    assert.equal(
        (html.match(/aria-label="Return instructions"/g) ?? []).length,
        1,
    );
    assert.match(html, /Verified Sagay return address/);
    assert.match(html, /0938 647 6046/);
    assert.match(html, /Contact us before courier shipping/);
});

test('return notice uses a safe fallback without inventing a missing address', async () => {
    const { component } = loadComponent(true, './ReturnInstructions.vue');
    const html = await renderToString(
        Vue.createSSRApp(component, {
            instructions: {
                label: 'Return instructions',
                branch: null,
                message: 'Returns require inspection and approval.',
            },
        }),
    );
    assert.match(html, /Contact Battlefront to confirm the return address/);
    assert.doesNotMatch(html, /undefined|null|0938/);
});

test('Staff-assisted refund proof remains visible without any customer request entries', async () => {
    const { component } = loadComponent(true);
    const html = await renderToString(
        Vue.createSSRApp(component, {
            order: order({
                can_request_return: false,
                return_requests: [],
                refund_payments: [
                    {
                        id: 3,
                        amount: '100.00',
                        payment_method: 'gcash',
                        paid_at: '2026-10-23T06:30:00Z',
                        confirmation_method: 'approver',
                        proof: { id: 4 },
                    },
                ],
            }),
        }),
    );
    assert.match(html, /View payment proof/);
    assert.match(html, /\/orders\/7\/refund-payments\/3\/proof\/4/);
    assert.doesNotMatch(html, /Request Return\/Refund|independently verified/);
});
