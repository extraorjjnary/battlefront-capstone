import assert from 'node:assert/strict';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import {
    deliveryLocations as locations,
    emptyDeliveryAddress,
    findAddressNode as findNode,
    loadAddressComponent,
} from '../../lib/deliveryAddressTestHelpers.js';

function loadPage(inlineTemplate = false, errors = {}) {
    return loadAddressComponent('../pages/Checkout/Index.vue', inlineTemplate, { errors });
}

function checkoutProps(prefill = {}) {
    return {
        cart: {
            items: [{
                id: 11, quantity: 1, product: { name: 'Battlefront Mouse', price: '100.00' }, line_total: '100.00',
            }],
            total: '100.00', total_quantity: 1, item_count: 1,
        },
        customer: { name: 'Customer', default_delivery_address: 'Saved address <script>unsafe</script>' },
        pickupLocation: { name: 'Sagay', address: 'Pickup address' },
        fulfillmentMethods: [
            { value: 'pickup', label: 'Pickup' }, { value: 'delivery', label: 'Delivery' },
        ],
        paymentMethods: [
            { value: 'cash', label: 'Cash', available_for: ['pickup'], requires_proof: false },
            {
                value: 'gcash', label: 'GCash', available_for: ['pickup', 'delivery'], requires_proof: true,
                payment_account: { account_name: 'Demo', account_number: '09XX', is_demo: true },
            },
        ],
        pickupQuote: { product_subtotal: '100.00', delivery_fee: '0.00', total: '100.00' },
        deliveryQuotes: locations.map((location) => ({
            destination: location.destination,
            product_subtotal: '100.00', delivery_fee: '80.00', total: '180.00', carrier: 'lbc',
            estimated_delivery_start: '2026-10-13', estimated_delivery_end: '2026-10-14',
        })),
        deliveryLocations: locations,
        deliveryAddressPrefill: { ...emptyDeliveryAddress(), ...prefill },
    };
}

test('checkout selects the supplied destination quote and resets delivery values for pickup', () => {
    const scope = Vue.effectScope();
    try {
        const props = checkoutProps();
        const page = scope.run(() => loadPage().setup(props, { expose() {} }));
        page.chooseFulfillment('delivery');
        for (const location of locations) {
            page.deliveryAddress.value = {
                delivery_destination: location.destination,
                delivery_barangay_code: location.barangays[0].code,
                delivery_address_details: '12 Mabini Street',
            };
            assert.equal(page.selectedQuote.value, props.deliveryQuotes.find(
                (quote) => quote.destination === location.destination,
            ));
        }
        page.chooseFulfillment('pickup');
        assert.equal(page.deliveryAddress.value.delivery_destination, '');
        assert.equal(page.deliveryAddress.value.delivery_barangay_code, '');
        assert.equal(page.selectedQuote.value, props.pickupQuote);
    } finally {
        scope.stop();
    }
});

test('verified profile parts prefill checkout without copying the full profile into street details', () => {
    const scope = Vue.effectScope();
    try {
        const page = scope.run(() => loadPage().setup(checkoutProps({
            delivery_destination: 'Sagay City',
            delivery_barangay_code: '1804523018',
            delivery_address_details: '12 Mabini Street',
        }), { expose() {} }));
        page.chooseFulfillment('delivery');
        assert.deepEqual(page.deliveryAddress.value, {
            delivery_destination: 'Sagay City',
            delivery_barangay_code: '1804523018',
            delivery_address_details: '12 Mabini Street',
        });
    } finally {
        scope.stop();
    }
});

test('checkout renders shared structured fields and errors, and pickup submits no delivery inputs', async () => {
    const scope = Vue.effectScope();
    try {
        const render = scope.run(() => loadPage(true, {
            delivery_barangay_code: 'Select a barangay.',
            delivery_address_details: 'Enter street details.',
        }).setup(checkoutProps(), { expose() {} }));
        const root = () => render({}, []);
        const fulfillment = (value) => findNode(root(), (node) =>
            node.props?.name === 'fulfillment_method' && node.props?.value === value,
        );
        fulfillment('delivery').props.onChange();
        let html = await renderToString(root());
        assert.match(html, /Delivery available in selected Negros Occidental areas\./);
        assert.match(html, /City \/ Municipality/);
        assert.match(html, /House \/ Building \/ Street \/ Landmark/);
        assert.match(html, /name="delivery_barangay_code"/);
        assert.match(html, /name="delivery_address_details"[^>]*required/);
        assert.match(html, /Select a barangay\./);
        assert.match(html, /Enter street details\./);
        assert.ok(html.includes('&lt;script&gt;unsafe&lt;/script&gt;'));
        assert.doesNotMatch(html, /name="delivery_address"/);
        assert.doesNotMatch(html, /name="(?:region|province)"/);
        const fields = findNode(root(), (node) => node.props?.locations === locations);
        fields.props['onUpdate:modelValue']({
            delivery_destination: 'Sagay City',
            delivery_barangay_code: '1804523018',
            delivery_address_details: '12 Mabini Street',
        });
        html = await renderToString(root());
        assert.match(html, /name="delivery_barangay_code" value="1804523018"/);
        fulfillment('pickup').props.onChange();
        html = await renderToString(root());
        assert.doesNotMatch(html, /name="delivery_(?:destination|barangay_code|address_details)"/);
        assert.match(html, /Pickup address/);
    } finally {
        scope.stop();
    }
});
