import assert from 'node:assert/strict';
import test from 'node:test';
import {
    BUSINESS_TIMEZONE,
    formatCalendarDate,
    formatDateTime,
    formatTimestampDate,
} from './dateTime.js';

test('timestamps always display in Manila time across the UTC date boundary', () => {
    assert.equal(BUSINESS_TIMEZONE, 'Asia/Manila');
    assert.equal(
        formatDateTime('2026-09-09T16:00:00.000000Z'),
        'Sep 10, 2026, 12:00 AM',
    );
});

test('timestamp dates use Manila time even when only the date is shown', () => {
    assert.equal(
        formatTimestampDate('2026-09-09T16:00:00.000000Z', 'long'),
        'September 10, 2026',
    );
});

test('calendar dates retain their provided day without timezone conversion', () => {
    assert.equal(formatCalendarDate('2026-09-10'), 'Sep 10, 2026');
});
