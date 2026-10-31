import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { compileSkeletonTestComponent } from '../../lib/skeletonTestHelpers.js';

function reportComponent(allowed) {
    return compileSkeletonTestComponent(
        readFileSync(new URL('./OperationalExports.vue', import.meta.url), 'utf8'),
        {
            vue: Vue,
            '@inertiajs/vue3': {
                usePage: () => ({ props: { auth: { can: { exportReports: allowed } } } }),
            },
            '@/components/ui/button': {
                Button: { props: ['variant', 'asChild'], render() { return this.$slots.default?.(); } },
            },
            '@/actions/App/Http/Controllers/Administration/OperationalExportController': {
                default: {
                    url: ({ feature, format }, { query }) =>
                        `/operations/${feature}/export/${format}?${new URLSearchParams(
                            Object.entries(query).filter(([, value]) => value !== undefined),
                        )}`,
                },
            },
        },
    );
}

test('print links preserve report selection and open safely in a new tab', async () => {
    for (const feature of ['stock-requests', 'damage-reports', 'supplier-returns', 'customer-returns']) {
        const html = await renderToString(Vue.createSSRApp(reportComponent(true), {
            feature,
            filters: { q: 'hardware', overdue: 1, view: 'mine' },
            caseId: 12,
            requestId: 34,
            paymentId: 56,
        }));
        assert.match(html, new RegExp(`/operations/${feature}/export/print`));
        assert.match(html, /q=hardware&amp;overdue=1&amp;view=mine&amp;case=12&amp;request_id=34&amp;payment_id=56/);
        assert.match(html, /target="_blank" rel="noopener noreferrer"/);
        assert.match(html, />Print<\/a>/);
        assert.match(html, /export\/csv/);
        assert.match(html, /export\/pdf/);
    }
});

test('print and export controls are hidden without export permission', async () => {
    const html = await renderToString(Vue.createSSRApp(reportComponent(false), { feature: 'stock-requests' }));
    assert.doesNotMatch(html, /Print|Export CSV|Export PDF/);
});
