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

function nationwideFields(address = {}) {
    const props = Vue.reactive(fieldProps({ nationwide: true, modelValue: { ...emptyDeliveryAddress(), ...address } }));
    const scope = Vue.effectScope();
    const fields = scope.run(() => loadAddressComponent('../components/DeliveryAddressFields.vue').setup(props, {
        expose() {}, emit: (_, value) => { props.modelValue = value; },
    }));
    return { props, fields, scope };
}

test('nationwide selectors clear descendants and discard stale location responses', async (t) => {
    const pending = [];
    t.mock.method(globalThis, 'fetch', (url) => new Promise((resolve) => pending.push({ url, resolve })));
    const { props, fields, scope } = nationwideFields({ delivery_destination: '1804523000', delivery_barangay_code: '1804523018' });
    try {
        const oldRequest = fields.updateRegion('1800000000');
        assert.equal(props.modelValue.delivery_destination, '');
        assert.equal(props.modelValue.delivery_barangay_code, '');
        const newRequest = fields.updateRegion('1300000000');
        const reply = (request, locations) => request.resolve({ ok: true, json: async () => ({ data: { locations } }) });
        reply(pending[2], []);
        reply(pending[3], [{ code: '1380300000', name: 'City of Makati' }]);
        await newRequest;
        reply(pending[0], [{ code: '1804500000', name: 'Negros Occidental' }]);
        reply(pending[1], [{ code: '1830200000', name: 'City of Bacolod' }]);
        await oldRequest;
        assert.equal(fields.regionCode.value, '1300000000');
        assert.deepEqual(fields.provinces.value, []);
        assert.equal(fields.cities.value[0].code, '1380300000');
    } finally { scope.stop(); }
});

test('nationwide saved address hydration loads only its parents and barangays', async (t) => {
    const requested = [];
    t.mock.method(globalThis, 'fetch', async (url) => {
        const query = new URL(url, 'https://example.test').searchParams;
        requested.push(query.get('level'));
        const locations = {
            regions: [{ code: '1800000000', name: 'Negros Island Region' }],
            city: [{ code: '1804523000', name: 'City of Sagay', region_code: '1800000000', province_code: '1804500000' }],
            provinces: [{ code: '1804500000', name: 'Negros Occidental' }],
            cities: [{ code: '1804523000', name: 'City of Sagay' }],
            barangays: [{ code: '1804523018', name: 'Poblacion I' }],
        }[query.get('level')];
        return { ok: true, json: async () => ({ data: { locations } }) };
    });
    const { props, fields, scope } = nationwideFields({ delivery_destination: 'Sagay City', delivery_barangay_code: '1804523018', delivery_address_details: '12 Mabini' });
    try {
        await fields.initialize();
        assert.equal(props.modelValue.delivery_city_code, '1804523000');
        assert.equal(props.modelValue.delivery_region_code, '1800000000');
        assert.equal(props.modelValue.delivery_barangay_code, '1804523018');
        assert.equal(props.modelValue.delivery_address_details, '12 Mabini');
        assert.deepEqual(requested, ['regions', 'city', 'provinces', 'cities', 'barangays']);
    } finally { scope.stop(); }
});

test('location lookup failures expose retry state without restoring old children', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => ({ ok: false }));
    const { props, fields, scope } = nationwideFields();
    try {
        await fields.updateRegion('1300000000');
        assert.match(fields.loadError.value, /retry/);
        assert.equal(fields.loading.value, false);
        assert.equal(props.modelValue.delivery_city_code, '');
        assert.deepEqual(fields.cities.value, []);
    } finally { scope.stop(); }
});

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
