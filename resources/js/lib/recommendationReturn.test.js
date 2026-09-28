import assert from 'node:assert/strict';
import test from 'node:test';
import {
    consumeRecommendationVisit,
    recommendationReturnUrl,
    rememberRecommendationVisit,
} from './recommendationReturn.js';

test('returns the original recommendation results URL for a product visit', () => {
    const results =
        '/recommendations/results?budget=500.00&intended_use=gaming&tag_ids%5B0%5D=1';
    const product = `/products/17?return_to=${encodeURIComponent(results)}`;

    assert.equal(
        recommendationReturnUrl(product, '/recommendations/results'),
        results,
    );
});

test('rejects external and unrelated return destinations', () => {
    for (const returnTo of [
        'https://elsewhere.example/recommendations/results?budget=500&intended_use=gaming',
        '/products?budget=500&intended_use=gaming',
        '/recommendations/results?budget=500',
        'http://[',
    ]) {
        assert.equal(
            recommendationReturnUrl(
                `/products/17?return_to=${encodeURIComponent(returnTo)}`,
                '/recommendations/results',
            ),
            null,
        );
    }
});

test('uses browser history only for the matching same-tab product visit', () => {
    const originalWindow = globalThis.window;
    globalThis.window = { history: { length: 2 } };

    try {
        rememberRecommendationVisit(
            {
                button: 0,
                altKey: false,
                ctrlKey: false,
                metaKey: false,
                shiftKey: false,
            },
            17,
        );

        assert.equal(consumeRecommendationVisit(18), false);
        assert.equal(consumeRecommendationVisit(17), false);

        rememberRecommendationVisit(
            {
                button: 0,
                altKey: false,
                ctrlKey: false,
                metaKey: false,
                shiftKey: false,
            },
            17,
        );

        assert.equal(consumeRecommendationVisit(17), true);
    } finally {
        if (originalWindow === undefined) {
            delete globalThis.window;
        } else {
            globalThis.window = originalWindow;
        }
    }
});

test('modified product links do not arm browser-history return', () => {
    rememberRecommendationVisit(
        {
            button: 0,
            altKey: false,
            ctrlKey: true,
            metaKey: false,
            shiftKey: false,
        },
        17,
    );

    assert.equal(consumeRecommendationVisit(17), false);
});
