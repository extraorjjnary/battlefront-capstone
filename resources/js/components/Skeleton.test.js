import assert from 'node:assert/strict';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import { h } from 'vue';
import { loadSkeletonComponent } from '../lib/skeletonTestHelpers.js';

test('skeleton shapes retain custom sizing and respect reduced motion', async () => {
    const html = await renderToString(
        h(loadSkeletonComponent('ui/skeleton/Skeleton.vue'), {
            class: 'h-8 w-full rounded-none',
            'aria-hidden': 'true',
        }),
    );

    assert.match(html, /motion-reduce:animate-none/);
    assert.match(html, /bg-primary\/10/);
    assert.match(html, /h-8 w-full rounded-none/);
    assert.doesNotMatch(html, /rounded-md/);
});

for (const name of ['ProductCardSkeleton', 'StatSkeleton', 'ListSkeleton']) {
    test(`${name} is decorative and has no interactive placeholders`, async () => {
        const html = await renderToString(
            h(loadSkeletonComponent(`${name}.vue`)),
        );

        assert.match(html, /^<div[^>]*aria-hidden="true"/);
        assert.doesNotMatch(
            html,
            /<(?:a|button|input)|tabindex=|role="status"/,
        );
        assert.equal(
            html,
            await renderToString(h(loadSkeletonComponent(`${name}.vue`))),
        );
        if (name === 'ProductCardSkeleton') {
            assert.match(html, /aspect-square.*sm:aspect-3\/2/);
            assert.match(html, /p-2\.5.*sm:p-4/);
        }
    });
}

test('list skeletons render only the requested preview rows', async () => {
    const component = loadSkeletonComponent('ListSkeleton.vue');
    const defaults = await renderToString(h(component));
    const custom = await renderToString(h(component, { rows: 2 }));

    assert.equal((defaults.match(/data-slot="skeleton"/g) ?? []).length, 6);
    assert.equal((custom.match(/data-slot="skeleton"/g) ?? []).length, 4);
});

test('table skeletons preserve table semantics and configured row and column counts', async () => {
    const component = loadSkeletonComponent('TableRowsSkeleton.vue');
    const defaults = await renderToString(h('table', [h(component)]));
    const custom = await renderToString(
        h('table', [h(component, { rows: 2, columns: 5 })]),
    );

    assert.match(custom, /^<table><tbody[^>]*aria-hidden="true"/);
    assert.equal((defaults.match(/<tr>/g) ?? []).length, 3);
    assert.equal((defaults.match(/<td\b/g) ?? []).length, 12);
    assert.equal((custom.match(/<tr>/g) ?? []).length, 2);
    assert.equal((custom.match(/<td\b/g) ?? []).length, 10);
    assert.doesNotMatch(custom, /<(?:a|button|input)|tabindex=/);
});
