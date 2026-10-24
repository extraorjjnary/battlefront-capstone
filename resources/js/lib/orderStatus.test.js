import assert from 'node:assert/strict';
import test from 'node:test';
import { orderDisplayStatus, orderStatusBadgeClass } from './orderStatus.js';

test('cancellation review refund processing and completion retain their shared status colors', () => {
    assert.match(orderStatusBadgeClass('requested'), /text-amber-700/);
    assert.match(orderStatusBadgeClass('refund_processing'), /text-sky-700/);
    assert.match(orderStatusBadgeClass('refunded'), /text-emerald-700/);
    assert.match(orderStatusBadgeClass('rejected'), /text-destructive/);
});

test('rejected cancellation requests resume displaying actual order progress', () => {
    const order = {
        status: { value: 'processing', label: 'Preparing for delivery' },
        cancellation: {
            status: {
                value: 'rejected',
                label: 'Cancellation request rejected',
            },
        },
    };
    assert.deepEqual(orderDisplayStatus(order), {
        value: 'processing',
        label: 'Preparing for delivery',
    });
    order.cancellation.status = {
        value: 'refunded',
        label: 'Refund completed',
    };
    assert.deepEqual(orderDisplayStatus(order), {
        value: 'refunded',
        label: 'Refund completed',
    });
});
