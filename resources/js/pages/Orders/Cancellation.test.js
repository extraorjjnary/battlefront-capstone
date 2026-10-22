import { pageHeaderModule } from '../../components/pageHeaderTestHelpers.js';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { formatCurrency } from '../../lib/currency.js';
import { formatDateTime } from '../../lib/dateTime.js';
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
            '@/components/PageHeader.vue': pageHeaderModule,
            vue: Vue,
            '@inertiajs/vue3': {
                Head: primitive,
                Link: primitive,
                usePage: () => ({
                    props: {
                        auth: {
                            can: { assignOrders: true, processOrders: true },
                        },
                    },
                }),
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
            '@/lib/dateTime': { formatDateTime },
            '@/lib/orderStatus': { orderStatusBadgeClass },
            '@/actions/App/Http/Controllers/Administration/OrderStatusController':
                {
                    default: {
                        update: (id) => ({
                            url: `/administration/orders/${id}/status`,
                            method: 'patch',
                        }),
                    },
                },
            '@/routes/administration/orders/cod-collection': {
                store: (id) => ({
                    url: `/administration/orders/${id}/cod-collection`,
                    method: 'post',
                }),
            },
            '@/routes/administration/orders/pickup-confirmation': {
                store: (id) => ({
                    url: `/administration/orders/${id}/pickup-confirmation`,
                    method: 'post',
                }),
            },
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
                method: {
                    value: 'cash',
                    label: 'Cash (Pay at Pickup)',
                    requires_proof: false,
                    description:
                        'Pay cash when collecting your products at the store.',
                },
                status: { value: 'pending', label: 'Pending' },
                pay_at_pickup: true,
                can_confirm_pickup: false,
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

test('customer detail labels an unconfigured zero-fee delivery without showing an LBC estimate', async () => {
    const loaded = loadPage('./Show.vue', true);
    const html = await renderToString(
        Vue.h(
            loaded.component,
            orderProps({
                fulfillment: { value: 'delivery', label: 'Delivery' },
                product_subtotal: '100.00',
                delivery_fee: '0.00',
                total: '100.00',
                delivery_quote: {
                    pricing_configured: false,
                    destination: 'City of Makati',
                    notice: 'Delivery fee not configured.',
                    assumption_label: 'No LBC quotation or booking exists.',
                },
            }),
        ),
    );

    assert.match(html, /Delivery fee not configured/);
    assert.match(html, /City of Makati/);
    assert.match(html, /No LBC quotation or booking exists/);
    assert.doesNotMatch(html, /Estimated preparation and transit/);
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

test('admin detail flags unconfigured delivery logistics without offering LBC tracking', async () => {
    const loaded = loadPage('../Administration/Orders/Show.vue', true);
    const props = orderProps({
        status: { value: 'processing', label: 'Processing' },
        fulfillment: { value: 'delivery', label: 'Delivery' },
        payment: {
            method: { value: 'gcash', label: 'GCash', requires_proof: true },
            status: { value: 'verified', label: 'Verified' },
            rejection_reasons: [],
        },
        delivery_quote: {
            pricing_configured: false,
            assumption_label: 'No LBC quotation or booking exists.',
        },
        shipment: null,
    });

    const html = await renderToString(
        Vue.h(loaded.component, { order: props.order }),
    );

    assert.match(html, /No configured delivery rate/);
    assert.match(html, /saved product subtotal with no delivery fee/);
    assert.match(html, /Manual shipment setup is unavailable/);
    assert.doesNotMatch(html, /Shipment progress/);
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

function codOrderProps(canConfirm = false) {
    const props = orderProps({
        status: { value: 'processing', label: 'Processing' },
        fulfillment: { value: 'delivery', label: 'Delivery' },
        allowed_status_transitions: [],
        payment: {
            method: {
                value: 'cod',
                label: 'Cash on Delivery (COD)',
                requires_proof: false,
            },
            status: { value: 'pending', label: 'COD — Awaiting Collection' },
            can_confirm_cod_collection: canConfirm,
            rejection_reasons: [],
        },
    });
    return { order: props.order };
}

test('pending COD offers an enabled Processing option and submits without payment verification', async () => {
    const props = codOrderProps();
    props.order.status = { value: 'pending', label: 'Pending' };
    props.order.allowed_status_transitions = [
        { value: 'processing', label: 'Processing' },
        { value: 'cancelled', label: 'Cancelled' },
    ];
    const loaded = loadPage('../Administration/Orders/Show.vue', true);
    const html = await renderToString(Vue.h(loaded.component, props));
    const processingOption = html.match(
        /<div\b[^>]*\bvalue="processing"[^>]*>/,
    )?.[0];
    assert.ok(processingOption, 'Processing must be offered for eligible COD');
    assert.doesNotMatch(processingOption, /\bdisabled(?:=|\s|>)/);
    assert.doesNotMatch(html, /\bvalue="completed"/);

    const scope = Vue.effectScope();
    try {
        const admin = loadPage('../Administration/Orders/Show.vue');
        const page = scope.run(() =>
            admin.component.setup(props, { expose() {} }),
        );
        page.selectOrderStatus('processing');
        page.requestOrderStatusUpdate();
        assert.equal(page.orderStatusForm.status, 'processing');
        assert.equal(page.orderConfirmationOpen.value, false);
        assert.deepEqual(
            admin.submissions.map(({ target }) => target),
            [
                {
                    url: '/administration/orders/7/status',
                    method: 'patch',
                },
            ],
        );
        assert.equal(
            page.paymentStatusForm.manual_verification_confirmed,
            false,
        );
        assert.equal(props.order.payment.status.value, 'pending');
    } finally {
        scope.stop();
    }
});

test('unverified prepaid payments cannot select or submit server-excluded fulfillment transitions', async () => {
    for (const method of ['gcash', 'maya']) {
        for (const status of ['pending', 'rejected']) {
            const props = {
                order: orderProps({
                    payment: {
                        method: {
                            value: method,
                            label: method,
                            requires_proof: true,
                        },
                        status: { value: status, label: status },
                        rejection_reasons: [],
                    },
                }).order,
            };
            const loaded = loadPage('../Administration/Orders/Show.vue', true);
            const html = await renderToString(Vue.h(loaded.component, props));
            assert.doesNotMatch(html, /\bvalue="(?:processing|completed)"/);
            assert.match(html, /\bvalue="cancelled"/);

            const scope = Vue.effectScope();
            try {
                const admin = loadPage('../Administration/Orders/Show.vue');
                const page = scope.run(() =>
                    admin.component.setup(props, { expose() {} }),
                );
                for (const transition of ['processing', 'completed']) {
                    page.selectOrderStatus(transition);
                    page.requestOrderStatusUpdate();
                }
                assert.equal(admin.submissions.length, 0);
                assert.equal(page.orderConfirmationOpen.value, false);
            } finally {
                scope.stop();
            }
        }
    }
});

test('processing COD cannot directly submit completion when the server excludes it', () => {
    const scope = Vue.effectScope();
    try {
        const loaded = loadPage('../Administration/Orders/Show.vue');
        const page = scope.run(() =>
            loaded.component.setup(codOrderProps(), { expose() {} }),
        );
        page.selectOrderStatus('completed');
        page.requestOrderStatusUpdate();
        assert.equal(loaded.submissions.length, 0);
        assert.equal(page.orderConfirmationOpen.value, false);
    } finally {
        scope.stop();
    }
});

test('pickup offers unpaid Processing and shows combined confirmation only when server eligible', async () => {
    for (const method of ['cash', 'card_at_store']) {
        for (const eligible of [false, true]) {
            const props = orderProps({
                status: {
                    value: eligible ? 'processing' : 'pending',
                    label: eligible ? 'Processing' : 'Pending',
                },
                allowed_status_transitions: eligible
                    ? [{ value: 'cancelled', label: 'Cancelled' }]
                    : [
                          { value: 'processing', label: 'Processing' },
                          { value: 'cancelled', label: 'Cancelled' },
                      ],
            });
            props.order.payment.can_confirm_pickup = eligible;
            props.order.payment.method.value = method;
            props.order.payment.method.description =
                method === 'cash'
                    ? 'Pay cash at pickup.'
                    : 'Pay through a physical debit/credit card terminal. This is not an online payment gateway.';
            const loaded = loadPage('../Administration/Orders/Show.vue', true);
            const html = await renderToString(
                Vue.h(loaded.component, { order: props.order }),
            );
            assert.equal(
                html.includes('Confirm Payment &amp; Complete Pickup'),
                eligible,
            );
            assert.doesNotMatch(
                html,
                /Apply payment decision|Verify payment before moving|value="completed"/,
            );
            if (!eligible) {
                const option = html.match(
                    /<div\b[^>]*\bvalue="processing"[^>]*>/,
                )?.[0];
                assert.ok(option);
                assert.doesNotMatch(option, /\bdisabled(?:=|\s|>)/);
            }
            if (method === 'card_at_store') {
                assert.match(html, /physical debit\/credit card terminal/);
                assert.match(html, /not an online payment gateway/);
            }
        }
    }
});

test('pickup confirmation requires both checkboxes and prevents duplicate or stale submissions', () => {
    const scope = Vue.effectScope();
    try {
        const loaded = loadPage('../Administration/Orders/Show.vue');
        const props = Vue.reactive({
            order: orderProps({
                status: { value: 'processing', label: 'Processing' },
            }).order,
        });
        props.order.payment.can_confirm_pickup = true;
        const page = scope.run(() =>
            loaded.component.setup(props, { expose() {} }),
        );
        page.requestPickupConfirmation();
        assert.equal(page.pickupConfirmationOpen.value, true);
        page.submitPickupConfirmation();
        assert.equal(loaded.submissions.length, 0);
        page.pickupConfirmationForm.payment_received_confirmed = true;
        page.submitPickupConfirmation();
        assert.equal(loaded.submissions.length, 0);
        page.pickupConfirmationForm.products_handed_over_confirmed = true;
        page.submitPickupConfirmation();
        assert.deepEqual(loaded.submissions[0].target, {
            url: '/administration/orders/7/pickup-confirmation',
            method: 'post',
        });
        page.submitPickupConfirmation();
        assert.equal(loaded.submissions.length, 1);
        loaded.submissions[0].options.onSuccess();
        assert.equal(page.pickupConfirmationOpen.value, false);
        assert.equal(
            page.pickupConfirmationForm.payment_received_confirmed,
            false,
        );
        assert.equal(
            page.pickupConfirmationForm.products_handed_over_confirmed,
            false,
        );
        props.order.payment.can_confirm_pickup = false;
        page.pickupConfirmationForm.processing = false;
        page.requestPickupConfirmation();
        assert.equal(page.pickupConfirmationOpen.value, false);
    } finally {
        scope.stop();
    }
});

test('admin COD controls show collection confirmation only when eligible and hide ordinary payment decisions', async () => {
    for (const eligible of [false, true]) {
        const loaded = loadPage('../Administration/Orders/Show.vue', true);
        const html = await renderToString(
            Vue.h(loaded.component, codOrderProps(eligible)),
        );
        assert.match(html, /COD — Awaiting Collection/);
        assert.match(
            html,
            /Handle failed delivery, unpaid orders, and returns manually/,
        );
        assert.match(
            html,
            /Delivered does not verify payment or record a sale/,
        );
        assert.match(
            html,
            /No automatic cancellation, refund, return, or stock restoration/,
        );
        assert.doesNotMatch(
            html,
            /Apply payment decision|manually at the store|decision is final/,
        );
        assert.equal(html.includes('Confirm COD Collection'), eligible);
    }
});

test('COD confirmation requires an explicit checkbox and blocks duplicate submissions', () => {
    const scope = Vue.effectScope();
    try {
        const loaded = loadPage('../Administration/Orders/Show.vue');
        const props = Vue.reactive(codOrderProps(true));
        const page = scope.run(() =>
            loaded.component.setup(props, { expose() {} }),
        );
        page.requestCodConfirmation();
        assert.equal(page.codConfirmationOpen.value, true);
        assert.equal(page.codCollectionForm.cod_collection_confirmed, false);
        page.submitCodCollection();
        assert.equal(loaded.submissions.length, 0);
        page.codCollectionForm.cod_collection_confirmed = true;
        page.submitCodCollection();
        assert.deepEqual(loaded.submissions[0].target, {
            url: '/administration/orders/7/cod-collection',
            method: 'post',
        });
        page.submitCodCollection();
        assert.equal(loaded.submissions.length, 1);
        loaded.submissions[0].options.onSuccess();
        assert.equal(page.codConfirmationOpen.value, false);
        assert.equal(page.codCollectionForm.cod_collection_confirmed, false);
        page.codCollectionForm.processing = false;
        props.order.payment.can_confirm_cod_collection = false;
        page.requestCodConfirmation();
        assert.equal(page.codConfirmationOpen.value, false);
    } finally {
        scope.stop();
    }
});

test('COD delivery confirmation explains that delivery alone does not complete or pay the order', async () => {
    const scope = Vue.effectScope();
    try {
        const loaded = loadPage('../Administration/Orders/Show.vue', true);
        const props = codOrderProps();
        props.handler_options = [];
        props.order.shipment = { status: { value: 'out_for_delivery' } };
        props.order.shipment_actions = {
            next_status: { value: 'delivered', label: 'Delivered' },
        };
        const render = scope.run(() =>
            loaded.component.setup(props, { expose() {} }),
        );
        const root = render({}, []);
        const candidates = [];
        function inspect(node) {
            if (!node || typeof node !== 'object') return;
            if (node.props?.onClick)
                candidates.push({
                    type: node.type,
                    name: node.props.onClick.name,
                    props: node.props,
                    children: node.children,
                });
            const children = node.children;
            if (Array.isArray(children)) children.forEach(inspect);
            else if (children && typeof children === 'object')
                Object.values(children).forEach((child) =>
                    inspect(typeof child === 'function' ? child() : child),
                );
        }
        inspect(root);
        const markDelivered = candidates.find(
            ({ name }) => name === 'requestShipmentUpdate',
        );
        assert.ok(
            markDelivered,
            'The eligible shipment milestone must be actionable.',
        );
        markDelivered.props.onClick();

        assert.equal(loaded.submissions.length, 0);
        assert.equal(
            loaded.forms.some((form) => form.status === 'delivered'),
            true,
        );
    } finally {
        scope.stop();
    }
});
