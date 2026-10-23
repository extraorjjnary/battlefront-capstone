import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { compileSkeletonTestComponent } from '../../lib/skeletonTestHelpers.js';

const Summary = compileSkeletonTestComponent(
    readFileSync(
        new URL('./StockAdjustmentSummary.vue', import.meta.url),
        'utf8',
    ),
    { vue: Vue },
);

for (const [name, requested, expected] of [
    ['increase', 14, '+4 units'],
    ['decrease', 7, '−3 units'],
    ['unchanged', 10, '0 units'],
    ['zero', 0, '−10 units'],
    ['numeric input', '12', '+2 units'],
    ['empty input', '', '—'],
    ['fractional input', '1.5', '—'],
    ['negative input', -1, '—'],
]) {
    test(`stock summary displays ${name} with current, requested, and signed difference`, async () => {
        const html = await renderToString(
            Vue.h(Summary, {
                currentQuantity: 10,
                requestedQuantity: requested,
            }),
        );

        assert.match(html, /Current<\/dt><dd>10 units/);
        assert.match(html, /Requested<\/dt><dd>/);
        assert.ok(html.includes(`Difference</dt><dd>${expected}</dd>`));
    });
}
