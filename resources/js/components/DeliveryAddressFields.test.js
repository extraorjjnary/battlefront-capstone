import assert from 'node:assert/strict';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import {
    deliveryLocations,
    emptyDeliveryAddress,
    loadAddressComponent,
} from '../lib/deliveryAddressTestHelpers.js';

function fieldProps(overrides = {}) {
    return {
        modelValue: emptyDeliveryAddress(),
        locations: deliveryLocations,
        errors: {},
        namePrefix: 'delivery',
        required: true,
        ...overrides,
    };
}

test('shared address fields populate all 12 locality lists and reset barangay on city changes', () => {
    const scope = Vue.effectScope();
    try {
        const props = Vue.reactive(fieldProps());
        const fields = scope.run(() => loadAddressComponent('../components/DeliveryAddressFields.vue').setup(props, {
            expose() {},
            emit(event, address) {
                assert.equal(event, 'update:modelValue');
                props.modelValue = address;
            },
        }));
        assert.deepEqual(fields.availableBarangays.value, []);
        for (const location of deliveryLocations) {
            fields.updateDestination(location.destination);
            assert.equal(props.modelValue.delivery_barangay_code, '');
            assert.deepEqual(fields.availableBarangays.value, location.barangays);
            fields.updateBarangay(location.barangays[0].code);
            assert.equal(props.modelValue.delivery_barangay_code, location.barangays[0].code);
            fields.updateDetails('12 Mabini Street');
            assert.equal(props.modelValue.delivery_address_details, '12 Mabini Street');
        }
        fields.updateDestination('');
        assert.deepEqual(fields.availableBarangays.value, []);
        assert.equal(props.modelValue.delivery_barangay_code, '');
        assert.equal(props.modelValue.delivery_address_details, '12 Mabini Street');
    } finally {
        scope.stop();
    }
});

test('selecting the current locality preserves its barangay and incoming props remain unmodified', () => {
    const address = {
        delivery_destination: 'Sagay City',
        delivery_barangay_code: '1804523018',
        delivery_address_details: '12 Mabini Street',
    };
    const props = fieldProps({ modelValue: address });
    const updates = [];
    const fields = loadAddressComponent('../components/DeliveryAddressFields.vue').setup(props, {
        expose() {}, emit: (_, value) => updates.push(value),
    });

    fields.updateDestination('Sagay City');
    assert.deepEqual(updates, []);
    fields.updateDestination('Bacolod City');
    assert.equal(updates[0].delivery_barangay_code, '');
    assert.equal(address.delivery_destination, 'Sagay City');
    assert.equal(address.delivery_barangay_code, '1804523018');
});

test('shared fields support optional profile names, required checkout inputs, and field-specific errors', async () => {
    const component = loadAddressComponent('../components/DeliveryAddressFields.vue', true);
    const optional = await renderToString(Vue.h(component, fieldProps({
        namePrefix: 'default_delivery',
        required: false,
        errors: {
            default_delivery_destination: 'Select a supported delivery destination.',
            default_delivery_barangay_code: 'Select a barangay.',
        },
    })));
    assert.match(optional, /name="default_delivery_destination"/);
    assert.match(optional, /name="default_delivery_barangay_code"/);
    assert.match(optional, /name="default_delivery_address_details"/);
    assert.match(optional, /aria-required="false"/);
    assert.match(optional, /Select a barangay\./);
    assert.doesNotMatch(optional, /<textarea[^>]*\srequired/);
    assert.doesNotMatch(optional, /name="(?:region|province|default_delivery_address)"/);

    const required = await renderToString(Vue.h(component, fieldProps()));
    assert.match(required, /<textarea[^>]*\srequired/);
    assert.match(required, /aria-required="true"/);
});
