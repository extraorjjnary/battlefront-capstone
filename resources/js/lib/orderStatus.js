const orderStatusBadgeClasses = {
    pending:
        'border-amber-500/50 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    processing:
        'border-sky-500/50 bg-sky-500/10 text-sky-700 dark:text-sky-300',
    completed:
        'border-emerald-500/50 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    cancelled: 'border-destructive/50 bg-destructive/10 text-destructive',
    verified:
        'border-emerald-500/50 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    rejected: 'border-destructive/50 bg-destructive/10 text-destructive',
};

export function orderStatusBadgeClass(status) {
    return orderStatusBadgeClasses[status] ?? '';
}
