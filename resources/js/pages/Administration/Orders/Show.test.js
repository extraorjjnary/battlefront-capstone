import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { compileSkeletonTestComponent } from '../../../lib/skeletonTestHelpers.js';

function orderHarness(t, overrides = {}, permissions = {}) {
    const primitive = {
        render() {
            return Vue.h('div', this.$slots.default?.());
        },
    };
    const empty = { render: () => null };
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
    const page = {
        props: {
            auth: {
                can: {
                    processOrders: true,
                    assignOrders: false,
                    ...permissions,
                },
            },
        },
    };
    const submissions = [];
    const forms = [];
    const props = Vue.reactive({
        order: {
            id: 1,
            reference: 'BF-000001',
            can_process: false,
            assigned_handler_id: 2,
            assigned_handler_name: 'Assigned Staff',
            customer: { name: 'Customer', email: 'customer@example.test' },
            recipient: { name: 'Recipient', contact_number: '09171234567' },
            fulfillment: { value: 'delivery', label: 'Delivery' },
            status: { value: 'processing', label: 'Processing' },
            shipment: {
                status: { value: 'handed_to_lbc', label: 'Handed to LBC' },
                tracking_reference: 'LBC-1',
            },
            shipment_actions: {
                next_status: { value: 'in_transit', label: 'In transit' },
                can_update_reference: true,
            },
            allowed_status_transitions: [
                { value: 'cancelled', label: 'Cancelled' },
            ],
            payment: {
                method: {
                    value: 'gcash',
                    label: 'GCash',
                    requires_proof: true,
                },
                status: { value: 'pending', label: 'Pending' },
                proof_submitted: true,
                proof_available: true,
                rejection_reasons: [],
            },
            items: [],
            total: '100.00',
            total_quantity: 0,
            delivery_fee: '0.00',
            ...overrides,
        },
        handler_options: [{ id: 2, name: 'Assigned Staff' }],
    });
    const inertia = {
        Head: empty,
        Link: primitive,
        usePage: () => page,
        useForm: (data) => {
            const form = Vue.reactive({
                ...data,
                errors: {},
                processing: false,
                reset() {},
                clearErrors() {},
                submit: (...args) => submissions.push(args),
                post: (...args) => submissions.push(args),
                patch: (...args) => submissions.push(args),
            });
            forms.push(form);
            return form;
        },
    };
    const source = readFileSync(new URL('./Show.vue', import.meta.url), 'utf8');
    const modules = { vue: Vue, '@inertiajs/vue3': inertia };
    for (const [, name] of source.matchAll(/from ['"]([^'"]+)['"]/g)) {
        if (modules[name]) continue;
        if (name.startsWith('@/actions/')) {
            const route = () => ({ url: '/orders/1', method: 'patch' });
            modules[name] = { default: new Proxy(route, { get: () => route }) };
        } else if (name.startsWith('@/routes/')) {
            modules[name] = {
                store: () => ({ url: '/orders/1', method: 'post' }),
            };
        } else if (name.startsWith('@/lib/')) {
            modules[name] = {
                formatDateTime: () => 'Today',
                formatCurrency: String,
                orderStatusBadgeClass: () => '',
            };
        } else {
            modules[name] = new Proxy(
                {},
                {
                    get: (_, key) =>
                        key === 'Button'
                            ? button
                            : key === 'Dialog'
                              ? dialog
                              : name === '@lucide/vue'
                                ? empty
                                : primitive,
                },
            );
        }
    }
    const component = compileSkeletonTestComponent(source, modules);
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const render = scope.run(() => component.setup(props, { expose() {} }));
    let tree;
    return {
        props,
        forms,
        submissions,
        html: () =>
            renderToString(Vue.h({ render: () => (tree = render({}, [])) })),
        tree: () => tree,
    };
}

function findButton(node, label) {
    if (Array.isArray(node)) {
        return node.map((child) => findButton(child, label)).find(Boolean);
    }
    if (!node || typeof node !== 'object') return null;
    if (node.children?.default) {
        const children = node.children.default();
        if (
            node.type?.render &&
            children.some(
                (child) =>
                    typeof child.children === 'string' &&
                    child.children.includes(label),
            )
        ) {
            if (node.props?.onClick) return node;
        }
        return findButton(children, label);
    }
    return findButton(node.children, label);
}

test('other staff can inspect details without processing controls', async (t) => {
    const view = orderHarness(t);
    const html = await view.html();
    assert.match(html, /Assigned handler/);
    assert.match(html, /Assigned Staff/);
    assert.match(html, /Only the assigned handler can process this order/);
    assert.doesNotMatch(
        html,
        /Update order status|Save reference|Mark In transit|Claim order|Assign handler/,
    );
});

test('an unassigned order offers claiming and explains the processing requirement', async (t) => {
    const view = orderHarness(t, {
        assigned_handler_id: null,
        assigned_handler_name: null,
    });
    const html = await view.html();
    assert.match(html, /Unassigned/);
    assert.match(html, /Claim order/);
    assert.match(html, /before processing it/);
    assert.doesNotMatch(html, /Update order status|Save reference/);
});

test('supervisors can reassign while processing controls remain restricted', async (t) => {
    const html = await orderHarness(t, {}, { assignOrders: true }).html();
    assert.match(html, /Assign handler/);
    assert.match(html, /Reassign it explicitly/);
    assert.doesNotMatch(html, /Update order status|Save reference|Claim order/);
});

test('the current handler sees eligible controls and loses submission access after reassignment', async (t) => {
    const view = orderHarness(t, { can_process: true });
    const html = await view.html();
    assert.match(html, /\(you\)/);
    assert.match(html, /Update order status/);
    assert.match(html, /Save reference/);
    assert.match(html, /Mark In transit/);
    const shipmentButton = findButton(view.tree(), 'Mark');
    assert.ok(shipmentButton);
    shipmentButton.props.onClick();
    assert.equal(view.submissions.length, 1);
    view.props.order.can_process = false;
    shipmentButton.props.onClick();
    assert.equal(view.submissions.length, 1);
    assert.doesNotMatch(
        await view.html(),
        /Save reference|Mark In transit|Update order status/,
    );
});

test('completed orders retain final-handler tracking corrections without claim or reassignment', async (t) => {
    const view = orderHarness(
        t,
        {
            can_process: true,
            status: { value: 'completed', label: 'Completed' },
            allowed_status_transitions: [],
            shipment_actions: { next_status: null, can_update_reference: true },
        },
        { assignOrders: true },
    );
    view.props.handler_options = [];
    const html = await view.html();
    assert.match(html, /Save reference/);
    assert.doesNotMatch(html, /Claim order|Assign handler|Update order status/);
});
