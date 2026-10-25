import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { formatCurrency } from '../lib/currency.js';

const primitive = {
    render() {
        return Vue.h('div', this.$attrs, this.$slots.default?.());
    },
};

function loadPanel(inlineTemplate = false) {
    const { descriptor } = parse(
        readFileSync(
            new URL('./OrderCancellationPanel.vue', import.meta.url),
            'utf8',
        ),
    );
    const script = compileScript(descriptor, {
        id: 'cancellation-panel',
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
    const submissions = [];
    const modules = new Proxy(
        {
            vue: Vue,
            '@/lib/currency': { formatCurrency },
            '@inertiajs/vue3': {
                useForm(initial) {
                    const form = Vue.reactive({
                        ...initial,
                        errors: {},
                        processing: false,
                        reset(...fields) {
                            for (const field of fields)
                                form[field] = initial[field];
                        },
                        submit(target, options) {
                            form.processing = true;
                            submissions.push({
                                target,
                                options,
                                data: { ...form },
                            });
                        },
                    });
                    return form;
                },
            },
            '@/routes/administration/orders/cancellation-request': {
                update: (id) => ({
                    url: `/administration/orders/${id}/cancellation-request`,
                    method: 'patch',
                }),
            },
            '@/routes/administration/customer-refunds/payments': {
                store: (id) => ({
                    url: `/administration/customer-refunds/${id}/payments`,
                    method: 'post',
                }),
            },
            '@/routes/orders/refund-payments/proof': {
                show: {
                    url: ({ order, payment, evidence }) =>
                        `/orders/${order}/refund-payments/${payment}/proof/${evidence}`,
                },
            },
            '@/routes/administration/evidence': {
                show: { url: (id) => `/administration/evidence/${id}` },
            },
        },
        {
            get: (target, key) =>
                target[key] ?? new Proxy({}, { get: () => primitive }),
        },
    );
    return { component: new Function('modules', code)(modules), submissions };
}

function panelProps(overrides = {}) {
    return {
        orderId: 7,
        paymentMethod: 'gcash',
        cancellation: {
            status: { value: 'requested', label: 'Cancellation requested' },
            can_review: false,
            can_record_payment: false,
            refund: null,
            ...overrides,
        },
    };
}

test('review requires server permission and account confirmation and submits to the review endpoint once', () => {
    const scope = Vue.effectScope();
    try {
        const loaded = loadPanel();
        const props = Vue.reactive(panelProps({ can_review: true }));
        const page = scope.run(() =>
            loaded.component.setup(props, { expose() {} }),
        );
        page.decide('approve');
        assert.equal(loaded.submissions.length, 0);
        page.review.account_checked = true;
        page.review.amount_received = '50.25';
        page.review.decision_note = 'Actual account checked.';
        page.decide('approve');
        page.decide('reject');
        assert.equal(loaded.submissions.length, 1);
        assert.equal(loaded.submissions[0].target.method, 'patch');
        assert.equal(loaded.submissions[0].data.amount_received, '50.25');
        page.review.processing = false;
        props.cancellation.can_review = false;
        page.decide('reject');
        assert.equal(loaded.submissions.length, 1);
    } finally {
        scope.stop();
    }
});

test('refund recording requires actual transfer confirmation and refreshes the submission token after partial payment', async () => {
    const scope = Vue.effectScope();
    try {
        const loaded = loadPanel();
        const props = Vue.reactive(
            panelProps({
                can_record_payment: true,
                payment_submission_key: 'first-token',
                refund: { id: 9, unrecorded_amount: '100.00' },
            }),
        );
        const page = scope.run(() =>
            loaded.component.setup(props, { expose() {} }),
        );
        page.submitPayment();
        assert.equal(loaded.submissions.length, 0);
        page.payment.payment_confirmed = true;
        page.payment.amount = '40.25';
        page.submitPayment();
        page.submitPayment();
        assert.equal(loaded.submissions.length, 1);
        assert.equal(
            loaded.submissions[0].target.url,
            '/administration/customer-refunds/9/payments',
        );
        props.cancellation.refund.unrecorded_amount = '59.75';
        props.cancellation.payment_submission_key = 'second-token';
        await Vue.nextTick();
        loaded.submissions[0].options.onSuccess();
        assert.equal(page.payment.amount, '59.75');
        assert.equal(page.payment.submission_key, 'second-token');
        assert.equal(page.payment.payment_confirmed, false);
    } finally {
        scope.stop();
    }
});

test('customer and admin proof links use their own protected routes and customer views expose no review controls', async () => {
    for (const administration of [false, true]) {
        const loaded = loadPanel(true);
        const props = {
            ...panelProps({
                status: { value: 'refunded', label: 'Refund completed' },
                refund: {
                    id: 9,
                    approved_amount: '100.00',
                    confirmed_paid_amount: '100.00',
                    outstanding_amount: '0.00',
                    payments: [
                        {
                            id: 11,
                            amount: '100.00',
                            payment_method: 'gcash',
                            proof: { id: 13 },
                        },
                    ],
                },
            }),
            administration,
        };
        const html = await renderToString(Vue.h(loaded.component, props));
        assert.match(html, /Refund completed/);
        assert.match(
            html,
            administration
                ? /href="\/administration\/evidence\/13"/
                : /href="\/orders\/7\/refund-payments\/11\/proof\/13"/,
        );
        assert.doesNotMatch(
            html,
            /Approve cancellation|Confirm refund payment/,
        );
    }
});
