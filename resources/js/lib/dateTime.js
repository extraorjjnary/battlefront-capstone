export const BUSINESS_TIMEZONE = 'Asia/Manila';

function parseTimestamp(value) {
    // Database timestamps without an offset are stored in UTC.
    if (
        typeof value === 'string' &&
        /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(value)
    ) {
        return new Date(value.replace(' ', 'T') + 'Z');
    }

    return new Date(value);
}

export function formatAgeDays(days) {
    if (days < 1) {
        return 'Less than a day old';
    }

    return days + (days === 1 ? ' day old' : ' days old');
}

export function formatDateTime(
    value,
    dateStyle = 'medium',
    timezone = BUSINESS_TIMEZONE,
) {
    return new Intl.DateTimeFormat('en-PH', {
        dateStyle,
        timeStyle: 'short',
        timeZone: timezone,
    }).format(parseTimestamp(value));
}

export function formatTimestampDate(
    value,
    dateStyle = 'medium',
    timezone = BUSINESS_TIMEZONE,
) {
    return new Intl.DateTimeFormat('en-PH', {
        dateStyle,
        timeZone: timezone,
    }).format(parseTimestamp(value));
}

export function formatCalendarDate(value, dateStyle = 'medium') {
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));

    return new Intl.DateTimeFormat('en-PH', {
        dateStyle,
        timeZone: 'UTC',
    }).format(date);
}
