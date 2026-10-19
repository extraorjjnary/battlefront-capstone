export const BUSINESS_TIMEZONE = 'Asia/Manila';

export function formatDateTime(value, dateStyle = 'medium', timezone = BUSINESS_TIMEZONE) {
    return new Intl.DateTimeFormat('en-PH', {
        dateStyle,
        timeStyle: 'short',
        timeZone: timezone,
    }).format(new Date(value));
}

export function formatTimestampDate(value, dateStyle = 'medium', timezone = BUSINESS_TIMEZONE) {
    return new Intl.DateTimeFormat('en-PH', {
        dateStyle,
        timeZone: timezone,
    }).format(new Date(value));
}

export function formatCalendarDate(value, dateStyle = 'medium') {
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));

    return new Intl.DateTimeFormat('en-PH', {
        dateStyle,
        timeZone: 'UTC',
    }).format(date);
}
