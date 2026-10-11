import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { formatCurrency } from '../../lib/currency.js';
import { orderStatusBadgeClass } from '../../lib/orderStatus.js';

const primitive = {
    render() {
        return Vue.h('div', this.$attrs, this.$slots.default?.());
    },
};
const button = {
    render() {
        return Vue.h('button', this.$attrs, this.$slots.default?.());
    },
};
const dialog = {
    props: ['open'],
    render() {
        return this.open ? Vue.h('div', this.$slots.default?.()) : null;
    },
};
const inputError = {
    props: ['message'],
    render() {
        return this.message ? Vue.h('p', this.message) : null;
    },
};

function loadPage(path, inlineTemplate = false) {
    const { descriptor } = parse(
        readFileSync(new URL(path, import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: 'cancellation-test',
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
    const forms = [];
    const route = (id) => ({ url: `/orders/${id}/cancel`, method: 'post' });
    route.index = () => ({ url: '/orders', method: 'get' });
    route.update = route;
    const generic = new Proxy(
        {},
        {
            get: (_, key) =>
                ['default', 'store', 'index'].includes(key) ? route : primitive,
        },
    );
    const modules = new Proxy(
        {
            vue: Vue,
            '@inertiajs/vue3': {
                Head: primitive,
                Link: primitive,
                useForm(initial) {
                    const form = Vue.reactive({
                        ...initial,
                        errors: {},
                        processing: false,
                        clearErrors() {
                            form.errors = {};
                        },
                        reset() {
                            Object.assign(form, initial);
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
            '@/components/ui/button': { Button: button },
            '@/components/ui/dialog': new Proxy(
                { Dialog: dialog },
                { get: (target, key) => target[key] ?? primitive },
            ),
            '@/components/InputError.vue': { default: inputError },
            '@/lib/currency': { formatCurrency },
            '@/lib/orderStatus': { orderStatusBadgeClass },
        },
        { get: (target, key) => target[key] ?? generic },
    );
    return {
        component: new Function('modules', code)(modules),
        submissions,
        forms,
    };
}

function orderProps(overrides = {}) {
    return {
        isConfirmation: false,
        order: {
            id: 7,
            reference: 'BF-000007',
            created_at: '2026-10-12T08:00:00Z',
            can_cancel: true,
            status: { value: 'pending', label: 'Pending' },
            recipient: { name: 'Customer', contact_number: '09171234567' },
            customer: { name: 'Customer', email: 'customer@example.com' },
            fulfillment: { value: 'pickup', label: 'Pickup' },
            payment: {
                method: { value: 'cash', label: 'Cash', requires_proof: false },
                status: { value: 'pending', label: 'Pending' },
                notice: 'Payment will be handled when you collect your order.',
                rejection_reasons: [],
                can_resubmit_proof: false,
            },
            allowed_status_transitions: [
                { value: 'cancelled', label: 'Cancelled' },
            ],
            cancellation_blocked_reason: null,
            shipment: null,
            shipment_actions: null,
            product_subtotal: null,
            delivery_quote: null,
            items: [],
            item_count: 0,
            total_quantity: 0,
            total: '20.30',
            ...overrides,
        },
    };
}

test('customer detail shows cancellation only for server-eligible orders', async () => {
    const eligible = loadPage('./Show.vue', true);
    const html = await renderToString(Vue.h(eligible.component, orderProps()));
    assert.match(html, /Cancel order/);
    assert.doesNotMatch(html, /Confirm cancellation/);
    const ineligible = loadPage('./Show.vue', true);
    const hidden = await renderToString(
        Vue.h(ineligible.component, orderProps({ can_cancel: false })),
    );
    assert.doesNotMatch(hidden, /Cancel order/);
});

test('customer cancellation requires confirmation and prevents duplicate submissions', () => {
    const scope = Vue.effectScope();
    try {
        const loaded = loadPage('./Show.vue');
        const props = Vue.reactive(orderProps());
        const page = scope.run(() =>
            loaded.component.setup(props, { expose() {} }),
        );
        page.requestCancellation();
        assert.equal(page.cancellationOpen.value, true);
        assert.equal(loaded.submissions.length, 0);
        page.cancellationOpen.value = false;
        assert.equal(loaded.submissions.length, 0);
        page.requestCancellation();
        page.submitCancellation();
        assert.deepEqual(loaded.submissions[0].target, {
            url: '/orders/7/cancel',
            method: 'post',
        });
        assert.equal(loaded.submissions[0].options.preserveScroll, true);
        page.submitCancellation();
        assert.equal(loaded.submissions.length, 1);
        props.order.can_cancel = false;
        props.order.status = { value: 'cancelled', label: 'Cancelled' };
        loaded.submissions[0].options.onSuccess();
        assert.equal(page.cancellationOpen.value, false);
        page.cancellationForm.processing = false;
        page.requestCancellation();
        page.submitCancellation();
        assert.equal(page.cancellationOpen.value, false);
        assert.equal(loaded.submissions.length, 1);
    } finally {
        scope.stop();
    }
});

test('customer loading and stale-state errors remain visible when cancellation becomes unavailable', async () => {
    const scope = Vue.effectScope();
    try {
        const loaded = loadPage('./Show.vue', true);
        const props = Vue.reactive(orderProps());
        const render = scope.run(() =>
            loaded.component.setup(props, { expose() {} }),
        );
        loaded.forms[1].processing = true;
        const pending = await renderToString(render({}, []));
        assert.match(pending, /<button[^>]*disabled[^>]*>[\s\S]*?Cancel order/);
        loaded.forms[1].processing = false;
        loaded.forms[1].errors.status =
            'Payment has changed. Refresh your order.';
        props.order.can_cancel = false;
        props.order.status = { value: 'processing', label: 'Processing' };
        const changed = await renderToString(render({}, []));
        assert.match(changed, /Payment has changed\. Refresh your order\./);
        assert.match(changed, /Processing/);
        assert.doesNotMatch(changed, /Cancel order/);
    } finally {
        scope.stop();
    }
});

test('failed customer cancellation closes confirmation and keeps validation feedback', () => {
    const scope = Vue.effectScope();
    try {
        const loaded = loadPage('./Show.vue');
        const page = scope.run(() =>
            loaded.component.setup(orderProps(), { expose() {} }),
        );
        page.requestCancellation();
        page.submitCancellation();
        page.cancellationForm.errors.status = 'Order no longer eligible.';
        loaded.submissions[0].options.onError();
        assert.equal(page.cancellationOpen.value, false);
        assert.equal(
            page.cancellationForm.errors.status,
            'Order no longer eligible.',
        );
    } finally {
        scope.stop();
    }
});

test('admin handoff feedback does not describe a processing order as terminal or offer cancellation', async () => {
    const loaded = loadPage('../Administration/Orders/Show.vue', true);
    const html = await renderToString(
        Vue.h(loaded.component, {
            order: orderProps({
                status: { value: 'processing', label: 'Processing' },
                allowed_status_transitions: [],
                cancellation_blocked_reason:
                    'This shipment has been handed to LBC. Normal order cancellation is no longer available.',
                payment: {
                    method: {
                        value: 'gcash',
                        label: 'GCash',
                        requires_proof: true,
                    },
                    status: { value: 'verified', label: 'Verified' },
                },
            }).order,
        }),
    );
    assert.match(html, /handed to LBC/);
    assert.match(html, /Continue delivery using the shipment milestones/);
    assert.doesNotMatch(html, /terminal state/);
    assert.doesNotMatch(html, /Move order to/);
    assert.doesNotMatch(html, /Cancellation remains available/);
});

test('admin cancelled orders hide payment decisions and cancellation confirmation preserves verified payment wording', async () => {
    const loaded = loadPage('../Administration/Orders/Show.vue', true);
    const html = await renderToString(
        Vue.h(loaded.component, {
            order: orderProps({
                status: { value: 'cancelled', label: 'Cancelled' },
                allowed_status_transitions: [],
            }).order,
        }),
    );
    assert.doesNotMatch(html, /Apply payment decision/);
    const scope = Vue.effectScope();
    try {
        const admin = loadPage('../Administration/Orders/Show.vue');
        const props = Vue.reactive(orderProps());
        props.order.payment.status = { value: 'verified', label: 'Verified' };
        const page = scope.run(() =>
            admin.component.setup(props, { expose() {} }),
        );
        page.selectOrderStatus('cancelled');
        page.requestOrderStatusUpdate();
        assert.equal(page.orderConfirmationOpen.value, true);
        assert.match(
            page.orderConfirmationDescription.value,
            /Verified payment stays recorded/,
        );
        assert.equal(admin.submissions.length, 0);
        props.order.allowed_status_transitions = [];
        page.orderConfirmationOpen.value = false;
        page.requestOrderStatusUpdate();
        assert.equal(page.orderConfirmationOpen.value, false);
    } finally {
        scope.stop();
    }
});
