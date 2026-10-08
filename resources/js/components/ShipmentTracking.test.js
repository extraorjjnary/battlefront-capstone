import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { formatCurrency } from '../lib/currency.js';
import { orderStatusBadgeClass } from '../lib/orderStatus.js';

const source = readFileSync(new URL('./ShipmentTracking.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const script = compileScript(descriptor, { id: 'shipment-tracking-test', inlineTemplate: true });
const modules = {
    vue: Vue,
    '@lucide/vue': { Truck: { render: () => Vue.h('svg') } },
    '@/components/ui/badge': { Badge: { render() { return Vue.h('span', this.$slots.default?.()); } } },
    '@/lib/currency': { formatCurrency },
    '@/lib/orderStatus': { orderStatusBadgeClass },
};
const executable = script.content
    .replace(/import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g, (_, names, name) =>
        `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(name)}];`)
    .replace('export default', 'return');
const component = new Function('modules', executable)(modules);

function trackingProps(overrides = {}) {
    return {
        shipment: {
            carrier: 'lbc',
            status: { value: 'awaiting_preparation', label: 'Awaiting preparation' },
            tracking_reference: null,
            eta: { anchor_date: null, timezone: null, estimated_delivery_start: null, estimated_delivery_end: null },
            timeline: [{ status: 'awaiting_preparation', label: 'Awaiting preparation', occurred_at: '2026-10-08T08:00:00+00:00' }],
            notice: 'Shipment status is manually maintained by Battlefront. This is not live LBC or GPS tracking.',
            history_notice: null,
            ...overrides,
        },
        quote: { destination: 'Bacolod City', delivery_fee: '250.00', base_fee: '250.00', handling_surcharge: '0.00', eta_min_days: 3, eta_max_days: 5, packing_expectation: 'Standard packing' },
    };
}

test('unprepared shipments show saved fees relative ETA and manual wording without an invented reference', async () => {
    const html = await renderToString(Vue.h(component, trackingProps()));

    assert.match(html, /Awaiting preparation/);
    assert.match(html, /Bacolod City/);
    assert.match(html, /250\.00/);
    assert.match(html, /3.*5.*calendar days from the start of preparation/s);
    assert.match(html, /manually maintained by Battlefront/);
    assert.match(html, /not live LBC or GPS tracking/);
    assert.doesNotMatch(html, /LBC tracking \/ reference/);
});

test('operational civil dates retain their date even in another device timezone', async () => {
    const previous = process.env.TZ;
    process.env.TZ = 'Pacific/Honolulu';
    try {
        const html = await renderToString(Vue.h(component, trackingProps({
            eta: {
                anchor_date: '2026-10-08', timezone: 'Asia/Manila',
                estimated_delivery_start: '2026-10-11', estimated_delivery_end: '2026-10-13',
                notice: 'Arrival is not guaranteed.',
            },
        })));

        assert.match(html, /Oct 11, 2026/);
        assert.match(html, /Oct 13, 2026/);
        assert.match(html, /Asia\/Manila/);
        assert.doesNotMatch(html, /Oct 10, 2026/);
    } finally {
        if (previous === undefined) {
            delete process.env.TZ;
        } else {
            process.env.TZ = previous;
        }
    }
});

test('manual references are escaped and cancelled shipments identify the original estimate', async () => {
    const html = await renderToString(Vue.h(component, trackingProps({
        status: { value: 'cancelled', label: 'Cancelled' },
        tracking_reference: '<script>unsafe</script>',
    })));

    assert.match(html, /Original delivery estimate/);
    assert.match(html, /LBC tracking \/ reference/);
    assert.match(html, /&lt;script&gt;unsafe&lt;\/script&gt;/);
    assert.doesNotMatch(html, /<script>/);
});
