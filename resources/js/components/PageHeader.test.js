import assert from 'node:assert/strict';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import { h } from 'vue';
import { pageHeaderModule } from './pageHeaderTestHelpers.js';

test('page titles preserve IDs and omit empty supporting regions', async () => {
    const html = await renderToString(
        h(pageHeaderModule.default, {
            title: 'Your cart <script>',
            headingId: 'cart-heading',
        }),
    );

    assert.match(
        html,
        /<h1 id="cart-heading"[^>]*>Your cart &lt;script&gt;<\/h1>/,
    );
    assert.doesNotMatch(html, /<p\b/);
});

test('page headers show context and actions alongside the title', async () => {
    const html = await renderToString(
        h(
            pageHeaderModule.default,
            { title: 'Orders', description: 'Sagay City operations' },
            {
                metadata: () => h('span', '3 pending'),
                actions: () => h('a', { href: '/orders' }, 'View orders'),
            },
        ),
    );

    assert.match(html, /<h1[^>]*>Orders<\/h1>/);
    assert.match(html, /<p[^>]*>Sagay City operations<\/p>/);
    assert.match(html, /<span>3 pending<\/span>/);
    assert.match(html, /<a href="\/orders">View orders<\/a>/);
});
